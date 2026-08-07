<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Assign Albums to a Contributor
 *
 * Lets an administrator or moderator ('manage_albums' permission) grant an
 * album-restricted account (any group holding 'manage_assigned_albums' —
 * the built-in contributor group, or a custom group with that permission)
 * access to specific albums, backed by AlbumAssignmentService /
 * {PREFIX}album_assignments (Migration0005).
 *
 * Reached via the "Assign Albums" button next to eligible rows on
 * admin/users.php. Gated on 'manage_albums' rather than 'user_management' —
 * this is an album-scoped action, not a user-management action, so a
 * moderator can grant album access without needing the Users page.
 *
 * GET  ?user=<id>  Renders the checkbox picker, pre-checked with the
 *                  target account's current assignments.
 * POST action=save Replaces the full assignment set in one call via
 *                  AlbumAssignmentService::setAssignedAlbums().
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.10.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('manage_albums');

$current_user     = lumora_current_user();
$current_user_id  = (int) ($current_user['user_id'] ?? 0);
$users_url        = h(lumora_base_url() . 'admin/users.php');
$csrf              = h(lumora_csrf_token());

$user_id = lumora_int($_GET['user'] ?? ($_POST['user_id'] ?? 0), 0, 1);

// ── Migration guard ───────────────────────────────────────────────────────────
// Migration0005 creates {PREFIX}album_assignments; must be applied before
// assignments can be saved or listed.
if (in_array('Migration0005_CreateAlbumAssignmentsTable', SchemaService::getPendingMigrations(), true)) {
    $upd_h   = h(lumora_base_url() . 'admin/update.php');
    $content = '<div class="alert alert-warning">'
             . '<strong>⚠ Database update required</strong><br>'
             . 'Assigning albums to contributors requires a schema update (Migration 0005) '
             . 'that has not yet been applied. Please run pending migrations first.'
             . '<div class="mt-2">'
             . '<a href="' . $upd_h . '" class="btn btn-warning btn-sm">🗄 Run Database Update</a>'
             . ' <a href="' . $users_url . '" class="btn btn-outline-secondary btn-sm">← Back to Users</a>'
             . '</div></div>';
    lum_admin_page('Assign Albums', $content, 'users');
}

if ($user_id <= 0) {
    lum_flash('Invalid user.', 'danger');
    lumora_redirect($users_url);
}

$target = UserService::getUser($user_id);
if (!$target) {
    lum_flash('User not found.', 'danger');
    lumora_redirect($users_url);
}
if (!UserService::roleHasPermission((string) $target['role'], 'manage_assigned_albums')) {
    lum_flash('Album assignments only apply to accounts whose group holds the "manage_assigned_albums" permission.', 'danger');
    lumora_redirect($users_url);
}

$self_url = lumora_base_url() . 'admin/user_albums.php?user=' . $user_id;
$self_h   = h($self_url);

// ── POST: save ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    $submitted_ids = $_POST['album_ids'] ?? [];
    $album_ids     = is_array($submitted_ids) ? array_map('intval', $submitted_ids) : [];

    $result = AlbumAssignmentService::setAssignedAlbums($user_id, $album_ids, $current_user_id);
    if ($result === true) {
        lum_flash('Album assignments updated for "' . h($target['username']) . '".');
    } else {
        lum_flash((string) $result, 'danger');
    }
    lumora_redirect($self_url);
}

// ── GET: build the checkbox picker ────────────────────────────────────────────
$all_albums   = GalleryService::getAllAdminAlbumsGrouped();
$assigned_ids = array_flip(AlbumAssignmentService::getAssignedAlbumIds($user_id));

