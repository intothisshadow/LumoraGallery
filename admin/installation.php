<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Installation Settings
 *
 * Migration utility for updating Lumora's installation configuration after
 * moving to a new domain, subdirectory, or server environment without needing
 * to manually edit config.php or run raw SQL queries.
 *
 * Capabilities:
 *   - Displays current installation details alongside live environment data.
 *   - Auto-detects differences between the stored config and the current server.
 *   - Provides a guided migration helper for common scenarios (domain change,
 *     subdirectory change, server migration, HTTPS enablement).
 *   - Updates base_url and related config values with full audit logging.
 *   - Requires password re-authentication before applying any changes.
 *   - Runs an on-demand health check across all critical installation components.
 *   - Exports the current installation state as a JSON snapshot for safekeeping.
 *   - Shows a paginated audit log of all recent configuration changes.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('site_configuration');

$self    = lumora_base_url() . 'admin/installation.php';
$self_h  = h($self);
$csrf    = h(lumora_csrf_token());
$csrf_js = json_encode(lumora_csrf_token());

// ── POST handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    $act = isset($_POST['action']) ? (string) $_POST['action'] : 'update';

    // ── Export ────────────────────────────────────────────────────────────────
    if ($act === 'export') {
        $json     = InstallationService::exportSettings();
        $filename = 'lumora-installation-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    // ── Static asset cache headers (LG-033) ─────────────────────────────────────
    if ($act === 'write_cache_headers') {
        $result = CacheHeaderService::writeRules();
        lum_flash(
            $result['success'] ? 'Cache-control rules written to .htaccess.' : $result['error'],
            $result['success'] ? 'success' : 'danger'
        );
        lumora_redirect($self);
    }

    if ($act === 'remove_cache_headers') {
        $result = CacheHeaderService::removeRules();
        lum_flash(
            $result['success'] ? 'Cache-control rules removed from .htaccess.' : $result['error'],
            $result['success'] ? 'success' : 'danger'
        );
        lumora_redirect($self);
    }

    // ── LiteSpeed page caching, opt-in (LG-033 follow-up) ───────────────────────
    if ($act === 'write_page_cache') {
        $ttl = (int) LumoraConfig::sanitizeValue('litespeed_page_cache_ttl', $_POST['page_cache_ttl'] ?? '0');
        if ($ttl <= 0) {
            lum_flash('Enter a TTL greater than 0 seconds to enable page caching.', 'danger');
            lumora_redirect($self);
        }
        $result = CacheHeaderService::writePageCacheRules($ttl);
        if ($result['success']) {
            LumoraConfig::set('litespeed_page_cache_ttl', (string) $ttl);
        }
        lum_flash(
            $result['success'] ? 'Page caching enabled (' . $ttl . 's TTL).' : $result['error'],
            $result['success'] ? 'success' : 'danger'
        );
        lumora_redirect($self);
    }

    if ($act === 'remove_page_cache') {
        $result = CacheHeaderService::removePageCacheRules();
        if ($result['success']) {
            LumoraConfig::set('litespeed_page_cache_ttl', '0');
        }
        lum_flash(
            $result['success'] ? 'Page caching disabled.' : $result['error'],
            $result['success'] ? 'success' : 'danger'
        );
        lumora_redirect($self);
    }

    // ── Apply settings ────────────────────────────────────────────────────────
    if ($act === 'update') {
        $password = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';

        if ($password === '') {
            lum_flash('Please enter your current password to confirm the changes.', 'danger');
            lumora_redirect($self);
        }

        // Re-authenticate the current admin before applying any changes.
        $cur_user = lumora_current_user();
        $user_id  = (int) ($cur_user['user_id'] ?? 0);
        $username = isset($cur_user['username']) ? (string) $cur_user['username'] : 'admin';

        $db_user = LumoraDB::fetchOne(
            'SELECT id, password_hash FROM `{PREFIX}users` WHERE id = ?',
            [$user_id]
        );

        if (!$db_user || !password_verify($password, (string) $db_user['password_hash'])) {
            lum_flash('Incorrect password — no changes were applied.', 'danger');
            lumora_redirect($self);
        }

        // Collect and validate submitted settings.
        $settings = [];
        if (isset($_POST['base_url'])) {
            $settings['base_url'] = (string) $_POST['base_url'];
        }

        $ip     = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $result = InstallationService::applySettings($settings, $user_id, $username, $ip);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                lum_flash($err, 'danger');
            }
        } elseif (!empty($result['applied'])) {
            $labels = ['base_url' => 'Site URL'];
            $names  = array_map(
                static fn(string $k): string => $labels[$k] ?? $k,
                $result['applied']
            );
            lum_flash('Settings updated: ' . implode(', ', $names) . '. Caches have been cleared.');
        } else {
            lum_flash('No changes detected — all values are already up to date.', 'info');
        }

        lumora_redirect($self);
    }
}

