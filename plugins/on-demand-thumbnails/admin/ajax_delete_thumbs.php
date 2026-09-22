<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — AJAX: Batch-Delete Thumbnails
 *
 * Drives a batch delete — real or dry run — from the Admin → On-Demand
 * Thumbnails settings page in small chunks, mirroring the in-place AJAX
 * pattern Admin → Updates uses for its own multi-stage update workflow
 * (see admin/ajax_update_perform.php) rather than a single long-running
 * request with a full page reload at the end. Both modes share this same
 * chunked flow because both have to do the same potentially-large
 * filesystem scan — a dry run skips only the actual unlink() calls, not
 * the scanning, so it's exposed to the same large-recursive-section risk.
 *
 * 'start' does no scanning at all — it only validates the target folder
 * and opens a job queued with just that one directory (see
 * OnDemandDeleteJobService); everything else happens one directory per
 * 'batch' call, so no single request ever has to walk more of the tree
 * than one directory's immediate contents, regardless of how large the
 * overall scope is.
 *
 * POST parameters:
 *   csrf_token string  (always required)
 *   action     string  'start' | 'batch'
 *   folder     string  Path relative to albums/ (action = 'start' only)
 *   recursive  '1'|''  (action = 'start' only)
 *   mode       string  'all' | 'every_other' (action = 'start' only)
 *   dry_run    '1'|''  (action = 'start' only)
 *   job_id     string  32-hex job ID returned by 'start' (action = 'batch' only)
 *
 * Response JSON shape ('start'):
 *   { success: bool, message?: string, done: bool, job_id?: string, dry_run?: bool }
 *
 * Response JSON shape ('batch'): see OnDemandDeleteJobService::processBatch().
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.1
 * @see        OnDemandThumbnailService::processFolder()/immediateSubdirectories() Backing logic.
 * @see        OnDemandDeleteJobService Persists the work queue between 'start' and each 'batch' call.
 */
define('LUMORA_ENTRY', true);

// This file is at plugins/on-demand-thumbnails/admin/ajax_delete_thumbs.php.
$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once $_lumora_root . '/admin/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/OnDemandThumbnailService.php';
require_once dirname(__DIR__) . '/OnDemandDeleteJobService.php';

header('Content-Type: application/json; charset=utf-8');

if (!lumora_has_permission('site_configuration')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if (!hash_equals(lumora_csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!PluginService::isEnabled('on-demand-thumbnails')) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Plugin is disabled.']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));

switch ($action) {

    // ── Validate the target and open a job queued with just that folder ────
    case 'start':
        $folder_input = trim((string) ($_POST['folder'] ?? ''));
        $recursive    = ($_POST['recursive'] ?? '') === '1';
        $every_other  = ($_POST['mode'] ?? 'all') === 'every_other';
        $dry_run      = ($_POST['dry_run'] ?? '') === '1';

        // An empty folder targets albums/ itself — every top-level folder
        // directly under albums/ in one run — but only paired with
        // Recursive, so a blank field can't silently no-op.
        if ($folder_input === '' && !$recursive) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Check Recursive to run against all of albums/, or enter a specific folder.']);
            exit;
        }

        // Same confinement check as before — resolve real paths and confirm
        // the target actually lives under albums/ before touching anything.
        $albums_real = realpath(LUMORA_ALBUMS_PATH);
        $target_real = $folder_input !== '' ? realpath(LUMORA_ALBUMS_PATH . $folder_input) : $albums_real;

        if ($albums_real === false || $target_real === false
            || !str_starts_with($target_real . DIRECTORY_SEPARATOR, $albums_real . DIRECTORY_SEPARATOR)
        ) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'That folder could not be found under albums/.']);
            exit;
        }

        $jobId = OnDemandDeleteJobService::create($target_real, $recursive, $every_other, $dry_run);
        echo json_encode([
            'success' => true,
            'done'    => false,
            'job_id'  => $jobId,
            'dry_run' => $dry_run,
        ]);
        break;

    // ── Process one directory of an already-open job ────────────────────────
    case 'batch':
        $jobId = trim((string) ($_POST['job_id'] ?? ''));
        if ($jobId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'done' => true, 'message' => 'Missing job_id.']);
            exit;
        }
        echo json_encode(OnDemandDeleteJobService::processBatch($jobId));
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}

exit;
