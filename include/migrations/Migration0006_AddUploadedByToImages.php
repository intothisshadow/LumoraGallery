<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0006
 *
 * Adds the `uploaded_by` column to {PREFIX}images (DB version 12):
 *
 *   uploaded_by  int UNSIGNED  NOT NULL DEFAULT 0
 *
 * Records which user account uploaded each image, enabling row-level
 * ownership enforcement for the contributor role's 'edit_own_images'
 * permission (GalleryService::imageBelongsToUser(),
 * lumora_require_image_access() in auth.php).
 *
 * 0 means "no recorded owner" (pre-existing images at the time of this
 * migration, or images inserted by a path that does not yet record an
 * uploader, such as the Coppermine importer). Users holding 'manage_images'
 * always have unrestricted access regardless of uploaded_by; only
 * 'edit_own_images' holders are scoped by this column.
 *
 * Backfill: all existing images (uploaded_by = 0 immediately after the
 * column is added) are assigned to the primary administrator account — the
 * lowest-id user with role = 'admin' — so ownership is defined immediately
 * rather than left unowned. New uploads via Batch Add
 * (ThumbnailService::batchAddImage()) record the uploading user's ID
 * directly at insert time.
 *
 * @package    LumoraGallery
 * @subpackage Database
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.10.0
 * @see        AbstractMigration Base class every migration extends.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0006_AddUploadedByToImages extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->columnExists('images', 'uploaded_by')) {
            $prefix = LumoraDB::prefix();
            LumoraDB::query(
                "ALTER TABLE `{$prefix}images`
                    ADD COLUMN `uploaded_by` int UNSIGNED NOT NULL DEFAULT 0
                        COMMENT 'FK to users.id, 0 = no recorded owner'
                        AFTER `album_id`,
                    ADD KEY `uploaded_by` (`uploaded_by`)"
            );
        }

        // Backfill: assign all currently-unowned images to the primary
        // administrator (lowest-id user with role = 'admin'), so existing
        // installs never have to reason about "ownerless" images. Safe to
        // re-run: only rows still at the default 0 are touched.
        $admin_id = (int) (LumoraDB::fetchValue(
            "SELECT MIN(id) FROM `{PREFIX}users` WHERE role = 'admin'"
        ) ?? 0);

        if ($admin_id > 0) {
            LumoraDB::query(
                'UPDATE `{PREFIX}images` SET uploaded_by = ? WHERE uploaded_by = 0',
                [$admin_id]
            );
        }
    }

    public function down(): void
    {
        if ($this->columnExists('images', 'uploaded_by')) {
            $prefix = LumoraDB::prefix();
            // Drop the key before the column for engines that require it,
            // ignoring failure if the key name differs or is already gone.
            try {
                LumoraDB::query("ALTER TABLE `{$prefix}images` DROP KEY `uploaded_by`");
            } catch (\Throwable) {
            }
            LumoraDB::query("ALTER TABLE `{$prefix}images` DROP COLUMN `uploaded_by`");
        }
    }
}
