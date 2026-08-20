<?php

declare(strict_types=1);
/**
 * Lumora Gallery — Visitor Stats Plugin — Version Constants
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

/** Plugin version. Update only when releasing a new plugin version — must match plugin.json. */
define('LUMORA_VISITOR_STATS_VERSION', '1.0.0');

/** Pageview log entries older than this many days are pruned automatically. */
define('LUMORA_VISITOR_STATS_RETENTION_DAYS', 90);
