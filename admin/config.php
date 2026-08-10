<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Configuration
 *
 * Handles all gallery settings stored in lum_config.
 * Also provides config export (JSON download) and import.
 *
 * Theme selection and other display-related settings (default colour mode,
 * category layout, "Powered by" credit) moved to admin/appearance.php
 * (LG-043) — see that file for the theme card grid, install/update/delete
 * from ZIP, and preview links.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.0.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('site_configuration');

$base   = lumora_base_url() . 'admin/config.php';
$csrf   = h(lumora_csrf_token());
$base_h = h($base);

// ── POST: save settings, export, import ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act = $_POST['action'] ?? 'save';

    if ($act === 'export') {
        $rows = LumoraDB::fetchAll('SELECT name, value FROM `{PREFIX}config` ORDER BY name ASC');
        $data = [];
        foreach ($rows as $r) { $data[$r['name']] = $r['value']; }
        $json = json_encode(['lumora_config' => $data, 'exported_at' => date('c'), 'version' => LUMORA_VERSION], JSON_PRETTY_PRINT);
        $filename = 'lumora-config-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    if ($act === 'save') {
        // White-list the settings we accept.
        // theme, default_color_mode, category_layout, and show_powered_by
        // moved to admin/appearance.php (LG-043) — save their own logic
        // there via the same LumoraConfig::sanitizeValue() calls.
        $allowed = [
            'gallery_name', 'gallery_description', 'base_url',
            'thumb_width', 'thumb_height', 'per_page',
            'allowed_extensions',
            'timezone',
            'thumb_quality',
            'max_upload_size_mb', 'max_image_width', 'max_image_height',
            'count_album_views', 'log_mode', 'gallery_offline',
            'latest_albums_count', 'latest_images_count',
            'who_is_online_duration',
            'install_ping_enabled',
            'litespeed_cache_purge',
        ];

        // Boolean checkbox keys: always save even when not present in POST
        // (unchecked checkbox sends nothing; treated as '0' by sanitizeValue()).
        $bool_keys = ['count_album_views', 'gallery_offline', 'install_ping_enabled', 'litespeed_cache_purge'];

        // Detect the install-ping opt-in transition before overwriting it, so
        // an immediate first ping can fire the moment it's switched on rather
        // than waiting for the next admin page load's periodic check.
        $ping_was_enabled = InstallPingService::isEnabled();

        // Every value is normalised through LumoraConfig::sanitizeValue() so the
        // same enum/range constraints apply here and in the `import` action below
        // — see TODO-security.md #11.
        foreach ($allowed as $key) {
            if (in_array($key, $bool_keys, true)) {
                lumora_set_config($key, LumoraConfig::sanitizeValue($key, $_POST[$key] ?? '0'));
            } elseif (isset($_POST[$key])) {
                lumora_set_config($key, LumoraConfig::sanitizeValue($key, $_POST[$key]));
            }
        }

        // Fire the first ping immediately on enable — see class docblock.
        // Failure is swallowed internally by InstallPingService; this call
        // can never turn into a user-facing error on the settings page.
        if (!$ping_was_enabled && InstallPingService::isEnabled()) {
            try {
                InstallPingService::sendPing();
            } catch (\Throwable) {
                // Non-fatal — the periodic check on the next admin page load
                // will retry.
            }
        }

        lum_flash('Settings saved.');
        lumora_redirect($base);
    }

    if ($act === 'import') {
        $file = $_FILES['config_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            lum_flash('No file uploaded or upload error.', 'danger');
            lumora_redirect($base);
        }

        $json = @file_get_contents($file['tmp_name']);
        $data = $json ? @json_decode($json, true) : null;

        if (!$data || !isset($data['lumora_config']) || !is_array($data['lumora_config'])) {
            lum_flash('Invalid config file format.', 'danger');
            lumora_redirect($base);
        }

        // theme, default_color_mode, category_layout, and show_powered_by
        // moved to admin/appearance.php (LG-043); imported config files
        // containing those keys simply leave them untouched here now.
        $safe_keys = [
            'gallery_name', 'gallery_description',
            'thumb_width', 'thumb_height', 'per_page',
            'allowed_extensions',
            'timezone', 'thumb_quality',
            'max_upload_size_mb', 'max_image_width', 'max_image_height',
            'count_album_views', 'log_mode', 'gallery_offline',
            'latest_albums_count', 'latest_images_count', 'who_is_online_duration',
        ];
        $imported = 0;
        foreach ($data['lumora_config'] as $k => $v) {
            if (in_array($k, $safe_keys, true)) {
                // Same per-key enum/range validation as the `save` action above —
                // an imported JSON file can no longer store an out-of-range or
                // unrecognised value just because it passed the key-name
                // whitelist check. See TODO-security.md #11.
                lumora_set_config($k, LumoraConfig::sanitizeValue($k, $v));
                $imported++;
            }
        }
        // base_url is intentionally excluded from import to avoid breaking the current install.
        lum_flash('Imported ' . $imported . ' settings. Note: base_url was not changed.');
        lumora_redirect($base);
    }
}

