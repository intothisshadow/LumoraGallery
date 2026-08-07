<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Category Management
 *
 * Actions: list (default), new, edit, save, delete
 *
 * List view displays ALL categories as a parent/child hierarchy tree.
 * The full tree is always shown (no pagination); categories are ordered by
 * pos then name within each level. Edit and Delete buttons are present on
 * every row and continue to function exactly as before.
 *
 * $all_cats is fetched once unconditionally via getAllCategoriesWithCounts()
 * and serves double duty: (1) the new/edit parent dropdown, (2) the tree
 * view with album and subcategory counts alongside each category name.
 *
 * Category create/update/delete business logic (thumb_image_id validation,
 * self-parent prevention, cascading reparent-on-delete) lives in
 * GalleryService::createCategory()/updateCategory()/deleteCategory() — this
 * page only handles permission checks, CSRF validation, request parsing,
 * flash messages, and redirects.
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
lumora_require_permission('manage_albums');

$action = $_GET['action'] ?? 'list';
$id     = lumora_int($_GET['id'] ?? 0, 0, 1);
$base   = lumora_base_url() . 'admin/categories.php';

// ── POST: save new or edited category ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $edit_id        = lumora_int($_POST['id']             ?? 0, 0, 0);
        $name           = trim($_POST['name']                 ?? '');
        $desc           = trim($_POST['description']          ?? '');
        $parent_id      = lumora_int($_POST['parent_id']      ?? 0, 0, 0);
        $pos            = lumora_int($_POST['pos']             ?? 0, 0, 0);
        $thumb_image_id = lumora_int($_POST['thumb_image_id'] ?? 0, 0, 0);

        $data = [
            'name'           => $name,
            'description'    => $desc,
            'parent_id'      => $parent_id,
            'pos'            => $pos,
            'thumb_image_id' => $thumb_image_id,
        ];

        if ($edit_id > 0) {
            $result = GalleryService::updateCategory($edit_id, $data);
            if (is_string($result)) {
                lum_flash($result, 'danger');
                lumora_redirect($base . '?action=edit&id=' . $edit_id);
            }
            if ($result['warning'] !== null) {
                lum_flash($result['warning'], 'warning');
            }
            lum_flash('Category updated.');
        } else {
            $result = GalleryService::createCategory($data);
            if (is_string($result)) {
                lum_flash($result, 'danger');
                lumora_redirect($base . '?action=new');
            }
            if ($result['warning'] !== null) {
                lum_flash($result['warning'], 'warning');
            }
            lum_flash('Category created.');
        }
        lumora_redirect($base);
    }

    if ($act === 'delete') {
        $del_id = lumora_int($_POST['id'] ?? 0, 0, 1);
        if ($del_id > 0) {
            $message = GalleryService::deleteCategory($del_id);
            if ($message !== null) {
                lum_flash($message);
            }
        }
        lumora_redirect($base);
    }
}

// ── Common setup ──────────────────────────────────────────────────────────────
// getAllCategoriesWithCounts() returns a superset of getAllCategoriesFlat():
// same columns plus album_count and subcategory_count. All callers that
// previously used the flat version continue to work unchanged.
$all_cats = GalleryService::getAllCategoriesWithCounts();
$csrf     = h(lumora_csrf_token());
$base_h   = h($base);

// ── Parent dropdown helper ────────────────────────────────────────────────────
/**
 * $cats must be in depth-first hierarchical order with a `depth` key on each
 * row — i.e. GalleryService::getAllCategoriesTreeOrdered() — not the flat
 * parent_id-grouped order from getAllCategoriesFlat()/getAllCategoriesWithCounts().
 * Same underlying fix as albums.php's album_cat_options() — see TODO.md §15.
 */
function cat_parent_options(array $cats, int $exclude_id = 0, int $selected = 0): string
{
    $html = '<option value="0">— Root (no parent) —</option>';
    foreach ($cats as $c) {
        $id = (int) $c['id'];
        if ($id === $exclude_id) continue;
        $sel    = ($id === $selected) ? ' selected' : '';
        $depth  = (int) ($c['depth'] ?? 0);
        $prefix = str_repeat('— ', $depth);
        $html .= '<option value="' . $id . '"' . $sel . '>'
            . h($prefix . $c['name'])
            . '</option>';
    }
    return $html;
}