// ── Data preparation ──────────────────────────────────────────────────────────
$env        = InstallationService::detectEnvironment();
$stored     = InstallationService::getStoredConfig();
$diffs      = InstallationService::detectChanges();
$recent     = InstallationService::getRecentChanges(15);
$server_env = ServerEnvironmentService::detect();
$cache_hdrs = CacheHeaderService::status();
$page_cache = CacheHeaderService::pageCacheStatus();

// Pre-escaped values for HTML attributes and content.
$v_stored_url   = h($stored['base_url']);
$v_detected_url = h($env['detected_url']);
$v_root_path    = h($env['root_path']);
$v_albums_path  = h($env['albums_path']);
$v_cache_path   = h($env['cache_path']);
$v_php_version  = h($env['php_version']);
$v_web_server   = h($env['web_server']);
$v_db_host      = h($stored['db_host']);
$v_db_name      = h($stored['db_name']);
$v_db_prefix    = h($stored['db_prefix']);

// Accessibility / writability indicators.
$albums_ok  = is_dir($env['albums_path']) && is_writable($env['albums_path']);
$cache_dir  = LUMORA_ROOT . 'cache';
$cache_ok   = !is_dir($cache_dir) || is_writable($cache_dir);

$albums_badge = $albums_ok
    ? '<span class="badge bg-success ms-2">✓ writable</span>'
    : '<span class="badge bg-danger ms-2">✗ not writable</span>';
$cache_badge = $cache_ok
    ? '<span class="badge bg-success ms-2">✓ writable</span>'
    : '<span class="badge bg-warning text-dark ms-2">⚠ not writable</span>';
$https_badge = $env['https']
    ? '<span class="badge bg-success ms-2">HTTPS active</span>'
    : '<span class="badge bg-secondary ms-2">HTTP only</span>';

// ── Web server & capability detection (LG-033) ──────────────────────────────
$v_server_name = h($server_env['name']);
$v_server_raw  = h($server_env['raw']);
$litespeed_badge = $server_env['is_litespeed']
    ? '<span class="badge bg-success ms-2">LiteSpeed detected</span>'
    : '<span class="badge bg-secondary ms-2">Not LiteSpeed</span>';

$capability_badge = static function (bool $supported, string $label): string {
    return $supported
        ? '<span class="badge bg-success me-1">✓ ' . h($label) . '</span>'
        : '<span class="badge bg-secondary me-1">— ' . h($label) . '</span>';
};
$capabilities_html = $capability_badge($server_env['http2'], 'HTTP/2')
    . $capability_badge($server_env['http3'], 'HTTP/3')
    . $capability_badge($server_env['brotli'], 'Brotli')
    . $capability_badge($server_env['lscache_active'], 'LSCache active');

$cache_hdrs_badge = $cache_hdrs['managed_present']
    ? '<span class="badge bg-success ms-2">✓ installed</span>'
    : '<span class="badge bg-secondary ms-2">not installed</span>';
$v_cache_hdrs_path = h($cache_hdrs['path']);
$cache_hdrs_action_label   = $cache_hdrs['managed_present'] ? '↻ Update' : '⬇ Install';
$cache_hdrs_remove_disabled = $cache_hdrs['managed_present'] ? '' : ' disabled';

