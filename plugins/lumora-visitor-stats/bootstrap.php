<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Visitor Stats Plugin — Bootstrap
 *
 * Required on every request once this plugin is enabled — see
 * PluginService::loadEnabledPlugins(). Registers this plugin's hooks and
 * nothing else: no database writes happen here, only in VisitorStatsService
 * (called from the 'lumora_pageview' action below) and activate.php (run
 * once, on enable).
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

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/VisitorStatsService.php';

// ── Log every public pageview ────────────────────────────────────────────────
HookService::addAction('lumora_pageview', [VisitorStatsService::class, 'logPageview']);

// ── Admin sidebar nav item ───────────────────────────────────────────────────
HookService::addFilter('admin_nav_sections', static function (array $sections): array {
    $item = [
        'icon'       => '📈',
        'label'      => 'Visitor Stats',
        'href'       => h(lumora_base_url() . 'plugins/lumora-visitor-stats/admin/stats.php'),
        'permission' => null,
    ];

    foreach ($sections as &$section) {
        if ($section['label'] === null) {
            $section['items']['lumora_visitor_stats'] = $item;
            return $sections;
        }
    }
    unset($section);

    // No unlabeled top-level section found (unexpected, but stay defensive) —
    // prepend one of our own rather than dropping the nav item silently.
    array_unshift($sections, ['label' => null, 'items' => ['lumora_visitor_stats' => $item]]);
    return $sections;
});

// ── Dashboard mini widget ────────────────────────────────────────────────────
HookService::addFilter('admin_dashboard_widgets_html', static function (string $html): string {
    $summary   = VisitorStatsService::getVisitSummary();
    $vs_today  = number_format($summary['today']);
    $vs_week   = number_format($summary['week']);
    $stats_url = h(lumora_base_url() . 'plugins/lumora-visitor-stats/admin/stats.php');

    return $html . <<<HTML
<div class="d-flex justify-content-between align-items-center mb-2 mt-4">
  <h5 class="mb-0">Visitor Stats — Last 7 Days</h5>
  <a href="{$stats_url}" class="btn btn-sm btn-outline-primary">View Full Stats</a>
</div>
<div class="row row-cols-2 g-3 mb-4">
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$vs_today}</div>
      <div class="lum-adm-stat-lbl">Views Today</div>
    </div>
  </div>
  <div class="col">
    <div class="lum-adm-stat text-center">
      <div class="lum-adm-stat-num">{$vs_week}</div>
      <div class="lum-adm-stat-lbl">Views This Week</div>
    </div>
  </div>
</div>
HTML;
});
