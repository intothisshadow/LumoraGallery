<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Group Management
 *
 * Allows administrators to:
 *   - View every permission group (the three built-in system groups plus any
 *     custom groups) with its permission set and current user count
 *   - Grant or revoke individual permissions per group
 *   - Create new custom groups
 *   - Rename any group (including system groups)
 *   - Delete custom groups (with safeguards — see below)
 *
 * Permission changes take effect immediately: GroupService caches permissions
 * per-request only, and every permission check reads live from
 * {PREFIX}group_permissions on the next request for any user in the group.
 *
 * Safeguards:
 *   - The three system groups (admin, moderator, contributor) can never be
 *     deleted.
 *   - The 'admin' system group can never lose 'user_management' or
 *     'site_configuration' — GroupService::updateGroupPermissions() silently
 *     re-adds them if a submitted form omits either one, so administrators
 *     can never lock themselves out of Users/Groups or Configuration.
 *   - A group with one or more user accounts still assigned to it cannot be
 *     deleted — reassign those accounts to a different group first (via
 *     Admin → Users).
 *
 * Security:
 *   - All POST actions require a valid CSRF token.
 *   - Gated on the 'user_management' permission, same as admin/users.php.
 *   - Requires Migration0007 (DB version 13) for the {PREFIX}groups /
 *     {PREFIX}group_permissions tables; a warning with a migration link is
 *     shown if pending (GroupService falls back to the legacy hardcoded
 *     three-role behaviour in the meantime, so nothing breaks either way).
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
lumora_require_permission('user_management');

$base   = lumora_base_url() . 'admin/groups.php';
$base_h = h($base);
$csrf_h = h(lumora_csrf_token());

// ── POST: handle all write actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $action = trim($_POST['action'] ?? '');

    switch ($action) {

        case 'create':
            $name        = trim($_POST['name'] ?? '');
            $permissions = array_map('strval', (array) ($_POST['permissions'] ?? []));

            $result = GroupService::createGroup($name, $permissions);
            if (is_int($result)) {
                lum_flash('Group "' . h($name) . '" created successfully.');
                lumora_redirect($base . '?action=edit&id=' . $result);
            }
            lum_flash((string) $result, 'danger');
            lumora_redirect($base . '?action=new');
            break;

        case 'save':
            $gid         = lumora_int($_POST['group_id'] ?? 0, 0, 1);
            $name        = trim($_POST['name'] ?? '');
            $permissions = array_map('strval', (array) ($_POST['permissions'] ?? []));

            if ($gid <= 0) {
                lum_flash('Invalid group.', 'danger');
                lumora_redirect($base);
            }

            $renameResult = GroupService::renameGroup($gid, $name);
            $permResult   = GroupService::updateGroupPermissions($gid, $permissions);

            if ($renameResult === true && $permResult === true) {
                lum_flash('Group updated successfully.');
            } else {
                $err = $renameResult !== true ? $renameResult : $permResult;
                lum_flash((string) $err, 'danger');
            }
            lumora_redirect($base . '?action=edit&id=' . $gid);
            break;

        case 'delete':
            $gid = lumora_int($_POST['group_id'] ?? 0, 0, 1);

            if ($gid <= 0) {
                lum_flash('Invalid group.', 'danger');
                lumora_redirect($base);
            }

            $result = GroupService::deleteGroup($gid);
            if ($result === true) {
                lum_flash('Group deleted.');
            } else {
                lum_flash((string) $result, 'danger');
            }
            lumora_redirect($base);
            break;

        default:
            lumora_redirect($base);
    }
}

// ── GET: determine which view to render ──────────────────────────────────────
$view_action = trim($_GET['action'] ?? '');
$edit_id     = lumora_int($_GET['id'] ?? 0, 0, 1);

// ── Migration guard ───────────────────────────────────────────────────────────
// Migration0007 creates {PREFIX}groups / {PREFIX}group_permissions and widens
// users.role from an ENUM to a VARCHAR. GroupService falls back to the legacy
// hardcoded three-role behaviour until this has run, so the page still works
// — but creating/editing/deleting groups requires the real tables.
if (in_array('Migration0007_CreateGroupsTables', SchemaService::getPendingMigrations(), true)) {
    $upd_h   = h(lumora_base_url() . 'admin/update.php');
    $content = '<div class="alert alert-warning">'
             . '<strong>⚠ Database update required</strong><br>'
             . 'Creating, renaming, and deleting groups requires a schema update '
             . '(Migration 0007) that has not yet been applied. The built-in '
             . 'Administrator, Moderator, and Contributor groups are shown below '
             . 'using their current fixed permissions in the meantime.'
             . '<div class="mt-2">'
             . '<a href="' . $upd_h . '" class="btn btn-warning btn-sm">🗄 Run Database Update</a>'
             . '</div></div>';
} else {
    $content = '';
}

/**
 * Render the permission checkbox list shared by the New and Edit forms.
 *
 * @param list<string> $checked   Permission slugs currently granted.
 * @param list<string> $locked    Permission slugs that are always checked and
 *                                disabled (cannot be unchecked from the UI).
 */
