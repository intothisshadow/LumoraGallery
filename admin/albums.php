<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Album Management
 *
 * Actions: list (default), new, edit, save, delete
 *
 * Creating an album:
 *   - Generates a zero-padded folder name (e.g. "00001") via lumora_generate_folder()
 *     unless the admin specifies a custom folder name.
 *   - Creates the filesystem directory albums/{folder}/ if it doesn't exist.
 *
 * List view — three modes:
 *
 *   Hierarchy mode (default — no search term, no category filter; 'manage_albums'
 *   holders only): All albums are grouped under their category in the full
 *     category tree. Albums belonging to subcategories are nested beneath their
 *     parent category header. Uncategorized albums (category_id = 0) appear at
 *     the top in a dedicated section. The complete album set is loaded in a
 *     single query; no pagination is applied in this mode.
 *
 *   Flat / filtered mode (search term or category filter active; 'manage_albums'
 *   holders only): Reverts to the traditional paginated table. Pagination,
 *     per-page selector, and category filter all work as before. A ✕ Clear
 *     button resets to hierarchy mode.
 *
 *   Assigned mode ('manage_assigned_albums' holders without 'manage_albums' —
 *   i.e. contributors): A flat, unpaginated table of only the albums assigned
 *     to the current user via AlbumAssignmentService. No New Album button, no
 *     category filter, no per-row Delete button; category reassignment is
 *     read-only on the edit form. See TODO.md §18 for the full design.
 *
 *   GET parameters (manage_albums holders only):
 *     q:        partial album title search → triggers flat mode
 *     cat:      category filter (ID) → triggers flat mode
 *     per_page: persisted in $_SESSION['lum_adm_per_page_albums']
 *     page:     1-based, clamped by lumora_pagination()
 *
 * Album create/update/delete business logic (folder auto-generation,
 * thumb_image_id validation, cascading delete, on-disk folder handling)
 * lives in GalleryService::createAlbum()/updateAlbum()/deleteAlbum() — this
 * page only handles permission checks, CSRF validation, request parsing,
 * flash messages, and redirects.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_any_permission(['manage_albums', 'manage_assigned_albums']);

$can_manage_all   = lumora_has_permission('manage_albums');
$current_user     = lumora_current_user();
$current_user_id  = (int) ($current_user['user_id'] ?? 0);

$action = $_GET['action'] ?? 'list';
$id     = lumora_int($_GET['id'] ?? 0, 0, 1);
$base   = lumora_base_url() . 'admin/albums.php';
$csrf   = h(lumora_csrf_token());
$base_h = h($base);

