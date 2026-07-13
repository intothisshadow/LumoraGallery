<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Helpers
 *
 * Shared utilities for the admin panel:
 *   lum_flash()             — queue a flash message
 *   lum_flash_html()        — render and clear queued flash messages
 *   lum_per_page_selector() — render a per-page size selector form
 *   lum_admin_pagination()  — render Bootstrap 5 pagination controls
 *   lum_admin_page()        — render a full admin page (sidebar nav is filtered
 *                             to the items the current user's role grants
 *                             access to — see GroupService::getGroupPermissions())
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

// ── Flash messages ────────────────────────────────────────────────────────────

/**
 * Queue a flash message to display on the next page load.
 *
 * @param string $msg     The message text (will be HTML-escaped on display).
 * @param string $type    Bootstrap alert type: 'success' | 'danger' | 'warning' | 'info'
 */
function lum_flash(string $msg, string $type = 'success'): void
{
    $_SESSION['lum_flash'][] = ['msg' => $msg, 'type' => $type];
}

/**
 * Return HTML for any queued flash messages and clear them.
 */
function lum_flash_html(): string
{
    if (empty($_SESSION['lum_flash'])) return '';
    $html = '';
    foreach ($_SESSION['lum_flash'] as $f) {
        $html .= '<div class="alert alert-' . h($f['type']) . ' alert-dismissible fade show py-2" role="alert">'
            . h($f['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            . '</div>';
    }
    unset($_SESSION['lum_flash']);
    return $html;
}

// ── Pagination helpers ────────────────────────────────────────────────────────

/**
 * Render a per-page size selector form for admin list pages.
 *
 * Submitting the form resets to page 1 (no page= param in the form).
 * Extra GET params (e.g. category filter) are preserved as hidden inputs.
 *
 * @param string    $action   Raw form action URL (the page file URL, no query string).
 * @param array     $preserve Extra GET params to preserve, keyed by name. Values of
 *                            0 or '' are omitted (treated as "not active").
 * @param int       $current  Currently selected items-per-page value.
 * @param list<int> $options  Available page-size options.
 * @return string HTML <form> for per-page selection.
 */
function lum_per_page_selector(
    string $action,
    array  $preserve = [],
    int    $current  = 25,
    array  $options  = [25, 50, 100]
): string {
    $hidden = '';
    foreach ($preserve as $k => $v) {
        $vs = (string) $v;
        if ($vs !== '' && $vs !== '0') {
            $hidden .= '<input type="hidden" name="' . h((string) $k) . '" value="' . h($vs) . '">';
        }
    }

    $opts = '';
    foreach ($options as $opt) {
        $sel   = ($opt === $current) ? ' selected' : '';
        $opts .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
    }

    return '<form method="get" action="' . h($action) . '" class="d-inline-flex align-items-center gap-1">'
        . $hidden
        . '<label class="text-muted small mb-0 me-1">Per page:</label>'
        . '<select name="per_page" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">'
        . $opts
        . '</select>'
        . '</form>';
}

/**
 * Render Bootstrap 5 pagination controls.
 *
 * Displays a window of page numbers (±2 around current) plus first and last,
 * with ellipsis indicators for gaps. Returns '' when there is only one page.
 *
 * @param array $pag Pagination descriptor returned by lumora_pagination().
 * @return string    HTML <nav> element, or '' when pagination is not needed.
 */
function lum_admin_pagination(array $pag): string
{
    if ($pag['total_pages'] <= 1) return '';

    $current = $pag['current_page'];
    $total   = $pag['total_pages'];

    // Collect page numbers to render: always include first, last, and a ±2 window.
    $pages = [];
    for ($p = 1; $p <= $total; $p++) {
        if ($p === 1 || $p === $total || abs($p - $current) <= 2) {
            $pages[] = $p;
        }
    }

    $html = '<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0 flex-wrap">';

    // Previous button.
    if ($pag['has_prev']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h((string) $pag['prev_url']) . '">‹ Prev</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">‹ Prev</span></li>';
    }

    // Numbered page links with ellipsis gaps.
    $prev_p = null;
    foreach ($pages as $p) {
        if ($prev_p !== null && $p > $prev_p + 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        if ($p === $current) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $p . '</span></li>';
        } else {
            $url   = h(sprintf($pag['url_pattern'], $p));
            $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">' . $p . '</a></li>';
        }
        $prev_p = $p;
    }

    // Next button.
    if ($pag['has_next']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h((string) $pag['next_url']) . '">Next ›</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next ›</span></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

// ── Page renderer ─────────────────────────────────────────────────────────────

/**
 * Render a full admin panel page.
 *
 * Includes a dark-mode initialization script (runs before Bootstrap CSS loads
 * to prevent flash-of-wrong-theme) and a toggle button in the topbar that
 * cycles Auto → Dark → Light → Auto and syncs the preference to the database
 * for the logged-in user.
 *
 * @param string $title   Page title (shown in <title> and <h1>).
 * @param string $content Main content HTML.
 * @param string $active  Active sidebar item key (matches $nav entries).
 */
function lum_admin_page(string $title, string $content, string $active = ''): never
{
    // Opt-in anonymous install ping (TODO.md #27) — a cheap no-op on every
    // call unless the feature is enabled AND the ~monthly interval has
    // elapsed; see InstallPingService's class docblock. Wrapped defensively
    // even though the service already fails silently internally, so a
    // future change there can never turn into a broken admin panel.
    try {
        InstallPingService::maybeSendPing();
    } catch (\Throwable) {
        // Never let this affect the admin page render.
    }

    $base_url     = h(lumora_base_url());
    $admin_url    = h(lumora_base_url() . 'admin/');
    $gallery_url  = h(lumora_base_url());
    $user         = lumora_current_user();
    $username     = h($user['username'] ?? 'Admin');
    $csrf         = h(lumora_csrf_token());
    $csrf_js      = json_encode(lumora_csrf_token());
    $ajax_cm_url  = json_encode(lumora_base_url() . 'admin/ajax_color_mode.php');
    $ver          = LUMORA_VERSION;
    $flash        = lum_flash_html();
    $title_h      = h($title);
    $gallery_name = h(lumora_config('gallery_name', 'Lumora Gallery'));

    // ── Colour mode for dark mode init ─────────────────────────────────────────
    // Read the user's stored DB preference (column may not exist before migration
    // 0004 runs — fall back to 'auto' gracefully).
    $user_color_mode = 'auto';
    try {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid > 0) {
            $cm = LumoraDB::fetchValue(
                'SELECT `color_mode` FROM `{PREFIX}users` WHERE `id` = ?',
                [$uid]
            );
            if (in_array($cm, ['auto', 'light', 'dark'], true)) {
                $user_color_mode = $cm;
            }
        }
    } catch (\Throwable) {
        // Column not yet added — migration 0004 pending. Use 'auto' fallback.
    }

    // Site-wide default (admin can set in Configuration).
    $site_default = in_array((string) lumora_config('default_color_mode', 'auto'), ['auto', 'light', 'dark'], true)
        ? (string) lumora_config('default_color_mode', 'auto')
        : 'auto';

    // Effective initial preference — user pref overrides site default.
    $init_pref_js = json_encode($user_color_mode !== 'auto' ? $user_color_mode : $site_default);

    // Persistent security warning: shown on every admin page until install/ is gone.
    $install_warn = is_dir(LUMORA_ROOT . 'install')
        ? '<div class="alert alert-danger alert-dismissible fade show py-2 mb-3" role="alert">'
          . '<strong>Security warning:</strong> The <code>install/</code> directory still exists. '
          . 'Delete it immediately via FTP or your hosting control panel to prevent unauthorised reinstallation.'
          . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
          . '</div>'
        : '';

    // Badge on the Updates nav item — shown when a new version is available OR
    // when schema migrations are pending. Cache-only reads; no HTTP call.
    $update_badge = (UpdateService::hasCachedUpdate() || SchemaService::hasPendingMigrations())
        ? ' <span class="badge bg-danger" style="font-size:.6rem;vertical-align:middle;line-height:1">!</span>'
        : '';

    $nav_items = [
        'dashboard'    => ['icon' => '📊', 'label' => 'Dashboard',              'url' => 'dashboard.php',    'permission' => null],
        'batch'        => ['icon' => '⬆️', 'label' => 'Batch Add',              'url' => 'batch.php',        'permission' => 'batch_add'],
        'categories'   => ['icon' => '📁', 'label' => 'Categories',             'url' => 'categories.php',   'permission' => 'manage_albums'],
        'albums'       => ['icon' => '🖼️', 'label' => 'Albums',                 'url' => 'albums.php',       'permission' => 'manage_albums'],
        'images'       => ['icon' => '📸', 'label' => 'Images',                 'url' => 'images.php',       'permission' => ['manage_images', 'edit_own_images']],
        'config'       => ['icon' => '⚙️', 'label' => 'Configuration',          'url' => 'config.php',       'permission' => 'site_configuration'],
        'tools'        => ['icon' => '🔧', 'label' => 'Tools',                  'url' => 'tools.php',        'permission' => 'maintenance_tools'],
        'installation' => ['icon' => '🖥️', 'label' => 'Installation',           'url' => 'installation.php', 'permission' => 'site_configuration'],
        'migrate'      => ['icon' => '📥', 'label' => 'Import',                 'url' => 'migrate.php',      'permission' => 'site_configuration'],
        'updates'      => ['icon' => '🔔', 'label' => 'Updates' . $update_badge, 'url' => 'update.php',       'permission' => 'view_updates'],
        'account'      => ['icon' => '👤', 'label' => 'Account',                'url' => 'account.php',      'permission' => null],
        'users'        => ['icon' => '👥', 'label' => 'Users',                  'url' => 'users.php',        'permission' => 'user_management'],
        'groups'       => ['icon' => '🛡️', 'label' => 'Groups',                'url' => 'groups.php',       'permission' => 'user_management'],
    ];

    // Hide nav items the current user's role does not grant access to.
    // 'permission' === null means every logged-in role may see the item
    // (dashboard, account); an array means any one of the listed permissions
    // is sufficient (mirrors lumora_require_any_permission()).
    $nav_items = array_filter($nav_items, static function (array $item): bool {
        $perm = $item['permission'];
        if ($perm === null) return true;
        foreach ((array) $perm as $p) {
            if (lumora_has_permission($p)) return true;
        }
        return false;
    });

    $nav_html = '';
    foreach ($nav_items as $key => $item) {
        $cls = ($key === $active) ? ' class="active"' : '';
        $nav_html .= '<li' . $cls . '>'
            . '<a href="' . $admin_url . h($item['url']) . '">'
            . $item['icon'] . ' ' . $item['label']
            . '</a></li>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <!-- Colour-mode init: runs before any CSS to prevent flash-of-wrong-theme -->
  <script>
  (function () {
    'use strict';
    var initPref = {$init_pref_js};
    var stored   = '';
    try { stored = localStorage.getItem('lum_color_mode') || ''; } catch (e) {}
    // User's explicit localStorage choice always wins; fall back to server-side pref.
    var pref = stored || initPref || 'auto';
    var dark;
    if (pref === 'dark')       { dark = true; }
    else if (pref === 'light') { dark = false; }
    else {
      try { dark = window.matchMedia('(prefers-color-scheme: dark)').matches; }
      catch (e) { dark = false; }
    }
    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
  })();
  </script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title_h} — Lumora Gallery Admin</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="{$admin_url}admin.css">
</head>
<body class="lum-admin">

<nav class="navbar navbar-expand-lg navbar-dark lum-admin-topbar px-3">
  <a class="navbar-brand fw-bold" href="{$admin_url}">⚡ Lumora Gallery Admin <span class="opacity-50 fw-normal small">v{$ver}</span></a>
  <button class="navbar-toggler ms-auto me-2" type="button" data-bs-toggle="collapse"
          data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="adminNav">
    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
      <li class="nav-item">
        <a class="nav-link" href="{$gallery_url}" target="_blank" rel="noopener">↗ View Gallery</a>
      </li>
      <li class="nav-item">
        <a class="nav-link text-white-50 small" href="{$admin_url}account.php">👤 {$username}</a>
      </li>
      <li class="nav-item d-flex align-items-center">
        <button type="button" id="lum-adm-color-toggle" class="lum-color-toggle me-2"
                title="Toggle colour mode" aria-label="Toggle colour mode">
          <span id="lum-adm-color-icon">🖥️</span>
        </button>
      </li>
      <li class="nav-item">
        <form method="post" action="{$admin_url}logout.php" class="d-inline">
          <input type="hidden" name="csrf_token" value="{$csrf}">
          <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
        </form>
      </li>
    </ul>
  </div>
</nav>

<div class="lum-admin-layout">
  <!-- Sidebar -->
  <aside class="lum-admin-sidebar">
    <div class="lum-admin-gallery-name small text-truncate px-3 py-2 text-white-50">{$gallery_name}</div>
    <ul class="lum-admin-nav list-unstyled m-0 p-0">
      {$nav_html}
    </ul>
  </aside>

  <!-- Main -->
  <main class="lum-admin-main p-3 p-md-4">
    <h1 class="h4 mb-3">{$title_h}</h1>
    {$install_warn}
    {$flash}
    {$content}
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  'use strict';

  var CSRF       = {$csrf_js};
  var AJAX_CM    = {$ajax_cm_url};

  var toggle = document.getElementById('lum-adm-color-toggle');
  var icon   = document.getElementById('lum-adm-color-icon');
  if (!toggle) return;

  function currentPref() {
    try { return localStorage.getItem('lum_color_mode') || 'auto'; } catch (e) { return 'auto'; }
  }

  var icons = { auto: '\ud83d\udda5\ufe0f', light: '\u2600\ufe0f', dark: '\ud83c\udf19' };
  var labels = {
    auto:  'Colour mode: Auto (system) — click for dark',
    dark:  'Colour mode: Dark — click for light',
    light: 'Colour mode: Light — click for auto'
  };

  function applyTheme(pref) {
    var dark;
    if (pref === 'dark')       { dark = true; }
    else if (pref === 'light') { dark = false; }
    else {
      try { dark = window.matchMedia('(prefers-color-scheme: dark)').matches; }
      catch (e) { dark = false; }
    }
    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
    if (icon)   icon.textContent = icons[pref] || icons.auto;
    if (toggle) toggle.setAttribute('title', labels[pref] || labels.auto);
  }

  // Initialise icon to match current preference.
  applyTheme(currentPref());

  // React to OS-level changes when in auto mode.
  try {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (currentPref() === 'auto') applyTheme('auto');
    });
  } catch (e) {}

  toggle.addEventListener('click', function () {
    var cur  = currentPref();
    var next = cur === 'auto' ? 'dark' : cur === 'dark' ? 'light' : 'auto';
    try { localStorage.setItem('lum_color_mode', next); } catch (e) {}

    // Smooth transition for subsequent switches (not first paint).
    document.documentElement.style.transition = 'background-color .25s ease, color .25s ease, border-color .25s ease';
    applyTheme(next);

    // Sync to DB so the preference persists across devices.
    try {
      var params = new URLSearchParams({ csrf_token: CSRF, mode: next });
      fetch(AJAX_CM, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : params.toString(),
      }).catch(function () {});
    } catch (e) {}
  });
})();
</script>
</body>
</html>
HTML;
    exit;
}