// ── Current values ────────────────────────────────────────────────────────────
$cfg = [
    'gallery_name'        => lumora_config('gallery_name',        'Lumora Gallery'),
    'gallery_description' => lumora_config('gallery_description', ''),
    'base_url'            => lumora_config('base_url',            ''),
    'thumb_width'         => lumora_config('thumb_width',         '250'),
    'thumb_height'        => lumora_config('thumb_height',        '250'),
    'per_page'            => lumora_config('per_page',            '48'),
    'allowed_extensions'  => lumora_config('allowed_extensions',  'jpg,jpeg,png,gif,webp'),
    'timezone'            => lumora_config('timezone',            'UTC'),
    'thumb_quality'       => lumora_config('thumb_quality',       '85'),
    'max_upload_size_mb'  => lumora_config('max_upload_size_mb',  '0'),
    'max_image_width'     => lumora_config('max_image_width',     '0'),
    'max_image_height'    => lumora_config('max_image_height',    '0'),
    'count_album_views'   => lumora_config('count_album_views',   '1'),
    'log_mode'            => lumora_config('log_mode',            'off'),
    'gallery_offline'     => lumora_config('gallery_offline',     '0'),
    'latest_albums_count' => lumora_config('latest_albums_count', '5'),
    'latest_images_count' => lumora_config('latest_images_count', '8'),
    'who_is_online_duration' => lumora_config('who_is_online_duration', '5'),
    'install_ping_enabled'   => lumora_config('install_ping_enabled',   '0'),
    'litespeed_cache_purge'  => lumora_config('litespeed_cache_purge',  '0'),
];

// Web server detection (LG-033) — informs the LiteSpeed Cache toggle below.
$server_env = ServerEnvironmentService::detect();

// Detect active image processor.
$processor_status = extension_loaded('imagick')
    ? '✓ Imagick PHP extension (active, preferred)'
    : (extension_loaded('gd')
        ? '⚠ GD library (fallback — install the PHP imagick extension for better quality)'
        : '✗ None found — thumbnail generation disabled');

// Theme management (activation, install/update/delete from ZIP, preview
// links) moved to admin/appearance.php (LG-043) — see ThemeService.

// Pre-compute values safe for use in HTML attributes.
$v_gallery_name    = h($cfg['gallery_name']);
$v_base_url        = h($cfg['base_url']);
$v_gallery_desc    = h($cfg['gallery_description']);
$v_per_page        = h($cfg['per_page']);
$v_allowed_ext     = h($cfg['allowed_extensions']);
$v_timezone        = h($cfg['timezone']);
$v_thumb_quality   = h($cfg['thumb_quality']);
$v_max_upload      = h($cfg['max_upload_size_mb']);
$v_max_img_w       = h($cfg['max_image_width']);
$v_max_img_h       = h($cfg['max_image_height']);
$v_thumb_w         = h($cfg['thumb_width']);
$v_thumb_h         = h($cfg['thumb_height']);
$appearance_url_h  = h(lumora_base_url() . 'admin/appearance.php');

