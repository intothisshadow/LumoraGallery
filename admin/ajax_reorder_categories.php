<?php

declare(strict_types=1);
/**
 * Lumora Gallery — Admin AJAX: Reorder Categories (drag-and-drop, TODO.md #23)
 *
 * Persists a category reorder/reparent performed by dragging a row in the
 * admin/categories.php hierarchy tree. Business rules (cycle prevention,
 * pos renumbering) live in GalleryService::reorderCategories() — this
 * endpoint only handles permission checks, CSRF validation, request
 * parsing, and the JSON response.
 *
 * POST params:
 *   moved_id       int    Category being dragged.
 *   new_parent_id  int    Parent bucket to place it in (0 = root).
 *   ordered_ids    string JSON-encoded array of every sibling ID (including
 *                         moved_id) that should end up under new_parent_id,
 *                         in their intended display order.
 *   csrf_token     string
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

$moved_id      = lumora_int($_POST['moved_id']      ?? 0, 0, 1);
$new_parent_id = lumora_int($_POST['new_parent_id'] ?? 0, 0, 0);
$ordered_raw   = $_POST['ordered_ids'] ?? '';

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

if ($moved_id === 0 || empty($ordered_ids)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}

$error = GalleryService::reorderCategories($moved_id, $new_parent_id, $ordered_ids);

if ($error !== null) {
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

echo json_encode(['success' => true]);