function lum_group_permission_checkboxes(array $checked, array $locked = []): string
{
    $html = '<div class="row row-cols-1 row-cols-md-2 g-2">';
    foreach (GroupService::ALL_PERMISSIONS as $slug => $label) {
        $id       = 'lum-perm-' . h($slug);
        $isLocked = in_array($slug, $locked, true);
        $isChecked = $isLocked || in_array($slug, $checked, true);
        $chk      = $isChecked ? ' checked' : '';
        $dis      = $isLocked ? ' disabled' : '';
        // Disabled checkboxes don't submit — a hidden input keeps the value
        // in the POST body so a locked permission is never silently dropped.
        $hidden   = $isLocked
            ? '<input type="hidden" name="permissions[]" value="' . h($slug) . '">'
            : '';
        $lockNote = $isLocked
            ? ' <span class="text-muted small">(required)</span>'
            : '';
        $html .= '<div class="col">'
               . '<div class="form-check">'
               . '<input class="form-check-input" type="checkbox" name="permissions[]" '
               .   'value="' . h($slug) . '" id="' . $id . '"' . $chk . $dis . '>'
               . $hidden
               . '<label class="form-check-label small" for="' . $id . '">' . h($label) . '</label>'
               . $lockNote
               . '</div></div>';
    }
    $html .= '</div>';
    return $html;
}

// ════════════════════════════════════════════════════════════════════════════
// VIEW: Create Group
// ════════════════════════════════════════════════════════════════════════════
if ($view_action === 'new') {

    $checkboxes = lum_group_permission_checkboxes([]);

    $content .= <<<HTML
<div class="mb-3">
  <a href="{$base_h}" class="btn btn-sm btn-outline-secondary">← Back to Groups</a>
</div>

<div class="lum-adm-card" style="max-width:720px">
  <h5 class="mb-1">Create New Group</h5>
  <p class="text-muted small mb-3">Custom groups can be assigned to staff accounts on the
    Users page, alongside the built-in Administrator, Moderator, and Contributor groups.</p>

  <form method="post" action="{$base_h}">
    <input type="hidden" name="action"     value="create">
    <input type="hidden" name="csrf_token" value="{$csrf_h}">

    <div class="mb-3">
      <label class="form-label fw-semibold" for="lum-grp-name">Group Name</label>
      <input type="text" id="lum-grp-name" name="name" class="form-control"
             required minlength="2" maxlength="100" autocomplete="off">
      <div class="form-text">2–100 characters. Used to derive a fixed internal identifier;
        choose a name distinct from existing groups.</div>
    </div>

    <div class="mb-4">
      <label class="form-label fw-semibold d-block">Permissions</label>
      {$checkboxes}
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">Create Group</button>
      <a href="{$base_h}" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
HTML;

    lum_admin_page('Create Group', $content, 'groups');
}

