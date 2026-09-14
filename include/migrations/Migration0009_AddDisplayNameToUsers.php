<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0009
 *
 * Adds a `display_name` column to {PREFIX}users (DB version 15):
 *
 *   display_name varchar(100) NOT NULL DEFAULT ''
 *
 * Separates the public-facing identity (Display Name) from the login
 * credential (Username), so a future "who uploaded/posted this" feature
 * never has to expose half of a working login credential to visitors.
 * Backfills every existing account's display_name from its current
 * username so no account is left blank.
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
 * @see        UserService::createUser()/updateUser() Validate and persist this column.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0009_AddDisplayNameToUsers extends AbstractMigration
{
    public function up(): void
    {
        $prefix = LumoraDB::prefix();

        if (!$this->columnExists('users', 'display_name')) {
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users`
                    ADD COLUMN `display_name` varchar(100) NOT NULL DEFAULT ''
                        COMMENT 'Public-facing identity, distinct from the login username'
                    AFTER `username`"
            );

            LumoraDB::query(
                "UPDATE `{$prefix}users` SET `display_name` = `username` WHERE `display_name` = ''"
            );
        }
    }

    public function down(): void
    {
        if ($this->columnExists('users', 'display_name')) {
            $prefix = LumoraDB::prefix();
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users` DROP COLUMN `display_name`"
            );
        }
    }
}