// ── POST: save ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $edit_id        = lumora_int($_POST['id']             ?? 0, 0, 0);
        $title          = trim($_POST['title']                ?? '');
        $desc           = trim($_POST['description']          ?? '');
        $visibility     = lumora_int($_POST['visibility']     ?? 0, 0, 0, 1);
        $pos            = lumora_int($_POST['pos']             ?? 0, 0, 0);
        $folder         = lumora_sanitize_folder(trim($_POST['folder'] ?? ''));
        $thumb_image_id = lumora_int($_POST['thumb_image_id'] ?? 0, 0, 0);

        // Only 'manage_albums' holders may create albums or reassign category.
        // A contributor's assigned-album edit form omits the category field
        // entirely, so category_id is only trusted from POST for managers.
        if ($edit_id > 0) {
            lumora_require_album_access($edit_id);
        } else {
            lumora_require_permission('manage_albums');
        }
        $cat_id = $can_manage_all
            ? lumora_int($_POST['category_id'] ?? 0, 0, 0)
            : null; // resolved from the existing row below when editing

        if ($edit_id > 0) {
            // Editing — don't change folder (to avoid breaking filesystem paths).
            $result = GalleryService::updateAlbum($edit_id, [
                'title'          => $title,
                'description'    => $desc,
                'visibility'     => $visibility,
                'pos'            => $pos,
                'thumb_image_id' => $thumb_image_id,
                // category_id is only ever written by 'manage_albums' holders —
                // GalleryService::updateAlbum() ignores this key entirely when
                // $can_manage_all is false, leaving the existing category untouched.
                'category_id'    => $cat_id ?? 0,
            ], allow_category_change: $can_manage_all);

            if (is_string($result)) {
                lum_flash($result, 'danger');
                lumora_redirect($base . '?action=edit&id=' . $edit_id);
            }
            if ($result['warning'] !== null) {
                lum_flash($result['warning'], 'warning');
            }
            lum_flash('Album updated.');
        } else {
            // New album (manage_albums holders only — enforced above).
            $result = GalleryService::createAlbum([
                'category_id'    => $cat_id ?? 0,
                'folder'         => $folder,
                'title'          => $title,
                'description'    => $desc,
                'visibility'     => $visibility,
                'pos'            => $pos,
                'thumb_image_id' => $thumb_image_id,
            ]);

            if (is_string($result)) {
                lum_flash($result, 'danger');
                lumora_redirect($base . '?action=new');
            }
            if ($result['warning'] !== null) {
                lum_flash($result['warning'], 'warning');
            }
            lum_flash('Album "' . $title . '" created. Upload images to albums/' . $result['folder'] . '/');
        }
        lumora_redirect($base);
    }

    if ($act === 'delete') {
        // Contributors can edit assigned albums but never create or delete them.
        lumora_require_permission('manage_albums');

        $del_id = lumora_int($_POST['id'] ?? 0, 0, 1);
        if ($del_id > 0) {
            lum_flash(GalleryService::deleteAlbum($del_id));
        }
        lumora_redirect($base);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Build <option> list for the category dropdown on the new/edit form. */
function album_cat_options(array $cats, int $selected = 0): string
{
    $html = '<option value="0">— No category —</option>';
    foreach ($cats as $c) {
        $sel  = ((int)$c['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . (int)$c['id'] . '"' . $sel . '>'
            . h(($c['parent_id'] > 0 ? '— ' : '') . $c['name'])
            . '</option>';
    }
    return $html;
}

// ── Hierarchy render helpers ──────────────────────────────────────────────────

/**
 * Render a single album table row for the hierarchy view (6 columns).
 *
 * The Title cell is indented by $indent_px to reflect the category depth.
 *
 * @param array<string, mixed> $a         Album row (includes image_count, folder, etc.).
 * @param int                  $indent_px Left padding in pixels for the title cell.
 * @param string               $base_h    HTML-escaped base URL.
 * @param string               $csrf      HTML-escaped CSRF token.
 */
function render_album_row(array $a, int $indent_px, string $base_h, string $csrf): string
{
    $title_h    = h($a['title']);
    $folder_h   = h($a['folder']);
    $vis_h      = $a['visibility']
        ? '<span class="badge bg-secondary">Private</span>'
        : '<span class="badge bg-success">Public</span>';
    $img_cnt    = number_format((int) $a['image_count']);
    $edit_url   = h($base_h . '?action=edit&id=' . (int) $a['id']);
    $batch_url  = h(lumora_base_url() . 'admin/batch.php?album='  . (int) $a['id']);
    $images_url = h(lumora_base_url() . 'admin/images.php?album=' . (int) $a['id']);
    $view_url   = h(lumora_base_url() . 'album.php?album='        . (int) $a['id']);
    $del_conf   = h('Delete album \'' . $a['title'] . '\'? All DB records will be removed. If the album folder is empty it will also be deleted; otherwise files on disk are kept.');

    $title_cell = '<div style="padding-left:' . $indent_px . 'px">'
        . '<a href="' . $edit_url . '">' . $title_h . '</a>'
        . '</div>';

    return '<tr>'
        . '<td>' . $title_cell . '</td>'
        . '<td><code class="small">' . $folder_h . '</code></td>'
        . '<td>' . $img_cnt . '</td>'
        . '<td>' . $vis_h . '</td>'
        . '<td>'
        .   '<a href="' . $batch_url  . '" class="btn btn-sm btn-outline-primary"    title="Batch Add">&#x2B06;&#xFE0F;</a>'
        .   '<a href="' . $images_url . '" class="btn btn-sm btn-outline-secondary"  title="Manage Images">&#x1F4F8;</a>'
        .   '<a href="' . $view_url   . '" class="btn btn-sm btn-outline-secondary"  title="View album" target="_blank">&#x2197;</a>'
        .   '<a href="' . $edit_url   . '" class="btn btn-sm btn-outline-secondary"  title="Edit">&#x270F;&#xFE0F;</a>'
        . '</td>'
        . '<td>'
        .   '<form method="post" action="' . $base_h . '" data-confirm="' . $del_conf . '"'
        .       ' onsubmit="return confirm(this.dataset.confirm)">'
        .     '<input type="hidden" name="action"     value="delete">'
        .     '<input type="hidden" name="id"         value="' . (int) $a['id'] . '">'
        .     '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
        .     '<button type="submit" class="btn btn-sm btn-outline-danger">&#x1F5D1;</button>'
        .   '</form>'
        . '</td>'
        . '</tr>';
}

/**
 * Render a single album table row for the contributor "assigned albums" view
 * (5 columns — no Delete column, since contributors cannot delete albums).
 *
 * @param array<string, mixed> $a Album row (includes image_count, folder, cat_name, etc.).
 */
function render_assigned_album_row(array $a): string
{
    $title_h    = h($a['title']);
    $cat_h      = h($a['cat_name'] ?? '—');
    $folder_h   = h($a['folder']);
    $vis_h      = $a['visibility']
        ? '<span class="badge bg-secondary">Private</span>'
        : '<span class="badge bg-success">Public</span>';
    $img_cnt    = number_format((int) $a['image_count']);
    $edit_url   = h(lumora_base_url() . 'admin/albums.php?action=edit&id=' . (int) $a['id']);
    $batch_url  = h(lumora_base_url() . 'admin/batch.php?album='  . (int) $a['id']);
    $images_url = h(lumora_base_url() . 'admin/images.php?album=' . (int) $a['id']);
    $view_url   = h(lumora_base_url() . 'album.php?album='        . (int) $a['id']);

    return '<tr>'
        . '<td><a href="' . $edit_url . '">' . $title_h . '</a></td>'
        . '<td>' . $cat_h . '</td>'
        . '<td><code>' . $folder_h . '</code></td>'
        . '<td>' . $img_cnt . '</td>'
        . '<td>' . $vis_h . '</td>'
        . '<td>'
        .   '<a href="' . $batch_url  . '" class="btn btn-sm btn-outline-primary"   title="Batch Add">&#x2B06;&#xFE0F;</a>'
        .   '<a href="' . $images_url . '" class="btn btn-sm btn-outline-secondary" title="Manage Images">&#x1F4F8;</a>'
        .   '<a href="' . $view_url   . '" class="btn btn-sm btn-outline-secondary" title="View album" target="_blank">&#x2197;</a>'
        .   '<a href="' . $edit_url   . '" class="btn btn-sm btn-outline-secondary" title="Edit">&#x270F;&#xFE0F;</a>'
        . '</td>'
        . '</tr>';
}

/**
 * Recursively render category section headers and album rows for the hierarchy view.
 *
 * For each child category of $parent_cat_id:
 *   1. Renders a section-header <tr> showing the category name and album count.
 *   2. Renders each album in that category as an indented album row.
 *   3. Recurses into that category's children at depth + 1.
 *
 * Only categories that exist in $cats_by_parent are visited. A $visited
 * ref-array prevents infinite recursion caused by corrupt parent_id cycles.
 *
 * @param array<int, list<array<string, mixed>>> $cats_by_parent  parent_id => [category rows]
 * @param array<int, list<array<string, mixed>>> $albums_by_cat   category_id => [album rows]
 * @param int                                    $parent_cat_id   Starting parent category ID.
 * @param int                                    $depth           Nesting depth (0 = root categories).
 * @param string                                 $base_h          HTML-escaped base URL.
 * @param string                                 $csrf            HTML-escaped CSRF token.
 * @param array<int, true>                       $visited         Cycle guard, passed by ref.
 */
function render_album_tree(
    array  $cats_by_parent,
    array  $albums_by_cat,
    int    $parent_cat_id,
    int    $depth,
    string $base_h,
    string $csrf,
    array  &$visited
): string {
    if (!isset($cats_by_parent[$parent_cat_id])) return '';
    $html = '';

    foreach ($cats_by_parent[$parent_cat_id] as $c) {
        $cat_id = (int) $c['id'];
        if (isset($visited[$cat_id])) continue; // cycle guard
        $visited[$cat_id] = true;

        $cat_albums      = $albums_by_cat[$cat_id] ?? [];
        $cat_album_count = count($cat_albums);

        // Category section header: indented tree connector + name + album count badge.
        $cat_indent_px = $depth * 20;
        $connector     = $depth > 0
            ? '<span class="lum-tree-connector" aria-hidden="true">└ </span>'
            : '';
        $cat_name_h    = h($c['name']);
        $badge         = $cat_album_count > 0
            ? '<span class="badge rounded-pill text-bg-primary ms-2">' . $cat_album_count . '</span>'
            : '';

        $header_inner = '<div style="padding-left:' . $cat_indent_px . 'px">'
            . $connector . '<strong>' . $cat_name_h . '</strong>' . $badge
            . '</div>';

        $html .= '<tr class="lum-tree-cat-header"><td colspan="6">' . $header_inner . '</td></tr>';

        // Album rows nested one level deeper than the category header.
        $album_indent_px = ($depth + 1) * 20;
        foreach ($cat_albums as $a) {
            $html .= render_album_row($a, $album_indent_px, $base_h, $csrf);
        }

        // Recurse into child categories.
        $html .= render_album_tree(
            $cats_by_parent, $albums_by_cat, $cat_id, $depth + 1, $base_h, $csrf, $visited
        );
    }

    return $html;
}

// ── New / Edit form ───────────────────────────────────────────────────────────
if ($action === 'new' || $action === 'edit') {
    if ($action === 'new') {
        // Contributors can edit their assigned albums but never create new ones.
        lumora_require_permission('manage_albums');
    } else {
        lumora_require_album_access($id);
    }

    $all_cats = $can_manage_all ? get_all_categories_flat() : [];

    $album = ($action === 'edit' && $id > 0)
        ? LumoraDB::fetchOne('SELECT * FROM `{PREFIX}albums` WHERE id = ?', [$id])
        : null;

    if ($action === 'edit' && !$album) {
        lum_flash('Album not found.', 'danger');
        lumora_redirect($base);
    }

    $ftitle  = $action === 'new' ? 'New Album' : 'Edit Album';
    $title_v = h($album['title']       ?? '');
    $desc_v  = h($album['description'] ?? '');
    $cat_v   = (int)($album['category_id']    ?? 0);
    $vis_v   = (int)($album['visibility']     ?? 0);
    $pos_v   = (int)($album['pos']            ?? 0);
    $id_v    = (int)($album['id']             ?? 0);
    $folder_v= h($album['folder']            ?? '');
    $thumb_v = (int)($album['thumb_image_id'] ?? 0);
    $vis_pub = $vis_v === 0 ? ' selected' : '';
    $vis_prv = $vis_v === 1 ? ' selected' : '';

    // Category reassignment is a 'manage_albums' capability — contributors
    // editing an assigned album see the category name as read-only text.
    if ($can_manage_all) {
        $cat_opts  = album_cat_options($all_cats, $cat_v);
        $cat_field = '<div class="mb-3">
             <label class="form-label fw-semibold">Category</label>
             <select name="category_id" class="form-select">' . $cat_opts . '</select>
           </div>';
    } else {
        $cat_name_h = h($album['cat_name'] ?? '');
        if ($cat_name_h === '') {
            $cat_display = LumoraDB::fetchValue('SELECT name FROM `{PREFIX}categories` WHERE id = ?', [$cat_v]);
            $cat_name_h  = $cat_display !== null ? h((string) $cat_display) : '— No category —';
        }
        $cat_field = '<div class="mb-3">
             <label class="form-label fw-semibold">Category</label>
             <input type="text" value="' . $cat_name_h . '" class="form-control" disabled>
             <div class="form-text">Only an administrator or moderator can move an album between categories.</div>
           </div>';
    }

    // "Assigned to: …" line — visible only to 'manage_albums' holders, and only
    // when the album currently has contributor assignments.
    $assigned_block = '';
    if ($can_manage_all && $id_v > 0) {
        $assigned_users = AlbumAssignmentService::getAssignedUsers($id_v);
        if (!empty($assigned_users)) {
            $names = implode(', ', array_map(static fn(array $u): string => h($u['username']), $assigned_users));
            $assigned_block = '<div class="alert alert-info py-2 small mb-3">'
                . '<strong>Assigned to:</strong> ' . $names
                . '</div>';
        }
    }

    $folder_field = $action === 'new'
        ? '<div class="mb-3">
             <label class="form-label fw-semibold">Folder Path <small class="text-muted">(optional — auto-generated numeric if blank)</small></label>
             <input type="text" name="folder" value="" class="form-control font-monospace"
                    placeholder="e.g. Xena/Season1/1x01-SinsOfThePast">
             <div class="form-text">
               Use <code>/</code> to create subfolders: <code>ShowName/Season2/EpisodeSlug</code>.<br>
               Allowed: letters, digits, hyphens <code>-</code>, underscores <code>_</code>, dots <code>.</code>.<br>
               Must be unique. Leave blank for an auto-generated numeric folder (e.g. <code>00042</code>).
             </div>
           </div>'
        : '<div class="mb-3">
             <label class="form-label fw-semibold">Folder</label>
             <input type="text" value="' . $folder_v . '" class="form-control font-monospace" disabled>
             <div class="form-text">Folder cannot be changed after creation.</div>
           </div>';

    $content = <<<HTML
<a href="{$base_h}" class="btn btn-sm btn-outline-secondary mb-3">← Back to list</a>
{$assigned_block}
<div class="lum-adm-card">
  <form method="post" action="{$base_h}">
    <input type="hidden" name="action"     value="save">
    <input type="hidden" name="id"         value="{$id_v}">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <div class="mb-3">
      <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
      <input type="text" name="title" value="{$title_v}" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Description</label>
      <textarea name="description" rows="3" class="form-control">{$desc_v}</textarea>
    </div>
    {$cat_field}
    {$folder_field}
    <div class="mb-3">
      <label class="form-label fw-semibold">Visibility</label>
      <select name="visibility" class="form-select" style="max-width:200px">
        <option value="0"{$vis_pub}>Public</option>
        <option value="1"{$vis_prv}>Private (hidden)</option>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Position (sort order)</label>
      <input type="number" name="pos" value="{$pos_v}" class="form-control" style="max-width:120px">
    </div>
    <div class="mb-4">
      <label class="form-label fw-semibold">Cover Image <small class="text-muted">(optional)</small></label>
      <input type="number" name="thumb_image_id" value="{$thumb_v}" class="form-control"
             style="max-width:140px" min="0">
      <div class="form-text">Image ID to use as the album cover thumbnail. 0 = auto-pick the first image in this album.</div>
    </div>
    <button type="submit" class="btn btn-primary">Save Album</button>
  </form>
</div>
HTML;
    lum_admin_page($ftitle, $content, 'albums');
}

// ── List: assigned-only mode (contributors — 'manage_assigned_albums' without
// 'manage_albums') ─────────────────────────────────────────────────────────────
if (!$can_manage_all) {
    $assigned = AlbumAssignmentService::getAssignedAlbums($current_user_id);

    if (empty($assigned)) {
        $content = '<div class="lum-adm-card">'
            . '<div class="alert alert-info mb-0">'
            . "You haven't been assigned any albums yet. Contact an administrator "
            . 'or moderator to get access.'
            . '</div></div>';
        lum_admin_page('Albums', $content, 'albums');
    }

    $rows = '';
    foreach ($assigned as $a) {
        $rows .= render_assigned_album_row($a);
    }

    $label   = count($assigned) === 1 ? 'album' : 'albums';
    $summary = 'Showing your ' . count($assigned) . ' assigned ' . $label;

    $content =
        '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
        . '<span class="text-muted small">' . $summary . '</span>'
        . '</div>'
        . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
        . '<thead><tr>'
        . '<th>Title</th><th>Category</th><th>Folder</th><th>Images</th><th>Visibility</th><th>Actions</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';

    lum_admin_page('Albums', $content, 'albums');
}

// ── List: manage_albums holders (hierarchy / flat / filtered) ─────────────────

// Search / filter state (read early — determines which mode is used below).
$search     = trim($_GET['q']   ?? '');
$search_h   = h($search);
$filter_cat = lumora_int($_GET['cat'] ?? 0, 0, 0);

// Hierarchy mode: active when no search and no category filter are applied.
// Any filter or search term switches to the traditional flat paginated view.
$hierarchy_mode = ($search === '' && $filter_cat === 0);

// Per-page: read from GET, persist in session; used by flat mode and preserved
// in the hierarchy search form so flat mode inherits the preferred page size.
$valid_per_page = [25, 50, 100];
$raw_per_page   = lumora_int($_GET['per_page'] ?? 0, 0, 0);
if (in_array($raw_per_page, $valid_per_page, true)) {
    $_SESSION['lum_adm_per_page_albums'] = $raw_per_page;
    $per_page = $raw_per_page;
} else {
    $per_page = (int) ($_SESSION['lum_adm_per_page_albums'] ?? 25);
    if (!in_array($per_page, $valid_per_page, true)) $per_page = 25;
}

$page  = lumora_int($_GET['page'] ?? 1, 1, 1);
$new_h = h($base . '?action=new');

// ── Hierarchy mode ────────────────────────────────────────────────────────────
if ($hierarchy_mode) {
    // Two queries: all categories (with counts) and all albums.
    $all_cats_h = GalleryService::getAllCategoriesWithCounts();
    $all_albums = GalleryService::getAllAdminAlbumsGrouped();

    // Build data structures for tree rendering.
    $cats_by_parent = [];
    foreach ($all_cats_h as $c) {
        $cats_by_parent[(int) $c['parent_id']][] = $c;
    }
    $albums_by_cat = [];
    foreach ($all_albums as $a) {
        $albums_by_cat[(int) $a['category_id']][] = $a;
    }

    $total_albums = count($all_albums);
    $lbl          = $total_albums === 1 ? 'album' : 'albums';

    // ── Build hierarchy rows ──────────────────────────────────────────────────

    $rows = '';

    // 1. Uncategorized albums (category_id = 0) — always shown first if any exist.
    $uncategorized = $albums_by_cat[0] ?? [];
    if (!empty($uncategorized)) {
        $uc_count   = count($uncategorized);
        $uc_badge   = '<span class="badge rounded-pill text-bg-secondary ms-2">' . $uc_count . '</span>';
        $uc_header  = '<div><em class="text-muted">(No Category)</em>' . $uc_badge . '</div>';
        $rows .= '<tr class="lum-tree-cat-header"><td colspan="6">' . $uc_header . '</td></tr>';
        foreach ($uncategorized as $a) {
            $rows .= render_album_row($a, 20, $base_h, $csrf);
        }
    }

    // 2. Category hierarchy: root categories and their descendants.
    $visited = [];
    $rows   .= render_album_tree($cats_by_parent, $albums_by_cat, 0, 0, $base_h, $csrf, $visited);

    if ($rows === '') {
        $rows = '<tr><td colspan="6" class="text-center text-muted py-4">'
            . 'No albums yet. <a href="' . $new_h . '">Create one</a>.'
            . '</td></tr>';
    }

    // Hierarchy search form — submitting takes the user to flat/search mode.
    $search_form =
        '<form method="get" action="' . $base_h . '" class="d-flex align-items-center gap-2 mb-3 flex-wrap">'
        . '<input type="hidden" name="per_page" value="' . $per_page . '">'
        . '<div class="input-group input-group-sm" style="max-width:340px">'
        . '<input type="text" name="q" value="" class="form-control"'
        . ' placeholder="Search by album name\xe2\x80\xa6" maxlength="200" autocomplete="off">'
        . '<button type="submit" class="btn btn-outline-secondary">Search</button>'
        . '</div>'
        . '</form>';

    $summary_text = $total_albums > 0
        ? 'Hierarchy view &middot; ' . number_format($total_albums) . ' ' . $lbl
        : '0 albums';

    $content =
        $search_form
        . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
        . '<span class="text-muted small">' . $summary_text . '</span>'
        . '<a href="' . $new_h . '" class="btn btn-primary btn-sm">+ New Album</a>'
        . '</div>'
        . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
        . '<thead><tr>'
        . '<th>Title</th>'
        . '<th>Folder</th>'
        . '<th style="width:70px">Images</th>'
        . '<th style="width:90px">Visibility</th>'
        . '<th>Actions</th>'
        . '<th style="width:50px"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';

    lum_admin_page('Albums', $content, 'albums');
}

// ── Flat / filtered mode ──────────────────────────────────────────────────────

// Database queries — only records for the current page are loaded.
$total  = GalleryService::countAdminAlbums($filter_cat, $search);
$albums = GalleryService::getAdminAlbums($filter_cat, $page, $per_page, $search);

// Pagination descriptor. URL pattern preserves cat, q, and per_page.
$url_params = 'per_page=' . $per_page;
if ($search !== '') {
    $url_params = 'q=' . urlencode($search) . '&' . $url_params;
}
if ($filter_cat > 0) {
    $url_params = 'cat=' . $filter_cat . '&' . $url_params;
}
$url_pattern = $base . '?' . $url_params . '&page=%d';
$pag         = lumora_pagination($total, $per_page, $page, $url_pattern);

// Row HTML.
$rows = '';
if (empty($albums)) {
    if ($total === 0 && $search !== '') {
        $clear_url_empty = $base . ($filter_cat > 0 ? '?cat=' . $filter_cat . '&per_page=' . $per_page : '?per_page=' . $per_page);
        $empty_msg = 'No albums found matching <strong>' . $search_h . '</strong>. '
            . '<a href="' . h($clear_url_empty) . '">Clear search</a>.';
    } elseif ($total === 0) {
        $empty_msg = 'No albums yet. <a href="' . $base_h . '?action=new">Create one</a>.';
    } else {
        $empty_msg = 'No albums on this page.';
    }
    $rows = '<tr><td colspan="7" class="text-center text-muted py-4">' . $empty_msg . '</td></tr>';
} else {
    foreach ($albums as $a) {
        $title_h    = h($a['title']);
        $cat_h      = h($a['cat_name'] ?? '—');
        $folder_h   = h($a['folder']);
        $vis_h      = $a['visibility'] ? '<span class="badge bg-secondary">Private</span>' : '<span class="badge bg-success">Public</span>';
        $img_cnt    = number_format((int)$a['image_count']);
        $edit_url   = h($base . '?action=edit&id=' . (int)$a['id']);
        $batch_url  = h(lumora_base_url() . 'admin/batch.php?album='  . (int)$a['id']);
        $images_url = h(lumora_base_url() . 'admin/images.php?album=' . (int)$a['id']);
        $view_url   = h(lumora_base_url() . 'album.php?album='        . (int)$a['id']);
        $del_conf   = h('Delete album \'' . $a['title'] . '\'? All DB records will be removed. If the album folder is empty it will also be deleted; otherwise files on disk are kept.');
        $rows .= '<tr>'
            . '<td><a href="' . $edit_url . '">' . $title_h . '</a></td>'
            . '<td>' . $cat_h . '</td>'
            . '<td><code>' . $folder_h . '</code></td>'
            . '<td>' . $img_cnt . '</td>'
            . '<td>' . $vis_h . '</td>'
            . '<td>'
            .   '<a href="' . $batch_url  . '" class="btn btn-sm btn-outline-primary"   title="Batch Add">&#x2B06;&#xFE0F;</a>'
            .   '<a href="' . $images_url . '" class="btn btn-sm btn-outline-secondary" title="Manage Images">&#x1F4F8;</a>'
            .   '<a href="' . $view_url   . '" class="btn btn-sm btn-outline-secondary" title="View album" target="_blank">&#x2197;</a>'
            .   '<a href="' . $edit_url   . '" class="btn btn-sm btn-outline-secondary" title="Edit">&#x270F;&#xFE0F;</a>'
            . '</td>'
            . '<td>'
            .   '<form method="post" action="' . $base_h . '" data-confirm="' . $del_conf . '"'
            .       ' onsubmit="return confirm(this.dataset.confirm)">'
            .     '<input type="hidden" name="action"     value="delete">'
            .     '<input type="hidden" name="id"         value="' . $a['id'] . '">'
            .     '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            .     '<button type="submit" class="btn btn-sm btn-outline-danger">&#x1F5D1;</button>'
            .   '</form>'
            . '</td>'
            . '</tr>';
    }
}

// Item count summary.
if ($total === 0) {
    $summary = $search !== ''
        ? 'No results for <strong>' . $search_h . '</strong>'
        : '0 albums';
} else {
    $label   = $total === 1 ? 'album' : 'albums';
    $summary = 'Showing ' . $pag['start_item'] . '&ndash;' . $pag['end_item'] . ' of ' . $total . ' ' . $label;
    if ($search !== '') {
        $summary .= ' matching <strong>' . $search_h . '</strong>';
    }
}

// Per-page selector form (preserves cat and q filters).
$preserve = [];
if ($filter_cat > 0) $preserve['cat'] = $filter_cat;
if ($search !== '')  $preserve['q']   = $search;
$per_page_sel = lum_per_page_selector($base, $preserve, $per_page);
$pag_html     = lum_admin_pagination($pag);

$pag_bar = $pag_html
    ? '<div class="d-flex justify-content-center my-2">' . $pag_html . '</div>'
    : '';

// Flat-mode search form: preserves per_page and category filter; resets to page 1.
$search_hidden_cat = $filter_cat > 0
    ? '<input type="hidden" name="cat" value="' . $filter_cat . '">'
    : '';
$clear_base = $base . ($filter_cat > 0 ? '?cat=' . $filter_cat . '&per_page=' . $per_page : '?per_page=' . $per_page);
$search_clear_html = $search !== ''
    ? '<a href="' . h($clear_base) . '" class="btn btn-sm btn-outline-secondary" title="Clear search">&#x2715; Clear</a>'
    : '';

$search_form =
    '<form method="get" action="' . $base_h . '" class="d-flex align-items-center gap-2 mb-3 flex-wrap">'
    . $search_hidden_cat
    . '<input type="hidden" name="per_page" value="' . $per_page . '">'
    . '<div class="input-group input-group-sm" style="max-width:340px">'
    . '<input type="text" name="q" value="' . $search_h . '" class="form-control"'
    . ' placeholder="Search by album name\xe2\x80\xa6" maxlength="200" autocomplete="off">'
    . '<button type="submit" class="btn btn-outline-secondary">Search</button>'
    . '</div>'
    . $search_clear_html
    . '</form>';

$content =
    $search_form
    . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">'
    . '<span class="text-muted small">' . $summary . '</span>'
    . '<div class="d-flex align-items-center gap-2">'
    . $per_page_sel
    . '<a href="' . $new_h . '" class="btn btn-primary btn-sm">+ New Album</a>'
    . '</div>'
    . '</div>'
    . $pag_bar
    . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
    . '<thead><tr>'
    . '<th>Title</th><th>Category</th><th>Folder</th><th>Images</th><th>Visibility</th><th>Actions</th><th></th>'
    . '</tr></thead>'
    . '<tbody>' . $rows . '</tbody></table></div>'
    . $pag_bar;

lum_admin_page('Albums', $content, 'albums');
