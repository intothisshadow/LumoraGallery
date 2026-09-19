<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Entry Point
 *
 * Reached via an .htaccess rewrite rule placed in a test album folder (see
 * README.md), never linked to directly — a normal thumbnail request for a
 * missing thumb_*.jpg file gets rewritten here with the original folder
 * and filename as query parameters. This is a standalone entry point, not
 * part of Lumora's normal page routing, so it loads the app bootstrap
 * itself before doing anything else.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.18.5
 */

define('LUMORA_ENTRY', true);
require_once dirname(__DIR__, 2) . '/include/bootstrap.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/OnDemandRateLimitService.php';
require_once __DIR__ . '/OnDemandThumbnailService.php';

$folder = (string) ($_GET['folder'] ?? '');
$file   = (string) ($_GET['file']   ?? '');

OnDemandThumbnailService::serve($folder, $file);
