<?php
declare(strict_types=1);
/**
 * Lumora Gallery — AJAX: Save User Colour Mode Preference
 *
 * Saves the requesting user's colour-mode preference (auto / light / dark)
 * to the {PREFIX}users.color_mode column so it persists across devices and
 * browser sessions.  The browser also stores the preference in localStorage
 * as the primary fast-path; this endpoint provides the durable server-side
 * copy used when localStorage is unavailable or a new device is used.
 *
 * POST parameters:
 *   csrf_token  string  (required)
 *   mode        string  'auto' | 'light' | 'dark'
 *
 * Response JSON shape:
 *   { success: bool, message: string }
 *
 * Security:
 *   - Any logged-in staff session required (admin, moderator, contributor).
 *   - CSRF token validated.
 *   - Mode value is validated against the allowed enum.
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

if (!lumora_is_logged_in()) {
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

$mode = trim((string) ($_POST['mode'] ?? ''));

if (!in_array($mode, ['auto', 'light', 'dark'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid mode value. Use: auto, light, or dark.']);
    exit;
}

// ── Save ──────────────────────────────────────────────────────────────────────

$user = lumora_current_user();
$uid  = (int) ($user['id'] ?? 0);

if ($uid <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Could not determine current user.']);
    exit;
}

try {
    LumoraDB::query(
        'UPDATE `{PREFIX}users` SET `color_mode` = ? WHERE `id` = ?',
        [$mode, $uid]
    );
    echo json_encode(['success' => true, 'message' => 'Colour mode saved.']);
} catch (\Throwable $e) {
    // color_mode column may not exist yet (migration 0004 pending).
    // Fail silently — localStorage preference is the primary mechanism.
    lumora_log('error', 'ajax_color_mode: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save preference (column may not exist yet — run database updates).']);
}

exit;