$rows = '';
foreach ($all_albums as $a) {
    $album_id_v = (int) $a['id'];
    $checked    = isset($assigned_ids[$album_id_v]) ? ' checked' : '';
    $title_h    = h($a['title']);
    $cat_h      = h($a['cat_name'] ?? '— No category —');
    $folder_h   = h($a['folder']);
    $img_cnt    = number_format((int) $a['image_count']);

    $rows .= '<tr class="lum-ua-row" data-search="' . h(mb_strtolower($a['title'] . ' ' . ($a['cat_name'] ?? '') . ' ' . $a['folder'])) . '">'
        . '<td class="text-center" style="width:36px">'
        .   '<input type="checkbox" class="form-check-input" name="album_ids[]" value="' . $album_id_v . '"' . $checked . '>'
        . '</td>'
        . '<td>' . $title_h . '</td>'
        . '<td class="text-muted small">' . $cat_h . '</td>'
        . '<td><code class="small">' . $folder_h . '</code></td>'
        . '<td class="text-muted small">' . $img_cnt . '</td>'
        . '</tr>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="5" class="text-center text-muted py-4">No albums exist yet.</td></tr>';
}

$username_h = h($target['username']);
$current_count = count($assigned_ids);

$content = <<<HTML
<div class="mb-3">
  <a href="{$users_url}" class="btn btn-sm btn-outline-secondary">← Back to Users</a>
</div>

<div class="lum-adm-card mb-3">
  <h5 class="mb-1">Assign Albums to {$username_h}</h5>
  <p class="text-muted small mb-0">
    Only checked albums will be visible to this contributor in Albums, Batch Add,
    and the album picker. Currently assigned: <strong>{$current_count}</strong>.
  </p>
</div>

<form method="post" action="{$self_h}">
  <input type="hidden" name="action"   value="save">
  <input type="hidden" name="user_id"  value="{$user_id}">
  <input type="hidden" name="csrf_token" value="{$csrf}">

  <div class="lum-adm-card mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <input type="text" id="lum-ua-filter" class="form-control form-control-sm" style="max-width:320px"
             placeholder="Filter by title, category, or folder…" autocomplete="off">
      <button type="button" id="lum-ua-check-all" class="btn btn-sm btn-outline-secondary">Check visible</button>
      <button type="button" id="lum-ua-uncheck-all" class="btn btn-sm btn-outline-secondary">Uncheck visible</button>
      <span id="lum-ua-count" class="text-muted small ms-auto"></span>
    </div>
    <div class="table-responsive" style="max-height:60vh;overflow-y:auto">
      <table class="table table-hover lum-adm-table align-middle mb-0">
        <thead class="sticky-top" style="background:var(--bs-body-bg,#fff)">
          <tr><th></th><th>Title</th><th>Category</th><th>Folder</th><th>Images</th></tr>
        </thead>
        <tbody id="lum-ua-tbody">{$rows}</tbody>
      </table>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Save Assignments</button>
  <a href="{$users_url}" class="btn btn-outline-secondary">Cancel</a>
</form>

<script>
(function () {
  'use strict';

  var filter    = document.getElementById('lum-ua-filter');
  var tbody     = document.getElementById('lum-ua-tbody');
  var checkAll  = document.getElementById('lum-ua-check-all');
  var uncheckAll= document.getElementById('lum-ua-uncheck-all');
  var countEl   = document.getElementById('lum-ua-count');
  if (!tbody) return;

  var rows = Array.prototype.slice.call(tbody.querySelectorAll('.lum-ua-row'));

  function updateCount() {
    var checked = tbody.querySelectorAll('input[type=checkbox]:checked').length;
    if (countEl) countEl.textContent = checked + ' selected';
  }

  function applyFilter() {
    var term = (filter.value || '').toLowerCase().trim();
    rows.forEach(function (row) {
      var match = term === '' || (row.dataset.search || '').indexOf(term) !== -1;
      row.style.display = match ? '' : 'none';
    });
  }

  if (filter) filter.addEventListener('input', applyFilter);

  function setVisibleChecked(state) {
    rows.forEach(function (row) {
      if (row.style.display === 'none') return;
      var cb = row.querySelector('input[type=checkbox]');
      if (cb) cb.checked = state;
    });
    updateCount();
  }

  if (checkAll)   checkAll.addEventListener('click', function () { setVisibleChecked(true); });
  if (uncheckAll) uncheckAll.addEventListener('click', function () { setVisibleChecked(false); });

  tbody.addEventListener('change', function (e) {
    if (e.target && e.target.type === 'checkbox') updateCount();
  });

  updateCount();
}());
</script>
HTML;

lum_admin_page('Assign Albums', $content, 'users');
