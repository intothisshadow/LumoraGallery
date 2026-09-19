<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Batch-Delete CLI
 *
 * Shared implementation behind both tools/delete-all-thumbs.sh and
 * tools/delete-every-other-thumb.sh, which are thin wrappers around this
 * script — they handle CLI argument parsing, the interactive confirmation
 * prompt, and human-readable output; this script does the actual file
 * listing/sorting/deletion via OnDemandThumbnailService::deleteThumbnails(),
 * the same method Admin → On-Demand Thumbnails calls (LG-068). Deliberately
 * does not load Lumora's full bootstrap — this operation is pure filesystem
 * work with no database dependency, and the two shell wrappers historically
 * had none either.
 *
 * Usage: php delete-thumbs-cli.php <path> [--recursive] [--every-other] [--dry-run]
 * Prints a per-folder breakdown, then a summary line; exits 0 on success,
 * 1 on a usage error.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.0
 * @see        OnDemandThumbnailService::deleteThumbnails() Does the actual work.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

define('LUMORA_ENTRY', true);
if (!defined('LUMORA_THUMB_PREFIX')) {
    define('LUMORA_THUMB_PREFIX', 'thumb_');
}
require_once dirname(__DIR__) . '/OnDemandThumbnailService.php';

$args      = array_slice($argv, 1);
$path      = null;
$recursive = false;
$everyOther = false;
$dryRun    = false;

foreach ($args as $arg) {
    switch ($arg) {
        case '--recursive':  $recursive  = true; break;
        case '--every-other': $everyOther = true; break;
        case '--dry-run':    $dryRun     = true; break;
        default:
            if (str_starts_with($arg, '-')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                exit(1);
            }
            $path = $arg;
    }
}

if ($path === null || !is_dir($path)) {
    fwrite(STDERR, "Usage: php delete-thumbs-cli.php <path> [--recursive] [--every-other] [--dry-run]\n");
    exit(1);
}

$result = OnDemandThumbnailService::deleteThumbnails($path, $recursive, $everyOther, $dryRun);

if ($result['total_found'] === 0) {
    echo "No thumb_* files found under: {$path}\n";
    exit(0);
}

foreach ($result['folders'] as $dir => $counts) {
    if ($recursive) {
        echo "{$dir}: found {$counts['found']}, " . ($dryRun ? 'would delete' : 'deleted') . " {$counts['deleted']}\n";
    } else {
        echo "Found {$counts['found']} thumb_* files in: {$dir}\n";
    }
}

echo "\n";
if ($dryRun) {
    echo "Total: would delete {$result['total_deleted']} of {$result['total_found']} thumb_* file(s).\n";
    echo "Dry run — nothing deleted.\n";
} else {
    echo "Deleted {$result['total_deleted']} of {$result['total_found']} thumb_* file(s) found.\n";
    echo "Recover any of these later via Admin -> Tools -> Regenerate Missing Thumbnails.\n";
}
