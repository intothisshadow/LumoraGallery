<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Core Bootstrap
 *
 * Every entry point (public pages, admin pages, AJAX handlers) must define
 * LUMORA_ENTRY before requiring this file. The installer defines LUMORA_INSTALLER
 * additionally so that the "redirect to /install/" guard is skipped.
 *
 * Load order:
 *   1. PHP version check
 *   2. Path constants
 *   3. version.php
 *   4. config.php existence check / redirect to installer
 *   5. config.php  (DB credentials + LUMORA_INSTALLED)
 *   6. db.php      (connects immediately)
 *   7. Service classes: LumoraConfig, GalleryService, ThumbnailService, ThemeRenderer,
 *                       MigrationService, UpdateService, SchemaService,
 *                       AbstractUpdateProvider, GitHubUpdateProvider, UpdaterService,
 *                       BackupService, InstallationService, GroupService, UserService,
 *                       AlbumAssignmentService, InstallPingService,
 *                       ServerEnvironmentService, CacheHeaderService
 *   8. functions.php  (utility helpers + legacy forwarding wrappers)
 *   9. auth.php
 *  10. thumb.php     (legacy forwarding wrappers → ThumbnailService)
 *  11. template.php  (legacy forwarding wrappers → ThemeRenderer)
 *  12. PHP session start — lazy for public pages (see step 12's own comment
 *      below and lumora_ensure_session() in functions.php)
 * 12a. Remember-me auto-login (persistent cookie re-authentication)
 *  13. Gallery config loaded from DB via LumoraConfig::load()
 *  14. Timezone applied from config
 *  15. LiteSpeed Cache purge hook registered for admin-panel POST requests
 *      (LG-033) — CacheHeaderService::purgeLiteSpeedCache() itself checks the
 *      config toggle and LiteSpeed detection before sending anything
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
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
        // Walk up until we find a segment that is not admin/, album.php, etc.
        $base_path = rtrim(dirname($script), '/\\');
        // If we're in admin/, go one level up.
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
// Loaded before the legacy include files so the forwarding wrappers in steps
// 8–11 can delegate to these classes immediately on first call.
// Class definitions are parsed here; no method is invoked until after all
// includes are loaded, so forward-references to free functions are safe.
require_once LUMORA_INCLUDE . 'services/LumoraConfig.php';
require_once LUMORA_INCLUDE . 'services/GalleryService.php';
require_once LUMORA_INCLUDE . 'services/ThumbnailService.php';
require_once LUMORA_INCLUDE . 'services/ThemeRenderer.php';
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
require_once LUMORA_INCLUDE . 'services/AlbumAssignmentService.php';
require_once LUMORA_INCLUDE . 'services/InstallPingService.php';
require_once LUMORA_INCLUDE . 'services/ServerEnvironmentService.php';
require_once LUMORA_INCLUDE . 'services/CacheHeaderService.php';

// ── 8–11. Legacy includes (wrappers + utilities) ─────────────────────────────
require_once LUMORA_INCLUDE . 'functions.php';
require_once LUMORA_INCLUDE . 'auth.php';
require_once LUMORA_INCLUDE . 'thumb.php';
require_once LUMORA_INCLUDE . 'template.php';

// ── 12. Session (lazy for public pages) ──────────────────────────────────────
// Admin-panel requests, and any request that already carries a session
// cookie (an existing login, or an in-progress album/image hit-count
// throttle), start the session immediately — same as before. A first-time
// anonymous visitor to a public page gets no session at all: no Set-Cookie,
// no PHP session cache-limiter headers (Cache-Control: no-store etc.), so a
// page cache (LiteSpeed Cache or otherwise) can actually cache the response
// (LG-033 follow-up). Public code that needs to write $_SESSION on demand
// (album.php, ajax_hit.php, lumora_csrf_token(), lumora_check_remember_cookie())
// calls lumora_ensure_session() itself right before doing so.
$_lum_is_admin_request = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/');
if ($_lum_is_admin_request || isset($_COOKIE[session_name()])) {
    lumora_ensure_session();
}
unset($_lum_is_admin_request);

// ── 12a. Remember-me auto-login ──────────────────────────────────────────────
// If no active admin session exists, attempt to re-authenticate transparently
// via a persistent remember-me cookie (30-day split-token scheme).
// This must run after session_start() and after auth.php is loaded (step 9),
// but before any page-level lumora_require_admin() call can redirect to login.
if (!lumora_is_logged_in()) {
    lumora_check_remember_cookie();
}

// ── 13. Gallery config ───────────────────────────────────────────────────────
lumora_load_config();

// ── 14. Timezone ─────────────────────────────────────────────────────────────
// Apply the timezone stored in config (default UTC).
// Validate against the known list before calling date_default_timezone_set()
// so that unknown identifiers fall back to UTC cleanly without the @ operator.
$_lum_tz = (string) lumora_config('timezone', 'UTC');
if ($_lum_tz === '' || !in_array($_lum_tz, \DateTimeZone::listIdentifiers(), true)) {
    $_lum_tz = 'UTC';
}
date_default_timezone_set($_lum_tz);
unset($_lum_tz);

// ── 15. LiteSpeed Cache purge on admin content changes ──────────────────────
// Registered for every admin-panel POST request (uploads, edits, deletes,
// album/category changes, theme changes, configuration changes all reach
// admin/*.php via POST) rather than at each individual mutation call site —
// CacheHeaderService::purgeLiteSpeedCache() is a safe no-op unless the
// litespeed_cache_purge config toggle is on and the current server is
// detected as LiteSpeed/OpenLiteSpeed, so this has no effect on Apache,
// nginx, Caddy, or with the toggle left at its default (off).
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')
) {
    register_shutdown_function([CacheHeaderService::class, 'purgeLiteSpeedCache']);
}

unset($_lumora_config_file);
