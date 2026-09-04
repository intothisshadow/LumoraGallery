<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Core Bootstrap
 *
 * Every entry point (public pages, admin pages, AJAX handlers) must define
 * LUMORA_ENTRY before requiring this file. The installer also defines
 * LUMORA_INSTALLER so the "redirect to /install/" guard is skipped.
 *
 * Load order: PHP version check, path constants, version.php, config.php
 * (or redirect to the installer), database connection, service classes,
 * legacy wrapper includes, session start, remember-me auto-login, gallery
 * config, enabled plugins, timezone, LiteSpeed Cache purge hook.
 *
 * @package    LumoraGallery
 * @subpackage Core
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.0.0
 */

if (!defined('LUMORA_ENTRY')) {
    exit('Direct access denied.');
}

// ── 1. PHP version ──────────────────────────────────────────────────────────
if (PHP_VERSION_ID < 80200) {
    exit('Lumora requires PHP 8.2 or higher. You are running PHP ' . PHP_VERSION . '.');
}

// ── 2. Path constants ────────────────────────────────────────────────────────
// __DIR__ is always include/, so dirname(__DIR__) is the Lumora root.
define('LUMORA_ROOT',        dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('LUMORA_INCLUDE',     __DIR__          . DIRECTORY_SEPARATOR);
define('LUMORA_ALBUMS_PATH', LUMORA_ROOT . 'albums'  . DIRECTORY_SEPARATOR);
define('LUMORA_THEMES_PATH', LUMORA_ROOT . 'themes'  . DIRECTORY_SEPARATOR);
define('LUMORA_ADMIN_PATH',  LUMORA_ROOT . 'admin'   . DIRECTORY_SEPARATOR);
define('LUMORA_PLUGINS_PATH', LUMORA_ROOT . 'plugins' . DIRECTORY_SEPARATOR);
define('LUMORA_COVERS_PATH', LUMORA_ROOT . 'covers'  . DIRECTORY_SEPARATOR);

/** Coppermine-compatible thumbnail prefix. */
define('LUMORA_THUMB_PREFIX', 'thumb_');

// ── 3. Version ───────────────────────────────────────────────────────────────
require_once LUMORA_ROOT . 'version.php';

// ── 4. Config existence check ────────────────────────────────────────────────
$_lumora_config_file = LUMORA_ROOT . 'config.php';

if (!file_exists($_lumora_config_file)) {
    if (!defined('LUMORA_INSTALLER')) {
        // Detect the correct path to /install/ relative to this request.
        $proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script    = $_SERVER['SCRIPT_NAME'] ?? '/';
        $base_path = rtrim(dirname($script), '/\\');
        if (str_ends_with($base_path, '/admin')) {
            $base_path = dirname($base_path);
        }
        $install_url = $proto . '://' . $host . $base_path . '/install/';
        header('Location: ' . $install_url);
        exit;
    }
    return; // Inside installer, stop here — installer handles its own DB setup.
}

// ── 5. Load config.php ───────────────────────────────────────────────────────
require_once $_lumora_config_file;

// ── 6. Database ──────────────────────────────────────────────────────────────
require_once LUMORA_INCLUDE . 'db.php';

try {
    LumoraDB::connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PREFIX, DB_CHARSET);
} catch (RuntimeException $e) {
    error_log('Lumora: database connection error: ' . $e->getMessage());
    exit('Database connection failed. Please check your config.php settings.');
}

// ── 7. Service classes ───────────────────────────────────────────────────────
// Loaded before the legacy includes below so their forwarding wrappers can
// delegate to these classes immediately on first call.
require_once LUMORA_INCLUDE . 'services/LumoraConfig.php';
require_once LUMORA_INCLUDE . 'services/GalleryService.php';
require_once LUMORA_INCLUDE . 'services/ThumbnailService.php';
require_once LUMORA_INCLUDE . 'services/ThemeRenderer.php';
require_once LUMORA_INCLUDE . 'services/ThemeService.php';
require_once LUMORA_INCLUDE . 'services/MigrationService.php';
require_once LUMORA_INCLUDE . 'services/UpdateService.php';
require_once LUMORA_INCLUDE . 'services/SchemaService.php';
require_once LUMORA_INCLUDE . 'services/AbstractUpdateProvider.php';
require_once LUMORA_INCLUDE . 'services/GitHubUpdateProvider.php';
require_once LUMORA_INCLUDE . 'services/UpdaterService.php';
require_once LUMORA_INCLUDE . 'services/BackupService.php';
require_once LUMORA_INCLUDE . 'services/InstallationService.php';
require_once LUMORA_INCLUDE . 'services/GroupService.php';
require_once LUMORA_INCLUDE . 'services/UserService.php';
require_once LUMORA_INCLUDE . 'services/RateLimitService.php';
require_once LUMORA_INCLUDE . 'services/AlbumAssignmentService.php';
require_once LUMORA_INCLUDE . 'services/InstallPingService.php';
require_once LUMORA_INCLUDE . 'services/ServerEnvironmentService.php';
require_once LUMORA_INCLUDE . 'services/CacheHeaderService.php';
require_once LUMORA_INCLUDE . 'services/HookService.php';
require_once LUMORA_INCLUDE . 'services/PluginService.php';

// ── 8–11. Legacy includes (wrappers + utilities) ─────────────────────────────
require_once LUMORA_INCLUDE . 'functions.php';
require_once LUMORA_INCLUDE . 'auth.php';
require_once LUMORA_INCLUDE . 'thumb.php';
require_once LUMORA_INCLUDE . 'template.php';

// ── 12. Session (lazy for public pages) ──────────────────────────────────────
// Admin requests and any request already carrying a session cookie start the
// session now; a first-time public visitor gets none, so the response stays
// cacheable. See lumora_ensure_session() in functions.php.
$_lum_is_admin_request = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/');
if ($_lum_is_admin_request || isset($_COOKIE[session_name()])) {
    lumora_ensure_session();
}
unset($_lum_is_admin_request);

// ── 12a. Remember-me auto-login ──────────────────────────────────────────────
// Must run after the session starts and after auth.php loads, but before any
// page-level lumora_require_admin() call can redirect to login.
if (!lumora_is_logged_in()) {
    lumora_check_remember_cookie();
}

// ── 13. Gallery config ───────────────────────────────────────────────────────
lumora_load_config();

// ── 13a. Enabled feature plugins ─────────────────────────────────────────────
// Must run after config loads (enabled state lives in {PREFIX}config) and
// after every service class above is defined, since a plugin's bootstrap
// may call into any of them.
PluginService::loadEnabledPlugins();

// ── 14. Timezone ─────────────────────────────────────────────────────────────
// Validated against the known list first so an unrecognised identifier
// falls back to UTC cleanly instead of failing.
$_lum_tz = (string) lumora_config('timezone', 'UTC');
if ($_lum_tz === '' || !in_array($_lum_tz, \DateTimeZone::listIdentifiers(), true)) {
    $_lum_tz = 'UTC';
}
date_default_timezone_set($_lum_tz);
unset($_lum_tz);

// ── 15. LiteSpeed Cache purge on admin content changes ──────────────────────
// Registered for every admin POST rather than at each mutation call site;
// purgeLiteSpeedCache() itself is a no-op unless the config toggle is on and
// the server is detected as LiteSpeed/OpenLiteSpeed.
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')
) {
    register_shutdown_function([CacheHeaderService::class, 'purgeLiteSpeedCache']);
}

unset($_lumora_config_file);
