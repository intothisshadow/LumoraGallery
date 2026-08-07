<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Bulk Rename Images (LG-26)
 *
 * Renames a selected set of images within a single album using an
 * admin-defined naming pattern (prefix / suffix / sequential numbering /
 * custom template), previewing the result before anything on disk or in
 * the database changes.
 *
 * Reached only via a POST from the Image Manager's "Rename Selected" button
 * (admin/images.php), which supplies album_id + ids[]. A three-step flow,
 * all POST (there is no GET entry point):
 *   1. action=form    (default on first load) — show the pattern form.
 *   2. action=preview — compute and display the proposed filenames.
 *   3. action=apply   — perform the rename on disk and in the database.
 *
 * The rename plan (which filename each image becomes) is always recomputed
 * server-side from album_id + ids[] + the pattern fields on every step —
 * the client never supplies a final filename directly, so tampering with
 * the preview's hidden fields can at most change the pattern, never target
 * an arbitrary filename outside what that pattern would produce.
 *
 * File renames use a two-phase temp-name swap (old → unique temp → new) so
 * that a pattern which permutes existing filenames (e.g. two images trading
 * names) can never collide mid-operation. If anything fails partway through
 * either phase, every file already touched is rolled back to its original
 * name before reporting the error — the operation is all-or-nothing, and
 * the database is only updated once every file is confirmed in its final
 * location on disk.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.13.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('manage_images');

$base = lumora_base_url() . 'admin/images.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lumora_redirect($base);
}
lumora_csrf_validate();

$csrf = h(lumora_csrf_token());

$album_id = lumora_int($_POST['album_id'] ?? 0, 0, 1);
$ids_raw  = $_POST['ids'] ?? [];
$ids      = array_values(array_unique(array_filter(
    array_map(static fn(mixed $v): int => (int) $v, is_array($ids_raw) ? $ids_raw : []),
    static fn(int $v): bool => $v > 0
)));
if (count($ids) > 500) {
    $ids = array_slice($ids, 0, 500);
}

if ($album_id === 0 || empty($ids)) {
    lum_flash('No images were selected to rename.', 'danger');
    lumora_redirect($base);
}

$album = LumoraDB::fetchOne('SELECT * FROM `{PREFIX}albums` WHERE id = ?', [$album_id]);
if (!$album) {
    lum_flash('Album not found.', 'danger');
    lumora_redirect($base);
}

$back_url = $base . '?album=' . $album_id;

$images = GalleryService::getAlbumImagesByIds($album_id, $ids);
if (empty($images)) {
    lum_flash('None of the selected images could be found in this album.', 'danger');
    lumora_redirect($back_url);
}

$act = (string) ($_POST['action'] ?? 'form');

// ── Pattern fields — carried forward as hidden inputs on every step ──────────
$prefix  = trim((string) ($_POST['prefix']  ?? ''));
$pattern = trim((string) ($_POST['pattern'] ?? ''));
$suffix  = trim((string) ($_POST['suffix']  ?? ''));
$start   = lumora_int($_POST['start'] ?? 1, 1, 0, 999999);
$pad     = lumora_int($_POST['pad']   ?? 3, 3, 1, 10);

if ($pattern === '') {
    $pattern = '{name}';
}
$template = $prefix . $pattern . $suffix;

// ── Build the rename plan (pure computation — no side effects) ──────────────
$plan = [];
foreach ($images as $i => $img) {
    $new = lumora_build_rename_filename($template, $img['filename'], $start + $i, $pad);
    $plan[] = [
        'id'        => (int) $img['id'],
        'old'       => $img['filename'],
        'new'       => $new,
        'unchanged' => $new === $img['filename'],
        'conflict'  => null,
    ];
}

// Detect duplicate targets within the batch itself, and targets that would
// collide with an image outside the batch that keeps its current name.
$new_name_counts = [];
foreach ($plan as $row) {
    if ($row['unchanged']) continue;
    $new_name_counts[$row['new']] = ($new_name_counts[$row['new']] ?? 0) + 1;
}
$other_filenames = array_fill_keys(GalleryService::getOtherAlbumFilenames($album_id, $ids), true);

$conflicts = [];
foreach ($plan as &$row) {
    if ($row['unchanged']) continue;
    if (($new_name_counts[$row['new']] ?? 0) > 1) {
        $row['conflict'] = 'duplicate target filename within this batch';
    } elseif (isset($other_filenames[$row['new']])) {
        $row['conflict'] = 'already used by another image in this album';
    }
    if ($row['conflict'] !== null) {
        $conflicts[] = $row;
    }
}
unset($row);
$has_conflicts = !empty($conflicts);
$changed_count = count(array_filter($plan, static fn(array $r): bool => !$r['unchanged']));

