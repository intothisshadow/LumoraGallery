<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: Bundled theme GitHub updates
 *
 * POST parameters:
 *   csrf_token  string  (always required)
 *   action      string  'check' | 'run_stage'
 *   folder      string  Theme folder (run_stage only)
 *   stage       string  check | download | verify | apply (run_stage only)
 *
 * `check` refreshes the release check and returns
 *   { success: true, updates: [{folder, name, installed, latest}], count: int }
 * `run_stage` returns the same { success, stage, message, next, details }
 * shape as ajax_update_perform.php; the client calls the stages in order
 * while `next` is non-null.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.20.0
 * @see        ThemeUpdateService Implements the stages this endpoint dispatches.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';

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

$action = (string) ($_POST['action'] ?? '');

if ($action === 'check') {
    UpdateService::check(force: true);
    $updates = array_values(array_map(
        static fn(array $u): array => [
            'folder'    => $u['folder'],
            'name'      => $u['name'],
            'installed' => $u['installed'],
            'latest'    => $u['latest'],
        ],
        ThemeUpdateService::availableUpdates()
    ));
    echo json_encode(['success' => true, 'updates' => $updates, 'count' => count($updates)]);
    exit;
}

if ($action === 'run_stage') {
    $folder = (string) ($_POST['folder'] ?? '');
    $stage = (string) ($_POST['stage'] ?? '');
    if (preg_match('/^[a-z0-9_-]+$/', $folder) !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid theme folder.']);
        exit;
    }
    echo json_encode(ThemeUpdateService::runStage($stage, $folder));
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
