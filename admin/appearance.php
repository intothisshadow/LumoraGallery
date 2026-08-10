<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Appearance
 *
 * Theme management (card grid: activate, preview, install/update from ZIP,
 * delete) plus the display-related settings that previously lived in
 * admin/config.php's "Appearance" card: theme, default_color_mode,
 * category_layout, show_powered_by (LG-043 — split out of Configuration
 * into its own page, modeled on Lumora Press's Appearance → Themes screen).
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.15.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('site_configuration');

$base   = lumora_base_url() . 'admin/appearance.php';
$csrf   = h(lumora_csrf_token());
$base_h = h($base);

// ── POST: activate / install / update / delete / save display settings ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act = $_POST['action'] ?? '';

    if ($act === 'activate_theme') {
        $folder = (string) ($_POST['folder'] ?? '');
        if (!in_array($folder, lumora_list_themes(), true)) {
            lum_flash('That theme could not be found.', 'danger');
        } else {
            lumora_set_config('theme', $folder);
            lum_flash('Theme "' . $folder . '" activated.');
        }
        lumora_redirect($base);
    }

    if ($act === 'install_theme') {
        $file = $_FILES['theme_zip'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            lum_flash('No file uploaded or upload error.', 'danger');
            lumora_redirect($base);
        }
        $r = ThemeService::installFromZip($file['tmp_name']);
        lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
        lumora_redirect($base);
    }

    if ($act === 'update_theme') {
        $folder = (string) ($_POST['folder'] ?? '');
        if (($_POST['confirm_overwrite'] ?? '') !== '1') {
            lum_flash('Update was not confirmed — no files were changed.', 'danger');
            lumora_redirect($base);
        }
        $file = $_FILES['theme_zip'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            lum_flash('No file uploaded or upload error.', 'danger');
            lumora_redirect($base);
        }
        $r = ThemeService::updateFromZip($file['tmp_name'], $folder);
        lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
        lumora_redirect($base);
    }

    if ($act === 'delete_theme') {
        $folder = (string) ($_POST['folder'] ?? '');
        $r = ThemeService::deleteTheme($folder);
        lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
        lumora_redirect($base);
    }

    if ($act === 'save_display') {
        $allowed = ['default_color_mode', 'category_layout', 'show_powered_by'];
        $bool_keys = ['show_powered_by'];
        foreach ($allowed as $key) {
            if (in_array($key, $bool_keys, true)) {
                lumora_set_config($key, LumoraConfig::sanitizeValue($key, $_POST[$key] ?? '0'));
            } elseif (isset($_POST[$key])) {
                lumora_set_config($key, LumoraConfig::sanitizeValue($key, $_POST[$key]));
            }
        }
        lum_flash('Display settings saved.');
        lumora_redirect($base);
    }

    lum_flash('Unknown action.', 'danger');
    lumora_redirect($base);
}

// ── Current values ────────────────────────────────────────────────────────────
$cfg = [
    'default_color_mode' => lumora_config('default_color_mode', 'auto'),
    'category_layout'    => lumora_config('category_layout',    'grid'),
    'show_powered_by'    => lumora_config('show_powered_by',    '1'),
];

$sel_cm_auto  = $cfg['default_color_mode'] === 'auto'  ? ' selected' : '';
$sel_cm_light = $cfg['default_color_mode'] === 'light' ? ' selected' : '';
$sel_cm_dark  = $cfg['default_color_mode'] === 'dark'  ? ' selected' : '';
$sel_cat_grid = $cfg['category_layout']    === 'grid'  ? ' selected' : '';
$sel_cat_list = $cfg['category_layout']    === 'list'  ? ' selected' : '';
$chk_powered_by = $cfg['show_powered_by']  === '1'     ? ' checked' : '';

// ── Theme card grid data ──────────────────────────────────────────────────────
$themes = ThemeService::listThemesWithMeta();

// ── Section-header icons (matches admin/config.php's pattern) ────────────────
$ic_appearance = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.4-.3-.4-.5-.9-.5-1.4 0-1.1.9-2 2-2h1.5c1.9 0 3.5-1.6 3.5-3.5C20 6.4 16.4 3 12 3z"></path><circle cx="7.5" cy="10.5" r="1" fill="currentColor" stroke="none"></circle><circle cx="10.5" cy="7" r="1" fill="currentColor" stroke="none"></circle><circle cx="15" cy="8" r="1" fill="currentColor" stroke="none"></circle></svg>';
$ic_upload     = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 18a4.5 4.5 0 0 1-1-8.9 5 5 0 0 1 9.8-1.6A4 4 0 0 1 17 15.9"></path><polyline points="9 13 12 10 15 13"></polyline><line x1="12" y1="10" x2="12" y2="19"></line></svg>';
$ic_display    = '<svg class="lum-adm-section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="18" x2="12" y2="21"></line></svg>';

