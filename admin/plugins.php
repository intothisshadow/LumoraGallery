<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Plugins
 *
 * Lists every discovered "feature" plugin (LG-045) — self-contained add-ons
 * under plugins/*&#47;plugin.json that hook into core via HookService rather
 * than patching it — and lets an admin enable or disable each one.
 *
 * The older "importer" plugin type (Coppermine, etc.) is unaffected by this
 * page: those are still discovered and run on-demand from admin/migrate.php
 * exactly as before, since they don't hook into every page load and have no
 * enable/disable state of their own.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 * @see        PluginService Discovery + enable/disable logic this page drives.
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

$rows = '';
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

        $rows .= <<<HTML
<div class="lum-adm-stat mb-3">
  <div class="d-flex justify-content-between align-items-start gap-3">
    <div>
      <h6 class="mb-1">{$name_h} <span class="text-muted small">v{$ver_h}</span> {$badge}</h6>
      <p class="text-muted small mb-1 lum-plugin-desc">{$desc_h}</p>
      <p class="text-muted small mb-0">By {$author_h}</p>
      {$incompatible_note}
    </div>
    <div class="flex-shrink-0 d-flex align-items-center">
      {$admin_link}{$toggle_form}
    </div>
  </div>
</div>
HTML;
    }
}

$content = '<p class="text-muted">Feature plugins extend Lumora by hooking into core behaviour '
    . '(pageview logging, admin nav items, dashboard widgets) without modifying any core files. '
    . 'Disabling a plugin stops it from running but never deletes its data.</p>'
    . $rows;

lum_admin_page('Plugins', $content, 'plugins');
