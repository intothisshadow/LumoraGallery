<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Lumora Press Shortcodes Plugin — Multi-Album Shortcode Admin Page
 *
 * Lets an admin check off several albums and get back one combined
 * [lumora_gallery_album album_id="1,2,3"] shortcode, instead of typing album
 * IDs by hand — the companion Lumora Press plugin's own shortcode renderer
 * already accepts a comma-separated album_id list. A plain GET form: nothing
 * here writes to the database, so no CSRF token is needed.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.0
 */
define('LUMORA_ENTRY', true);

// This file is at plugins/lumora-press-shortcodes/admin/multi-album.php.
$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once $_lumora_root . '/admin/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/LumoraPressShortcodesService.php';

lumora_require_login();

if (!PluginService::isEnabled('lumora-press-shortcodes')) {
    http_response_code(404);
    exit('Not found.');
}

$selected_ids = array_values(array_unique(array_map(
    static fn (mixed $id): int => lumora_int($id, 0, 0),
    (array) ($_GET['album_id'] ?? []),
)));
$selected_ids = array_filter($selected_ids, static fn (int $id): bool => $id > 0);

$shortcode_html = '';
if ($selected_ids !== []) {
    $shortcode = LumoraPressShortcodesService::buildMultiAlbumShortcode($selected_ids);
    $shortcode_html = LumoraPressShortcodesService::renderAdminField('Multi-Album Shortcode', $shortcode);
}

$albums = GalleryService::getAllAdminAlbumsGrouped();

$rows_html = '';
if ($albums === []) {
    $rows_html = '<p class="text-muted">No albums yet — create one under Admin → Albums first.</p>';
} else {
    $rows_html .= '<div class="list-group">';
    foreach ($albums as $album) {
        $id_i       = (int) $album['id'];
        $checked    = in_array($id_i, $selected_ids, true) ? ' checked' : '';
        $title_h    = h((string) $album['title']);
        $cat_h      = h((string) ($album['cat_name'] ?? 'Uncategorized'));
        $count      = (int) $album['image_count'];

        $rows_html .= <<<HTML
<label class="list-group-item d-flex align-items-center gap-2">
  <input class="form-check-input flex-shrink-0" type="checkbox" name="album_id[]" value="{$id_i}"{$checked}>
  <span class="flex-grow-1">{$title_h}</span>
  <span class="text-muted small">{$cat_h}</span>
  <span class="badge bg-secondary rounded-pill">{$count}</span>
</label>
HTML;
    }
    $rows_html .= '</div>';
}

$content = <<<HTML
<p class="form-text mb-3">
  Check off two or more albums and generate a single shortcode that combines
  their images — paste it into a Lumora Press post/page, same as the
  per-album shortcode already shown on each album's own edit page. Requires
  that project's own Lumora Gallery Shortcodes plugin.
</p>
{$shortcode_html}
<form method="get">
  {$rows_html}
  <button type="submit" class="btn btn-primary mt-3">Generate Shortcode</button>
</form>
HTML;

lum_admin_page('Multi-Album Shortcode', $content, 'lumora_press_shortcodes_multi_album');
