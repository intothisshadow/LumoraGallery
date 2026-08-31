<?php

declare(strict_types=1);
/**
 * Lumora Gallery — Lumora Press Shortcodes Plugin — Shortcode Builder
 *
 * Pure text/HTML generation only — no database access, no live connection
 * to any Lumora Press install. Every method here is a stateless formatter
 * over the album/image row data already loaded by the core admin/public
 * pages that call into this plugin's hooks (see bootstrap.php).
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

class LumoraPressShortcodesService
{
    /**
     * @param array{id?: int|string} $album
     */
    public static function buildAlbumShortcode(array $album): string
    {
        $album_id = (int) ($album['id'] ?? 0);
        return '[lumora_gallery_album album_id="' . $album_id . '"]';
    }

    /**
     * @param array{id?: int|string} $image
     */
    public static function buildImageShortcode(array $image): string
    {
        $image_id = (int) ($image['id'] ?? 0);
        return '[lumora_gallery_album image_id="' . $image_id . '"]';
    }

    /**
     * A labelled, read-only, click-to-copy field for the admin edit forms
     * (Bootstrap admin styling — see the surrounding admin/albums.php and
     * admin/images.php forms this is injected into).
     */
    public static function renderAdminField(string $label, string $shortcode): string
    {
        $label_h     = h($label);
        $shortcode_h = h($shortcode);

        return <<<HTML
<div class="lum-adm-card mb-4">
  <label class="form-label fw-semibold">{$label_h}</label>
  <div class="input-group">
    <input type="text" class="form-control font-monospace" value="{$shortcode_h}" readonly
           onclick="this.select()">
    <button type="button" class="btn btn-outline-secondary"
            onclick="this.previousElementSibling.select();document.execCommand('copy');this.textContent='Copied!';setTimeout(()=>{this.textContent='Copy';},1500);">Copy</button>
  </div>
  <div class="form-text">Paste into a Lumora Press post/page — requires that project's own Lumora Gallery Shortcodes plugin (LPP-015).</div>
</div>
HTML;
    }

    /**
     * The equivalent public-facing box shown on the album page (see
     * album.php), gated to logged-in users only by the caller.
     */
    public static function renderPublicAlbumBox(string $shortcode): string
    {
        $shortcode_h = h($shortcode);

        return <<<HTML
<div class="lum-shortcode-box">
  <span class="lum-shortcode-label">Lumora Press Shortcode</span>
  <div class="lum-shortcode-field">
    <input type="text" class="lum-shortcode-input" value="{$shortcode_h}" readonly onclick="this.select()">
    <button type="button" class="lum-shortcode-copy"
            onclick="this.previousElementSibling.select();document.execCommand('copy');this.nextElementSibling.textContent='Copied!';setTimeout(()=>{this.nextElementSibling.textContent='';},1500);">Copy</button>
    <span class="lum-shortcode-feedback" aria-live="polite"></span>
  </div>
</div>
HTML;
    }
}