// ════════════════════════════════════════════════════════════════════════════
// VIEW: Edit Group
// ════════════════════════════════════════════════════════════════════════════
if ($view_action === 'edit' && $edit_id > 0) {

    $g = GroupService::getGroup($edit_id);
    if (!$g) {
        lum_flash('Group not found.', 'danger');
        lumora_redirect($base);
    }

    $name_h    = h($g['name']);
    $slug_h    = h($g['slug']);
    $is_system = $g['is_system'];
    $user_ct   = $g['user_count'];
    $locked    = ($g['slug'] === 'admin') ? GroupService::ADMIN_LOCKED_PERMISSIONS : [];
    $checkboxes = lum_group_permission_checkboxes($g['permissions'], $locked);
    $users_url_h = h(lumora_base_url() . 'admin/users.php');
    $user_ct_s   = ($user_ct === 1) ? '' : 's';
    $user_ct_es  = ($user_ct === 1) ? 's' : '';

    $type_b = $is_system
        ? '<span class="badge bg-primary">System Group</span>'
        : '<span class="badge bg-secondary">Custom Group</span>';

    $system_note = $is_system
        ? '<div class="alert alert-info py-2 small mb-3">This is a built-in system group. '
          . 'It cannot be deleted, but its display name and permissions can still be adjusted.</div>'
        : '';

    // Delete panel — only meaningful for non-system, unused groups.
    $delete_panel = '';
    if ($is_system) {
        $delete_panel = '<div class="lum-adm-card">'
            . '<h5 class="mb-1">Delete Group</h5>'
            . '<p class="text-muted small mb-0">System groups cannot be deleted.</p>'
            . '</div>';
    } elseif ($user_ct > 0) {
        $delete_panel = '<div class="lum-adm-card">'
            . '<h5 class="mb-1">Delete Group</h5>'
            . '<p class="text-muted small mb-0">Cannot delete this group while <strong>' . $user_ct
            . '</strong> user account' . ($user_ct === 1 ? '' : 's') . ' still belong'
            . ($user_ct === 1 ? 's' : '') . ' to it. Reassign '
            . ($user_ct === 1 ? 'it' : 'them') . ' to a different group from '
            . '<a href="' . h(lumora_base_url() . 'admin/users.php') . '">Users</a> first.</p>'
            . '</div>';
    } else {
        $confirm_h = h('Delete group "' . $g['name'] . '"? This cannot be undone.');
        $delete_panel = '<div class="lum-adm-card">'
            . '<h5 class="mb-1">Delete Group</h5>'
            . '<p class="text-muted small mb-3">No user accounts currently belong to this group.</p>'
            . '<form method="post" action="' . $base_h . '" data-confirm="' . $confirm_h . '">'
            . '<input type="hidden" name="action"   value="delete">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
            . '<input type="hidden" name="group_id" value="' . $edit_id . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger">🗑 Delete Group</button>'
            . '</form></div>';
    }

    $content .= <<<HTML
<div class="mb-3">
  <a href="{$base_h}" class="btn btn-sm btn-outline-secondary">← Back to Groups</a>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="lum-adm-card">
      <div class="d-flex align-items-center justify-content-between mb-1">
        <h5 class="mb-0">Edit Group</h5>
        {$type_b}
      </div>
      <p class="text-muted small mb-3">Identifier: <code>{$slug_h}</code> — fixed at creation
        and used internally; renaming only changes the display name shown in the admin panel.</p>

      {$system_note}

      <form method="post" action="{$base_h}">
        <input type="hidden" name="action"   value="save">
        <input type="hidden" name="csrf_token" value="{$csrf_h}">
        <input type="hidden" name="group_id" value="{$edit_id}">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="lum-grp-name">Group Name</label>
          <input type="text" id="lum-grp-name" name="name" class="form-control"
                 value="{$name_h}" required minlength="2" maxlength="100" autocomplete="off">
        </div>

        <div class="mb-4">
          <label class="form-label fw-semibold d-block">Permissions</label>
          {$checkboxes}
        </div>

        <button type="submit" class="btn btn-primary">Save Changes</button>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="lum-adm-card mb-4">
      <h5 class="mb-1">Members</h5>
      <p class="text-muted small mb-3">
        <strong>{$user_ct}</strong> user account{$user_ct_s} currently
        belong{$user_ct_es} to this group.
      </p>
      <a href="{$users_url_h}" class="btn btn-outline-primary btn-sm">Manage Users</a>
    </div>

    {$delete_panel}
  </div>
</div>

<script>
(function () {
  'use strict';
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });
}());
</script>
HTML;

    lum_admin_page('Edit Group: ' . $name_h, $content, 'groups');
}

// ════════════════════════════════════════════════════════════════════════════
// VIEW: Group List (default)
// ════════════════════════════════════════════════════════════════════════════
$groups  = GroupService::getAllGroups();
$total   = count($groups);
$summary = $total . ' group' . ($total === 1 ? '' : 's');
$new_btn = '<a href="' . $base_h . '?action=new" class="btn btn-primary btn-sm">+ New Group</a>';

$rows = '';
foreach ($groups as $g) {
    $gid      = $g['id'];
    $name_h   = h($g['name']);
    $slug_h   = h($g['slug']);
    $edit_url = $gid > 0 ? h($base . '?action=edit&id=' . $gid) : '';
    $type_b   = $g['is_system']
        ? '<span class="badge bg-primary">System</span>'
        : '<span class="badge bg-secondary">Custom</span>';
    $perm_ct  = count($g['permissions']);
    $user_ct  = $g['user_count'];

    $name_cell = $edit_url !== ''
        ? '<a href="' . $edit_url . '" class="fw-semibold text-decoration-none">' . $name_h . '</a>'
        : '<span class="fw-semibold">' . $name_h . ' <span class="text-muted small">(pending migration)</span></span>';

    $actions = $edit_url !== ''
        ? '<a href="' . $edit_url . '" class="btn btn-sm btn-outline-primary">Edit</a>'
        : '<span class="text-muted small">Run pending database update to manage</span>';

    $rows .= '<tr>'
           . '<td class="align-middle">' . $name_cell . '</td>'
           . '<td class="align-middle"><code class="small">' . $slug_h . '</code></td>'
           . '<td class="align-middle">' . $type_b . '</td>'
           . '<td class="align-middle small">' . $perm_ct . ' permission' . ($perm_ct === 1 ? '' : 's') . '</td>'
           . '<td class="align-middle small">' . $user_ct . ' user' . ($user_ct === 1 ? '' : 's') . '</td>'
           . '<td class="align-middle">' . $actions . '</td>'
           . '</tr>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="6" class="text-center text-muted py-4">No groups found.</td></tr>';
}

$content .= <<<HTML
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div class="text-muted small">{$summary}</div>
  {$new_btn}
</div>

<div class="lum-adm-card p-0 mt-2 mb-3">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Identifier</th>
          <th>Type</th>
          <th>Permissions</th>
          <th>Users</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        {$rows}
      </tbody>
    </table>
  </div>
</div>
HTML;

lum_admin_page('Groups', $content, 'groups');
