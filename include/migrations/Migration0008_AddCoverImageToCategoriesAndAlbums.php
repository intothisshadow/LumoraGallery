<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0008
 *
 * Adds the `cover_image` column to `{PREFIX}categories` and `{PREFIX}albums`
 * (DB version 14):
 *
 *   cover_image  varchar(255)  NULL DEFAULT NULL
 *
 * Stores the bare filename of an admin-uploaded dedicated cover image
 * (stored under covers/categories/ or covers/albums/ — see
 * lumora_covers_path()/lumora_covers_url() in include/functions.php and
 * ThumbnailService::processCoverUpload()), independent of the existing
 * `thumb_image_id` column which instead points at an existing gallery
 * image. NULL means no dedicated cover has been uploaded.
 *
 * Cover resolution order (ThemeRenderer::renderItemThumb()):
 *   1. cover_image (uploaded cover, if set)
 *   2. thumb_image_id (existing gallery image picked by ID, if set)
 *   3. auto-pick the first image in the album/category
 *   4. placeholder icon
 *
 * @package    LumoraGallery
 * @subpackage Database
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.0
 * @see        AbstractMigration Base class every migration extends.
 * @see        GalleryService::createCategory()/updateCategory()/createAlbum()/updateAlbum() Persist this column.
 * @see        ThumbnailService::processCoverUpload() Validates and stores the uploaded file this column references.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0008_AddCoverImageToCategoriesAndAlbums extends AbstractMigration
{
    public function up(): void
    {
        $prefix = LumoraDB::prefix();

        if (!$this->columnExists('categories', 'cover_image')) {
            LumoraDB::query(
                "ALTER TABLE `{$prefix}categories`
                    ADD COLUMN `cover_image` varchar(255) NULL DEFAULT NULL
                        COMMENT 'Bare filename of an uploaded cover image under covers/categories/, NULL = none'
                        AFTER `thumb_image_id`"
            );
        }

        if (!$this->columnExists('albums', 'cover_image')) {
            LumoraDB::query(
                "ALTER TABLE `{$prefix}albums`
                    ADD COLUMN `cover_image` varchar(255) NULL DEFAULT NULL
                        COMMENT 'Bare filename of an uploaded cover image under covers/albums/, NULL = none'
                        AFTER `thumb_image_id`"
            );
        }
    }

    public function down(): void
    {
        $prefix = LumoraDB::prefix();

        if ($this->columnExists('categories', 'cover_image')) {
            LumoraDB::query("ALTER TABLE `{$prefix}categories` DROP COLUMN `cover_image`");
        }

        if ($this->columnExists('albums', 'cover_image')) {
            LumoraDB::query("ALTER TABLE `{$prefix}albums` DROP COLUMN `cover_image`");
        }
    }
}