$page_cache_badge = $page_cache['managed_present']
    ? '<span class="badge bg-success ms-2">✓ enabled</span>'
    : '<span class="badge bg-secondary ms-2">disabled</span>';
$stored_page_cache_ttl = (int) lumora_config('litespeed_page_cache_ttl', '0');
$v_page_cache_ttl = h((string) ($stored_page_cache_ttl > 0 ? $stored_page_cache_ttl : 600));
$page_cache_action_label    = $page_cache['managed_present'] ? '↻ Update' : '⚡ Enable';
$page_cache_remove_disabled = $page_cache['managed_present'] ? '' : ' disabled';

// Detected-changes notice.
$diffs_html = '';
if (!empty($diffs)) {
    $rows = '';
    foreach ($diffs as $d) {
        $rows .= '<tr>'
            . '<td class="small fw-semibold">' . h($d['label']) . '</td>'
            . '<td class="small font-monospace text-danger">' . h($d['stored']) . '</td>'
            . '<td class="small font-monospace text-success">' . h($d['detected']) . '</td>'
            . '</tr>';
    }
    $diffs_html = <<<HTML
<div class="lum-adm-card mb-4 border border-warning">
  <div class="d-flex align-items-center gap-2 mb-3">
    <span class="fs-5">⚠</span>
    <h5 class="mb-0 text-warning">Auto-Detected Changes</h5>
  </div>
  <p class="text-muted small mb-3">
    The following differences were detected between the current server environment
    and the stored configuration. Review the table below and use the
    <strong>Update Settings</strong> form to apply corrections.
  </p>
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-3">
      <thead class="table-warning">
        <tr>
          <th>Setting</th>
          <th>Stored value</th>
          <th>Detected value</th>
        </tr>
      </thead>
      <tbody>{$rows}</tbody>
    </table>
  </div>
  <button type="button" class="btn btn-sm btn-outline-secondary" id="lum-use-detected">
    ↙ Copy detected URL into the form
  </button>
</div>
HTML;
}

// Audit log table.
$audit_html = '';
if (!empty($recent)) {
    $log_rows = '';
    foreach ($recent as $r) {
        $log_rows .= '<tr>'
            . '<td class="small">' . h((string) ($r['changed_at'] ?? '')) . '</td>'
            . '<td class="small">' . h((string) ($r['username'] ?? '')) . '</td>'
            . '<td class="small font-monospace">' . h((string) ($r['ip'] ?? '')) . '</td>'
            . '<td class="small font-monospace">' . h((string) ($r['key'] ?? '')) . '</td>'
            . '<td class="small text-break" style="max-width:180px">'
            . '<span class="text-danger text-decoration-line-through">' . h((string) ($r['old_value'] ?? '')) . '</span>'
            . '</td>'
            . '<td class="small text-break" style="max-width:180px">'
            . '<span class="text-success">' . h((string) ($r['new_value'] ?? '')) . '</span>'
            . '</td>'
            . '</tr>';
    }
    $audit_html = <<<HTML
<div class="lum-adm-card mt-4">
  <h5 class="mb-3">📋 Configuration Change Log</h5>
  <p class="text-muted small mb-3">All changes applied through this page are recorded here.</p>
  <div class="table-responsive">
    <table class="table table-sm lum-adm-table align-middle mb-0">
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>Admin</th>
          <th>IP Address</th>
          <th>Setting</th>
          <th>Previous value</th>
          <th>New value</th>
        </tr>
      </thead>
      <tbody>{$log_rows}</tbody>
    </table>
  </div>
</div>
HTML;
} else {
    $audit_html = <<<HTML
<div class="lum-adm-card mt-4">
  <h5 class="mb-3">📋 Configuration Change Log</h5>
  <p class="text-muted small mb-0">No configuration changes have been recorded yet.
    Once you apply a change, entries will appear here.</p>
</div>
HTML;
}

// JS-safe values: json_encode() produces properly quoted, escaped JS string literals.
// h() produces HTML-safe strings only — raw URLs interpolated without quotes cause a
// SyntaxError in JS (e.g. "const x = https://example.com/" crashes the whole script).
$ajax_base_js    = json_encode(lumora_base_url() . 'admin/');
$stored_url_js   = json_encode($stored['base_url']);
$detected_url_js = json_encode($env['detected_url']);

