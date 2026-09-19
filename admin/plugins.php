<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Plugins
 *
 * Lists every discovered "feature" and "importer" plugin — see
 * PluginService's own class docblock for what distinguishes the two — and
 * lets an admin enable, disable, or (once disabled) permanently delete each
 * one, plus install a new plugin or update an already-installed one from an
 * uploaded ZIP (LG-069, mirroring Admin → Appearance's own theme ZIP
 * pipeline via ThemeService). Deletion and bulk enable/disable are
 * AJAX-only via ajax_plugin_delete.php/ajax_plugin_bulk_toggle.php and
 * PluginService's own methods; a single-row enable/disable stays a plain
 * synchronous form POST, since that never risked a long-running request.
 *
 * An importer plugin (Coppermine, etc.) is still run on-demand from
 * admin/migrate.php, not from anywhere on this page — disabling it here
 * only hides its "Run Importer" button there, for an admin who has already
 * migrated a gallery and wants it out of the way without deleting it.
 *
 * Every enable/disable is recorded via LogService for the Admin → Logs page.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 * @see        PluginService Discovery + enable/disable/delete/ZIP-install logic this page drives.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('site_configuration');

$base = h(lumora_base_url() . 'admin/plugins.php');

// ── Handle enable/disable/install/update ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    $post_action = (string) ($_POST['action'] ?? '');

    if ($post_action === 'install_plugin') {
        $file = $_FILES['plugin_zip'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            lum_flash('No file uploaded or upload error.', 'danger');
            lumora_redirect($base);
        }
        $r = PluginService::installFromZip($file['tmp_name']);
        lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
        lumora_redirect($base);
    }

    if ($post_action === 'update_plugin') {
        $id = (string) ($_POST['id'] ?? '');
        if (($_POST['confirm_overwrite'] ?? '') !== '1') {
            lum_flash('Update was not confirmed — no files were changed.', 'danger');
            lumora_redirect($base);
        }
        $file = $_FILES['plugin_zip'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            lum_flash('No file uploaded or upload error.', 'danger');
            lumora_redirect($base);
        }
        $r = PluginService::updateFromZip($file['tmp_name'], $id);
        lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
        lumora_redirect($base);
    }

    $id     = (string) ($_POST['id'] ?? '');
    $action = (string) ($_POST['do'] ?? '');

    $plugin = null;
    foreach (PluginService::discoverManageablePlugins() as $p) {
        if ($p['id'] === $id) { $plugin = $p; break; }
    }

    if ($plugin === null) {
        lum_flash('Plugin not found.', 'danger');
    } elseif ($action === 'enable') {
        if (!PluginService::isCompatible($plugin['min_lumora'])) {
            lum_flash('This plugin requires Lumora ' . $plugin['min_lumora'] . ' or newer.', 'danger');
        } elseif (PluginService::enablePlugin($plugin)) {
            $actor = lumora_current_user();
            LogService::log(
                'plugin_enabled',
                (int) ($actor['user_id'] ?? 0),
                (string) ($actor['username'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'Enabled plugin "' . $plugin['name'] . '" (' . $plugin['id'] . ')'
            );
            lum_flash('"' . $plugin['name'] . '" enabled.');
        } else {
            lum_flash('"' . $plugin['name'] . '" could not be activated — see the server error log for details.', 'danger');
        }
    } elseif ($action === 'disable') {
        PluginService::disablePlugin($plugin);
        $actor = lumora_current_user();
        LogService::log(
            'plugin_disabled',
            (int) ($actor['user_id'] ?? 0),
            (string) ($actor['username'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'Disabled plugin "' . $plugin['name'] . '" (' . $plugin['id'] . ')'
        );
        lum_flash('"' . $plugin['name'] . '" disabled.');
    }

    lumora_redirect($base);
}

// ── List ──────────────────────────────────────────────────────────────────────
$plugins = PluginService::discoverManageablePlugins();
$csrf_h  = h(lumora_csrf_token());
$csrf_js = json_encode(lumora_csrf_token());

$rows        = '';
$modals_html = '';
if (empty($plugins)) {
    $rows = '<p class="text-muted">No plugins found in <code>plugins/</code>.</p>';
} else {
    foreach ($plugins as $p) {
        $enabled    = PluginService::isEnabled($p['id'], $p['type']);
        $compatible = PluginService::isCompatible($p['min_lumora']);
        $name_h     = h($p['name']);
        $ver_h      = h($p['version']);
        $author_h   = h($p['author']);
        $desc_h     = h($p['description']);
        $id_h       = h($p['id']);
        $id_js      = json_encode($p['id']);
        $modal_id   = 'lum-plugin-update-' . preg_replace('/[^a-z0-9_-]/i', '-', $p['id']);

        $badge = $enabled
            ? '<span class="badge bg-success">Enabled</span>'
            : '<span class="badge bg-secondary">Disabled</span>';

        $type_badge = $p['type'] === 'importer'
            ? ' <span class="badge bg-info text-dark">Importer</span>'
            : '';

        $incompatible_note = !$compatible
            ? '<div class="text-danger small mt-1">Requires Lumora ' . h($p['min_lumora']) . ' or newer.</div>'
            : '';

        $admin_link = '';
        if ($enabled && $p['admin_url'] !== '') {
            $admin_link = '<a href="' . h(lumora_base_url() . $p['admin_url']) . '" class="btn btn-sm btn-outline-secondary me-2">Manage</a>';
        }

        $toggle_action = $enabled ? 'disable' : 'enable';
        $toggle_label  = $enabled ? 'Disable' : 'Enable';
        $toggle_class  = $enabled ? 'btn-outline-danger' : 'btn-outline-primary';
        $toggle_disabled = (!$enabled && !$compatible) ? ' disabled' : '';

        $toggle_form = '<form method="post" action="' . $base . '" class="d-inline">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
            . '<input type="hidden" name="id" value="' . $id_h . '">'
            . '<input type="hidden" name="do" value="' . $toggle_action . '">'
            . '<button type="submit" class="btn btn-sm ' . $toggle_class . '"' . $toggle_disabled . '>' . $toggle_label . '</button>'
            . '</form>';

        $update_btn = '<button type="button" class="btn btn-sm btn-outline-secondary" '
            . 'data-bs-toggle="modal" data-bs-target="#' . h($modal_id) . '">Update</button>';

        // Checkbox for bulk actions (Activate/Deactivate Selected apply to any
        // row; Delete Selected only actually deletes the disabled ones it's
        // given — ajax_plugin_delete.php already reports the rest as errors).
        $checkbox_html = '<input type="checkbox" class="form-check-input lum-plugin-check me-2" '
            . 'value="' . $id_h . '" onchange="lumPluginUpdCount()" aria-label="Select ' . $name_h . '">';

        $delete_btn = '';
        if (!$enabled) {
            $del_conf_h = h(
                'Permanently delete "' . $p['name'] . '"? Its files under plugins/' . $p['id']
                . '/ will be removed from disk. This cannot be undone.'
            );
            $delete_btn = '<button type="button" class="btn btn-sm btn-outline-danger" '
                . 'data-confirm="' . $del_conf_h . '" '
                . 'onclick="lumPluginSingleDelete(' . $id_js . ', this.dataset.confirm)">Delete</button>';
        }

        $rows .= <<<HTML
<div class="lum-adm-stat mb-3">
  <div class="d-flex justify-content-between align-items-start gap-3">
    <div class="d-flex align-items-start gap-2">
      <div class="pt-1">{$checkbox_html}</div>
      <div>
        <h6 class="mb-1">{$name_h} <span class="text-muted small">v{$ver_h}</span> {$badge}{$type_badge}</h6>
        <p class="text-muted small mb-1 lum-plugin-desc">{$desc_h}</p>
        <p class="text-muted small mb-0">By {$author_h}</p>
        {$incompatible_note}
      </div>
    </div>
    <div class="flex-shrink-0 d-flex align-items-center gap-2">
      {$admin_link}{$toggle_form}{$update_btn}{$delete_btn}
    </div>
  </div>
</div>
HTML;

        $modals_html .= <<<HTML
<div class="modal fade" id="{$modal_id}" tabindex="-1" aria-labelledby="{$modal_id}-label" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="{$modal_id}-label">Update "{$name_h}" from ZIP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="{$base}" enctype="multipart/form-data"
            onsubmit="return confirm('This will overwrite the currently-installed files for this plugin. Continue?');">
        <div class="modal-body">
          <p class="text-muted small">The uploaded archive's <code>plugin.json</code> must declare the same id (<code>{$id_h}</code>) as this plugin.</p>
          <input type="file" name="plugin_zip" accept=".zip" required class="form-control">
        </div>
        <div class="modal-footer">
          <input type="hidden" name="action" value="update_plugin">
          <input type="hidden" name="csrf_token" value="{$csrf_h}">
          <input type="hidden" name="id" value="{$id_h}">
          <input type="hidden" name="confirm_overwrite" value="1">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Update from ZIP</button>
        </div>
      </form>
    </div>
  </div>
</div>
HTML;
    }
}

$toolbar_html = '';
if (!empty($plugins)) {
    $ajax_base_js = json_encode(lumora_base_url() . 'admin/');
    $toolbar_html = <<<HTML
<div class="lum-adm-card mb-3 py-2">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <input type="checkbox" id="lum-plugin-check-all-header" onchange="lumPluginSelAll(this.checked)" title="Select / deselect all plugins">
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="lumPluginSelAll(true)">Select All</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="lumPluginSelAll(false)">None</button>
    <span id="lum-plugin-sel-count" class="text-muted small">0 selected</span>
    <div class="vr d-none d-sm-block"></div>
    <button type="button" id="lum-plugin-bulk-enable" class="btn btn-sm btn-outline-primary" disabled onclick="lumPluginBulkToggle('enable')">Activate Selected</button>
    <button type="button" id="lum-plugin-bulk-disable" class="btn btn-sm btn-outline-danger" disabled onclick="lumPluginBulkToggle('disable')">Deactivate Selected</button>
    <div class="vr d-none d-sm-block"></div>
    <button type="button" id="lum-plugin-bulk-delete" class="btn btn-sm btn-outline-danger" disabled onclick="lumPluginBulkDelete()">🗑 Delete Selected</button>
  </div>
  <div id="lum-plugin-bulk-status" class="mt-2 small d-none"></div>
</div>
<script>
var LUM_PLUGIN_CSRF = {$csrf_js};
var LUM_PLUGIN_AJAX = {$ajax_base_js};

function lumPluginGetChecks() {
  return Array.prototype.slice.call(document.querySelectorAll('.lum-plugin-check'));
}

function lumPluginSelAll(state) {
  lumPluginGetChecks().forEach(function(c) { c.checked = state; });
  var hdr = document.getElementById('lum-plugin-check-all-header');
  if (hdr) hdr.checked = state;
  lumPluginUpdCount();
}

function lumPluginUpdCount() {
  var n = lumPluginGetChecks().filter(function(c) { return c.checked; }).length;
  var cntEl = document.getElementById('lum-plugin-sel-count');
  ['lum-plugin-bulk-enable', 'lum-plugin-bulk-disable', 'lum-plugin-bulk-delete'].forEach(function(id) {
    var btn = document.getElementById(id);
    if (btn) btn.disabled = (n === 0);
  });
  if (cntEl) cntEl.textContent = n + ' selected';
}

function lumPluginSelectedIds() {
  return lumPluginGetChecks().filter(function(c) { return c.checked; }).map(function(c) { return c.value; });
}

function lumPluginShowStatus(msg, type) {
  var el = document.getElementById('lum-plugin-bulk-status');
  if (!el) return;
  el.textContent = msg;
  el.className = 'mt-2 small text-' + (type || 'muted');
  el.classList.remove('d-none');
}

function lumPluginPost(endpoint, extraBody, ids, callback) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', LUM_PLUGIN_AJAX + endpoint, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.timeout = 30000;
  xhr.onload = function() {
    if (xhr.status !== 200) { callback({ error: 'Server error ' + xhr.status }, null); return; }
    try { callback(null, JSON.parse(xhr.responseText)); }
    catch (e) { callback({ error: 'Bad server response.' }, null); }
  };
  xhr.onerror = xhr.ontimeout = function() { callback({ error: 'Network error.' }, null); };
  var body = 'csrf_token=' + encodeURIComponent(LUM_PLUGIN_CSRF) + (extraBody || '');
  ids.forEach(function(id) { body += '&' + encodeURIComponent('ids[]') + '=' + encodeURIComponent(id); });
  xhr.send(body);
}

function lumPluginHandleDeleteResult(err, data) {
  var n = lumPluginSelectedIds().length;
  lumPluginUpdCount();
  if (err) { lumPluginShowStatus('Error: ' + err.error, 'danger'); return; }
  var msg = data.deleted + ' plugin' + (data.deleted !== 1 ? 's' : '') + ' deleted.';
  if (data.errors && data.errors.length) msg += ' ' + data.errors.length + ' skipped: ' + data.errors.join(' | ');
  lumPluginShowStatus(msg, (data.errors && data.errors.length) ? 'warning' : 'success');
  if (data.deleted > 0) setTimeout(function() { location.reload(); }, 1400);
}

function lumPluginHandleToggleResult(verb, err, data) {
  lumPluginUpdCount();
  if (err) { lumPluginShowStatus('Error: ' + err.error, 'danger'); return; }
  var msg = data.changed + ' plugin' + (data.changed !== 1 ? 's' : '') + ' ' + verb + '.';
  if (data.errors && data.errors.length) msg += ' ' + data.errors.length + ' skipped: ' + data.errors.join(' | ');
  lumPluginShowStatus(msg, (data.errors && data.errors.length) ? 'warning' : 'success');
  if (data.changed > 0) setTimeout(function() { location.reload(); }, 1400);
}

/** Bulk-delete selected disabled plugins. */
function lumPluginBulkDelete() {
  var ids = lumPluginSelectedIds();
  if (ids.length === 0) return;
  if (!confirm('Permanently delete ' + ids.length + ' plugin' + (ids.length !== 1 ? 's' : '') + '? Their files will be removed from disk.\\n\\nThis cannot be undone.')) return;
  document.getElementById('lum-plugin-bulk-delete').disabled = true;
  lumPluginShowStatus('Deleting…', 'muted');
  lumPluginPost('ajax_plugin_delete.php', '', ids, lumPluginHandleDeleteResult);
}

/** Delete a single disabled plugin. Called via onclick on each row's Delete button. */
function lumPluginSingleDelete(pluginId, confirmMsg) {
  if (!confirm(confirmMsg || 'Delete this plugin? This cannot be undone.')) return;
  lumPluginShowStatus('Deleting…', 'muted');
  lumPluginPost('ajax_plugin_delete.php', '', [pluginId], lumPluginHandleDeleteResult);
}

/** Bulk-activate or bulk-deactivate selected plugins. */
function lumPluginBulkToggle(doAction) {
  var ids = lumPluginSelectedIds();
  if (ids.length === 0) return;
  var verb = doAction === 'enable' ? 'activated' : 'deactivated';
  document.getElementById('lum-plugin-bulk-enable').disabled = true;
  document.getElementById('lum-plugin-bulk-disable').disabled = true;
  lumPluginShowStatus((doAction === 'enable' ? 'Activating…' : 'Deactivating…'), 'muted');
  lumPluginPost('ajax_plugin_bulk_toggle.php', '&do=' + encodeURIComponent(doAction), ids, function(err, data) {
    lumPluginHandleToggleResult(verb, err, data);
  });
}
</script>
HTML;
}

$content = '<p class="text-muted">Feature plugins extend Lumora by hooking into core behaviour '
    . '(pageview logging, admin nav items, dashboard widgets) without modifying any core files. '
    . 'An <strong>Importer</strong>-badged plugin instead runs on demand from '
    . '<a href="' . h(lumora_base_url() . 'admin/migrate.php') . '">Admin &rarr; Import</a> — disabling it here just '
    . 'hides it there, for one you have already used and do not need again right now. '
    . 'Disabling a plugin stops it from running but never deletes its data.</p>'
    . $toolbar_html
    . $rows
    . $modals_html
    . '<div class="lum-adm-card mt-4">'
    . '<h5 class="mb-1">Install a Plugin</h5>'
    . '<p class="text-muted small">Upload a <code>.zip</code> archive containing a plugin folder (must include a <code>plugin.json</code>). '
    . 'If the archive wraps everything in a single top-level folder, it is flattened automatically. '
    . 'The destination folder name is the archive\'s own declared plugin id.</p>'
    . '<form method="post" action="' . $base . '" enctype="multipart/form-data" class="d-flex gap-2 align-items-center flex-wrap">'
    . '<input type="hidden" name="action" value="install_plugin">'
    . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
    . '<input type="file" name="plugin_zip" accept=".zip" required class="form-control" style="max-width:320px">'
    . '<button type="submit" class="btn btn-outline-secondary">&#x2B06; Upload &amp; Install</button>'
    . '</form>'
    . '</div>';

lum_admin_page('Plugins', $content, 'plugins');
