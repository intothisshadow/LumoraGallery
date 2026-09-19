<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0010
 *
 * Creates the {PREFIX}admin_log table (DB version 16) used by LogService to
 * record security/admin events — login successes and failures, and staff
 * account and plugin changes — for the Admin → Logs page (LG-067). This is
 * separate from the existing {PREFIX}log table, which only captures entries
 * when `log_mode` is set to 'all' and mixes page-visit traffic in with
 * errors; this table is always written to regardless of that setting, and
 * only ever holds security/audit-relevant rows.
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
 * @see        LogService Reads/writes this table.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0010_CreateAdminLogTable extends AbstractMigration
{
    public function up(): void
    {
        $prefix = LumoraDB::prefix();
        LumoraDB::query(
            "CREATE TABLE IF NOT EXISTS `{$prefix}admin_log` (
              `id`         bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `event_type` varchar(32)     NOT NULL,
              `user_id`    int UNSIGNED    NOT NULL DEFAULT 0,
              `username`   varchar(50)     NOT NULL DEFAULT '',
              `ip`         varchar(45)     NOT NULL DEFAULT '',
              `message`    text            NOT NULL,
              `created_at` datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `type_created` (`event_type`, `created_at`),
              KEY `user_created` (`user_id`, `created_at`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Security/admin event audit log (DB version 16)'"
        );
    }

    public function down(): void
    {
        $prefix = LumoraDB::prefix();
        LumoraDB::query("DROP TABLE IF EXISTS `{$prefix}admin_log`");
    }
}
