<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin AJAX: Regenerate Thumbnail
 *
 * Regenerates the thumbnail for a single image using the currently configured
 * thumb_width / thumb_height / thumb_quality settings.
 *
 * POST params: image_id (int), csrf_token (string)
 * Response:    JSON { ok: bool, message: string }
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
lumora_require_any_permission(['manage_images', 'edit_own_images']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

lumora_csrf_validate();

set_time_limit(60);

$image_id = lumora_int($_POST['image_id'] ?? 0, 0, 1);

if ($image_id === 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid image ID.']);
    exit;
}

$image = LumoraDB::fetchOne(
    'SELECT i.id, i.filename, i.uploaded_by, a.folder
     FROM `{PREFIX}images` i
     JOIN `{PREFIX}albums` a ON a.id = i.album_id
     WHERE i.id = ?',
    [$image_id]
);

if (!$image) {
    echo json_encode(['ok' => false, 'message' => 'Image not found.']);
    exit;
}

// A contributor holds 'edit_own_images' (not 'manage_images') and may only
// regenerate thumbnails for images they uploaded themselves.
if (!lumora_has_permission('manage_images')) {
    $current_user    = lumora_current_user();
    $current_user_id = (int) ($current_user['user_id'] ?? 0);
    if ((int) $image['uploaded_by'] !== $current_user_id) {
        echo json_encode(['ok' => false, 'message' => 'Not authorised for this image.']);
        exit;
    }
}

$original_path = lumora_album_path($image['folder']) . $image['filename'];

if (!is_file($original_path)) {
    echo json_encode(['ok' => false, 'message' => 'Original file not found on disk.']);
    exit;
}

$thumb_w = max(1, (int) lumora_config('thumb_width',  250));
$thumb_h = max(1, (int) lumora_config('thumb_height', 250));
$thumb_p = lumora_album_path($image['folder']) . LUMORA_THUMB_PREFIX . $image['filename'];

$ok = lumora_generate_thumb($original_path, $thumb_p, $thumb_w, $thumb_h);

echo json_encode([
    'ok'      => $ok,
    'message' => $ok
        ? 'Thumbnail regenerated.'
        : 'Thumbnail generation failed. Ensure GD or Imagick is available.',
]);
exit;
