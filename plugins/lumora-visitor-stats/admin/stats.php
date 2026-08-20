<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Visitor Stats Plugin — Admin Page
 *
 * Jetpack-style traffic overview: summary totals, a daily-views bar chart
 * over a selectable range, top images/albums, top referrers, and the
 * current Who-Is-Online numbers (via core's GalleryService::getOnlineStats()
 * — that feature is core, not part of this plugin).
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 */
define('LUMORA_ENTRY', true);

// This file is at plugins/lumora-visitor-stats/admin/stats.php.
$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once $_lumora_root . '/admin/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/VisitorStatsService.php';

lumora_require_login();

if (!PluginService::isEnabled('lumora-visitor-stats')) {
    http_response_code(404);
    exit('Not found.');
}

$plugin_admin_base = h(lumora_base_url() . 'plugins/lumora-visitor-stats/admin/stats.php');

// ── Range selector ────────────────────────────────────────────────────────────
$days = lumora_int($_GET['days'] ?? 30, 30, 1);
if (!in_array($days, [7, 30, 90], true)) {
    $days = 30;
}

$summary = VisitorStatsService::getVisitSummary();
$series  = VisitorStatsService::getVisitsOverTime($days);
$top_img = VisitorStatsService::getTopImages($days, 5);
$top_alb = VisitorStatsService::getTopAlbums($days, 5);
$top_ref = VisitorStatsService::getTopReferrers($days, 5);
$online  = GalleryService::getOnlineStats();

// ── Summary cards ─────────────────────────────────────────────────────────────
$s_today = number_format($summary['today']);
$s_week  = number_format($summary['week']);
$s_month = number_format($summary['month']);
$s_all   = number_format($summary['all_time']);

$summary_html = <<<HTML
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_today}</div>
      <div class="lum-adm-stat-lbl">Views Today</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_week}</div>
      <div class="lum-adm-stat-lbl">Last 7 Days</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_month}</div>
      <div class="lum-adm-stat-lbl">Last 30 Days</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$s_all}</div>
      <div class="lum-adm-stat-lbl">All-Time Views</div>
    </div>
  </div>
</div>
HTML;

// ── Range tabs ─────────────────────────────────────────────────────────────────
$range_tabs = '<div class="btn-group mb-3" role="group">';
foreach ([7 => '7 Days', 30 => '30 Days', 90 => '90 Days'] as $d => $label) {
    $active_cls = $d === $days ? ' active' : '';
    $range_tabs .= '<a href="' . $plugin_admin_base . '?days=' . $d . '" class="btn btn-sm btn-outline-primary' . $active_cls . '">' . $label . '</a>';
}
$range_tabs .= '</div>';

// ── Chart (Bootstrap progress bars — no core CSS or external JS needed) ───────
$max = 1;
foreach ($series as $point) {
    $max = max($max, $point['count']);
}

$chart_rows = '';
foreach ($series as $point) {
    $pct   = (int) round(($point['count'] / $max) * 100);
    $label = date('M j', strtotime($point['date']));
    $chart_rows .= '<div class="d-flex align-items-center gap-2 mb-1">'
        . '<div class="text-muted small" style="width:4.5rem;flex-shrink:0">' . h($label) . '</div>'
        . '<div class="progress flex-grow-1" style="height:1rem">'
        . '<div class="progress-bar" role="progressbar" style="width:' . max(2, $pct) . '%" '
        . 'aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"></div>'
        . '</div>'
        . '<div class="small text-end" style="width:3rem;flex-shrink:0">' . number_format($point['count']) . '</div>'
        . '</div>';
}

$chart_html = '<h5 class="mb-3">Views Over Time</h5>' . $chart_rows;

// ── Top lists ─────────────────────────────────────────────────────────────────
$img_items = '';
if (empty($top_img)) {
    $img_items = '<li class="list-group-item text-muted small">No image views yet in this range.</li>';
} else {
    foreach ($top_img as $row) {
        $title = h($row['title'] !== '' ? $row['title'] : $row['filename']);
        $url   = h(lumora_base_url() . 'admin/images.php?action=edit&id=' . (int) $row['id'] . '&album=' . (int) $row['album_id']);
        $img_items .= '<li class="list-group-item d-flex justify-content-between align-items-center">'
            . '<a class="text-truncate" href="' . $url . '">' . $title . '</a>'
            . '<span class="badge bg-primary rounded-pill">' . number_format((int) $row['views']) . '</span></li>';
    }
}

$alb_items = '';
if (empty($top_alb)) {
    $alb_items = '<li class="list-group-item text-muted small">No album views yet in this range.</li>';
} else {
    foreach ($top_alb as $row) {
        $url = h(lumora_base_url() . 'admin/albums.php?action=edit&id=' . (int) $row['id']);
        $alb_items .= '<li class="list-group-item d-flex justify-content-between align-items-center">'
            . '<a class="text-truncate" href="' . $url . '">' . h($row['title']) . '</a>'
            . '<span class="badge bg-primary rounded-pill">' . number_format((int) $row['views']) . '</span></li>';
    }
}

$ref_items = '';
if (empty($top_ref)) {
    $ref_items = '<li class="list-group-item text-muted small">No external referrers yet in this range.</li>';
} else {
    foreach ($top_ref as $row) {
        $ref_items .= '<li class="list-group-item d-flex justify-content-between align-items-center">'
            . '<span class="text-truncate">' . h($row['host']) . '</span>'
            . '<span class="badge bg-primary rounded-pill">' . number_format((int) $row['count']) . '</span></li>';
    }
}

$lists_html = <<<HTML
<div class="row g-3 mt-1">
  <div class="col-md-4">
    <h6 class="mb-2">Top Images</h6>
    <ul class="list-group list-group-flush">{$img_items}</ul>
  </div>
  <div class="col-md-4">
    <h6 class="mb-2">Top Albums</h6>
    <ul class="list-group list-group-flush">{$alb_items}</ul>
  </div>
  <div class="col-md-4">
    <h6 class="mb-2">Top Referrers</h6>
    <ul class="list-group list-group-flush">{$ref_items}</ul>
  </div>
</div>
HTML;

// ── Who Is Online ─────────────────────────────────────────────────────────────
$online_html = <<<HTML
<div class="row row-cols-2 g-3 mt-1 mb-4">
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$online['online']}</div>
      <div class="lum-adm-stat-lbl">Online Now</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$online['record_count']}</div>
      <div class="lum-adm-stat-lbl">Online Record</div>
    </div>
  </div>
</div>
HTML;

$content = $summary_html . $range_tabs . '<div class="lum-adm-stat mb-4">' . $chart_html . '</div>'
    . $lists_html . '<h5 class="mt-4 mb-3">Who Is Online</h5>' . $online_html;

lum_admin_page('Visitor Stats', $content, 'lumora_visitor_stats');
