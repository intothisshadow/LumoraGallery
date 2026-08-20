<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Visitor Stats Plugin — Activation
 *
 * Required exactly once by PluginService::enablePlugin(), the moment an
 * admin turns this plugin on from admin/plugins.php. Creates the plugin's
 * own {PREFIX}stats_hits table; never touched by core migrations or
 * install/schema.sql, since this plugin is entirely optional.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

$prefix = LumoraDB::prefix();
$table  = LumoraDB::table('stats_hits');

$exists = (int) LumoraDB::fetchValue(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    [$table]
);

if ($exists === 0) {
    LumoraDB::query(
        "CREATE TABLE `{$prefix}stats_hits` (
            `id`            bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            `item_type`     varchar(16)      NOT NULL,
            `item_id`       int UNSIGNED     NOT NULL DEFAULT 0,
            `referrer_host` varchar(255)     DEFAULT NULL,
            `ip_hash`       char(64)         DEFAULT NULL,
            `viewed_at`     datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `viewed_at` (`viewed_at`),
            KEY `item` (`item_type`, `item_id`, `viewed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Pageview log for the Visitor Stats plugin'"
    );
}