// ── Theme cards ────────────────────────────────────────────────────────────────
$cards_html  = '';
$modals_html = '';
foreach ($themes as $t) {
    $folder_h = h($t['folder']);
    $name_h   = h($t['name']);
    $author_h = $t['author'] !== '' ? h($t['author']) : '';
    $design_h = $t['design_uri'] !== ''
        ? '<a href="' . h($t['design_uri']) . '" target="_blank" rel="noopener">' . h($t['design_uri']) . '</a>'
        : '<span class="text-muted">&mdash;</span>';
    $preview_url = h(lumora_base_url() . '?theme=' . rawurlencode($t['folder']));
    $modal_id    = 'lum-theme-details-' . preg_replace('/[^a-z0-9_-]/i', '-', $t['folder']);

    $thumb_html = $t['screenshot'] !== null
        ? '<img src="' . h($t['screenshot']) . '" alt="' . $name_h . ' screenshot" class="lum-theme-card__screenshot" loading="lazy">'
        : '<div class="lum-theme-card__screenshot lum-theme-card__screenshot--placeholder" aria-hidden="true"></div>';

    $badge_html = $t['is_active'] ? '<span class="badge bg-success lum-theme-card__badge">Active</span>' : '';

    $activate_html = !$t['is_active']
        ? '<form method="post" action="' . $base_h . '" class="d-inline">'
            . '<input type="hidden" name="action" value="activate_theme">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="folder" value="' . $folder_h . '">'
            . '<button type="submit" class="btn btn-sm btn-primary">Activate</button>'
            . '</form>'
        : '<button type="button" class="btn btn-sm btn-primary" disabled>Active</button>';

    $cards_html .= '<div class="lum-theme-card' . ($t['is_active'] ? ' lum-theme-card--active' : '') . '">'
        . '<div class="lum-theme-card__screenshot-wrap">' . $thumb_html . '</div>'
        . '<div class="lum-theme-card__body">'
        . '<h3 class="lum-theme-card__name">' . $name_h . ' ' . $badge_html . '</h3>'
        . ($author_h !== '' ? '<p class="lum-theme-card__meta">By ' . $author_h . '</p>' : '')
        . '<div class="lum-theme-card__actions">'
        . $activate_html
        . '<a href="' . $preview_url . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">Preview &#x2197;</a>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#' . h($modal_id) . '">Details</button>'
        . '</div></div></div>';

    // Details modal: metadata, screenshot gallery, Update/Delete forms.
    $gallery_html = '';
    if (!empty($t['screenshots'])) {
        foreach ($t['screenshots'] as $shot) {
            $gallery_html .= '<img src="' . h($shot) . '" alt="' . $name_h . ' screenshot" class="lum-theme-details__screenshot">';
        }
    }

    $delete_html = '';
    if (!$t['is_active'] && !$t['is_protected']) {
        // Deliberately no theme name interpolated into the confirm() string —
        // h() HTML-escapes for the surrounding attribute, not for the nested
        // JS string literal, so a theme name containing a quote character
        // would decode back to a literal quote before JS parses it and could
        // break out of the string. A generic message avoids that entirely.
        $delete_html = '<form method="post" action="' . $base_h . '" class="d-inline"'
            . ' onsubmit="return confirm(\'Permanently delete this theme and all of its files? This cannot be undone.\');">'
            . '<input type="hidden" name="action" value="delete_theme">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="folder" value="' . $folder_h . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>'
            . '</form>';
    }

    $modals_html .= <<<HTML
<div class="modal fade" id="{$modal_id}" tabindex="-1" aria-labelledby="{$modal_id}-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="{$modal_id}-label">{$name_h} {$badge_html}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="lum-theme-details__gallery">{$gallery_html}</div>
        <dl class="row mb-0">
          <dt class="col-sm-3">Author</dt><dd class="col-sm-9">{$author_h}</dd>
          <dt class="col-sm-3">Design URI</dt><dd class="col-sm-9">{$design_h}</dd>
          <dt class="col-sm-3">Folder</dt><dd class="col-sm-9"><code>{$folder_h}</code></dd>
        </dl>
      </div>
      <div class="modal-footer justify-content-between flex-wrap gap-2">
        <form method="post" action="{$base_h}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center"
              onsubmit="return confirm('This will overwrite the currently-installed files for this theme. Continue?');">
          <input type="hidden" name="action" value="update_theme">
          <input type="hidden" name="csrf_token" value="{$csrf}">
          <input type="hidden" name="folder" value="{$folder_h}">
          <input type="hidden" name="confirm_overwrite" value="1">
          <input type="file" name="theme_zip" accept=".zip" required class="form-control form-control-sm" style="max-width:220px">
          <button type="submit" class="btn btn-sm btn-outline-warning">Update from ZIP</button>
        </form>
        {$delete_html}
      </div>
    </div>
  </div>
</div>
HTML;
}

