<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin AJAX: Bulk Enable/Disable Plugins
 *
 * Enables or disables several feature/importer plugins in one call,
 * backing the Activate Selected / Deactivate Selected bulk actions on
 * admin/plugins.php — the per-row Enable/Disable form there stays a plain
 * synchronous POST for a single plugin, since that already never risked a
 * long-running request.
 *
 * POST params: ids[] (string plugin id, up to 100), do ('enable'|'disable'), csrf_token (string)
 * Response:    JSON { changed: int, errors: string[] }
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.1
 * @see        PluginService::enablePlugin()/disablePlugin() Do the actual work.
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

$do = (string) ($_POST['do'] ?? '');
if ($do !== 'enable' && $do !== 'disable') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

$ids_raw = $_POST['ids'] ?? [];
if (!is_array($ids_raw) || count($ids_raw) === 0) {
    echo json_encode(['changed' => 0, 'errors' => ['No plugin IDs provided.']]);
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
foreach (PluginService::discoverManageablePlugins() as $p) {
    $plugins_by_id[$p['id']] = $p;
}

$actor      = lumora_current_user();
$actor_id   = (int) ($actor['user_id'] ?? 0);
$actor_name = (string) ($actor['username'] ?? '');
$actor_ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$changed = 0;
$errors  = [];

foreach ($ids as $id) {
    $plugin = $plugins_by_id[$id] ?? null;
    if ($plugin === null) {
        // Already gone or not a toggleable type — nothing to do.
        continue;
    }

    $enabled = PluginService::isEnabled($id, $plugin['type']);

    if ($do === 'enable') {
        if ($enabled) continue; // Already in the desired state.

        if (!PluginService::isCompatible($plugin['min_lumora'])) {
            $errors[] = $plugin['name'] . ': requires Lumora ' . $plugin['min_lumora'] . ' or newer.';
            continue;
        }
        if (!PluginService::enablePlugin($plugin)) {
            $errors[] = $plugin['name'] . ': could not be activated — see the server error log for details.';
            continue;
        }
        LogService::log('plugin_enabled', $actor_id, $actor_name, $actor_ip, 'Enabled plugin "' . $plugin['name'] . '" (' . $plugin['id'] . ')');
        $changed++;
    } else {
        if (!$enabled) continue; // Already in the desired state.

        PluginService::disablePlugin($plugin);
        LogService::log('plugin_disabled', $actor_id, $actor_name, $actor_ip, 'Disabled plugin "' . $plugin['name'] . '" (' . $plugin['id'] . ')');
        $changed++;
    }
}

echo json_encode(['changed' => $changed, 'errors' => $errors]);
exit;
