<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Bootstrap
 *
 * Required on every request once this plugin is enabled — see
 * PluginService::loadEnabledPlugins(). This plugin's request-time work
 * happens in thumb.php, a standalone entry point reached directly via an
 * .htaccess rewrite rule rather than through a normal Lumora page load, so
 * it never goes through this bootstrap file at all. This file's job is to
 * make the enabled/disabled toggle in Admin → Plugins mean something
 * (thumb.php checks PluginService::isEnabled() itself before generating
 * anything) and to register this plugin's settings page in the admin nav.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.18.5
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

require_once __DIR__ . '/version.php';

// ── Admin sidebar nav item ───────────────────────────────────────────────────
HookService::addFilter('admin_nav_sections', static function (array $sections): array {
    $item = [
        'icon'       => '🖼️',
        'label'      => 'On-Demand Thumbnails',
        'href'       => h(lumora_base_url() . 'plugins/on-demand-thumbnails/admin/settings.php'),
        'permission' => 'site_configuration',
    ];

    foreach ($sections as &$section) {
        if ($section['label'] === 'Maintenance') {
            $section['items']['on_demand_thumbnails'] = $item;
            return $sections;
        }
    }
    unset($section);

    $sections[] = ['label' => 'Maintenance', 'items' => ['on_demand_thumbnails' => $item]];
    return $sections;
});