// ── action=apply: perform the rename ──────────────────────────────────────────
if ($act === 'apply') {
    if ($has_conflicts) {
        lum_flash('Rename aborted: the pattern produces ' . count($conflicts) . ' conflicting filename(s). Adjust the pattern and try again.', 'danger');
        lumora_redirect($back_url);
    }
    if ($changed_count === 0) {
        lum_flash('No filenames needed to change — the selected images already match this pattern.', 'info');
        lumora_redirect($back_url);
    }

    $folder    = $album['folder'];
    $base_path = lumora_album_path($folder);
    $changed   = array_values(array_filter($plan, static fn(array $r): bool => !$r['unchanged']));

    // Phase 1: old name → unique temp name (id-based, cannot collide with
    // anything). Guards against a pattern that permutes existing filenames
    // (e.g. two images trading names) colliding mid-rename.
    $phase1_done = []; // id => ['old' => string, 'tmp' => string, 'thumb' => bool]
    $error       = null;
    foreach ($changed as $row) {
        $id  = $row['id'];
        $old = $row['old'];
        $ext = pathinfo($old, PATHINFO_EXTENSION);
        $tmp = '__lumrename_' . $id . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');

        $old_path  = $base_path . $old;
        $old_thumb = $base_path . LUMORA_THUMB_PREFIX . $old;
        $tmp_path  = $base_path . $tmp;
        $tmp_thumb = $base_path . LUMORA_THUMB_PREFIX . $tmp;

        if (is_file($old_path) && !rename($old_path, $tmp_path)) {
            $error = "Could not rename '{$old}' (step 1 of 2). Check folder permissions.";
            break;
        }
        $moved_thumb = is_file($old_thumb) && rename($old_thumb, $tmp_thumb);
        $phase1_done[$id] = ['old' => $old, 'tmp' => $tmp, 'thumb' => $moved_thumb];
    }

    if ($error !== null) {
        foreach ($phase1_done as $info) {
            $tmp_path  = $base_path . $info['tmp'];
            $orig_path = $base_path . $info['old'];
            if (is_file($tmp_path)) rename($tmp_path, $orig_path);
            if ($info['thumb']) {
                $tmp_thumb  = $base_path . LUMORA_THUMB_PREFIX . $info['tmp'];
                $orig_thumb = $base_path . LUMORA_THUMB_PREFIX . $info['old'];
                if (is_file($tmp_thumb)) rename($tmp_thumb, $orig_thumb);
            }
        }
        lum_flash($error . ' No filenames were changed.', 'danger');
        lumora_redirect($back_url);
    }

    // Phase 2: temp name → final new name.
    $phase2_done = []; // id => new filename
    foreach ($changed as $row) {
        $id   = $row['id'];
        $new  = $row['new'];
        $info = $phase1_done[$id];

        $tmp_path = $base_path . $info['tmp'];
        $new_path = $base_path . $new;

        if (is_file($tmp_path) && !rename($tmp_path, $new_path)) {
            $error = "Could not rename '{$info['old']}' to '{$new}' (step 2 of 2). Check folder permissions.";
            break;
        }
        if ($info['thumb']) {
            $tmp_thumb = $base_path . LUMORA_THUMB_PREFIX . $info['tmp'];
            $new_thumb = $base_path . LUMORA_THUMB_PREFIX . $new;
            if (is_file($tmp_thumb)) rename($tmp_thumb, $new_thumb);
        }
        $phase2_done[$id] = $new;
    }

    if ($error !== null) {
        // Unwind: finished phase-2 items back to their temp name, then every
        // phase-1 item (finished or not) back to its original name.
        foreach ($phase2_done as $id => $new) {
            $info     = $phase1_done[$id];
            $new_path = $base_path . $new;
            $tmp_path = $base_path . $info['tmp'];
            if (is_file($new_path)) rename($new_path, $tmp_path);
            if ($info['thumb']) {
                $new_thumb = $base_path . LUMORA_THUMB_PREFIX . $new;
                $tmp_thumb = $base_path . LUMORA_THUMB_PREFIX . $info['tmp'];
                if (is_file($new_thumb)) rename($new_thumb, $tmp_thumb);
            }
        }
        foreach ($phase1_done as $info) {
            $tmp_path  = $base_path . $info['tmp'];
            $orig_path = $base_path . $info['old'];
            if (is_file($tmp_path)) rename($tmp_path, $orig_path);
            if ($info['thumb']) {
                $tmp_thumb  = $base_path . LUMORA_THUMB_PREFIX . $info['tmp'];
                $orig_thumb = $base_path . LUMORA_THUMB_PREFIX . $info['old'];
                if (is_file($tmp_thumb)) rename($tmp_thumb, $orig_thumb);
            }
        }
        lum_flash($error . ' No filenames were changed.', 'danger');
        lumora_redirect($back_url);
    }

    // Every file is confirmed in its final location — commit the filename
    // changes to the database as one transaction.
    try {
        LumoraDB::beginTransaction();
        foreach ($phase2_done as $id => $new) {
            LumoraDB::update('images', ['filename' => $new], 'id = ?', [$id]);
        }
        LumoraDB::commit();
    } catch (\Throwable) {
        LumoraDB::rollBack();
        // Files are already renamed on disk but the DB failed — roll the
        // files back too so disk and database never disagree.
        foreach ($phase2_done as $id => $new) {
            $info      = $phase1_done[$id];
            $new_path  = $base_path . $new;
            $orig_path = $base_path . $info['old'];
            if (is_file($new_path)) rename($new_path, $orig_path);
            if ($info['thumb']) {
                $new_thumb  = $base_path . LUMORA_THUMB_PREFIX . $new;
                $orig_thumb = $base_path . LUMORA_THUMB_PREFIX . $info['old'];
                if (is_file($new_thumb)) rename($new_thumb, $orig_thumb);
            }
        }
        lum_flash('Database update failed; filenames were rolled back. No changes were applied.', 'danger');
        lumora_redirect($back_url);
    }

    $n = count($phase2_done);
    lum_flash($n . ' image' . ($n !== 1 ? 's' : '') . ' renamed successfully.');
    lumora_redirect($back_url);
}