// Select / checkbox states.
$sel_log_off    = $cfg['log_mode'] === 'off'    ? ' selected' : '';
$sel_log_errors = $cfg['log_mode'] === 'errors' ? ' selected' : '';
$sel_log_all    = $cfg['log_mode'] === 'all'    ? ' selected' : '';
$chk_album_views = $cfg['count_album_views'] === '1' ? ' checked' : '';
$chk_offline      = $cfg['gallery_offline']   === '1' ? ' checked' : '';
$chk_install_ping = $cfg['install_ping_enabled'] === '1' ? ' checked' : '';
$chk_litespeed_purge = $cfg['litespeed_cache_purge'] === '1' ? ' checked' : '';
$v_latest_albums  = h($cfg['latest_albums_count']);
$v_latest_images  = h($cfg['latest_images_count']);
$v_who_online_dur = h($cfg['who_is_online_duration']);

$processor_h = h($processor_status);

// LiteSpeed Cache purge note (LG-033) — the toggle itself always saves (so
// it's ready the moment an admin migrates to a LiteSpeed host) but shows a
// plain-language status line about whether it's actually doing anything on
// the current server. See admin/installation.php for the fuller
// detection/capability breakdown.
$litespeed_note_h = $server_env['is_litespeed']
    ? '<span class="text-success">✓ LiteSpeed/OpenLiteSpeed detected on this server — this toggle is active.</span>'
    : '<span class="text-muted">Detected web server: ' . h($server_env['name']) . ' — this toggle has no effect until Lumora is served by LiteSpeed or OpenLiteSpeed.</span>';
$installation_url_h = h(lumora_base_url() . 'admin/installation.php');

// Install-ping UUID for display only — never regenerated just to render the
// settings page; only getOrCreateUuid() (called from InstallPingService::sendPing())
// actually persists one.
$v_install_uuid = h((string) lumora_config('install_uuid', ''));
$install_uuid_html = $v_install_uuid !== ''
    ? '<div class="form-text mt-1">Install ID: <code>' . $v_install_uuid . '</code></div>'
    : '';

// ── Section-header SVG icons ──────────────────────────────────────────────────
// Small stroke-based icons (24x24 viewBox, currentColor) rendered inline so no
// external icon font/library is required. Sized and coloured via the shared
// .lum-adm-section-icon rule in admin.css (see TODO #25).
$ic_basic = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="11" x2="12" y2="16.5"></line><circle cx="12" cy="7.75" r="1" fill="currentColor" stroke="none"></circle></svg>';

$ic_images = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="8.5" cy="9.5" r="1.5"></circle><path d="M21 16l-5-5-4 4-2-2-5 5"></path></svg>';

$ic_behavior = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"></line><circle cx="9" cy="6" r="2" fill="currentColor" stroke="none"></circle><line x1="4" y1="12" x2="20" y2="12"></line><circle cx="15" cy="12" r="2" fill="currentColor" stroke="none"></circle><line x1="4" y1="18" x2="20" y2="18"></line><circle cx="7" cy="18" r="2" fill="currentColor" stroke="none"></circle></svg>';

$ic_upload = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 18a4.5 4.5 0 0 1-1-8.9 5 5 0 0 1 9.8-1.6A4 4 0 0 1 17 15.9"></path><polyline points="9 13 12 10 15 13"></polyline><line x1="12" y1="10" x2="12" y2="19"></line></svg>';

$ic_export = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="3" x2="12" y2="21"></line><polyline points="8 7 12 3 16 7"></polyline><polyline points="8 17 12 21 16 17"></polyline></svg>';

$ic_privacy = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"></path><path d="M9.5 12l1.8 1.8L15 10"></path></svg>';

$ic_performance = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 11 14 10 22 21 10 13 10 13 2"></polygon></svg>';

