<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Plugin Service
 *
 * Discovers and manages "feature" plugins (LG-045) — self-contained add-ons
 * under plugins/*&#47;plugin.json that extend Lumora through HookService
 * rather than by patching core files. This is distinct from the older
 * "importer" plugin type (see MigrationService::discoverImporters()), which
 * is discovered separately and only ever runs on-demand from
 * admin/migrate.php; a feature plugin instead registers hooks that fire on
 * every relevant page load once enabled.
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
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 * @see        HookService The action/filter registry plugin bootstrap files call into.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class PluginService
{
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

    /** True when the given plugin id is currently enabled. Feature plugins default to disabled (opt-in). */
    public static function isEnabled(string $id): bool
    {
        return LumoraConfig::get(self::configKey($id), '0') === '1';
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