// ── Category tree render ──────────────────────────────────────────────────────
/**
 * Recursively render <tr> rows for the admin category hierarchy table.
 *
 * Each call expands one level of children under $parent_id and recurses into
 * their children, producing a depth-first ordering that matches the visual tree.
 * A $visited ref-array guards against cycles caused by corrupt parent_id values.
 *
 * @param array<int, list<array<string, mixed>>> $cats_by_parent  parent_id => [category rows]
 * @param int                                    $parent_id       Parent to expand.
 * @param int                                    $depth           Nesting depth (0 = root).
 * @param string                                 $base_h          HTML-escaped base URL.
 * @param string                                 $csrf            HTML-escaped CSRF token.
 * @param array<int, true>                       $visited         Cycle guard, passed by ref.
 */
function render_category_tree_rows(
    array  $cats_by_parent,
    int    $parent_id,
    int    $depth,
    string $base_h,
    string $csrf,
    array  &$visited
): string {
    if (!isset($cats_by_parent[$parent_id])) return '';
    $html = '';

    foreach ($cats_by_parent[$parent_id] as $c) {
        $id = (int) $c['id'];
        if (isset($visited[$id])) continue; // cycle guard
        $visited[$id] = true;

        $name_h   = h($c['name']);
        $albums   = (int) ($c['album_count']       ?? 0);
        $subs     = (int) ($c['subcategory_count']  ?? 0);
        $pos_v    = (int) $c['pos'];
        $edit_url = h($base_h . '?action=edit&id=' . $id);
        $del_conf = h('Delete category \'' . $c['name'] . '\'? Child items will be moved to parent.');

        // Visual depth: 20 px per level, tree connector glyph for children.
        $indent_px = $depth * 20;
        $connector = $depth > 0
            ? '<span class="lum-tree-connector" aria-hidden="true">└ </span>'
            : '';

        $name_cell = '<div class="lum-tree-name" style="padding-left:' . $indent_px . 'px">'
            . '<span class="lum-drag-handle" title="Drag to reorder or reparent" aria-hidden="true">&#9776;</span>'
            . $connector . $name_h . '</div>';

        // Album count badge (shown on every row).
        $album_badge = $albums > 0
            ? '<span class="badge rounded-pill text-bg-secondary">' . $albums . '</span>'
            : '<span class="text-muted small">—</span>';

        // Subcategory count indicator (only when > 0; collapsed into the Name cell).
        $sub_indicator = $subs > 0
            ? ' &nbsp;<span class="lum-tree-sub-count text-muted small" title="'
              . $subs . ' direct sub-categor' . ($subs === 1 ? 'y' : 'ies') . '">'
              . '(' . $subs . ' ↳)</span>'
            : '';

        $html .=
            '<tr class="lum-drag-row" draggable="true" data-cat-id="' . $id . '" data-parent-id="' . $parent_id . '">'
            . '<td>' . $name_cell . $sub_indicator . '</td>'
            . '<td>' . $album_badge . '</td>'
            . '<td class="text-muted small">' . $pos_v . '</td>'
            . '<td><a href="' . $edit_url . '" class="btn btn-sm btn-outline-secondary">Edit</a></td>'
            . '<td>'
            .   '<form method="post" action="' . $base_h . '" data-confirm="' . $del_conf . '"'
            .       ' onsubmit="return confirm(this.dataset.confirm)">'
            .     '<input type="hidden" name="action"     value="delete">'
            .     '<input type="hidden" name="id"         value="' . $id . '">'
            .     '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            .     '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>'
            .   '</form>'
            . '</td>'
            . '</tr>';

        // Recurse into children.
        $html .= render_category_tree_rows(
            $cats_by_parent, $id, $depth + 1, $base_h, $csrf, $visited
        );
    }

    return $html;
}

