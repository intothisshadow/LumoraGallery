<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: Accept an Uploaded Update ZIP
 *
 * Alternative to the GitHub-based updater for servers that can't reach
 * GitHub, or to install a build that isn't a published release. Validates
 * the upload and stages an update session identically to a provider-fetched
 * release — see UpdaterService::acquireLockFromUpload(). The client then
 * drives it through the same run_stage AJAX flow (ajax_update_perform.php).
 *
 * POST parameters:
 *   csrf_token       string  (always required)
 *   update_zip       file    The uploaded release ZIP (multipart/form-data)
 *   replace_plugins  '0'|'1' Optional — same meaning as the run_stage preflight param
 *   replace_themes   '0'|'1' Optional — same meaning as the run_stage preflight param
 *
 * Response JSON shape:
 *   {
 *     success:  bool,
 *     message:  string,
 *     version:  string|null,   // detected target version, present on success
 *     details:  string[]
 *   }
 *
 * Requires the 'site_configuration' permission and a valid CSRF token.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.15.0
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

// ── Upload validation ───────────────────────────────────────────────────────────

$file = $_FILES['update_zip'] ?? null;

if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please choose a ZIP file to upload.']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'The uploaded file is larger than this server allows (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'The uploaded file is larger than this server allows.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder configured for uploads.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A server extension stopped the file upload.',
    ];
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $upload_errors[$file['error']] ?? 'The file upload failed. Please try again.',
    ]);
    exit;
}

if (!is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The file upload failed. Please try again.']);
    exit;
}

// ── Per-update replacement preferences (same meaning as the run_stage
// preflight params — stored in the lock file for later stages to read) ──────────
$replace_plugins = isset($_POST['replace_plugins']) && $_POST['replace_plugins'] === '1';
$replace_themes  = isset($_POST['replace_themes'])  && $_POST['replace_themes']  === '1';

$result = UpdaterService::acquireLockFromUpload($file['tmp_name'], [
    'replace_plugins' => $replace_plugins,
    'replace_themes'  => $replace_themes,
]);

$version = null;
if ($result['success']) {
    $lock    = UpdaterService::getLockInfo();
    $version = $lock['version'] ?? null;
}

echo json_encode([
    'success' => $result['success'],
    'message' => $result['message'],
    'version' => $version,
    'details' => $result['details'],
]);
exit;
