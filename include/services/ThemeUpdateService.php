<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Theme Update Service
 *
 * Updates the bundled theme from a `theme-{folder}-v{version}.zip` package
 * attached to the latest GitHub release, independently of a core update.
 * Mirrors PluginUpdateService: check → download → verify → apply stages under
 * the UpdaterService lock, applied with ThemeService::updateFromZip(). A
 * theme is versioned by the `Version:` header of its primary stylesheet; a
 * theme without one is never offered an update.
 *
 * @package    LumoraGallery
 * @subpackage Themes
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.20.0
 * @see        PluginUpdateService The plugin equivalent this mirrors.
 * @see        ThemeService::updateFromZip() Performs the folder swap.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class ThemeUpdateService
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
     * Pure selection: installed, versioned bundled themes whose advertised
     * package is newer than the installed version.
     *
     * @param array<string, array{version: string, download_url: string, sha256_url: string|null}> $advertised
     * @param array<string, array{name: string, version: string}>                                  $installed
     * @return array<string, array{folder: string, name: string, installed: string, latest: string, download_url: string, sha256_url: string|null}>
     */
    public static function selectUpdates(array $advertised, array $installed): array
    {
        $out = [];
        foreach ($advertised as $folder => $pkg) {
            $folder = (string) $folder;
            if (!ThemeService::isBundled($folder) || !isset($installed[$folder])) continue;
            if ($installed[$folder]['version'] === '') continue;
            if (version_compare($pkg['version'], $installed[$folder]['version'], '<=')) continue;

            $out[$folder] = [
                'folder'       => $folder,
                'name'         => $installed[$folder]['name'],
                'installed'    => $installed[$folder]['version'],
                'latest'       => $pkg['version'],
                'download_url' => $pkg['download_url'],
                'sha256_url'   => $pkg['sha256_url'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Installed bundled themes with their header name and version ('' when
     * unversioned).
     *
     * @return array<string, array{name: string, version: string}>
     */
    public static function installedBundled(): array
    {
        $out = [];
        foreach (lumora_list_themes() as $folder) {
            if (!ThemeService::isBundled($folder)) continue;
            $meta        = lumora_get_theme_meta($folder);
            $out[$folder] = ['name' => $meta['name'], 'version' => $meta['version']];
        }
        return $out;
    }

    /**
     * Bundled-theme updates known from the cached release check. Never makes
     * a network call.
     *
     * @return array<string, array{folder: string, name: string, installed: string, latest: string, download_url: string, sha256_url: string|null}>
     */
    public static function availableUpdates(): array
    {
        $payload    = UpdateService::getCachedPayload();
        $advertised = is_array($payload['themes'] ?? null) ? $payload['themes'] : [];

        return self::selectUpdates($advertised, self::installedBundled());
    }

    // ── Gates ─────────────────────────────────────────────────────────────────

    /**
     * Content gates for a downloaded package: it is a theme archive, its
     * `Version:` is the advertised one and strictly newer than installed,
     * and the running Lumora meets `Requires at least`.
     *
     * @return array{ok: bool, message: string}
     */
    public static function evaluatePackage(string $zipPath, string $installedVersion, string $expectedVersion): array
    {
        $meta = ThemeService::readMetaFromZipFile($zipPath);
        if ($meta === null) {
            return ['ok' => false, 'message' => 'The package is not a valid theme archive.'];
        }
        if ($meta['version'] !== $expectedVersion) {
            return ['ok' => false, 'message' => 'The package declares version "' . $meta['version'] . '", not the advertised ' . $expectedVersion . '.'];
        }
        if (version_compare($meta['version'], $installedVersion, '<=')) {
            return ['ok' => false, 'message' => 'Version ' . $meta['version'] . ' is not newer than the installed ' . $installedVersion . '.'];
        }
        if ($meta['requires'] !== '' && version_compare(LUMORA_VERSION, $meta['requires'], '<')) {
            return ['ok' => false, 'message' => 'This update requires Lumora Gallery ' . $meta['requires'] . ' or newer — update Lumora Gallery first.'];
        }

        return ['ok' => true, 'message' => 'Package accepted.'];
    }

    // ── Stages ────────────────────────────────────────────────────────────────

    /**
     * Run one stage for one theme. Any failure after the lock was taken
     * releases it and removes the downloaded package.
     *
     * @return array{success: bool, stage: string, message: string, next: string|null, details: list<string>}
     */
    public static function runStage(string $stage, string $folder): array
    {
        if (!in_array($stage, self::STAGE_SEQUENCE, true)) {
            return self::result(false, $stage, 'Unknown stage.');
        }
        if (!ThemeService::isBundled($folder)) {
            return self::result(false, $stage, 'Only the bundled theme can be updated from GitHub.');
        }

        if ($stage === self::STAGE_CHECK) {
            return self::stageCheck($folder);
        }

        $lock = UpdaterService::getLockInfo();
        if (($lock['kind'] ?? '') !== 'theme' || ($lock['theme_folder'] ?? '') !== $folder) {
            return self::result(false, $stage, 'No active update session for this theme. Please start again.');
        }

        try {
            $result = match ($stage) {
                self::STAGE_DOWNLOAD => self::stageDownload($folder, $lock),
                self::STAGE_VERIFY   => self::stageVerify($folder, $lock),
                default              => self::stageApply($folder, $lock),
            };
        } catch (\Throwable $e) {
            error_log('Lumora: theme update stage "' . $stage . '" failed: ' . $e->getMessage());
            $result = self::result(false, $stage, 'Unexpected error during the update.');
        }

        if (!$result['success'] || $result['next'] === null) {
            self::finish($folder);
        }
        return $result;
    }

    private static function stageCheck(string $folder): array
    {
        if (UpdaterService::isUpdateRunning()) {
            return self::result(false, self::STAGE_CHECK, 'Another update is already in progress.');
        }

        $entry = self::availableUpdates()[$folder] ?? null;
        if ($entry === null) {
            return self::result(false, self::STAGE_CHECK, 'No update is available for this theme. Run "Check for Updates" first.');
        }
        if (!AbstractUpdateProvider::createFromConfig()->isTrustedAssetUrl($entry['download_url'])) {
            return self::result(false, self::STAGE_CHECK, 'The package URL is not a release asset of the configured repository.');
        }
        if ($entry['sha256_url'] === null) {
            return self::result(false, self::STAGE_CHECK, 'The release has no checksum file for this package, so the update was refused.');
        }

        $acquired = UpdaterService::acquireLock($entry['latest'], [
            'kind'         => 'theme',
            'theme_folder' => $folder,
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
    private static function stageDownload(string $folder, array $lock): array
    {
        $provider = AbstractUpdateProvider::createFromConfig();
        $url      = (string) ($lock['download_url'] ?? '');
        if (!$provider->isTrustedAssetUrl($url)) {
            return self::result(false, self::STAGE_DOWNLOAD, 'The package URL is not trusted.');
        }

        $data = $provider->downloadAsset($url, self::MAX_PACKAGE_BYTES);
        if ($data === null) {
            return self::result(false, self::STAGE_DOWNLOAD, 'Download failed or the package is larger than the allowed size. Check that the server can make outbound HTTPS requests.');
        }

        UpdaterService::ensureUpdatesDir();
        if (file_put_contents(self::packagePath($folder), $data) === false) {
            return self::result(false, self::STAGE_DOWNLOAD, 'Could not write the package to disk. Check permissions on cache/.');
        }

        return self::result(true, self::STAGE_DOWNLOAD, 'Package downloaded.', self::STAGE_VERIFY, [
            lumora_format_bytes(strlen($data)) . ' downloaded',
        ]);
    }

    /** @param array<string, mixed> $lock */
    private static function stageVerify(string $folder, array $lock): array
    {
        $path = self::packagePath($folder);
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

        $gate = self::evaluatePackage($path, (string) ($lock['from_version'] ?? '0'), (string) $lock['version']);
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
    private static function stageApply(string $folder, array $lock): array
    {
        $path     = self::packagePath($folder);
        $from     = (string) ($lock['from_version'] ?? '');
        $to       = (string) $lock['version'];
        $expected = (string) ($lock['sha256'] ?? '');

        $actual = is_file($path) ? hash_file('sha256', $path) : false;
        if ($expected === '' || $actual === false || !hash_equals($expected, strtolower($actual))) {
            return self::result(false, self::STAGE_APPLY, 'The verified package is missing or changed. Please start again.');
        }

        HookService::doAction('before_theme_update', $folder, $from, $to);
        $r = ThemeService::updateFromZip($path, $folder);
        HookService::doAction('after_theme_update', $folder, $from, $to, $r['success']);

        $label = $folder . ' ' . $from . ' → ' . $to;
        UpdaterService::logUpdate($r['success'] ? 'info' : 'error', 'Theme update ' . $label . ': ' . $r['message']);
        UpdaterService::recordUpdateHistory($folder . ' ' . $to, $r['success'], 'Theme ' . $label . ($r['success'] ? '' : ' — ' . $r['message']));

        if ($r['success'] && function_exists('lumora_current_user')) {
            $actor = lumora_current_user();
            LogService::log(
                'theme_updated',
                (int) ($actor['user_id'] ?? 0),
                (string) ($actor['username'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'Updated theme "' . $folder . '" from ' . $from . ' to ' . $to . ' (GitHub)'
            );
        }

        return $r['success']
            ? self::result(true, self::STAGE_APPLY, 'Theme updated to ' . $to . '.', null, ['✓ ' . $label])
            : self::result(false, self::STAGE_APPLY, $r['message']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function packagePath(string $folder): string
    {
        return UpdaterService::updatesDir() . 'theme-' . preg_replace('/[^a-z0-9_-]/', '', $folder) . '.zip';
    }

    /** Remove the downloaded package and release the lock. */
    private static function finish(string $folder): void
    {
        $path = self::packagePath($folder);
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