// ── New / Edit form ───────────────────────────────────────────────────────────
if ($action === 'new' || $action === 'edit') {
    $cat = ($action === 'edit' && $id > 0) ? get_category($id) : null;
    if ($action === 'edit' && !$cat) {
        lum_flash('Category not found.', 'danger');
        lumora_redirect($base);
    }

    $title    = $action === 'new' ? 'New Category' : 'Edit Category';
    $name_v   = h($cat['name']           ?? '');
    $desc_v   = h($cat['description']    ?? '');
    $parent_v = (int)($cat['parent_id']  ?? 0);
    $pos_v    = (int)($cat['pos']        ?? 0);
    $id_v     = (int)($cat['id']         ?? 0);
    $thumb_v  = (int)($cat['thumb_image_id'] ?? 0);
    // Depth-first hierarchical order (not $all_cats' flat parent_id-grouped
    // order) so cat_parent_options() can indent each option correctly under
    // its actual ancestor chain. See TODO.md §15.
    $par_opts = cat_parent_options(GalleryService::getAllCategoriesTreeOrdered(), $id_v, $parent_v);

    $content = <<<HTML
<a href="{$base_h}" class="btn btn-sm btn-outline-secondary mb-3">← Back to list</a>
<div class="lum-adm-card">
  <form method="post" action="{$base_h}">
    <input type="hidden" name="action"     value="save">
    <input type="hidden" name="id"         value="{$id_v}">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <div class="mb-3">
      <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
      <input type="text" name="name" value="{$name_v}" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Description</label>
      <textarea name="description" rows="3" class="form-control">{$desc_v}</textarea>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Parent Category</label>
      <select name="parent_id" class="form-select">{$par_opts}</select>
    </div>
    <div class="mb-3">
      <label class="form-label fw-semibold">Position (sort order)</label>
      <input type="number" name="pos" value="{$pos_v}" class="form-control" style="max-width:120px">
      <div class="form-text">Lower numbers appear first. 0 = default.</div>
    </div>
    <div class="mb-4">
      <label class="form-label fw-semibold">Cover Image <small class="text-muted">(optional)</small></label>
      <input type="number" name="thumb_image_id" value="{$thumb_v}" class="form-control"
             style="max-width:140px" min="0">
      <div class="form-text">Image ID to use as the category cover thumbnail. 0 = auto-pick the first image from any album in this category.</div>
    </div>
    <button type="submit" class="btn btn-primary">Save Category</button>
  </form>
</div>
HTML;
    lum_admin_page($title, $content, 'categories');
}

// ── List — hierarchy tree ─────────────────────────────────────────────────────

// Build parent_id → [child categories] map for the recursive render.
$cats_by_parent = [];
foreach ($all_cats as $c) {
    $cats_by_parent[(int) $c['parent_id']][] = $c;
}

// Summary line.
$total   = count($all_cats);
$lbl     = $total === 1 ? 'category' : 'categories';
$summary = $total > 0 ? number_format($total) . ' ' . $lbl : '0 categories';

// Render tree rows starting from root (parent_id = 0).
$visited = [];
$rows    = render_category_tree_rows($cats_by_parent, 0, 0, $base_h, $csrf, $visited);

if ($rows === '') {
    $rows = '<tr><td colspan="5" class="text-center text-muted py-4">'
        . 'No categories yet. <a href="' . $base_h . '?action=new">Create one</a>.'
        . '</td></tr>';
}

$new_h = h($base . '?action=new');

$reorder_url_js = json_encode(lumora_base_url() . 'admin/ajax_reorder_categories.php');
$csrf_js        = json_encode($csrf);

$drag_script = <<<HTML
<div class="lum-reorder-toast" id="lum-cat-reorder-toast" role="status" aria-live="polite">
  <span class="spinner-border" aria-hidden="true"></span>
  <span id="lum-cat-reorder-toast-text">Saving order&hellip;</span>
