<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0005
 *
 * Creates the {PREFIX}album_assignments table (DB version 11):
 *
 *   id          bigint UNSIGNED  PK
 *   user_id     int UNSIGNED     FK -> users.id (contributor accounts only)
 *   album_id    int UNSIGNED     FK -> albums.id
 *   assigned_by int UNSIGNED     users.id of the admin/moderator who granted access
 *   assigned_at datetime
 *
 * Records which albums a contributor account has been granted access to via
 * the 'manage_assigned_albums' permission (AlbumAssignmentService). No FK
 * constraints are used, matching the rest of the schema — cleanup on user or
 * album deletion is handled at the application layer
 * (UserService::deleteUser(), admin/albums.php's delete handler).
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
 * @see        \AlbumAssignmentService Consumes the table this migration creates.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0005_CreateAlbumAssignmentsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->tableExists('album_assignments')) {
            return;
        }

        $prefix = LumoraDB::prefix();
        LumoraDB::query(
            "CREATE TABLE `{$prefix}album_assignments` (
                `id`          bigint UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     int UNSIGNED    NOT NULL,
                `album_id`    int UNSIGNED    NOT NULL,
                `assigned_by` int UNSIGNED    NOT NULL COMMENT 'user_id of the admin/moderator who granted it',
                `assigned_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_album` (`user_id`, `album_id`),
                KEY `user_id`  (`user_id`),
                KEY `album_id` (`album_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Per-user album assignments for the contributor role'"
        );
    }

    public function down(): void
    {
        if ($this->tableExists('album_assignments')) {
            $prefix = LumoraDB::prefix();
            LumoraDB::query("DROP TABLE `{$prefix}album_assignments`");
        }
    }
}
