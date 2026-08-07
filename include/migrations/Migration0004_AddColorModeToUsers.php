<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0004
 *
 * Adds a `color_mode` column to {PREFIX}users (DB version 10):
 *
 *   color_mode ENUM('auto','light','dark') NOT NULL DEFAULT 'auto'
 *
 * This stores each user's preferred colour scheme for the admin panel and
 * (when logged in) the public gallery.  The 'auto' default means "follow
 * the operating-system preference" — identical to the site-wide default
 * behaviour when the column is absent, so the migration is fully safe for
 * existing installations.
 *
 * @package    LumoraGallery
 * @subpackage Database
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.10.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0004_AddColorModeToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->columnExists('users', 'color_mode')) {
            $prefix = LumoraDB::prefix();
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users`
                 ADD COLUMN `color_mode`
                   enum('auto','light','dark') NOT NULL DEFAULT 'auto'
                   COMMENT 'User colour-scheme preference: auto = follow OS, light, dark'
                 AFTER `is_active`"
            );
        }
    }

    public function down(): void
    {
        if ($this->columnExists('users', 'color_mode')) {
            $prefix = LumoraDB::prefix();
            LumoraDB::query(
                "ALTER TABLE `{$prefix}users` DROP COLUMN `color_mode`"
            );
        }
    }
}
