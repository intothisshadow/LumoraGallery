<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin AJAX: Delete Plugins
 *
 * Permanently deletes one or more disabled feature plugins' directories
 * from disk, backing both the per-row Delete button and the Delete
 * Selected bulk action on admin/plugins.php.
 *
 * POST params: ids[] (string plugin id, up to 100), csrf_token (string)
 * Response:    JSON { deleted: int, errors: string[] }
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.0
 * @see        PluginService::deletePlugin() Path-safety and enabled-state checks live here.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('site_configuration');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

lumora_csrf_validate();

$ids_raw = $_POST['ids'] ?? [];
if (!is_array($ids_raw) || count($ids_raw) === 0) {
    echo json_encode(['deleted' => 0, 'errors' => ['No plugin IDs provided.']]);
    exit;
}

// Cast to strings and deduplicate; cap at 100 per call — plugin lists are
// small, this is generous headroom rather than a realistic ceiling.
$ids = array_values(array_unique(array_filter(
    array_map(static fn(mixed $v): string => (string) $v, $ids_raw),
    static fn(string $v): bool => $v !== ''
)));
if (count($ids) > 100) {
    $ids = array_slice($ids, 0, 100);
}

$plugins_by_id = [];
foreach (PluginService::discoverAll() as $p) {
    $plugins_by_id[$p['id']] = $p;
}

$deleted = 0;
$errors  = [];

foreach ($ids as $id) {
    $plugin = $plugins_by_id[$id] ?? null;

    if ($plugin === null) {
        // Already gone by the time the request landed — not an error,
        // just nothing left to do (a concurrent request may have deleted
        // it already, or it never existed).
        continue;
    }

    if ($plugin['type'] === 'feature' && PluginService::isEnabled($id)) {
        $errors[] = $plugin['name'] . ': still enabled — disable it first.';
        continue;
    }

    if (PluginService::deletePlugin($id)) {
        $deleted++;
    } else {
        $errors[] = $plugin['name'] . ': could not be deleted. Check folder permissions.';
    }
}

echo json_encode(['deleted' => $deleted, 'errors' => $errors]);
exit;
