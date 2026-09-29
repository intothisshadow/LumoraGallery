<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Plugin Service
 *
 * Discovers and manages plugins under plugins/*&#47;plugin.json — both the
 * "feature" type (self-contained add-ons that extend Lumora through
 * HookService rather than by patching core files; a feature plugin
 * registers hooks that fire on every relevant page load once enabled) and
 * the "importer" type (MigrationService::discoverImporters() also scans
 * these; run on-demand from admin/migrate.php, never via a bootstrap hook).
 * Both types share the same enabled/disabled toggle and Delete flow via
 * Admin → Plugins, but default oppositely: a feature plugin ships disabled
 * (opt-in, since enabling it starts running code on every page load), while
 * an importer plugin ships enabled (opt-out, since it needs to be runnable
 * immediately and is only ever invoked by an explicit admin action) — an
 * admin who has finished migrating a gallery can disable an importer they
 * no longer need without deleting it outright.
 *
 * A feature plugin's manifest may declare, relative to its own folder:
 *   "bootstrap": "bootstrap.php"   — required on every request once the
 *                                    plugin is enabled; must only register
 *                                    hooks (HookService::addAction/addFilter),
 *                                    never perform DB writes itself.
 *   "activate":  "activate.php"    — required once, only when the plugin is
 *                                    enabled from admin/plugins.php; this is
 *                                    where the plugin creates its own tables.
 *   "deactivate": "deactivate.php" — required once, only when the plugin is
 *                                    disabled; intentionally never called
 *                                    automatically, so a plugin never drops
 *                                    its own data without a deliberate admin
 *                                    action.
 *
 * Enabled/disabled state is stored per-plugin in {PREFIX}config under a
 * `plugin_enabled__{id}` key via LumoraConfig, the same mechanism every
 * other setting in Lumora already uses — no new table required.
 *
 * installFromZip()/updateFromZip() (LG-069) let Admin → Plugins install a
 * new plugin or update an already-installed one from an uploaded ZIP,
 * mirroring ThemeService's own ZIP pipeline: same staging-then-rename()
 * approach, same path-safety checks, same single-wrapping-folder
 * flattening. The destination folder is always the archive's own declared
 * plugin.json "id", matching every bundled plugin's existing folder-name
 * convention.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 * @see        HookService The action/filter registry plugin bootstrap files call into.
 * @see        ThemeService The equivalent ZIP install/update pipeline this mirrors.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class PluginService
{
    /** Plugins that ship with Lumora and may be updated from GitHub release packages. */
    public const BUNDLED_PLUGINS = [
        'coppermine-importer',
        'lumora-visitor-stats',
        'lumora-press-shortcodes',
        'on-demand-thumbnails',
    ];

    private const MAX_ZIP_ENTRIES           = 2000;
    private const MAX_ZIP_UNCOMPRESSED_SIZE = 50 * 1024 * 1024;

    /**
     * Scan LUMORA_PLUGINS_PATH for every plugin manifest, regardless of type.
     *
     * @return list<array{id: string, type: string, name: string, version: string,
     *                     min_lumora: string, description: string, author: string,
     *                     admin_url: string, bootstrap: string, activate: string,
     *                     deactivate: string, dir: string, manifest_path: string}>
     */
    public static function discoverAll(): array
    {
        if (!defined('LUMORA_PLUGINS_PATH') || !is_dir(LUMORA_PLUGINS_PATH)) {
            return [];
        }

        $plugins = [];

        foreach (glob(LUMORA_PLUGINS_PATH . '*/plugin.json') ?: [] as $manifest_path) {
            try {
                $json = file_get_contents($manifest_path);
                if ($json === false) continue;

                $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
                if (!is_array($data) || empty($data['id'])) continue;

                $data += [
                    'type'        => 'feature',
                    'min_lumora'  => '1.0.0',
                    'author'      => '',
                    'admin_url'   => '',
                    'description' => '',
                    'version'     => '0.0.0',
                    'name'        => (string) $data['id'],
                    'bootstrap'   => '',
                    'activate'    => '',
                    'deactivate'  => '',
                ];
                $data['manifest_path'] = $manifest_path;
                $data['dir']           = dirname($manifest_path) . DIRECTORY_SEPARATOR;
                $plugins[]             = $data;
            } catch (\Throwable) {
                // Skip malformed or unreadable manifests.
            }
        }

        return $plugins;
    }

    /**
     * Discover only "feature"-type plugins (hook-based, not the on-demand
     * importer type — see class docblock).
     *
     * @return list<array{id: string, type: string, name: string, version: string,
     *                     min_lumora: string, description: string, author: string,
     *                     admin_url: string, bootstrap: string, activate: string,
     *                     deactivate: string, dir: string, manifest_path: string}>
     */
    public static function discoverFeaturePlugins(): array
    {
        return array_values(array_filter(
            self::discoverAll(),
            static fn(array $p): bool => $p['type'] === 'feature'
        ));
    }

    /**
     * Discover every plugin type that has its own enable/disable toggle on
     * Admin → Plugins — both "feature" and "importer" (see class docblock).
     *
     * @return list<array{id: string, type: string, name: string, version: string,
     *                     min_lumora: string, description: string, author: string,
     *                     admin_url: string, bootstrap: string, activate: string,
     *                     deactivate: string, dir: string, manifest_path: string}>
     */
    public static function discoverManageablePlugins(): array
    {
        return array_values(array_filter(
            self::discoverAll(),
            static fn(array $p): bool => self::isToggleable($p['type'])
        ));
    }

    /** True for a plugin type that has its own enabled/disabled state (see class docblock). */
    private static function isToggleable(string $type): bool
    {
        return $type === 'feature' || $type === 'importer';
    }

    /** True when $id is one of the plugins that ship with Lumora. */
    public static function isBundled(string $id): bool
    {
        return in_array($id, self::BUNDLED_PLUGINS, true);
    }

    /**
     * Installed version (from each plugin.json) of every bundled plugin
     * currently on disk.
     *
     * @return array<string, array{name: string, version: string, min_lumora: string}>
     */
    public static function installedBundledVersions(): array
    {
        $out = [];
        foreach (self::discoverAll() as $p) {
            if (self::isBundled($p['id'])) {
                $out[$p['id']] = ['name' => $p['name'], 'version' => $p['version'], 'min_lumora' => $p['min_lumora']];
            }
        }
        return $out;
    }

    /** Return true when $plugin_min_lumora ≤ LUMORA_VERSION. */
    public static function isCompatible(string $plugin_min_lumora): bool
    {
        return version_compare(LUMORA_VERSION, $plugin_min_lumora, '>=');
    }

    /** Config key a plugin's enabled state is stored under. */
    private static function configKey(string $id): string
    {
        return 'plugin_enabled__' . preg_replace('/[^a-z0-9_-]/', '', strtolower($id));
    }

    /**
     * True when the given plugin id is currently enabled. A feature plugin
     * defaults to disabled (opt-in); an importer plugin defaults to enabled
     * (opt-out — it shipped with no toggle at all until this default was
     * added, so an existing install's importer must stay usable unless an
     * admin explicitly disables it).
     */
    public static function isEnabled(string $id, string $type = 'feature'): bool
    {
        $default = $type === 'importer' ? '1' : '0';

        return LumoraConfig::get(self::configKey($id), $default) === '1';
    }

    /**
     * Enable a plugin: persists the enabled flag, then — the first time it
     * is turned on — requires its "activate" script if declared, so the
     * plugin can create its own tables. Returns false (leaving the plugin
     * disabled) when activation throws, so a broken plugin never ends up
     * silently "enabled" with missing schema.
     *
     * @param array{id: string, dir: string, activate: string} $plugin One entry from discoverAll()/discoverFeaturePlugins().
     */
    public static function enablePlugin(array $plugin): bool
    {
        if ($plugin['activate'] !== '') {
            $path = $plugin['dir'] . $plugin['activate'];
            if (is_file($path)) {
                try {
                    require $path;
                } catch (\Throwable $e) {
                    error_log('Lumora: plugin "' . $plugin['id'] . '" activation failed: ' . $e->getMessage());
                    return false;
                }
            }
        }

        LumoraConfig::set(self::configKey($plugin['id']), '1');
        return true;
    }

    /**
     * Disable a plugin. Intentionally never touches the plugin's own data —
     * only its "deactivate" script (if declared) runs, for cleanup that
     * stops short of dropping tables (e.g. clearing a cache). Removing data
     * entirely is left to the admin deleting the plugin's folder and its
     * own table by hand.
     *
     * @param array{id: string, dir: string, deactivate: string} $plugin
     */
    public static function disablePlugin(array $plugin): void
    {
        LumoraConfig::set(self::configKey($plugin['id']), '0');

        if ($plugin['deactivate'] !== '') {
            $path = $plugin['dir'] . $plugin['deactivate'];
            if (is_file($path)) {
                try {
                    require $path;
                } catch (\Throwable $e) {
                    error_log('Lumora: plugin "' . $plugin['id'] . '" deactivation failed: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Permanently delete a plugin's entire directory from disk.
     *
     * Refuses to delete a currently-enabled feature or importer plugin —
     * disable it first, so a feature plugin's bootstrap/hooks are never left
     * referencing files that no longer exist mid-request, and an importer
     * plugin isn't removed while still offered as runnable from
     * admin/migrate.php. A plugin type with no toggle of its own is never
     * gated by this check.
     *
     * Path safety: resolves the plugin's directory and refuses to proceed
     * unless it's a direct child of LUMORA_PLUGINS_PATH — the same guard
     * Lumora Press's own PluginInstaller::delete() equivalent applies —
     * even though $id only ever selects a directory already produced by
     * discoverAll()'s own glob(), never a raw caller-supplied path.
     *
     * @return bool True on success; false if the plugin doesn't exist, is
     *              still enabled, or its directory fails the path-safety
     *              check or can't be removed.
     */
    public static function deletePlugin(string $id): bool
    {
        $plugin = null;
        foreach (self::discoverAll() as $p) {
            if ($p['id'] === $id) {
                $plugin = $p;
                break;
            }
        }
        if ($plugin === null) {
            return false;
        }

        if (self::isToggleable($plugin['type']) && self::isEnabled($id, $plugin['type'])) {
            return false;
        }

        $root = realpath(LUMORA_PLUGINS_PATH);
        $dir  = realpath($plugin['dir']);
        if ($root === false || $dir === false || dirname($dir) !== rtrim($root, DIRECTORY_SEPARATOR)) {
            return false;
        }

        UpdaterService::removeDirectory($dir);
        return !is_dir($dir);
    }

    /**
     * Validate an uploaded ZIP and install it as a brand-new plugin folder
     * inside plugins/. The destination folder name is the archive's own
     * declared plugin.json "id" — must not already exist (re-uploading for
     * an already-installed plugin is updateFromZip() instead, mirroring
     * ThemeService's install/update split).
     *
     * @return array{success: bool, message: string, id: string|null}
     */
    public static function installFromZip(string $tmpPath): array
    {
        return self::processZip($tmpPath, null);
    }

    /**
     * Validate an uploaded ZIP and replace an already-installed plugin's
     * files with it in place — enabled or not; a plugin's already-loaded
     * bootstrap for the current request is unaffected, since the updated
     * files only take effect from the next request onward.
     *
     * @return array{success: bool, message: string, id: string|null}
     */
    public static function updateFromZip(string $tmpPath, string $id): array
    {
        $found = false;
        foreach (self::discoverAll() as $p) {
            if ($p['id'] === $id) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['success' => false, 'message' => 'That plugin could not be found.', 'id' => null];
        }
        return self::processZip($tmpPath, $id);
    }

    /**
     * Shared install/update ZIP pipeline, mirroring
     * ThemeService::processZip() — same staging-then-rename() approach so a
     * bad upload can never leave a half-extracted plugin live, and the same
     * path-safety re-check against OS-specific normalisation/symlink edge
     * cases after extraction. $targetId === null means "install as a new
     * plugin"; a non-null value means "replace this already-installed
     * plugin's files in place".
     *
     * @return array{success: bool, message: string, id: string|null}
     */
    private static function processZip(string $tmpPath, ?string $targetId): array
    {
        if (!is_file($tmpPath)) {
            return ['success' => false, 'message' => 'The uploaded file could not be found.', 'id' => null];
        }
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension is required. Enable ext-zip on your server.', 'id' => null];
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpPath, \ZipArchive::RDONLY) !== true) {
            return ['success' => false, 'message' => 'The uploaded file is not a valid ZIP archive.', 'id' => null];
        }

        $numFiles = $zip->count();
        if ($numFiles === 0) {
            $zip->close();
            return ['success' => false, 'message' => 'The uploaded archive is empty.', 'id' => null];
        }
        if ($numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            return ['success' => false, 'message' => 'The archive contains too many files to be a valid plugin package.', 'id' => null];
        }

        $names = [];
        $totalUncompressed = 0;
        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) continue;

            $name = (string) $stat['name'];
            if (lumora_is_unsafe_zip_entry_name($name)) {
                $zip->close();
                return ['success' => false, 'message' => 'Archive contains an unsafe path entry: ' . $name . '. Aborting for security.', 'id' => null];
            }

            $names[]            = $name;
            $totalUncompressed += (int) $stat['size'];
        }

        if ($totalUncompressed > self::MAX_ZIP_UNCOMPRESSED_SIZE) {
            $zip->close();
            return ['success' => false, 'message' => 'The archive is too large to be processed safely.', 'id' => null];
        }

        $prefix         = self::detectRootPrefix($names);
        $manifestEntry  = $prefix . 'plugin.json';
        if (!in_array($manifestEntry, $names, true)) {
            $zip->close();
            return ['success' => false, 'message' => 'The archive does not contain a plugin.json file and cannot be a valid Lumora plugin.', 'id' => null];
        }

        $manifestId = self::readPluginIdFromZip($zip, $manifestEntry);
        if ($manifestId === '') {
            $zip->close();
            return ['success' => false, 'message' => 'plugin.json does not declare a valid "id".', 'id' => null];
        }

        if ($targetId === null) {
            $id          = $manifestId;
            $destination = LUMORA_PLUGINS_PATH . $id;
            if (is_dir($destination)) {
                $zip->close();
                return [
                    'success' => false,
                    'id'      => null,
                    'message' => "A plugin folder named \"{$id}\" already exists. "
                        . 'Remove it first, or use the Update action on that plugin instead.',
                ];
            }
        } else {
            if ($manifestId !== $targetId) {
                $zip->close();
                return [
                    'success' => false,
                    'id'      => null,
                    'message' => 'The uploaded archive\'s plugin.json id ("' . $manifestId . '") does not match the plugin being updated ("' . $targetId . '").',
                ];
            }
            $id          = $targetId;
            $destination = LUMORA_PLUGINS_PATH . $id;
        }

        $staging = LUMORA_PLUGINS_PATH . '.staging-' . bin2hex(random_bytes(8));
        if (!mkdir($staging, 0755, true)) {
            $zip->close();
            return ['success' => false, 'message' => 'Could not create a staging directory for the plugin.', 'id' => null];
        }

        $extracted = $zip->extractTo($staging);
        $zip->close();

        if (!$extracted) {
            UpdaterService::removeDirectory($staging);
            return ['success' => false, 'message' => 'Failed to extract the plugin archive.', 'id' => null];
        }

        // Post-extraction realpath guard, mirroring
        // ThemeService::processZip()'s belt-and-braces check against
        // OS-specific path normalisation and symlink edge cases the
        // string-based pre-extraction check alone cannot catch.
        $canonStaging = rtrim((string) (realpath($staging) ?: $staging), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $resolved = realpath($item->getPathname());
            if ($resolved !== false && !str_starts_with($resolved . DIRECTORY_SEPARATOR, $canonStaging)) {
                UpdaterService::removeDirectory($staging);
                return [
                    'success' => false,
                    'id'      => null,
                    'message' => 'Archive extraction produced a path outside the staging directory. Aborting for security.',
                ];
            }
        }

        $extractedRoot = $prefix !== ''
            ? rtrim($staging . DIRECTORY_SEPARATOR . rtrim($prefix, '/'), DIRECTORY_SEPARATOR)
            : rtrim($staging, DIRECTORY_SEPARATOR);

        if (!is_dir($extractedRoot) || !file_exists($extractedRoot . DIRECTORY_SEPARATOR . 'plugin.json')) {
            UpdaterService::removeDirectory($staging);
            return ['success' => false, 'message' => 'The archive does not have the expected plugin folder structure.', 'id' => null];
        }

        if ($targetId !== null) {
            // Update-in-place: swap the old folder out and the new one in
            // via two fast local rename() calls, so the window where
            // $destination doesn't exist at all is as small as the
            // filesystem allows — true atomicity would need a symlink
            // indirection layer this simpler feature doesn't have.
            $displaced = $destination . '.replaced-' . bin2hex(random_bytes(4));
            if (!@rename($destination, $displaced)) {
                UpdaterService::removeDirectory($staging);
                return ['success' => false, 'message' => 'Could not remove the existing plugin files before updating.', 'id' => null];
            }
            if (!@rename($extractedRoot, $destination)) {
                @rename($displaced, $destination);
                UpdaterService::removeDirectory($staging);
                return ['success' => false, 'message' => 'Could not install the updated plugin files; the previous version was restored.', 'id' => null];
            }
            UpdaterService::removeDirectory($displaced);
        } else {
            if (!@rename($extractedRoot, $destination)) {
                UpdaterService::removeDirectory($staging);
                return ['success' => false, 'message' => 'Could not install the plugin.', 'id' => null];
            }
        }

        UpdaterService::removeDirectory($staging);

        return [
            'success' => true,
            'id'      => $id,
            'message' => $targetId !== null
                ? 'Plugin "' . $id . '" updated.'
                : 'Plugin "' . $id . '" installed.',
        ];
    }

    /**
     * Decoded plugin.json from a plugin ZIP on disk (root or single
     * wrapping folder), or null when the archive or manifest is unreadable.
     *
     * @return array<string, mixed>|null
     */
    public static function readManifestFromZipFile(string $zipPath): ?array
    {
        if (!is_file($zipPath) || !class_exists('ZipArchive')) return null;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) return null;

        $names = [];
        for ($i = 0, $n = $zip->count(); $i < $n; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) $names[] = (string) $stat['name'];
        }

        $json = $zip->getFromName(self::detectRootPrefix($names) . 'plugin.json');
        $zip->close();
        if ($json === false) return null;

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /** Read and validate the "id" field out of a ZIP archive's plugin.json before anything is extracted. */
    private static function readPluginIdFromZip(\ZipArchive $zip, string $manifestEntry): string
    {
        $json = $zip->getFromName($manifestEntry);
        if ($json === false) return '';

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }
        if (!is_array($data) || empty($data['id']) || !is_string($data['id'])) return '';

        // Same charset a destination folder name must be safe as — mirrors
        // configKey()'s own sanitisation so an id containing anything else
        // can never be used to influence the destination path.
        return preg_match('/^[a-z0-9_-]+$/', $data['id']) === 1 ? $data['id'] : '';
    }

    /**
     * Detect a single wrapping top-level folder (a GitHub-export-style
     * plugin-name-1.0.0/ zip) so it can be flattened on extract, mirroring
     * ThemeService::detectRootPrefix()'s equivalent check for template.html.
     *
     * @param list<string> $names
     */
    private static function detectRootPrefix(array $names): string
    {
        if (in_array('plugin.json', $names, true)) return '';

        foreach ($names as $name) {
            if (str_ends_with($name, '/plugin.json') && substr_count($name, '/') === 1) {
                return substr($name, 0, -strlen('plugin.json'));
            }
        }
        return '';
    }

    /**
     * Require every enabled, compatible feature plugin's bootstrap file, so
     * it can register its hooks for the current request. Called once from
     * bootstrap.php after config is loaded. A plugin whose bootstrap throws
     * is logged and skipped rather than allowed to fatal the whole request.
     */
    public static function loadEnabledPlugins(): void
    {
        foreach (self::discoverFeaturePlugins() as $plugin) {
            if (!self::isEnabled($plugin['id'])) continue;
            if (!self::isCompatible($plugin['min_lumora'])) continue;
            if ($plugin['bootstrap'] === '') continue;

            $path = $plugin['dir'] . $plugin['bootstrap'];
            if (!is_file($path)) continue;

            try {
                require_once $path;
            } catch (\Throwable $e) {
                error_log('Lumora: plugin "' . $plugin['id'] . '" bootstrap failed: ' . $e->getMessage());
            }
        }
    }
}