// ── Page content ──────────────────────────────────────────────────────────────
$content = <<<HTML
<!-- ── Current Installation Information ──────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <h5 class="mb-0">🖥️ Current Installation Information</h5>
    <form method="post" action="{$self_h}">
      <input type="hidden" name="action"     value="export">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <button type="submit" class="btn btn-sm btn-outline-secondary">⬇ Export Snapshot (JSON)</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-borderless align-middle mb-0" style="max-width:800px">
      <tbody>
        <tr>
          <th class="text-muted small" style="width:220px">Site URL (stored)</th>
          <td class="font-monospace small">{$v_stored_url}</td>
        </tr>
        <tr>
          <th class="text-muted small">Site URL (auto-detected)</th>
          <td class="font-monospace small">{$v_detected_url} {$https_badge}</td>
        </tr>
        <tr>
          <th class="text-muted small">Application root</th>
          <td class="font-monospace small text-muted">{$v_root_path}</td>
        </tr>
        <tr>
          <th class="text-muted small">Albums directory</th>
          <td class="font-monospace small text-muted">{$v_albums_path}{$albums_badge}</td>
        </tr>
        <tr>
          <th class="text-muted small">Cache directory</th>
          <td class="font-monospace small text-muted">{$v_cache_path}{$cache_badge}</td>
        </tr>
        <tr>
          <th class="text-muted small">Database host</th>
          <td class="font-monospace small text-muted">{$v_db_host}</td>
        </tr>
        <tr>
          <th class="text-muted small">Database name</th>
          <td class="font-monospace small text-muted">{$v_db_name}</td>
        </tr>
        <tr>
          <th class="text-muted small">Table prefix</th>
          <td class="font-monospace small text-muted">{$v_db_prefix}</td>
        </tr>
        <tr>
          <th class="text-muted small">PHP version</th>
          <td class="small">{$v_php_version}</td>
        </tr>
        <tr>
          <th class="text-muted small">Web server</th>
          <td class="small">{$v_web_server}{$litespeed_badge}</td>
        </tr>
        <tr>
          <th class="text-muted small">Detected capabilities</th>
          <td class="small">{$capabilities_html}</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

{$diffs_html}

<!-- ── Static Asset Cache Headers (LG-033) ────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-1">🚀 Static Asset Cache Headers{$cache_hdrs_badge}</h5>
  <p class="text-muted small mb-3">
    Images, thumbnails, and theme CSS/JS are served directly by the web server rather than
    through PHP, so their browser-caching headers have to come from the web server itself.
    This installs a clearly-marked, removable block of standard <code>mod_expires</code> /
    <code>mod_headers</code> directives into the site's root <code>.htaccess</code> — read
    identically by Apache and LiteSpeed/OpenLiteSpeed (<code>{$v_server_name}</code> detected
    on this server). It sets long-lived immutable caching for images/thumbnails/fonts and a
    shorter cache window for CSS/JS, and never touches any other content already in your
    <code>.htaccess</code>. Has no effect on nginx or Caddy, which don't read
    <code>.htaccess</code> files — configure caching at the server level on those instead.
  </p>
  <p class="text-muted small mb-3">Managed file: <code class="small">{$v_cache_hdrs_path}</code></p>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post" action="{$self_h}" class="d-inline">
      <input type="hidden" name="action"     value="write_cache_headers">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <button type="submit" class="btn btn-sm btn-outline-primary">
        {$cache_hdrs_action_label} Recommended Rules
      </button>
    </form>
    <form method="post" action="{$self_h}" class="d-inline">
      <input type="hidden" name="action"     value="remove_cache_headers">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <button type="submit" class="btn btn-sm btn-outline-secondary"{$cache_hdrs_remove_disabled}>
        ✕ Remove Rules
      </button>
    </form>
  </div>
  <p class="text-muted small mt-3 mb-0">
    Public gallery pages now start a PHP session (and its accompanying no-cache headers) only
    when one is actually needed — an existing login, or an album/image view hit-count throttle
    — so a first-time anonymous visit can be cached by LiteSpeed Cache or a browser. The admin
    panel always starts a session and is additionally excluded from LSCache via
    <code>admin/.htaccess</code> (<code>CacheLookup off</code>), independent of the session
    cookie already doing so implicitly.
  </p>
