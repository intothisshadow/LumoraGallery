<?php

declare(strict_types=1);
/**
 * Lumora Gallery — Lumora Press Shortcodes Plugin — Bootstrap
 *
 * Required on every request once this plugin is enabled — see
 * PluginService::loadEnabledPlugins(). Registers this plugin's hooks and
 * nothing else: every callback here is pure text/HTML generation via
 * LumoraPressShortcodesService, no database writes.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.18.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/LumoraPressShortcodesService.php';

// ── Admin: Album edit form ───────────────────────────────────────────────────
HookService::addFilter('admin_album_edit_extra_fields', static function (string $html, array $album): string {
    $shortcode = LumoraPressShortcodesService::buildAlbumShortcode($album);
    return $html . LumoraPressShortcodesService::renderAdminField('Lumora Press Shortcode', $shortcode);
});

// ── Admin: Image edit form ───────────────────────────────────────────────────
HookService::addFilter('admin_image_edit_extra_fields', static function (string $html, array $image): string {
    $shortcode = LumoraPressShortcodesService::buildImageShortcode($image);
    return $html . LumoraPressShortcodesService::renderAdminField('Lumora Press Shortcode', $shortcode);
});

// ── Public: Album page info box ──────────────────────────────────────────────
HookService::addFilter('public_album_info_html', static function (string $html, array $album): string {
    $shortcode = LumoraPressShortcodesService::buildAlbumShortcode($album);
    return $html . LumoraPressShortcodesService::renderPublicAlbumBox($shortcode);
});

// ── Public: Per-image shortcode (shown in the lightbox info panel) ──────────
HookService::addFilter('public_image_shortcode', static function (string $value, array $image): string {
    return LumoraPressShortcodesService::buildImageShortcode($image);
});
