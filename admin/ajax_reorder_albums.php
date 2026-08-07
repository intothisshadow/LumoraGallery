<?php

declare(strict_types=1);
/**
 * Lumora Gallery — Admin AJAX: Reorder Albums (drag-and-drop, TODO.md #23)
 *
 * Persists an album reorder performed by dragging a row within a single
 * category section of admin/albums.php's hierarchy view. Albums are never
 * reparented to a different category via this endpoint — only relative
 * order within the same category changes. Business rules live in
 * GalleryService::reorderAlbums() — this endpoint only handles permission
 * checks, CSRF validation, request parsing, and the JSON response.
 *
 * Gated on 'manage_albums' only (not 'manage_assigned_albums') — contributors
 * see a flat, unpaginated "assigned albums" list with no category grouping,
 * so there is no drag-and-drop surface for them to reorder in the first place.
 *
 * POST params:
 *   category_id  int    Category bucket being reordered (0 = uncategorized).
 *   ordered_ids  string JSON-encoded array of every album ID belonging to
 *                       category_id, in their intended display order.
 *   csrf_token   string
 *
 * Response: JSON { success: bool, error?: string }
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
lumora_require_permission('manage_albums');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

lumora_csrf_validate();

$category_id = lumora_int($_POST['category_id'] ?? 0, 0, 0);
$ordered_raw = $_POST['ordered_ids'] ?? '';

$ordered_ids = [];
if (is_string($ordered_raw) && $ordered_raw !== '') {
    $decoded = json_decode($ordered_raw, true);
    if (is_array($decoded)) {
        $ordered_ids = array_values(array_filter(
            array_map(static fn(mixed $v): int => (int) $v, $decoded),
            static fn(int $v): bool => $v > 0
        ));
    }
}

if (empty($ordered_ids)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$error = GalleryService::reorderAlbums($category_id, $ordered_ids);

if ($error !== null) {
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

echo json_encode(['success' => true]);