</div>

<!-- ── LiteSpeed Page Caching, opt-in (LG-033 follow-up) ──────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-1">⚡ LiteSpeed Page Caching (Advanced){$page_cache_badge}</h5>
  <p class="text-muted small mb-3">
    Turns on actual LiteSpeed Cache page caching for the public gallery via a
    <code>CacheEnable</code> directive in the site's root <code>.htaccess</code> — for hosts
    where server-level LSCache configuration isn't available to you (e.g. some
    DirectAdmin/shared-hosting LiteSpeed setups with no WebAdmin console access). LiteSpeed-only
    (<code>&lt;IfModule LiteSpeed&gt;</code>); a no-op block on Apache, nginx, or Caddy. Public
    pages already send no session cookie for anonymous visitors, and the admin panel is excluded
    via <code>admin/.htaccess</code> above — this directive only turns caching on for what's
    already safe to cache.
  </p>
  <div class="alert alert-warning py-2 small mb-3">
    <strong>Tradeoff:</strong> a page served from cache never re-executes PHP, so the album/image
    hit counters and "Who Is Online" will undercount visits served from cache within the TTL
    below. Pick a TTL that balances the performance benefit against how much that undercounting
    matters to you — shorter TTLs undercount less but cache less.
  </div>
  <form method="post" action="{$self_h}" class="d-flex gap-2 align-items-end flex-wrap mb-0">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <div>
      <label class="form-label small mb-1" for="page_cache_ttl">Cache TTL (seconds)</label>
      <input type="number" name="page_cache_ttl" id="page_cache_ttl" min="1" max="86400"
             value="{$v_page_cache_ttl}" class="form-control form-control-sm" style="width:140px">
    </div>
    <button type="submit" name="action" value="write_page_cache" class="btn btn-sm btn-outline-primary">
      {$page_cache_action_label}
    </button>
    <button type="submit" name="action" value="remove_page_cache"
            class="btn btn-sm btn-outline-secondary"{$page_cache_remove_disabled}>
      ✕ Disable
    </button>
  </form>
</div>

