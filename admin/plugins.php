<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Plugins
 *
 * Lists every discovered "feature" plugin — self-contained add-ons under
 * plugins/*&#47;plugin.json that hook into core via HookService — and lets
 * an admin enable, disable, or (once disabled) permanently delete each one.
 * Deletion is AJAX-only via ajax_plugin_delete.php and
 * PluginService::deletePlugin().
 *
 * The older "importer" plugin type (Coppermine, etc.) is unaffected: those
 * are still discovered and run on-demand from admin/migrate.php, since they
 * don't hook into every page load and have no enable/disable state.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 * @see        PluginService Discovery + enable/disable/delete logic this page drives.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('site_configuration');

$base = h(lumora_base_url() . 'admin/plugins.php');

// ── Handle enable/disable ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    $id     = (string) ($_POST['id'] ?? '');
    $action = (string) ($_POST['do'] ?? '');

    $plugin = null;
    foreach (PluginService::discoverFeaturePlugins() as $p) {
        if ($p['id'] === $id) { $plugin = $p; break; }
    }

    if ($plugin === null) {
        lum_flash('Plugin not found.', 'danger');
    } elseif ($action === 'enable') {
        if (!PluginService::isCompatible($plugin['min_lumora'])) {
            lum_flash('This plugin requires Lumora ' . $plugin['min_lumora'] . ' or newer.', 'danger');
        } elseif (PluginService::enablePlugin($plugin)) {
            lum_flash('"' . $plugin['name'] . '" enabled.');
        } else {
            lum_flash('"' . $plugin['name'] . '" could not be activated — see the server error log for details.', 'danger');
        }
    } elseif ($action === 'disable') {
        PluginService::disablePlugin($plugin);
        lum_flash('"' . $plugin['name'] . '" disabled.');
    }

    lumora_redirect($base);
}

// ── List ──────────────────────────────────────────────────────────────────────
$plugins = PluginService::discoverFeaturePlugins();
$csrf_h  = h(lumora_csrf_token());
$csrf_js = json_encode(lumora_csrf_token());

$rows           = '';
$any_deletable  = false;
if (empty($plugins)) {
    $rows = '<p class="text-muted">No feature plugins found in <code>plugins/</code>.</p>';
} else {
    foreach ($plugins as $p) {
        $enabled    = PluginService::isEnabled($p['id']);
        $compatible = PluginService::isCompatible($p['min_lumora']);
        $name_h     = h($p['name']);
        $ver_h      = h($p['version']);
        $author_h   = h($p['author']);
        $desc_h     = h($p['description']);
        $id_h       = h($p['id']);
        $id_js      = json_encode($p['id']);

        $badge = $enabled
            ? '<span class="badge bg-success">Enabled</span>'
            : '<span class="badge bg-secondary">Disabled</span>';

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

        // Checkbox + per-row Delete button — disabled plugins only.
        $checkbox_html = '';
        $delete_btn    = '';
        if (!$enabled) {
            $any_deletable = true;
            $checkbox_html = '<input type="checkbox" class="form-check-input lum-plugin-check me-2" '
                . 'value="' . $id_h . '" onchange="lumPluginUpdCount()" aria-label="Select ' . $name_h . '">';

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
        <h6 class="mb-1">{$name_h} <span class="text-muted small">v{$ver_h}</span> {$badge}</h6>
        <p class="text-muted small mb-1 lum-plugin-desc">{$desc_h}</p>
        <p class="text-muted small mb-0">By {$author_h}</p>
        {$incompatible_note}
      </div>
    </div>
    <div class="flex-shrink-0 d-flex align-items-center gap-2">
      {$admin_link}{$toggle_form}{$delete_btn}
    </div>
  </div>
</div>
HTML;
    }
}

$toolbar_html = '';
if ($any_deletable) {
    $ajax_base_js = json_encode(lumora_base_url() . 'admin/');
    $toolbar_html = <<<HTML
<div class="lum-adm-card mb-3 py-2">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <input type="checkbox" id="lum-plugin-check-all-header" onchange="lumPluginSelAll(this.checked)" title="Select / deselect all disabled plugins">
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="lumPluginSelAll(true)">Select All</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="lumPluginSelAll(false)">None</button>
    <span id="lum-plugin-sel-count" class="text-muted small">0 selected</span>
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
  var delBtn = document.getElementById('lum-plugin-bulk-delete');
  if (cntEl) cntEl.textContent = n + ' selected';
  if (delBtn) delBtn.disabled = (n === 0);
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

function lumPluginPost(ids, callback) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', LUM_PLUGIN_AJAX + 'ajax_plugin_delete.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.timeout = 30000;
  xhr.onload = function() {
    if (xhr.status !== 200) { callback({ error: 'Server error ' + xhr.status }, null); return; }
    try { callback(null, JSON.parse(xhr.responseText)); }
    catch (e) { callback({ error: 'Bad server response.' }, null); }
  };
  xhr.onerror = xhr.ontimeout = function() { callback({ error: 'Network error.' }, null); };
  var body = 'csrf_token=' + encodeURIComponent(LUM_PLUGIN_CSRF);
  ids.forEach(function(id) { body += '&' + encodeURIComponent('ids[]') + '=' + encodeURIComponent(id); });
  xhr.send(body);
}

function lumPluginHandleResult(err, data) {
  var n = lumPluginSelectedIds().length;
  var delBtn = document.getElementById('lum-plugin-bulk-delete');
  if (delBtn) delBtn.disabled = (n === 0);
  if (err) { lumPluginShowStatus('Error: ' + err.error, 'danger'); return; }
  var msg = data.deleted + ' plugin' + (data.deleted !== 1 ? 's' : '') + ' deleted.';
  if (data.errors && data.errors.length) msg += ' ' + data.errors.length + ' skipped: ' + data.errors.join(' | ');
  lumPluginShowStatus(msg, (data.errors && data.errors.length) ? 'warning' : 'success');
  if (data.deleted > 0) setTimeout(function() { location.reload(); }, 1400);
}

/** Bulk-delete selected disabled plugins. */
function lumPluginBulkDelete() {
  var ids = lumPluginSelectedIds();
  if (ids.length === 0) return;
  if (!confirm('Permanently delete ' + ids.length + ' plugin' + (ids.length !== 1 ? 's' : '') + '? Their files will be removed from disk.\\n\\nThis cannot be undone.')) return;
  document.getElementById('lum-plugin-bulk-delete').disabled = true;
  lumPluginShowStatus('Deleting…', 'muted');
  lumPluginPost(ids, lumPluginHandleResult);
}

/** Delete a single disabled plugin. Called via onclick on each row's Delete button. */
function lumPluginSingleDelete(pluginId, confirmMsg) {
  if (!confirm(confirmMsg || 'Delete this plugin? This cannot be undone.')) return;
  lumPluginShowStatus('Deleting…', 'muted');
  lumPluginPost([pluginId], lumPluginHandleResult);
}
</script>
HTML;
}

$content = '<p class="text-muted">Feature plugins extend Lumora by hooking into core behaviour '
    . '(pageview logging, admin nav items, dashboard widgets) without modifying any core files. '
    . 'Disabling a plugin stops it from running but never deletes its data.</p>'
    . $toolbar_html
    . $rows;

lum_admin_page('Plugins', $content, 'plugins');