$content = <<<HTML
<form method="post" action="{$base_h}">
  <input type="hidden" name="action"     value="save">
  <input type="hidden" name="csrf_token" value="{$csrf}">

  <div class="alert alert-info py-2 px-3 mb-4">
    Theme selection, colour mode, category layout, and the "Powered by" credit
    now live on the <a href="{$appearance_url_h}">Appearance</a> page.
  </div>

  <!-- ── Basic Information ─────────────────────────────────────────── -->
  <div class="lum-adm-card mb-4">
    <h5 class="lum-adm-section-title mb-3">{$ic_basic}Basic Information</h5>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Gallery Name</label>
        <input type="text" name="gallery_name" value="{$v_gallery_name}" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Base URL</label>
        <input type="url" name="base_url" value="{$v_base_url}" class="form-control" required>
        <div class="form-text">Public URL with trailing slash, e.g. https://example.com/gallery/</div>
      </div>
    </div>

    <div class="mb-0">
      <label class="form-label fw-semibold">Gallery Description</label>
      <textarea name="gallery_description" rows="2" class="form-control">{$v_gallery_desc}</textarea>
    </div>
  </div>

  <!-- ── Images & Thumbnails ───────────────────────────────────────── -->
  <div class="lum-adm-card mb-4">
    <h5 class="lum-adm-section-title mb-3">{$ic_images}Images &amp; Thumbnails</h5>

    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Thumbnail Max Width (px)</label>
        <input type="number" name="thumb_width" value="{$v_thumb_w}" class="form-control" min="32" max="2000">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Thumbnail Max Height (px)</label>
        <input type="number" name="thumb_height" value="{$v_thumb_h}" class="form-control" min="32" max="2000">
      </div>
      <div class="col-sm-4">
        <label class="form-label fw-semibold">Images per Page</label>
        <input type="number" name="per_page" value="{$v_per_page}" class="form-control" min="1" max="500">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Allowed Extensions</label>
      <input type="text" name="allowed_extensions" value="{$v_allowed_ext}" class="form-control font-monospace" style="max-width:320px">
      <div class="form-text">Comma-separated list, e.g. <code>jpg,jpeg,png,gif,webp</code></div>
    </div>

    <div class="mb-0">
      <label class="form-label fw-semibold">Image Processor</label>
      <p class="mb-0"><strong>{$processor_h}</strong></p>
      <div class="form-text">Detected automatically — no configuration needed. Imagick PHP extension is preferred; GD is used as fallback.</div>
    </div>
  </div>

  <!-- ── Gallery Behavior ─────────────────────────────────────────── -->
  <div class="lum-adm-card mb-4">
    <h5 class="lum-adm-section-title mb-3">{$ic_behavior}Gallery Behavior</h5>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Timezone</label>
        <input type="text" name="timezone" value="{$v_timezone}"
               list="lum-tz-list" class="form-control font-monospace" style="max-width:280px">
        <datalist id="lum-tz-list">
          <option value="UTC">
          <option value="Europe/Helsinki">
          <option value="Europe/Tallinn">
          <option value="Europe/Riga">
          <option value="Europe/Vilnius">
          <option value="Europe/Stockholm">
          <option value="Europe/Oslo">
          <option value="Europe/Copenhagen">
          <option value="Europe/London">
          <option value="Europe/Dublin">
          <option value="Europe/Lisbon">
          <option value="Europe/Paris">
          <option value="Europe/Berlin">
          <option value="Europe/Amsterdam">
          <option value="Europe/Brussels">
          <option value="Europe/Madrid">
          <option value="Europe/Rome">
          <option value="Europe/Vienna">
          <option value="Europe/Zurich">
          <option value="Europe/Warsaw">
          <option value="Europe/Prague">
          <option value="Europe/Budapest">
          <option value="Europe/Bucharest">
          <option value="Europe/Athens">
          <option value="Europe/Sofia">
          <option value="Europe/Moscow">
          <option value="America/New_York">
          <option value="America/Chicago">
          <option value="America/Denver">
          <option value="America/Los_Angeles">
          <option value="America/Anchorage">
          <option value="America/Sao_Paulo">
          <option value="America/Argentina/Buenos_Aires">
          <option value="America/Toronto">
          <option value="America/Vancouver">
          <option value="America/Mexico_City">
          <option value="Asia/Tokyo">
          <option value="Asia/Seoul">
          <option value="Asia/Shanghai">
          <option value="Asia/Hong_Kong">
          <option value="Asia/Singapore">
          <option value="Asia/Bangkok">
          <option value="Asia/Kolkata">
          <option value="Asia/Dubai">
          <option value="Asia/Riyadh">
          <option value="Asia/Istanbul">
          <option value="Australia/Sydney">
          <option value="Australia/Melbourne">
          <option value="Australia/Perth">
          <option value="Pacific/Auckland">
          <option value="Pacific/Honolulu">
          <option value="Africa/Johannesburg">
          <option value="Africa/Cairo">
          <option value="Africa/Lagos">
        </datalist>
        <div class="form-text">Any valid PHP timezone identifier (e.g. <code>Europe/Helsinki</code>). Affects all displayed dates and timestamps.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Logging Mode</label>
        <select name="log_mode" class="form-select" style="max-width:280px">
          <option value="off"{$sel_log_off}>Off — no logging</option>
          <option value="errors"{$sel_log_errors}>Errors only (PHP error_log)</option>
          <option value="all"{$sel_log_all}>All visits + errors (DB + error_log)</option>
        </select>
        <div class="form-text"><em>All visits</em> mode requires the <code>{PREFIX}log</code> table
          (added in DB version 2 — run the <code>CREATE TABLE</code> from
          <code>install/schema.sql</code> on existing installations).</div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input type="hidden" name="count_album_views" value="0">
          <input class="form-check-input" type="checkbox" id="count_album_views"
                 name="count_album_views" value="1"{$chk_album_views}>
          <label class="form-check-label fw-semibold" for="count_album_views">Count Album Views</label>
        </div>
        <div class="form-text">Track how many times each album page is visited. Session-throttled to one count per visitor per session.</div>
      </div>
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input type="hidden" name="gallery_offline" value="0">
          <input class="form-check-input" type="checkbox" id="gallery_offline"
                 name="gallery_offline" value="1"{$chk_offline}>
          <label class="form-check-label fw-semibold text-danger" for="gallery_offline">Gallery Offline Mode</label>
        </div>
        <div class="form-text">Show a "Gallery Offline" maintenance page to all visitors. Admins always see the real gallery.</div>
      </div>
    </div>

    <div class="row g-3 mb-0">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Latest Updated Albums (home page)</label>
        <input type="number" name="latest_albums_count" value="{$v_latest_albums}"
               class="form-control" min="0" max="50" style="max-width:120px">
        <div class="form-text">How many recently updated albums to display on the home page. Set to <code>0</code> to hide the section.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Latest Additions (home page)</label>
        <input type="number" name="latest_images_count" value="{$v_latest_images}"
               class="form-control" min="0" max="50" style="max-width:120px">
        <div class="form-text">How many recently added images to display in the "Latest Additions" grid on the home page. Set to <code>0</code> to hide the section. Picking a count that divides evenly by your thumbnail grid's typical column count (e.g. 6, 8, or 12) avoids a sparse last row.</div>
      </div>
    </div>

    <div class="row g-3 mb-0">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Who Is Online — Window (minutes)</label>
        <input type="number" name="who_is_online_duration" value="{$v_who_online_dur}"
               class="form-control" min="1" max="60" style="max-width:120px">
        <div class="form-text">Visitors active within this many minutes are counted as online. Default 5. Range 1–60.</div>
      </div>
    </div>
  </div>

  <!-- ── Privacy ─────────────────────────────────────────────── -->
  <div class="lum-adm-card mb-4">
    <h5 class="lum-adm-section-title mb-3">{$ic_privacy}Privacy</h5>

    <div class="mb-0">
      <div class="form-check form-switch">
        <input type="hidden" name="install_ping_enabled" value="0">
        <input class="form-check-input" type="checkbox" id="install_ping_enabled"
               name="install_ping_enabled" value="1"{$chk_install_ping}>
        <label class="form-check-label fw-semibold" for="install_ping_enabled">Anonymous Install Ping</label>
      </div>
      <div class="form-text">
        <strong>Off by default.</strong> When enabled, Lumora periodically sends a tiny, anonymous
        ping (roughly once a month, plus once immediately when you turn this on) to let the
        developer see a rough count of active installs. The ping contains exactly three values
        and nothing else: a randomly generated install ID (not tied to your domain, gallery
        contents, or any personal data), your Lumora version, and your PHP version. It uses a
        separate request from the update checker above, so turning either one on or off never
        affects the other. If the request fails for any reason it fails silently — it never
        shows an error or blocks anything you're doing.
      </div>
      {$install_uuid_html}
    </div>
  </div>

  <!-- ── Performance ─────────────────────────────────────────── -->
  <div class="lum-adm-card mb-4">
    <h5 class="lum-adm-section-title mb-3">{$ic_performance}Performance</h5>

    <div class="mb-0">
      <div class="form-check form-switch">
        <input type="hidden" name="litespeed_cache_purge" value="0">
        <input class="form-check-input" type="checkbox" id="litespeed_cache_purge"
               name="litespeed_cache_purge" value="1"{$chk_litespeed_purge}>
        <label class="form-check-label fw-semibold" for="litespeed_cache_purge">LiteSpeed Cache Purge</label>
      </div>
      <div class="form-text">
        <strong>Off by default.</strong> When enabled, Lumora sends an LSCache purge-all header
        after content-changing admin actions (image uploads/edits/deletes, album and category
        changes, theme changes, and configuration saves) so LiteSpeed Cache never serves a stale
        page after you make a change. It has no effect on Apache, nginx, Caddy, or any server
        without LiteSpeed Cache active — safe to leave on regardless of your hosting.
      </div>
      <div class="form-text mt-1">{$litespeed_note_h}</div>
      <div class="form-text mt-1">
        See the <a href="{$installation_url_h}">Installation page</a> for full web server
        detection details and recommended static-asset cache headers.
      </div>
    </div>
  </div>

  <!-- ── Upload & Image Limits ─────────────────────────────── -->
  <div class="lum-adm-card mb-4">
    <h5 class="lum-adm-section-title mb-3">{$ic_upload}Upload &amp; Image Limits</h5>
    <p class="text-muted small mb-3">These limits are applied per image during <strong>Batch Add</strong>. Originals that exceed the dimension limits are downscaled in-place (overwriting the source file); originals that exceed the file-size limit are skipped entirely.</p>

    <div class="row g-3 mb-3">
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Thumbnail JPEG Quality</label>
        <input type="number" name="thumb_quality" value="{$v_thumb_quality}"
               class="form-control" min="1" max="100">
        <div class="form-text">1–100, default 85. Applies to JPEG and WebP thumbnails on Batch Add.</div>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Max File Size (MB)</label>
        <input type="number" name="max_upload_size_mb" value="{$v_max_upload}"
               class="form-control" min="0">
        <div class="form-text">0 = no limit. Files exceeding this size are skipped on Batch Add.</div>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Max Original Width (px)</label>
        <input type="number" name="max_image_width" value="{$v_max_img_w}"
               class="form-control" min="0">
        <div class="form-text">0 = no limit. Oversized originals are downscaled in-place on Batch Add.</div>
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold">Max Original Height (px)</label>
        <input type="number" name="max_image_height" value="{$v_max_img_h}"
               class="form-control" min="0">
        <div class="form-text">0 = no limit. Aspect ratio is preserved; images are never upscaled.</div>
      </div>
    </div>
  </div>

  <!-- ── Save (LG-34: kept out of any one card so it reads as saving the whole
       page, not just the section it happens to sit next to) ────────────── -->
  <div class="lum-adm-card lum-adm-save-bar mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h5 class="mb-1">💾 Save Changes</h5>
        <p class="text-muted small mb-0">Saves every setting on this page, from Basic Information through Upload &amp; Image Limits above.</p>
      </div>
      <button type="submit" class="btn btn-primary btn-lg">Save All Settings</button>
    </div>
  </div>
</form>

<div class="lum-adm-card">
  <h5 class="lum-adm-section-title mb-3">{$ic_export}Export / Import Configuration</h5>
  <p class="text-muted small">Export your settings to a JSON file for backup, or to quickly configure another Lumora installation.
     <strong>Note:</strong> base_url is never imported to prevent accidentally breaking an install.</p>
  <div class="d-flex gap-3 flex-wrap align-items-center">
    <form method="post" action="{$base_h}">
      <input type="hidden" name="action"     value="export">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <button type="submit" class="btn btn-outline-secondary">⬇ Export Config (JSON)</button>
    </form>
    <form method="post" action="{$base_h}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="action"     value="import">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <input type="file" name="config_file" accept=".json,application/json" class="form-control form-control-sm" style="max-width:240px">
      <button type="submit" class="btn btn-outline-secondary btn-sm"
              onclick="return confirm('Import will overwrite current settings (except base_url). Continue?')">⬆ Import</button>
    </form>
  </div>
</div>
HTML;

lum_admin_page('Configuration', $content, 'config');
