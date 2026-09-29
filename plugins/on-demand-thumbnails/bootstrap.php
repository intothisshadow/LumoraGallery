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
 * anything), to register this plugin's settings page in the admin nav, and
 * to offer thumbnail-skipping modes on Admin → Batch Add.
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
require_once __DIR__ . '/OnDemandThumbnailService.php';

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

// ── Batch Add: thumbnail mode ────────────────────────────────────────────────
HookService::addFilter('admin_batch_add_extra_fields', static function (string $html): string {
    $default   = OnDemandThumbnailService::batchDefaultMode();
    $installed = OnDemandThumbnailService::isHtaccessInstalled();
    $modes     = [
        OnDemandThumbnailService::BATCH_MODE_ALL         => 'Generate all thumbnails',
        OnDemandThumbnailService::BATCH_MODE_EVERY_OTHER => 'Generate every other thumbnail',
        OnDemandThumbnailService::BATCH_MODE_NONE        => 'Generate no thumbnails',
    ];

    // Skipping needs the rewrite rule, so without it only "all" is selectable.
    $selected = $installed ? $default : OnDemandThumbnailService::BATCH_MODE_ALL;

    $radios = '';
    foreach ($modes as $value => $label) {
        $disabled = ($value !== OnDemandThumbnailService::BATCH_MODE_ALL && !$installed) ? ' disabled' : '';
        $checked  = $value === $selected ? ' checked' : '';
        $id       = 'lum-odt-batch-' . $value;
        $radios  .= '<div class="form-check">'
            . '<input class="form-check-input" type="radio" name="batch_opt[odt_thumbs]" id="' . h($id) . '" value="' . h($value) . '"' . $checked . $disabled . '>'
            . '<label class="form-check-label" for="' . h($id) . '">' . h($label) . '</label></div>';
    }

    $note = $installed
        ? 'Skipped thumbnails are created the first time they are viewed.'
        : 'The other options need the albums/.htaccess rule — install it on '
            . '<a href="' . h(lumora_base_url() . 'plugins/on-demand-thumbnails/admin/settings.php#htaccess') . '">On-Demand Thumbnails</a> first.';

    return $html
        . '<div class="fw-semibold mb-1">Thumbnails</div>'
        . $radios
        . '<div class="form-text">' . $note . '</div>';
});

// ── Batch Add: per-image thumbnail decision ──────────────────────────────────
HookService::addFilter('batch_add_generate_thumb', static function (bool $generate, string $filename, string $folder, int $album_id, array $options): bool {
    static $installed = null;

    if (!$generate) return false;

    $mode = OnDemandThumbnailService::normalizeBatchMode((string) ($options['odt_thumbs'] ?? OnDemandThumbnailService::BATCH_MODE_ALL));
    if ($mode === OnDemandThumbnailService::BATCH_MODE_ALL) return true;

    // Without the rewrite rule a skipped thumbnail would be a broken image.
    $installed ??= OnDemandThumbnailService::isHtaccessInstalled();
    if (!$installed) return true;

    return OnDemandThumbnailService::shouldGenerateBatchThumb($mode, lumora_album_path($folder), $filename);
}, 10);
