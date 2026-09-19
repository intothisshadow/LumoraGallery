<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — AJAX: Batch-Delete Thumbnails
 *
 * Drives a real (non-dry-run) batch delete from the Admin → On-Demand
 * Thumbnails settings page in small chunks, mirroring the in-place AJAX
 * pattern Admin → Updates uses for its own multi-stage update workflow
 * (see admin/ajax_update_perform.php) rather than a single long-running
 * request with a full page reload at the end. A dry run stays a plain,
 * synchronous form POST on settings.php — it never touches the filesystem,
 * so there is nothing here for it to time out on.
 *
 * POST parameters:
 *   csrf_token string  (always required)
 *   action     string  'start' | 'batch'
 *   folder     string  Path relative to albums/ (action = 'start' only)
 *   recursive  '1'|''  (action = 'start' only)
 *   mode       string  'all' | 'every_other' (action = 'start' only)
 *   job_id     string  32-hex job ID returned by 'start' (action = 'batch' only)
 *
 * Response JSON shape ('start'):
 *   { success: bool, message?: string, done: bool, job_id?: string,
 *     total_found: int, to_delete_count?: int, folder_count?: int }
 *   done = true with no job_id means nothing matched — no job was created.
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
 * @see        OnDemandThumbnailService::planDeletion()/deleteFiles() Backing logic.
 * @see        OnDemandDeleteJobService Persists the plan between 'start' and each 'batch' call.
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

    // ── Plan the delete and open a job for it ──────────────────────────────
    case 'start':
        $folder_input = trim((string) ($_POST['folder'] ?? ''));
        $recursive    = ($_POST['recursive'] ?? '') === '1';
        $every_other  = ($_POST['mode'] ?? 'all') === 'every_other';

        // Same confinement check as the plain-form dry-run path in
        // settings.php — resolve real paths and confirm the target actually
        // lives under albums/ before touching anything.
        $albums_real = realpath(LUMORA_ALBUMS_PATH);
        $target_real = $folder_input !== '' ? realpath(LUMORA_ALBUMS_PATH . $folder_input) : false;

        if ($albums_real === false || $target_real === false
            || !str_starts_with($target_real . DIRECTORY_SEPARATOR, $albums_real . DIRECTORY_SEPARATOR)
        ) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'That folder could not be found under albums/.']);
            exit;
        }

        $plan = OnDemandThumbnailService::planDeletion($target_real, $recursive, $every_other);

        if ($plan['total_found'] === 0) {
            echo json_encode(['success' => true, 'done' => true, 'total_found' => 0]);
            exit;
        }

        $jobId = OnDemandDeleteJobService::create($plan);
        echo json_encode([
            'success'         => true,
            'done'            => false,
            'job_id'          => $jobId,
            'total_found'     => $plan['total_found'],
            'to_delete_count' => count($plan['to_delete']),
            'folder_count'    => count($plan['folders']),
        ]);
        break;

    // ── Process one batch of an already-open job ────────────────────────────
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
