<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Configuration Service
 *
 * Static cache for gallery configuration stored in {PREFIX}config.
 * Replaces the module-level $LUMORA_CONFIG global previously declared in
 * include/functions.php.  All access goes through LumoraConfig::get() /
 * LumoraConfig::set(); the legacy lumora_config() / lumora_set_config()
 * free functions in functions.php now delegate here for backward
 * compatibility.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class LumoraConfig
{
    /** @var array<string, string> Runtime config cache. */
    private static array $cache = [];

    /**
     * Load all rows from {PREFIX}config into the in-memory cache.
     * Called once per request by bootstrap.php after the DB connection.
     */
    public static function load(): void
    {
        $rows = LumoraDB::fetchAll('SELECT name, value FROM `{PREFIX}config`');
        foreach ($rows as $row) {
            self::$cache[$row['name']] = $row['value'];
        }
    }

    /**
     * Get a config value from the in-memory cache.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    /**
     * Persist a config value to the DB and update the in-memory cache.
     */
    public static function set(string $key, mixed $value): void
    {
        self::$cache[$key] = (string) $value;
        LumoraDB::query(
            'INSERT INTO `{PREFIX}config` (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, (string) $value]
        );
    }

    /**
     * Boolean-style config keys: stored as '1' or '0' only.
     * Shared by sanitizeValue() below.
     */
    private const BOOL_KEYS = ['count_album_views', 'gallery_offline', 'show_powered_by', 'install_ping_enabled', 'update_auto_check', 'litespeed_cache_purge'];

    /**
     * Validate and normalise a raw config value for a known config key,
     * applying the same enum/range constraints regardless of where the
     * value originated (the settings form, an imported JSON config file,
     * or any future caller).
     *
     * Centralising this here (rather than duplicating the per-key rules in
     * every caller) ensures a value can never bypass validation through one
     * entry point but not another — see TODO-security.md #11, where
     * admin/config.php's `import` action previously stored values with only
     * a key-name whitelist check, skipping the enum/range validation the
     * `save` action applied.
     *
     * Unknown keys fall through to a plain trim(), matching the previous
     * per-page `default` match arm.
     *
     * @param string $key      Config key name.
     * @param mixed  $rawValue Raw value from POST data or an imported JSON file.
     */
    public static function sanitizeValue(string $key, mixed $rawValue): string
    {
        $raw = (string) $rawValue;

        if (in_array($key, self::BOOL_KEYS, true)) {
            return $raw === '1' ? '1' : '0';
        }

        return match ($key) {
            'thumb_width', 'thumb_height'      => (string) max(1, (int) $raw),
            'per_page'                         => (string) max(1, (int) $raw),
            'base_url'                         => rtrim(trim($raw), '/') . '/',
            'thumb_quality'                    => (string) max(1, min(100, (int) $raw)),
            'max_upload_size_mb',
            'max_image_width',
            'max_image_height'                 => (string) max(0, (int) $raw),
            'latest_albums_count'              => (string) max(0, min(50, (int) $raw)),
            'who_is_online_duration'           => (string) max(1, min(60, (int) $raw)),
            'log_mode'                         => in_array($raw, ['off', 'errors', 'all'], true)
                                                    ? $raw : 'off',
            'timezone'                         => in_array(trim($raw), \DateTimeZone::listIdentifiers(), true)
                                                    ? trim($raw) : 'UTC',
            'category_layout'                  => in_array($raw, ['grid', 'list'], true)
                                                    ? $raw : 'grid',
            'default_color_mode'               => in_array($raw, ['auto', 'light', 'dark'], true)
                                                    ? $raw : 'auto',
            'update_channel'                   => in_array($raw, ['stable', 'prerelease'], true)
                                                    ? $raw : 'stable',
            'update_check_frequency'           => in_array($raw, ['daily', 'weekly'], true)
                                                    ? $raw : 'daily',
            'litespeed_page_cache_ttl'         => (string) max(0, min(86400, (int) $raw)),
            default                            => trim($raw),
        };
    }
}
