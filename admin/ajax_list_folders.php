<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: List Available Album Folders
 *
 * Returns directories under albums/ that exist on disk but aren't yet
 * claimed by any album row, so admin/albums.php?action=new can offer them
 * as selectable options for the Folder Path field (LG-040) instead of
 * requiring an admin to hand-type the exact path of a folder that may
 * already have been uploaded via FTP.
 *
 * POST params:
 *   csrf_token string
 *
 * JSON response:
 *   { "folders": ["Xena/Season1/1x01-SinsOfThePast", ...] }
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.14.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!lumora_has_permission('manage_albums')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    !hash_equals(lumora_csrf_token(), (string) $_POST['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed.']);
    exit;
}

try {
    echo json_encode(['folders' => GalleryService::listAvailableAlbumFolders()]);
} catch (\Throwable $e) {
    lumora_log('error', 'ajax_list_folders: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Folder scan failed unexpectedly.']);
}

exit;