</div>
<script>
(function () {
  'use strict';

  var REORDER_URL = {$reorder_url_js};
  var CSRF        = {$csrf_js};

  var table = document.querySelector('.lum-adm-table');
  if (!table) return;

  var toast     = document.getElementById('lum-cat-reorder-toast');
  var toastText = document.getElementById('lum-cat-reorder-toast-text');

  function showToast(text, isError) {
    toastText.textContent = text;
    toast.classList.toggle('is-error', !!isError);
    toast.classList.add('is-visible');
  }
  function hideToast() {
    toast.classList.remove('is-visible', 'is-error');
  }

  var draggedRow = null;

  function allRows() {
    return Array.prototype.slice.call(table.querySelectorAll('tr.lum-drag-row'));
  }

  function clearDropClasses() {
    allRows().forEach(function (r) {
      r.classList.remove('lum-drop-above', 'lum-drop-below', 'lum-drop-into');
    });
  }

  table.addEventListener('dragstart', function (e) {
    var row = e.target.closest('tr.lum-drag-row');
    if (!row) return;
    draggedRow = row;
    row.classList.add('lum-dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', row.dataset.catId); } catch (err) {}
  });

  table.addEventListener('dragover', function (e) {
    var row = e.target.closest('tr.lum-drag-row');
    if (!row || !draggedRow || row === draggedRow) return;
    e.preventDefault();

    var rect = row.getBoundingClientRect();
    var offset = (e.clientY - rect.top) / rect.height;

    clearDropClasses();
    if (offset < 0.25) {
      row.classList.add('lum-drop-above');
    } else if (offset > 0.75) {
      row.classList.add('lum-drop-below');
    } else {
      row.classList.add('lum-drop-into');
    }
  });

  table.addEventListener('dragend', function () {
    if (draggedRow) draggedRow.classList.remove('lum-dragging');
    clearDropClasses();
    draggedRow = null;
  });

  table.addEventListener('drop', function (e) {
    var targetRow = e.target.closest('tr.lum-drag-row');
    if (!targetRow || !draggedRow || targetRow === draggedRow) return;
    e.preventDefault();

    var mode = targetRow.classList.contains('lum-drop-above') ? 'above'
             : targetRow.classList.contains('lum-drop-below') ? 'below'
             : 'into';
    clearDropClasses();

    var movedId = parseInt(draggedRow.dataset.catId, 10);
    var newParentId;
    var siblingIds;

    if (mode === 'into') {
      newParentId = parseInt(targetRow.dataset.catId, 10);
      siblingIds = allRows()
        .filter(function (r) { return parseInt(r.dataset.parentId, 10) === newParentId && r !== draggedRow; })
        .map(function (r) { return parseInt(r.dataset.catId, 10); });
      siblingIds.push(movedId);
    } else {
      newParentId = parseInt(targetRow.dataset.parentId, 10);
      var siblings = allRows()
        .filter(function (r) { return parseInt(r.dataset.parentId, 10) === newParentId && r !== draggedRow; });
      var targetIndex = siblings.indexOf(targetRow);
      var insertAt = mode === 'above' ? targetIndex : targetIndex + 1;
      siblingIds = siblings.map(function (r) { return parseInt(r.dataset.catId, 10); });
      siblingIds.splice(insertAt, 0, movedId);
    }

    showToast('Saving order\u2026', false);

    var body = new URLSearchParams();
    body.set('csrf_token', CSRF);
    body.set('moved_id', String(movedId));
    body.set('new_parent_id', String(newParentId));
    body.set('ordered_ids', JSON.stringify(siblingIds));

    fetch(REORDER_URL, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.success) {
          showToast('Order saved \u2014 refreshing\u2026', false);
          window.location.reload();
        } else {
          showToast((data && data.error) || 'Could not save the new order.', true);
          setTimeout(hideToast, 4000);
        }
      })
      .catch(function () {
        showToast('Network error \u2014 could not save the new order.', true);
        setTimeout(hideToast, 4000);
      });
  });
})();
</script>
HTML;

$content =
    '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">'
    . '<span class="text-muted small">' . h($summary) . ' &middot; drag rows (&#9776;) to reorder or reparent</span>'
    . '<a href="' . $new_h . '" class="btn btn-primary btn-sm">+ New Category</a>'
    . '</div>'
    . '<div class="table-responsive"><table class="table table-hover lum-adm-table align-middle">'
    . '<thead><tr>'
    . '<th>Name</th>'
    . '<th style="width:90px">Albums</th>'
    . '<th style="width:60px">Pos</th>'
    . '<th style="width:70px"></th>'
    . '<th style="width:90px"></th>'
    . '</tr></thead>'
    . '<tbody>' . $rows . '</tbody></table></div>'
    . $drag_script;

lum_admin_page('Categories', $content, 'categories');
