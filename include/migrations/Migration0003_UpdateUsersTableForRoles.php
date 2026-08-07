<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0003
 *
 * Upgrades {PREFIX}users for multi-user staff account support (DB version 9):
 *
 *   1. Adds an `is_active` column (tinyint UNSIGNED, default 1 = enabled)
 *      so individual accounts can be disabled without deletion.
 *
 *   2. Migrates legacy role values before modifying the ENUM:
 *        'editor' → 'moderator'
 *        'viewer' → 'contributor'
 *
 *   3. Updates the `role` ENUM to the new three-role schema:
 *        'admin' | 'moderator' | 'contributor'
 *      and changes the default from 'viewer' to 'contributor'.
 *
 * The admin account created by the installer always has role='admin' and
 * is unaffected. The UPDATE statements for legacy roles are no-ops if no
 * 'editor' or 'viewer' rows exist.
 *
 * @package    LumoraGallery
 * @subpackage Database
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.9.0
 * @see        AbstractMigration Base class every migration extends.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0003_UpdateUsersTableForRoles extends AbstractMigration
{
    /**
     * Apply the migration (schema upgrade).
     *
     * Order matters:
     *   UPDATE legacy role values first, then ALTER the ENUM so no rows carry
     *   a value not in the new definition. ADD COLUMN is idempotent via the
     *   columnExists() guard.
     */
    public function up(): void
    {
        $prefix = LumoraDB::prefix();

        // 1. Add is_active column if absent.
        if (!$this->columnExists('users', 'is_active')) {
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users`
                 ADD COLUMN `is_active` tinyint UNSIGNED NOT NULL DEFAULT 1
                   COMMENT '1 = active, 0 = disabled'
                 AFTER `email`"
            );
        }

        // 2/3. Legacy role rename + ENUM redefinition — only when the column
        //      is still in its pre-v9 shape (enum containing 'editor'/'viewer').
        //      Migration0007 later widens `role` to varchar(50) so it can hold
        //      any group slug; without this guard, replaying this migration
        //      (or running it on an install already past Migration0007) would
        //      unconditionally convert `role` back to a 3-value ENUM, which
        //      silently truncates/rejects any custom group slug that doesn't
        //      match 'admin'/'moderator'/'contributor'. Unlike step 1's
        //      columnExists() guard, this step previously ran unconditionally
        //      every time — found via a migration-replay test that runs every
        //      migration in order against an already-fully-migrated schema.
        $col = LumoraDB::fetchOne(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'role'",
            [LumoraDB::table('users')]
        );
        $is_legacy_enum = $col !== null
            && stripos((string) $col['COLUMN_TYPE'], 'enum') === 0
            && stripos((string) $col['COLUMN_TYPE'], 'editor') !== false;

        if ($is_legacy_enum) {
            // Rename legacy roles before altering the ENUM definition.
            LumoraDB::query(
                "UPDATE `{$prefix}users` SET `role` = 'moderator'   WHERE `role` = 'editor'"
            );
            LumoraDB::query(
                "UPDATE `{$prefix}users` SET `role` = 'contributor' WHERE `role` = 'viewer'"
            );

            // Redefine the ENUM and update the default.
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users`
                 MODIFY COLUMN `role`
                   enum('admin','moderator','contributor') NOT NULL DEFAULT 'contributor'"
            );
        }
    }

    /**
     * Reverse the migration (schema downgrade).
     *
     * Restores the original ENUM values and removes is_active.
     * Roles are renamed back: 'moderator' → 'editor', 'contributor' → 'viewer'.
     */
    public function down(): void
    {
        $prefix = LumoraDB::prefix();

        // Rename roles back before restoring the original ENUM.
        LumoraDB::query(
            "UPDATE `{$prefix}users` SET `role` = 'editor' WHERE `role` = 'moderator'"
        );
        LumoraDB::query(
            "UPDATE `{$prefix}users` SET `role` = 'viewer' WHERE `role` = 'contributor'"
        );

        // Restore the original ENUM definition.
        LumoraDB::query(
            "ALTER TABLE `{$prefix}users`
             MODIFY COLUMN `role`
               enum('admin','editor','viewer') NOT NULL DEFAULT 'viewer'"
        );

        // Remove is_active column.
        if ($this->columnExists('users', 'is_active')) {
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users` DROP COLUMN `is_active`"
            );
        }
    }
}