<!-- ── Migration Scenarios ────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-1">🗺️ Migration Helpers</h5>
  <p class="text-muted small mb-3">
    Select a scenario to pre-fill the Site URL field below. You can edit the result
    before submitting.
  </p>

  <div class="accordion" id="lum-mig-accordion">

    <!-- Domain change -->
    <div class="accordion-item">
      <h6 class="accordion-header">
        <button class="accordion-button collapsed py-2 small" type="button"
                data-bs-toggle="collapse" data-bs-target="#lum-mig-domain">
          🌐 Domain Change — moved to a new domain name
        </button>
      </h6>
      <div id="lum-mig-domain" class="accordion-collapse collapse" data-bs-parent="#lum-mig-accordion">
        <div class="accordion-body py-3">
          <p class="small text-muted mb-2">Enter the new domain. Trailing slashes, subdirectory paths, and the http/https scheme are preserved from the stored URL.</p>
          <div class="d-flex gap-2 align-items-end flex-wrap">
            <div>
              <label class="form-label small mb-1">New domain</label>
              <input type="text" id="lum-mig-new-domain" class="form-control form-control-sm font-monospace"
                     placeholder="e.g. www.newdomain.com" style="min-width:260px">
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="lum-mig-domain-apply">
              Apply to form ↓
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Subdirectory change -->
    <div class="accordion-item">
      <h6 class="accordion-header">
        <button class="accordion-button collapsed py-2 small" type="button"
                data-bs-toggle="collapse" data-bs-target="#lum-mig-subdir">
          📂 Subdirectory Change — moved to a different path on the same server
        </button>
      </h6>
      <div id="lum-mig-subdir" class="accordion-collapse collapse" data-bs-parent="#lum-mig-accordion">
        <div class="accordion-body py-3">
          <p class="small text-muted mb-2">Enter the new subdirectory path. Leave blank to move to the web root.</p>
          <div class="d-flex gap-2 align-items-end flex-wrap">
            <div>
              <label class="form-label small mb-1">New subdirectory (relative to web root)</label>
              <input type="text" id="lum-mig-new-subdir" class="form-control form-control-sm font-monospace"
                     placeholder="e.g. /gallery or leave blank for root" style="min-width:260px">
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="lum-mig-subdir-apply">
              Apply to form ↓
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- HTTPS enablement -->
    <div class="accordion-item">
      <h6 class="accordion-header">
        <button class="accordion-button collapsed py-2 small" type="button"
                data-bs-toggle="collapse" data-bs-target="#lum-mig-https">
          🔒 HTTPS Enablement — switch from http:// to https://
        </button>
      </h6>
      <div id="lum-mig-https" class="accordion-collapse collapse" data-bs-parent="#lum-mig-accordion">
        <div class="accordion-body py-3">
          <p class="small text-muted mb-2">
            Replaces <code>http://</code> with <code>https://</code> in the stored URL.
            Ensure your SSL certificate is correctly installed and your web server is
            configured to serve HTTPS <strong>before</strong> applying this change.
          </p>
          <button type="button" class="btn btn-sm btn-outline-success" id="lum-mig-https-apply">
            🔒 Switch to https:// in form ↓
          </button>
        </div>
      </div>
    </div>

    <!-- Server migration -->
    <div class="accordion-item">
      <h6 class="accordion-header">
        <button class="accordion-button collapsed py-2 small" type="button"
                data-bs-toggle="collapse" data-bs-target="#lum-mig-server">
          🚀 Server Migration — moved to a new host entirely
        </button>
      </h6>
      <div id="lum-mig-server" class="accordion-collapse collapse" data-bs-parent="#lum-mig-accordion">
        <div class="accordion-body py-3">
          <p class="small text-muted mb-2">
            For a full server migration, replace the complete URL below and then verify
            the health check after saving.
          </p>
          <div class="d-flex gap-2 align-items-end flex-wrap">
            <div>
              <label class="form-label small mb-1">Complete new URL (with trailing slash)</label>
              <input type="url" id="lum-mig-full-url" class="form-control form-control-sm font-monospace"
                     placeholder="https://newserver.example.com/gallery/" style="min-width:360px">
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="lum-mig-full-apply">
              Apply to form ↓
            </button>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /accordion -->
</div>

<!-- ── Update Settings ────────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-1">✏️ Update Installation Settings</h5>
  <p class="text-muted small mb-3">
    All changes are logged with your username, IP address, previous and new values.
    Enter your current password to confirm before saving.
  </p>

  <form method="post" action="{$self_h}" id="lum-install-form">
    <input type="hidden" name="action"     value="update">
    <input type="hidden" name="csrf_token" value="{$csrf}">

    <div class="row g-3 mb-3">
      <div class="col-12 col-md-8">
        <label class="form-label fw-semibold">Site URL</label>
        <input type="url" name="base_url" id="lum-url-field"
               value="{$v_stored_url}"
               class="form-control font-monospace" required
               placeholder="https://example.com/gallery/">
        <div class="form-text">
          The public URL of this Lumora installation, with a trailing slash.
          Must match the URL visitors use to reach the gallery.
          Changing this will clear all application caches.
        </div>
      </div>
    </div>

    <div id="lum-url-preview" class="alert alert-info py-2 small d-none mb-3">
      <strong>Preview:</strong> Site URL will change from
      <span class="font-monospace" id="lum-preview-old"></span>
      to
      <span class="font-monospace fw-semibold" id="lum-preview-new"></span>
    </div>

    <details class="mb-3">
      <summary class="text-muted small" style="cursor:pointer">
        ℹ️ Rollback instructions — what to do if the site becomes unreachable
      </summary>
      <div class="mt-2 p-3 bg-light border rounded small">
        <p class="mb-2">If the gallery becomes unreachable after updating the URL:</p>
        <ol class="mb-2">
          <li>Connect to your server via FTP or your hosting file manager.</li>
          <li>Open <code>config.php</code> in the Lumora root directory — this file contains only
              database credentials, not the site URL.</li>
          <li>Log in to your database via phpMyAdmin or a similar tool.</li>
          <li>In the <code>{$v_db_prefix}config</code> table, find the row where
              <code>name = 'base_url'</code> and update its <code>value</code>
              back to the previous URL.</li>
          <li>Access the admin panel via the corrected URL and retry the change.</li>
        </ol>
        <p class="mb-0 text-muted">The JSON snapshot exported above records the previous URL and can serve as a reference.</p>
      </div>
    </details>

    <hr class="my-3">

    <div class="row g-3 mb-3">
      <div class="col-12 col-sm-6 col-md-4">
        <label class="form-label fw-semibold">Current Password <span class="text-danger">*</span></label>
        <input type="password" name="current_password" class="form-control"
               autocomplete="current-password" required>
        <div class="form-text">Required to confirm identity before changes are applied.</div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">💾 Save Changes</button>
  </form>