if ($cards_html === '') {
    $cards_html = '<p class="text-muted">No themes found in <code>themes/</code>.</p>';
}

$content = <<<HTML
<!-- ── Themes ─────────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="lum-adm-section-title mb-3">{$ic_appearance}Themes</h5>
  <p class="text-muted small">Display name, author, and design URI are read from a <code>Theme Name</code> / <code>Author</code> / <code>Design URI</code> CSS header comment at the top of each theme's primary stylesheet. Use <strong>Preview</strong> to see any installed theme rendered on the live gallery without activating it &mdash; only visible to you, in a new tab.</p>
  <div class="lum-theme-grid">{$cards_html}</div>
</div>

{$modals_html}

<!-- ── Install a theme ───────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="lum-adm-section-title mb-3">{$ic_upload}Install a Theme</h5>
  <p class="text-muted small">Upload a <code>.zip</code> archive containing a theme folder (must include a <code>template.html</code>). If the archive wraps everything in a single top-level folder, it is flattened automatically. The destination folder name is derived from the theme's declared name.</p>
  <form method="post" action="{$base_h}" enctype="multipart/form-data" class="d-flex gap-2 align-items-center flex-wrap">
    <input type="hidden" name="action" value="install_theme">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <input type="file" name="theme_zip" accept=".zip" required class="form-control" style="max-width:320px">
    <button type="submit" class="btn btn-outline-secondary">&#x2B06; Upload &amp; Install</button>
  </form>
</div>

<!-- ── Display settings ──────────────────────────────────────────── -->
<form method="post" action="{$base_h}">
  <input type="hidden" name="action" value="save_display">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <div class="lum-adm-card mb-4">
    <h5 class="lum-adm-section-title mb-3">{$ic_display}Display Settings</h5>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Default Colour Mode</label>
        <select name="default_color_mode" class="form-select" style="max-width:220px">
          <option value="auto"{$sel_cm_auto}>Auto (follow visitor's system preference)</option>
          <option value="light"{$sel_cm_light}>Light — always start in light mode</option>
          <option value="dark"{$sel_cm_dark}>Dark — always start in dark mode</option>
        </select>
        <div class="form-text">Sets the default colour scheme for visitors who have not yet used the
          <strong>🖥️ / ☀️ / 🌙</strong> toggle. Visitors' own preference (stored in their browser)
          always takes priority over this setting.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Category Layout</label>
        <select name="category_layout" class="form-select" style="max-width:260px">
          <option value="grid"{$sel_cat_grid}>Grid — card grid (default)</option>
          <option value="list"{$sel_cat_list}>List — one category per row</option>
        </select>
        <div class="form-text">Choose how categories are displayed on the home page and category browsing pages. <em>List</em> shows each category as a row with thumbnail, name, album count, and image count.</div>
      </div>
    </div>

    <div class="mb-0">
      <div class="form-check form-switch">
        <input type="hidden" name="show_powered_by" value="0">
        <input class="form-check-input" type="checkbox" id="show_powered_by"
               name="show_powered_by" value="1"{$chk_powered_by}>
        <label class="form-check-label fw-semibold" for="show_powered_by">Show Powered By Credit</label>
      </div>
      <div class="form-text">Display a &ldquo;Powered by Lumora Gallery&rdquo; credit in the site footer.</div>
    </div>
  </div>

  <div class="lum-adm-card lum-adm-save-bar mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h5 class="mb-1">💾 Save Display Settings</h5>
        <p class="text-muted small mb-0">Theme activation, install, update, and delete above save immediately and don't need this button.</p>
      </div>
      <button type="submit" class="btn btn-primary btn-lg">Save</button>
    </div>
  </div>
</form>
HTML;

lum_admin_page('Appearance', $content, 'appearance');
