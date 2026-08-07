<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: Perform Update Stage
 *
 * Multi-step update endpoint called from the Admin → Updates page.
 * Each call performs one stage of the update workflow and returns a JSON
 * result that the client uses to drive the progress UI.
 *
 * POST parameters:
 *   csrf_token  string  (always required)
 *   action      string  'run_stage' | 'rollback' | 'abort'
 *   stage       string  Stage name (required when action = 'run_stage')
 *   version     string  Target version (required for stage = 'preflight')
 *
 * Response JSON shape (run_stage / rollback):
 *   {
 *     success:  bool,
 *     stage:    string,         // completed stage
 *     message:  string,         // human-readable summary
 *     next:     string|null,    // next stage name; null = workflow finished
 *     details:  string[]        // optional detail lines for the progress log
 *   }
 *
 * Response JSON shape (abort):
 *   { success: bool, message: string }
 *
 * Security:
 *   - Requires the 'site_configuration' permission (not just 'view_updates')
 *     since every action this endpoint dispatches — run_stage, rollback, and
 *     abort — downloads/extracts archives, replaces application files, and/or
 *     runs database migrations. 'view_updates' only grants visibility into the
 *     read-only status page and update-check endpoint; it must never be
 *     sufficient on its own to actually perform an update, or a custom
 *     permission group intended for read-only monitoring would unexpectedly
 *     be able to execute the full update pipeline. See TODO-security.md #2.
 *   - CSRF token validated.
 *   - Stage and version inputs are validated before being passed to UpdaterService.
 *   - Update lock prevents concurrent update sessions.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.0.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────────────────────────

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

// ── Input ─────────────────────────────────────────────────────────────────────

$action  = trim((string) ($_POST['action']  ?? 'run_stage'));
$stage   = trim((string) ($_POST['stage']   ?? ''));
$version = trim((string) ($_POST['version'] ?? ''));

// Sanitise version: allow digits, dots, and an optional leading 'v'.
if ($version !== '' && !preg_match('/^v?[0-9]+(?:\.[0-9]+)*$/', $version)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid version format.']);
    exit;
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

switch ($action) {

    // ── Run a single update stage ─────────────────────────────────────────────
    case 'run_stage':
        if ($stage === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing stage parameter.']);
            exit;
        }

        if (!in_array($stage, UpdaterService::STAGE_SEQUENCE, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown stage: ' . $stage]);
            exit;
        }

        // Per-update replace options — accepted only for the preflight stage and
        // stored in the lock file for subsequent stages to read.  Both default to
        // false (preserve), keeping the safe default when the parameter is absent.
        $replace_plugins = isset($_POST['replace_plugins']) && $_POST['replace_plugins'] === '1';
        $replace_themes  = isset($_POST['replace_themes'])  && $_POST['replace_themes']  === '1';
        $options         = ['replace_plugins' => $replace_plugins, 'replace_themes' => $replace_themes];
        $result          = UpdaterService::runStage($stage, $version, $options);
        echo json_encode($result);
        break;

    // ── Rollback: restore backup and disable maintenance mode ─────────────────
    case 'rollback':
        $result = UpdaterService::rollback();
        echo json_encode([
            'success' => $result['success'],
            'stage'   => 'rollback',
            'message' => $result['message'],
            'next'    => null,
            'details' => $result['details'],
        ]);
        break;

    // ── Abort: force-reset a stuck session (no restoration) ───────────────────
    case 'abort':
        UpdaterService::forceAbort();
        echo json_encode(['success' => true, 'message' => 'Update session aborted.']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}

exit;
