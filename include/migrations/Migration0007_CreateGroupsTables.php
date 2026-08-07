<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0007
 *
 * Introduces dynamic permission groups (DB version 13), replacing the
 * formerly fixed admin/moderator/contributor role ENUM on {PREFIX}users:
 *
 *   1. Creates {PREFIX}groups (id, slug, name, is_system, created_at).
 *   2. Creates {PREFIX}group_permissions (id, group_id, permission).
 *   3. Seeds the three system groups (admin, moderator, contributor) with
 *      is_system = 1 and the exact permission sets that were previously
 *      hardcoded in UserService::ROLE_PERMISSIONS — existing installs see no
 *      behavioural change immediately after this migration runs.
 *   4. Widens `{PREFIX}users`.`role` from
 *      enum('admin','moderator','contributor') to varchar(50), so it can
 *      reference any group slug — including custom groups created later —
 *      rather than only the three original system roles.
 *
 * See include/services/GroupService.php for the read/write API this table
 * pair backs, and admin/groups.php for the admin UI (TODO.md item 21).
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
 * @see        \GroupService Consumes the tables this migration creates.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0007_CreateGroupsTables extends AbstractMigration
{
    public function up(): void
    {
        $prefix = LumoraDB::prefix();

        if (!$this->tableExists('groups')) {
            LumoraDB::query(
                "CREATE TABLE `{$prefix}groups` (
                    `id`         int UNSIGNED     NOT NULL AUTO_INCREMENT,
                    `slug`       varchar(50)      NOT NULL,
                    `name`       varchar(100)     NOT NULL,
                    `is_system`  tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = built-in group, cannot be deleted',
                    `created_at` datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='Permission groups — replaces the former fixed users.role ENUM'"
            );
        }

        if (!$this->tableExists('group_permissions')) {
            LumoraDB::query(
                "CREATE TABLE `{$prefix}group_permissions` (
                    `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
                    `group_id`   int UNSIGNED    NOT NULL,
                    `permission` varchar(64)     NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `group_permission` (`group_id`, `permission`),
                    KEY `group_id` (`group_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='Per-group permission grants'"
            );
        }

        // Seed the three built-in system groups exactly once, matching the
        // permission set that was previously hardcoded in
        // UserService::ROLE_PERMISSIONS. Safe to re-run: a group is only
        // inserted when its slug does not already exist.
        $seed = [
            'admin' => [
                'name'        => 'Administrator',
                'permissions' => [
                    'site_configuration', 'user_management', 'manage_albums',
                    'manage_images', 'moderate_comments', 'maintenance_tools',
                    'batch_add', 'view_updates',
                ],
            ],
            'moderator' => [
                'name'        => 'Moderator',
                'permissions' => [
                    'manage_albums', 'manage_images', 'moderate_comments',
                    'maintenance_tools',
                ],
            ],
            'contributor' => [
                'name'        => 'Contributor',
                'permissions' => [
                    'batch_add', 'edit_own_images', 'manage_assigned_albums',
                ],
            ],
        ];

        foreach ($seed as $slug => $def) {
            $existing = LumoraDB::fetchValue(
                'SELECT id FROM `{PREFIX}groups` WHERE slug = ?',
                [$slug]
            );
            if ($existing) {
                continue;
            }

            $id = (int) LumoraDB::insert('groups', [
                'slug'      => $slug,
                'name'      => $def['name'],
                'is_system' => 1,
            ]);
            foreach ($def['permissions'] as $permission) {
                LumoraDB::insert('group_permissions', [
                    'group_id'   => $id,
                    'permission' => $permission,
                ]);
            }
        }

        // Widen users.role from a fixed ENUM to a VARCHAR so it can hold any
        // group slug, not only the three original system roles. Only runs
        // when the column is still the old ENUM type (idempotent).
        $col = LumoraDB::fetchOne(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'role'",
            [LumoraDB::table('users')]
        );
        if ($col && stripos((string) $col['COLUMN_TYPE'], 'enum') === 0) {
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users`
                    MODIFY COLUMN `role` varchar(50) NOT NULL DEFAULT 'contributor'"
            );
        }
    }

    public function down(): void
    {
        $prefix = LumoraDB::prefix();

        // Restore the original three-role ENUM. Any user account belonging
        // to a custom group would violate this ENUM — reassign such accounts
        // to a system role (admin/moderator/contributor) before rolling back.
        LumoraDB::query(
            "ALTER TABLE `{$prefix}users`
                MODIFY COLUMN `role`
                  enum('admin','moderator','contributor') NOT NULL DEFAULT 'contributor'"
        );

        if ($this->tableExists('group_permissions')) {
            LumoraDB::query("DROP TABLE `{$prefix}group_permissions`");
        }
        if ($this->tableExists('groups')) {
            LumoraDB::query("DROP TABLE `{$prefix}groups`");
        }
    }
}
