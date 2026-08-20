<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Dashboard
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
lumora_require_login();

$stats   = get_gallery_stats();
$base    = h(lumora_base_url() . 'admin/');
$latest  = get_latest_images(6);

// ── Update notice (cache-only — no HTTP call) ────────────────────────────────
$update_notice = '';
$upd = UpdateService::getCachedStatus();
if ($upd['status'] === 'update_available' && $upd['latest'] !== null) {
    $upd_ver  = h($upd['latest']);
    $upd_dl   = $upd['download_url']  !== null ? h($upd['download_url'])  : '';
    $upd_cl   = $upd['changelog_url'] !== null ? h($upd['changelog_url']) : '';
    $upd_btns = '';
    if ($upd_cl) {
        $upd_btns .= '<a href="' . $upd_cl . '" target="_blank" rel="noopener"'
                   . ' class="btn btn-sm btn-outline-secondary ms-2">View Changelog</a>';
    }
    if ($upd_dl) {
        $upd_btns .= '<a href="' . $upd_dl . '" target="_blank" rel="noopener"'
                   . ' class="btn btn-sm btn-primary ms-2">Download ' . $upd_ver . '</a>';
    }
    $update_notice = '<div class="alert alert-info alert-dismissible fade show py-2 mb-4" role="alert">'
        . '🔔 <strong>Lumora ' . $upd_ver . ' is available.</strong> '
        . 'You are running ' . h(LUMORA_VERSION) . '.'
        . $upd_btns
        . ' <a href="' . $base . 'update.php" class="btn btn-sm btn-outline-secondary ms-2">Details</a>'
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}

// ── Migration notice (cache-only — no HTTP call) ─────────────────────────────
$migration_notice = '';
if (SchemaService::hasPendingMigrations()) {
    $updates_url_h    = h(lumora_base_url() . 'admin/update.php');
    $migration_notice = '<div class="alert alert-warning alert-dismissible fade show py-2 mb-4" role="alert">'
        . '⚠ <strong>Database update required.</strong> '
        . 'Lumora has schema migrations that need to be applied.'
        . ' <a href="' . $updates_url_h . '" class="btn btn-sm btn-warning ms-2">Run Database Update</a>'
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}

// ── Stat cards ─────────────────────────────────────────────────────────────
$s_cat  = number_format($stats['categories']);
$s_alb  = number_format($stats['albums']);
$s_img  = number_format($stats['images']);
$s_hits = number_format($stats['total_hits']);

$stat_html = <<<HTML
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_cat}</div>
      <div class="lum-adm-stat-lbl">Categories</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_alb}</div>
      <div class="lum-adm-stat-lbl">Albums</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_img}</div>
      <div class="lum-adm-stat-lbl">Images</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_hits}</div>
      <div class="lum-adm-stat-lbl">Total Views</div>
    </div>
  </div>
</div>
HTML;

// ── Quick links (filtered to the permissions the current role holds) ────────
$ql_buttons = [];
if (lumora_has_permission('batch_add')) {
    $ql_buttons[] = '<a href="' . $base . 'batch.php" class="btn btn-outline-primary w-100 py-3">⬆️ Batch Add Images</a>';
}
if (lumora_has_permission('manage_albums')) {
    $ql_buttons[] = '<a href="' . $base . 'categories.php?action=new" class="btn btn-outline-secondary w-100 py-3">📁 New Category</a>';
    $ql_buttons[] = '<a href="' . $base . 'albums.php?action=new" class="btn btn-outline-secondary w-100 py-3">🖼️ New Album</a>';
}
if (lumora_has_permission('site_configuration')) {
    $ql_buttons[] = '<a href="' . $base . 'config.php" class="btn btn-outline-secondary w-100 py-3">⚙️ Configuration</a>';
}

$ql = '';
if (!empty($ql_buttons)) {
    $ql = '<div class="row g-3 mb-4">';
    foreach ($ql_buttons as $btn_html) {
        $ql .= '<div class="col-sm-6 col-lg-3">' . $btn_html . '</div>';
    }
    $ql .= '</div>';
}

// ── Latest images preview ────────────────────────────────────────────────────
$latest_html = '';
if (!empty($latest)) {
    $latest_html = '<h5 class="mb-3">Latest Additions</h5><div class="d-flex flex-wrap gap-2">';
    foreach ($latest as $img) {
        $thumb = h(image_thumb_url($img));
        $orig  = h(image_original_url($img));
        $title = h($img['title'] ?: pathinfo($img['filename'], PATHINFO_FILENAME));
        $latest_html .= '<a href="' . $orig . '" target="_blank" rel="noopener" title="' . $title . '">'
            . '<img src="' . $thumb . '" width="80" height="80" style="object-fit:cover;border-radius:.3rem;border:1px solid #dee2e6" alt="' . $title . '" loading="lazy">'
            . '</a>';
    }
    $latest_html .= '</div>';
}

// ── Plugin dashboard widgets ──────────────────────────────────────────────────
// Feature plugins (e.g. a visitor-stats plugin) can inject their own widget
// HTML here without any core changes — see HookService/PluginService.
$plugin_widgets_html = HookService::applyFilters('admin_dashboard_widgets_html', '');

$content = $update_notice . $migration_notice . $stat_html . $ql . $plugin_widgets_html . $latest_html;
lum_admin_page('Dashboard', $content, 'dashboard');