</div>

<!-- ── Health Check ───────────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-1">🩺 Installation Health Check</h5>
  <p class="text-muted small mb-3">
    Verifies that all critical installation components are accessible and correctly
    configured. Run this after applying any setting changes or after a server migration.
  </p>
  <div class="d-flex gap-2 align-items-center mb-3">
    <button type="button" class="btn btn-outline-primary" id="lum-health-btn">
      ▶ Run Health Check
    </button>
    <span id="lum-health-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status">
      <span class="visually-hidden">Running…</span>
    </span>
  </div>

  <div id="lum-health-results" class="d-none">
    <div id="lum-health-summary" class="mb-2"></div>
    <div class="table-responsive">
      <table class="table table-sm lum-adm-table align-middle mb-0">
        <thead>
          <tr>
            <th style="width:2rem"></th>
            <th>Check</th>
            <th>Status</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody id="lum-health-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

{$audit_html}

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const CSRF         = {$csrf_js};
  const AJAX_BASE    = {$ajax_base_js};
  const STORED       = {$stored_url_js};
  const DETECTED_URL = {$detected_url_js};

  // ── URL preview ─────────────────────────────────────────────────────────────
  const urlField   = document.getElementById('lum-url-field');
  const preview    = document.getElementById('lum-url-preview');
  const previewOld = document.getElementById('lum-preview-old');
  const previewNew = document.getElementById('lum-preview-new');

  function updatePreview() {
    if (!urlField || !preview) return;
    const val = urlField.value.trim();
    const normalised = val.endsWith('/') ? val : val + '/';
    if (normalised !== STORED && val !== '') {
      previewOld.textContent = STORED;
      previewNew.textContent = normalised;
      preview.classList.remove('d-none');
    } else {
      preview.classList.add('d-none');
    }
  }

  if (urlField) urlField.addEventListener('input', updatePreview);
  updatePreview();

  // ── "Use detected URL" button (shown in the diff card) ─────────────────────
  const useDetected = document.getElementById('lum-use-detected');
  if (useDetected && urlField) {
    useDetected.addEventListener('click', function () {
      urlField.value = DETECTED_URL;
      urlField.dispatchEvent(new Event('input'));
      document.getElementById('lum-install-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // ── Migration scenario helpers ──────────────────────────────────────────────
  function getCurrentUrl() {
    return (urlField ? urlField.value.trim() : '') || STORED;
  }

  function setUrl(url) {
    if (!urlField) return;
    urlField.value = url.endsWith('/') ? url : url + '/';
    urlField.dispatchEvent(new Event('input'));
    document.getElementById('lum-install-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Domain change
  const domainApply = document.getElementById('lum-mig-domain-apply');
  if (domainApply) {
    domainApply.addEventListener('click', function () {
      const newDomain = (document.getElementById('lum-mig-new-domain')?.value || '').trim();
      if (!newDomain) return;
      try {
        const u    = new URL(getCurrentUrl());
        u.hostname = newDomain.replace(/^https?:\/\//, '').split('/')[0];
        setUrl(u.origin + u.pathname);
      } catch {
        setUrl('https://' + newDomain.replace(/^https?:\/\//, '').split('/')[0] + '/');
      }
    });
  }

  // Subdirectory change
  const subdirApply = document.getElementById('lum-mig-subdir-apply');
  if (subdirApply) {
    subdirApply.addEventListener('click', function () {
      const raw = (document.getElementById('lum-mig-new-subdir')?.value || '').trim();
      try {
        const u  = new URL(getCurrentUrl());
        const p  = raw === '' ? '/' : ('/' + raw.replace(/^\/+|\/+$/g, '') + '/');
        setUrl(u.origin + p);
      } catch {
        setUrl(getCurrentUrl());
      }
    });
  }

  // HTTPS enablement
  const httpsApply = document.getElementById('lum-mig-https-apply');
  if (httpsApply) {
    httpsApply.addEventListener('click', function () {
      setUrl(getCurrentUrl().replace(/^http:\/\//, 'https://'));
    });
  }

  // Full server migration
  const fullApply = document.getElementById('lum-mig-full-apply');
  if (fullApply) {
    fullApply.addEventListener('click', function () {
      const val = (document.getElementById('lum-mig-full-url')?.value || '').trim();
      if (val) setUrl(val);
    });
  }

  // ── Health check ────────────────────────────────────────────────────────────
  const healthBtn     = document.getElementById('lum-health-btn');
  const healthSpinner = document.getElementById('lum-health-spinner');
  const healthResults = document.getElementById('lum-health-results');
  const healthTbody   = document.getElementById('lum-health-tbody');
  const healthSummary = document.getElementById('lum-health-summary');

  function esc(v) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(v)));
    return d.innerHTML;
  }

  if (healthBtn) {
    healthBtn.addEventListener('click', async function () {
      healthBtn.disabled = true;
      if (healthSpinner) healthSpinner.classList.remove('d-none');
      if (healthResults) healthResults.classList.add('d-none');

      try {
        const resp = await fetch(AJAX_BASE + 'ajax_installation_health.php', {
          method:  'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body:    'csrf_token=' + encodeURIComponent(CSRF),
        });

        if (!resp.ok) throw new Error('Server returned ' + resp.status);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        if (healthTbody) {
          healthTbody.innerHTML = data.checks.map(function (c) {
            const icon   = c.ok ? '✓' : (c.status === 'WARNING' ? '⚠' : '✗');
            const colour = c.ok ? 'text-success' : (c.status === 'WARNING' ? 'text-warning' : 'text-danger');
            return '<tr>'
              + '<td class="' + colour + ' fw-bold fs-6">' + icon + '</td>'
              + '<td class="small fw-semibold">' + esc(c.name) + '</td>'
              + '<td class="small"><span class="badge bg-' + (c.ok ? 'success' : (c.status === 'WARNING' ? 'warning text-dark' : 'danger')) + '">' + esc(c.status) + '</span></td>'
              + '<td class="small text-muted">' + esc(c.detail) + '</td>'
              + '</tr>';
          }).join('');
        }

        if (healthSummary) {
          healthSummary.innerHTML = data.all_ok
            ? '<div class="alert alert-success py-2 small mb-0">✓ All checks passed — installation appears healthy.</div>'
            : '<div class="alert alert-warning py-2 small mb-0">⚠ One or more checks require attention. Review the table below.</div>';
        }

        if (healthResults) healthResults.classList.remove('d-none');
      } catch (err) {
        if (healthSummary) {
          healthSummary.innerHTML = '<div class="alert alert-danger py-2 small mb-0">Error running health check: ' + esc(err.message) + '</div>';
        }
        if (healthResults) healthResults.classList.remove('d-none');
      } finally {
        healthBtn.disabled = false;
        if (healthSpinner) healthSpinner.classList.add('d-none');
      }
    });
  }

}); // end DOMContentLoaded
</script>
HTML;

lum_admin_page('Installation Settings', $content, 'installation');
