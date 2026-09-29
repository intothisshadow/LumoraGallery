<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Plugin Update Service
 *
 * Updates the bundled plugins from `plugin-{id}-v{version}.zip` packages
 * attached to the latest GitHub release, independently of a core update.
 * Discovery reuses the cached release check (GitHubUpdateProvider's
 * `plugins` map); applying reuses PluginService::updateFromZip(). Each
 * update runs as check → download → verify → apply stages under the
 * UpdaterService lock so it can never overlap a core update.
 *
 * A package must not need a database change; schema changes ship with a
 * core release.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.20.0
 * @see        GitHubUpdateProvider::parsePluginAssets() Source of the package list.
 * @see        PluginService::updateFromZip() Performs the file swap.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class PluginUpdateService
{
    public const STAGE_CHECK    = 'check';
    public const STAGE_DOWNLOAD = 'download';
    public const STAGE_VERIFY   = 'verify';
    public const STAGE_APPLY    = 'apply';

    public const STAGE_SEQUENCE = [
        self::STAGE_CHECK,
        self::STAGE_DOWNLOAD,
        self::STAGE_VERIFY,
        self::STAGE_APPLY,
    ];

    private const MAX_PACKAGE_BYTES = 20 * 1024 * 1024;

    // ── Discovery ─────────────────────────────────────────────────────────────

    /**
     * Pure selection: bundled plugins whose advertised package is newer than
     * the installed version.
     *
     * @param array<string, array{version: string, download_url: string, sha256_url: string|null}> $advertised
     * @param array<string, array{name: string, version: string, min_lumora: string}>               $installed
     * @return array<string, array{id: string, name: string, installed: string, latest: string, download_url: string, sha256_url: string|null}>
     */
    public static function selectUpdates(array $advertised, array $installed): array
    {
        $out = [];
        foreach ($advertised as $id => $pkg) {
            $id = (string) $id;
            if (!PluginService::isBundled($id) || !isset($installed[$id])) continue;
            if (version_compare($pkg['version'], $installed[$id]['version'], '<=')) continue;

            $out[$id] = [
                'id'           => $id,
                'name'         => $installed[$id]['name'],
                'installed'    => $installed[$id]['version'],
                'latest'       => $pkg['version'],
                'download_url' => $pkg['download_url'],
                'sha256_url'   => $pkg['sha256_url'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Bundled-plugin updates known from the cached release check. Never
     * makes a network call.
     *
     * @return array<string, array{id: string, name: string, installed: string, latest: string, download_url: string, sha256_url: string|null}>
     */
    public static function availableUpdates(): array
    {
        $payload    = UpdateService::getCachedPayload();
        $advertised = is_array($payload['plugins'] ?? null) ? $payload['plugins'] : [];

        return self::selectUpdates($advertised, PluginService::installedBundledVersions());
    }

    /** Force a fresh release check, then return availableUpdates(). */
    public static function refresh(): array
    {
        UpdateService::check(force: true);
        return self::availableUpdates();
    }

    // ── Gates ─────────────────────────────────────────────────────────────────

    /**
     * Content gates for a downloaded package: manifest id matches, version is
     * the advertised one and a strict upgrade, and the running Lumora meets
     * `min_lumora`.
     *
     * @return array{ok: bool, message: string}
     */
    public static function evaluatePackage(string $zipPath, string $id, string $installedVersion, string $expectedVersion): array
    {
        $manifest = PluginService::readManifestFromZipFile($zipPath);
        if ($manifest === null) {
            return ['ok' => false, 'message' => 'The package is not a valid plugin archive.'];
        }
        if (($manifest['id'] ?? null) !== $id) {
            return ['ok' => false, 'message' => 'The package\'s plugin id does not match "' . $id . '".'];
        }

        $version = (string) ($manifest['version'] ?? '');
        if ($version !== $expectedVersion) {
            return ['ok' => false, 'message' => 'The package declares version ' . $version . ', not the advertised ' . $expectedVersion . '.'];
        }
        if (version_compare($version, $installedVersion, '<=')) {
            return ['ok' => false, 'message' => 'Version ' . $version . ' is not newer than the installed ' . $installedVersion . '.'];
        }

        $min = (string) ($manifest['min_lumora'] ?? '1.0.0');
        if (!PluginService::isCompatible($min)) {
            return ['ok' => false, 'message' => 'This update requires Lumora Gallery ' . $min . ' or newer — update Lumora Gallery first.'];
        }

        return ['ok' => true, 'message' => 'Package accepted.'];
    }

    // ── Stages ────────────────────────────────────────────────────────────────

    /**
     * Run one stage for one plugin. Any failure after the lock was taken
     * releases it and removes the downloaded package.
     *
     * @return array{success: bool, stage: string, message: string, next: string|null, details: list<string>}
     */
    public static function runStage(string $stage, string $id): array
    {
        if (!in_array($stage, self::STAGE_SEQUENCE, true)) {
            return self::result(false, $stage, 'Unknown stage.');
        }
        if (!PluginService::isBundled($id)) {
            return self::result(false, $stage, 'Only bundled plugins can be updated from GitHub.');
        }

        if ($stage === self::STAGE_CHECK) {
            return self::stageCheck($id);
        }

        $lock = UpdaterService::getLockInfo();
        if (($lock['kind'] ?? '') !== 'plugin' || ($lock['plugin_id'] ?? '') !== $id) {
            return self::result(false, $stage, 'No active update session for this plugin. Please start again.');
        }

        try {
            $result = match ($stage) {
                self::STAGE_DOWNLOAD => self::stageDownload($id, $lock),
                self::STAGE_VERIFY   => self::stageVerify($id, $lock),
                default              => self::stageApply($id, $lock),
            };
        } catch (\Throwable $e) {
            error_log('Lumora: plugin update stage "' . $stage . '" failed: ' . $e->getMessage());
            $result = self::result(false, $stage, 'Unexpected error during the update.');
        }

        if (!$result['success'] || $result['next'] === null) {
            self::finish($id);
        }
        return $result;
    }

    private static function stageCheck(string $id): array
    {
        if (UpdaterService::isUpdateRunning()) {
            return self::result(false, self::STAGE_CHECK, 'Another update is already in progress.');
        }

        $entry = self::availableUpdates()[$id] ?? null;
        if ($entry === null) {
            return self::result(false, self::STAGE_CHECK, 'No update is available for this plugin. Run "Check for Updates" first.');
        }
        if (!AbstractUpdateProvider::createFromConfig()->isTrustedAssetUrl($entry['download_url'])) {
            return self::result(false, self::STAGE_CHECK, 'The package URL is not a release asset of the configured repository.');
        }
        if ($entry['sha256_url'] === null) {
            return self::result(false, self::STAGE_CHECK, 'The release has no checksum file for this package, so the update was refused.');
        }

        $acquired = UpdaterService::acquireLock($entry['latest'], [
            'kind'         => 'plugin',
            'plugin_id'    => $id,
            'from_version' => $entry['installed'],
            'download_url' => $entry['download_url'],
            'sha256_url'   => $entry['sha256_url'],
        ]);
        if (!$acquired) {
            return self::result(false, self::STAGE_CHECK, 'Could not start the update session.');
        }

        return self::result(true, self::STAGE_CHECK, 'Update available.', self::STAGE_DOWNLOAD, [
            $entry['name'] . ' ' . $entry['installed'] . ' → ' . $entry['latest'],
        ]);
    }

    /** @param array<string, mixed> $lock */
    private static function stageDownload(string $id, array $lock): array
    {
        $url = (string) ($lock['download_url'] ?? '');
        if (!AbstractUpdateProvider::createFromConfig()->isTrustedAssetUrl($url)) {
            return self::result(false, self::STAGE_DOWNLOAD, 'The package URL is not trusted.');
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 60,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'user_agent'      => 'Lumora Gallery/' . LUMORA_VERSION,
                'ignore_errors'   => false,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        set_error_handler(static fn(): bool => true);
        try {
            $data = file_get_contents($url, false, $ctx, 0, self::MAX_PACKAGE_BYTES + 1);
        } finally {
            restore_error_handler();
        }

        if ($data === false || $data === '') {
            return self::result(false, self::STAGE_DOWNLOAD, 'Download failed. Check that the server can make outbound HTTPS requests.');
        }
        if (strlen($data) > self::MAX_PACKAGE_BYTES) {
            return self::result(false, self::STAGE_DOWNLOAD, 'The package is larger than the allowed size.');
        }

        UpdaterService::ensureUpdatesDir();
        if (file_put_contents(self::packagePath($id), $data) === false) {
            return self::result(false, self::STAGE_DOWNLOAD, 'Could not write the package to disk. Check permissions on cache/.');
        }

        return self::result(true, self::STAGE_DOWNLOAD, 'Package downloaded.', self::STAGE_VERIFY, [
            lumora_format_bytes(strlen($data)) . ' downloaded',
        ]);
    }

    /** @param array<string, mixed> $lock */
    private static function stageVerify(string $id, array $lock): array
    {
        $path = self::packagePath($id);
        if (!is_file($path)) {
            return self::result(false, self::STAGE_VERIFY, 'The package was not found. Please start again.');
        }

        $provider = AbstractUpdateProvider::createFromConfig();
        $sumUrl   = (string) ($lock['sha256_url'] ?? '');
        if (!$provider->isTrustedAssetUrl($sumUrl)) {
            return self::result(false, self::STAGE_VERIFY, 'The checksum URL is not trusted.');
        }

        $expected = $provider->fetchChecksumFor($sumUrl, basename((string) parse_url((string) $lock['download_url'], PHP_URL_PATH)));
        if ($expected === null) {
            return self::result(false, self::STAGE_VERIFY, 'The checksum could not be retrieved, so the update was refused.');
        }

        $actual = hash_file('sha256', $path);
        if ($actual === false || !hash_equals($expected, strtolower($actual))) {
            return self::result(false, self::STAGE_VERIFY, 'Integrity check failed: SHA-256 mismatch. The download may be corrupt or tampered with.');
        }

        $gate = self::evaluatePackage($path, $id, (string) ($lock['from_version'] ?? '0.0.0'), (string) $lock['version']);
        if (!$gate['ok']) {
            return self::result(false, self::STAGE_VERIFY, $gate['message']);
        }

        UpdaterService::updateLock(['sha256' => $expected]);

        return self::result(true, self::STAGE_VERIFY, 'Package verified.', self::STAGE_APPLY, [
            '✓ SHA-256 verified (' . substr($expected, 0, 16) . '…)',
            '✓ ' . $gate['message'],
        ]);
    }

    /** @param array<string, mixed> $lock */
    private static function stageApply(string $id, array $lock): array
    {
        $path     = self::packagePath($id);
        $from     = (string) ($lock['from_version'] ?? '');
        $to       = (string) $lock['version'];
        $expected = (string) ($lock['sha256'] ?? '');

        $actual = is_file($path) ? hash_file('sha256', $path) : false;
        if ($expected === '' || $actual === false || !hash_equals($expected, strtolower($actual))) {
            return self::result(false, self::STAGE_APPLY, 'The verified package is missing or changed. Please start again.');
        }

        HookService::doAction('before_plugin_update', $id, $from, $to);
        $r = PluginService::updateFromZip($path, $id);
        HookService::doAction('after_plugin_update', $id, $from, $to, $r['success']);

        $label = $id . ' ' . $from . ' → ' . $to;
        UpdaterService::logUpdate($r['success'] ? 'info' : 'error', 'Plugin update ' . $label . ': ' . $r['message']);
        UpdaterService::recordUpdateHistory($id . ' ' . $to, $r['success'], 'Plugin ' . $label . ($r['success'] ? '' : ' — ' . $r['message']));

        if ($r['success'] && function_exists('lumora_current_user')) {
            $actor = lumora_current_user();
            LogService::log(
                'plugin_updated',
                (int) ($actor['user_id'] ?? 0),
                (string) ($actor['username'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'Updated plugin "' . $id . '" from ' . $from . ' to ' . $to . ' (GitHub)'
            );
        }

        return $r['success']
            ? self::result(true, self::STAGE_APPLY, 'Plugin updated to ' . $to . '.', null, ['✓ ' . $label])
            : self::result(false, self::STAGE_APPLY, $r['message']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function packagePath(string $id): string
    {
        return UpdaterService::updatesDir() . 'plugin-' . preg_replace('/[^a-z0-9_-]/', '', $id) . '.zip';
    }

    /** Remove the downloaded package and release the lock. */
    private static function finish(string $id): void
    {
        $path = self::packagePath($id);
        if (is_file($path)) {
            unlink($path);
        }
        UpdaterService::releaseLock();
    }

    /**
     * @param list<string> $details
     * @return array{success: bool, stage: string, message: string, next: string|null, details: list<string>}
     */
    private static function result(bool $success, string $stage, string $message, ?string $next = null, array $details = []): array
    {
        return ['success' => $success, 'stage' => $stage, 'message' => $message, 'next' => $next, 'details' => $details];
    }
}