// ── action=form / action=preview: render the page ────────────────────────────
$album_title_h = h($album['title']);
$count         = count($images);
$back_url_h    = h($back_url);

$hidden = '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
    . '<input type="hidden" name="album_id" value="' . $album_id . '">';
foreach ($ids as $iid) {
    $hidden .= '<input type="hidden" name="ids[]" value="' . $iid . '">';
}

$prefix_h  = h($prefix);
$pattern_h = h($pattern);
$suffix_h  = h($suffix);
$plural    = $count !== 1 ? 's' : '';

$content = <<<HTML
<a href="{$back_url_h}" class="btn btn-sm btn-outline-secondary mb-3">← Back to Image List</a>
<div class="lum-adm-card mb-3">
  <h5 class="mb-1">Bulk Rename — {$album_title_h}</h5>
  <p class="text-muted small mb-3">Renaming {$count} selected image{$plural} in this album. File extensions are always preserved.</p>
  <form method="post" action="">
    {$hidden}
    <input type="hidden" name="action" value="preview">
    <div class="row g-3">
      <div class="col-sm-3">
        <label class="form-label fw-semibold small">Prefix</label>
        <input type="text" name="prefix" value="{$prefix_h}" class="form-control" placeholder="e.g. vacation-">
      </div>
      <div class="col-sm-3">
        <label class="form-label fw-semibold small">Base pattern</label>
        <input type="text" name="pattern" value="{$pattern_h}" class="form-control" placeholder="{name}">
        <div class="form-text">Tokens: <code>{name}</code> original name, <code>{num}</code> sequence number.</div>
      </div>
      <div class="col-sm-2">
        <label class="form-label fw-semibold small">Suffix</label>
        <input type="text" name="suffix" value="{$suffix_h}" class="form-control" placeholder="e.g. -final">
      </div>
      <div class="col-sm-2 col-6">
        <label class="form-label fw-semibold small">Start #</label>
        <input type="number" name="start" value="{$start}" min="0" max="999999" class="form-control">
      </div>
      <div class="col-sm-2 col-6">
        <label class="form-label fw-semibold small">Digits</label>
        <input type="number" name="pad" value="{$pad}" min="1" max="10" class="form-control">
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt-3">👁 Preview</button>
  </form>
</div>
HTML;

if ($act === 'preview') {
    $rows = '';
    foreach ($plan as $row) {
        $old_h = h($row['old']);
        $new_h = h($row['new']);
        if ($row['conflict'] !== null) {
            $status_h = '<span class="badge bg-danger">' . h($row['conflict']) . '</span>';
        } elseif ($row['unchanged']) {
            $status_h = '<span class="badge bg-secondary">unchanged</span>';
        } else {
            $status_h = '<span class="badge bg-success">ok</span>';
        }
        $rows .= '<tr><td class="small font-monospace">' . $old_h . '</td>'
            . '<td class="small font-monospace">' . $new_h . '</td>'
            . '<td>' . $status_h . '</td></tr>';
    }

    $preview_table = '<div class="table-responsive"><table class="table table-sm table-hover">'
        . '<thead><tr><th>Current filename</th><th>New filename</th><th>Status</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';

    if ($has_conflicts) {
        $apply_html = '<div class="alert alert-danger">'
            . count($conflicts) . ' filename conflict(s) found. Adjust the pattern above and preview again before applying.'
            . '</div>';
    } elseif ($changed_count === 0) {
        $apply_html = '<div class="alert alert-info">No filenames would change with this pattern.</div>';
    } else {
        $apply_html = '<form method="post" action="">' . $hidden
            . '<input type="hidden" name="action" value="apply">'
            . '<input type="hidden" name="prefix" value="' . $prefix_h . '">'
            . '<input type="hidden" name="pattern" value="' . $pattern_h . '">'
            . '<input type="hidden" name="suffix" value="' . $suffix_h . '">'
            . '<input type="hidden" name="start" value="' . $start . '">'
            . '<input type="hidden" name="pad" value="' . $pad . '">'
            . '<button type="submit" class="btn btn-success"'
            . ' onclick="return confirm(\'Rename ' . $changed_count . ' file(s) now? This cannot be undone automatically.\')">'
            . '✅ Apply Rename (' . $changed_count . ')</button>'
            . '</form>';
    }

    $content .= '<div class="lum-adm-card"><h6 class="fw-semibold mb-3">Preview</h6>' . $preview_table . $apply_html . '</div>';
}

lum_admin_page('Bulk Rename', $content, 'images');
