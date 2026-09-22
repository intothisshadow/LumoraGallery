<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Admin Settings Page
 *
 * Three self-contained sections (LG-068):
 *   - albums/.htaccess install/remove — writes/removes the rewrite rule
 *     block documented in README.md, additively (see
 *     OnDemandThumbnailService::installHtaccess()/removeHtaccess()),
 *     instead of requiring manual SSH/FTP editing.
 *   - Rate limiting — on/off toggle and requests-per-60-seconds threshold
 *     for OnDemandRateLimitService, previously a fixed, always-on constant.
 *   - Batch-delete thumbnails — the same delete-all / delete-every-other
 *     logic as tools/delete-all-thumbs.sh and tools/delete-every-other-
 *     thumb.sh (now thin CLI wrappers around
 *     OnDemandThumbnailService::deleteThumbnails()), run from the browser
 *     instead of over SSH. Both a dry run and a real delete drive the same
 *     in-place, no-reload, folder-chunked AJAX progress loop via
 *     ajax_delete_thumbs.php — the same pattern Admin → Updates uses for
 *     its own multi-stage update workflow — because a dry run needs the
 *     same potentially-large filesystem scan a real delete does (it just
 *     skips the actual unlink() calls); a single synchronous request for
 *     either risked silently dying against PHP's max_execution_time or
 *     memory_limit on a large recursive section with no feedback shown at
 *     all. The plain form POST below (`delete_thumbnails`) only runs as a
 *     no-JS fallback.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.0
 * @see        OnDemandThumbnailService Backing logic for every action on this page.
 * @see        OnDemandDeleteJobService Persists a delete/dry-run job's work queue between AJAX batch calls.
 * @see        OnDemandRateLimitService Reads the rate-limit settings this page writes.
 */
define('LUMORA_ENTRY', true);

// This file is at plugins/on-demand-thumbnails/admin/settings.php.
$_lumora_root = dirname(dirname(dirname(__DIR__)));
require_once $_lumora_root . '/include/bootstrap.php';
require_once $_lumora_root . '/admin/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/OnDemandRateLimitService.php';
require_once dirname(__DIR__) . '/OnDemandThumbnailService.php';

lumora_require_permission('site_configuration');

$base         = lumora_base_url() . 'plugins/on-demand-thumbnails/admin/settings.php';
$base_h       = h($base);
$csrf_h       = h(lumora_csrf_token());
$csrf_js      = json_encode(lumora_csrf_token());
$ajax_base_js = json_encode(lumora_base_url() . 'plugins/on-demand-thumbnails/admin/');

if (!PluginService::isEnabled('on-demand-thumbnails')) {
    $plugins_url_h = h(lumora_base_url() . 'admin/plugins.php');
    $content = '<div class="alert alert-warning">'
        . 'On-Demand Thumbnails is currently disabled. '
        . '<a href="' . $plugins_url_h . '">Enable it on Admin → Plugins</a> to configure it here.'
        . '</div>';
    lum_admin_page('On-Demand Thumbnails', $content, 'on_demand_thumbnails');
}

// ── POST: handle all write actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();
    $action = trim($_POST['action'] ?? '');

    switch ($action) {

        case 'install_htaccess':
            $result = OnDemandThumbnailService::installHtaccess();
            lum_flash($result === true ? 'albums/.htaccess rule installed.' : $result, $result === true ? 'success' : 'danger');
            lumora_redirect($base . '#htaccess');
            break;

        case 'remove_htaccess':
            $result = OnDemandThumbnailService::removeHtaccess();
            lum_flash($result === true ? 'albums/.htaccess rule removed.' : $result, $result === true ? 'success' : 'danger');
            lumora_redirect($base . '#htaccess');
            break;

        case 'save_rate_limit':
            $enabled = isset($_POST['rl_enabled']) ? '1' : '0';
            $max     = lumora_int($_POST['rl_max'] ?? OnDemandRateLimitService::DEFAULT_MAX_REQUESTS, OnDemandRateLimitService::DEFAULT_MAX_REQUESTS, 1);
            if (!in_array($max, OnDemandRateLimitService::SELECTABLE_MAX_REQUESTS, true)) {
                $max = OnDemandRateLimitService::DEFAULT_MAX_REQUESTS;
            }
            LumoraConfig::set('odt_rate_limit_enabled', $enabled);
            LumoraConfig::set('odt_rate_limit_max_requests', (string) $max);
            lum_flash('Rate limit settings saved.');
            lumora_redirect($base . '#rate-limit');
            break;

        case 'delete_thumbnails':
            $folder_input = trim($_POST['folder'] ?? '');
            $recursive    = isset($_POST['recursive']);
            $every_other  = ($_POST['mode'] ?? 'all') === 'every_other';
            $dry_run      = isset($_POST['dry_run']);

            // An empty folder targets albums/ itself — every top-level folder
            // directly under albums/ (e.g. Season 1, Season 2, ...) in one run
            // — but only paired with Recursive, so leaving the field blank by
            // mistake can't silently no-op against a folder with nothing in it.
            if ($folder_input === '' && !$recursive) {
                lum_flash('Check Recursive to run against all of albums/, or enter a specific folder.', 'danger');
                lumora_redirect($base . '#batch-delete');
            }

            // A dry run never deletes anything, but it still has to plan the
            // delete (enumerate every folder/file) to report what would
            // happen — the expensive part for a whole recursive section,
            // not the deleting. Same limits as ajax_delete_thumbs.php's
            // 'start' action, for the same reason: without this, planning
            // a large enough section dies mid-request against the default
            // 30s/128M with no output sent yet, so lum_flash()/redirect()
            // never run and the page just shows nothing happened.
            set_time_limit(300);
            ini_set('memory_limit', '512M');

            // Resolve and confine to albums/ — this is a web-triggered action,
            // unlike the CLI scripts (shell access already implies trust), so
            // the target must be verified to actually live under albums/
            // before anything is touched.
            $albums_real = realpath(LUMORA_ALBUMS_PATH);
            $target_real = $folder_input !== '' ? realpath(LUMORA_ALBUMS_PATH . $folder_input) : $albums_real;

            if ($albums_real === false || $target_real === false
                || !str_starts_with($target_real . DIRECTORY_SEPARATOR, $albums_real . DIRECTORY_SEPARATOR)
            ) {
                lum_flash('That folder could not be found under albums/.', 'danger');
                lumora_redirect($base . '#batch-delete');
            }

            $result = OnDemandThumbnailService::deleteThumbnails($target_real, $recursive, $every_other, $dry_run);

            if ($result['total_found'] === 0) {
                lum_flash('No thumb_* files found under that folder.', 'warning');
            } elseif ($dry_run) {
                lum_flash('Dry run: would delete ' . $result['total_deleted'] . ' of ' . $result['total_found'] . ' thumb_* file(s) found across ' . count($result['folders']) . ' folder(s).', 'info');
            } else {
                lum_flash('Deleted ' . $result['total_deleted'] . ' of ' . $result['total_found'] . ' thumb_* file(s) found across ' . count($result['folders']) . ' folder(s). Recover any of these later via Admin → Tools → Regenerate Missing Thumbnails.');
            }
            lumora_redirect($base . '#batch-delete');
            break;

        default:
            lumora_redirect($base);
    }
}

// ── Page state ────────────────────────────────────────────────────────────────
$htaccess_installed = OnDemandThumbnailService::isHtaccessInstalled();
$rl_enabled          = OnDemandRateLimitService::isEnabled();
$rl_max              = OnDemandRateLimitService::maxRequests();

$rl_opts = '';
foreach (OnDemandRateLimitService::SELECTABLE_MAX_REQUESTS as $opt) {
    $sel = ($opt === $rl_max) ? ' selected' : '';
    $rl_opts .= '<option value="' . $opt . '"' . $sel . '>' . $opt . ' requests / 60s</option>';
}

$htaccess_status_html = $htaccess_installed
    ? '<span class="badge bg-success">Installed</span>'
    : '<span class="badge bg-secondary">Not installed</span>';

$htaccess_action_form = $htaccess_installed
    ? '<form method="post" action="' . $base_h . '" data-confirm="Remove the On-Demand Thumbnails rule from albums/.htaccess? Any thumbnail you have already deleted will 404 until you either re-add this rule or regenerate it.">'
      . '<input type="hidden" name="action" value="remove_htaccess">'
      . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
      . '<button type="submit" class="btn btn-sm btn-outline-danger">Remove Rule</button>'
      . '</form>'
    : '<form method="post" action="' . $base_h . '">'
      . '<input type="hidden" name="action" value="install_htaccess">'
      . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
      . '<button type="submit" class="btn btn-sm btn-primary">Install Rule</button>'
      . '</form>';

$rl_checked = $rl_enabled ? ' checked' : '';

$content = <<<HTML
<div class="lum-adm-card mb-4" id="htaccess">
  <h5 class="mb-1">albums/.htaccess Rewrite Rule</h5>
  <p class="text-muted small mb-3">
    Catches requests for deleted <code>thumb_*</code> files anywhere under <code>albums/</code> and routes them to this
    plugin instead of returning a 404. Safe to install even before deleting any thumbnails — nothing changes until you
    actually delete a <code>thumb_*</code> file (see Batch-Delete Thumbnails below).
  </p>
  <div class="d-flex align-items-center gap-3">
    <div>Status: {$htaccess_status_html}</div>
    {$htaccess_action_form}
  </div>
</div>

<div class="lum-adm-card mb-4" id="rate-limit">
  <h5 class="mb-1">Rate Limiting</h5>
  <p class="text-muted small mb-3">
    Per-IP request limit for <code>thumb.php</code>, over a fixed rolling 60-second window. A backstop for direct-origin
    abuse — a CDN in front of the site (see README.md) is what actually keeps most traffic from reaching this endpoint at all.
  </p>
  <form method="post" action="{$base_h}" class="d-flex align-items-center flex-wrap gap-3">
    <input type="hidden" name="action" value="save_rate_limit">
    <input type="hidden" name="csrf_token" value="{$csrf_h}">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch" id="lum-odt-rl-enabled" name="rl_enabled" value="1"{$rl_checked}>
      <label class="form-check-label" for="lum-odt-rl-enabled">Enabled</label>
    </div>
    <select name="rl_max" class="form-select form-select-sm" style="max-width:200px">
      {$rl_opts}
    </select>
    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
  </form>
</div>

<div class="lum-adm-card mb-4" id="batch-delete">
  <h5 class="mb-1">Batch-Delete Thumbnails</h5>
  <p class="text-muted small mb-3">
    Deletes <code>thumb_*</code> files under a folder inside <code>albums/</code> — originals are never touched, and
    anything deleted here can be recovered via <strong>Admin → Tools → Regenerate Missing Thumbnails</strong>. Same
    logic as <code>tools/delete-all-thumbs.sh</code> / <code>tools/delete-every-other-thumb.sh</code>, run from here
    instead of over SSH. Leave Folder blank with <strong>Recursive</strong> checked to run against every top-level
    folder directly under <code>albums/</code> in one go (e.g. several season folders sitting side by side there).
  </p>
  <form method="post" action="{$base_h}" id="lum-odt-delete-form">
    <input type="hidden" name="action" value="delete_thumbnails">
    <input type="hidden" name="csrf_token" value="{$csrf_h}">
    <div class="row g-2 align-items-end mb-2">
      <div class="col-md-5">
        <label class="form-label small text-muted mb-1">Folder (relative to albums/ — blank + Recursive = all of albums/)</label>
        <input type="text" id="lum-odt-folder" name="folder" class="form-control form-control-sm" placeholder="Season8/8x03-TheLongNight (blank = all of albums/)">
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Mode</label>
        <select id="lum-odt-mode" name="mode" class="form-select form-select-sm">
          <option value="every_other" selected>Delete every other</option>
          <option value="all">Delete all</option>
        </select>
      </div>
      <div class="col-md-4 d-flex align-items-center gap-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="lum-odt-recursive" name="recursive" value="1">
          <label class="form-check-label small" for="lum-odt-recursive">Recursive</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="lum-odt-dryrun" name="dry_run" value="1" checked>
          <label class="form-check-label small" for="lum-odt-dryrun">Dry run</label>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-sm btn-warning">Run</button>
  </form>

  <div id="lum-odt-delete-progress" class="d-none mt-3">
    <div class="progress mb-2" style="height:1.25rem" role="progressbar" aria-label="Delete progress"
         aria-valuemin="0" aria-valuemax="100" id="lum-odt-progress-wrap">
      <div id="lum-odt-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
    </div>
    <div id="lum-odt-progress-status" class="small text-muted"></div>
    <button type="button" id="lum-odt-progress-reset" class="btn btn-sm btn-outline-secondary mt-2 d-none">Run Another</button>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var CSRF      = {$csrf_js};
  var AJAX_BASE = {$ajax_base_js};

  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });

  async function post(endpoint, body) {
    var params = new URLSearchParams(Object.assign({ csrf_token: CSRF }, body));
    var resp = await fetch(AJAX_BASE + endpoint, {
      method : 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body   : params.toString(),
    });
    if (!resp.ok) throw new Error('Server returned HTTP ' + resp.status);
    return resp.json();
  }

  // ── Batch-delete: both a dry run and a real delete are driven here as a
  // chunked AJAX loop — mirroring Admin → Updates' own in-place, no-reload
  // progress pattern (admin/ajax_update_perform.php) — so scanning a large
  // recursive section can't silently die against PHP's max_execution_time
  // or memory_limit with no feedback at all, which a single synchronous
  // request risked. A dry run needs the same scan as a real delete (it just
  // skips the actual unlink() calls), so it's exposed to the same risk and
  // gets the same chunked treatment; the plain form POST fallback in
  // settings.php's own POST handler only runs if JS is unavailable.
  //
  // Each 'batch' call processes exactly one directory (see
  // OnDemandDeleteJobService), discovering subdirectories to check as it
  // goes rather than up front — so the total directory count isn't known
  // until the job finishes, and there's no meaningful "N of M" percentage
  // to show. The bar just stays a full-width moving-stripes indicator
  // while running; the status line's running counts are the real feedback.

  var delForm      = document.getElementById('lum-odt-delete-form');
  var dryRun       = document.getElementById('lum-odt-dryrun');
  var folderInput  = document.getElementById('lum-odt-folder');
  var modeSelect   = document.getElementById('lum-odt-mode');
  var recursiveChk = document.getElementById('lum-odt-recursive');
  var progressWrap = document.getElementById('lum-odt-delete-progress');
  var progressBar  = document.getElementById('lum-odt-progress-bar');
  var progressStat = document.getElementById('lum-odt-progress-status');
  var progressReset= document.getElementById('lum-odt-progress-reset');

  function setStatus(statusHtml) {
    if (progressStat) progressStat.innerHTML = statusHtml;
  }

  async function runDelete(isDryRun) {
    delForm.classList.add('d-none');
    progressWrap.classList.remove('d-none');
    progressReset.classList.add('d-none');
    progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
    progressBar.style.width = '100%';
    progressBar.textContent = '';
    setStatus('Starting…');

    var startResp;
    try {
      startResp = await post('ajax_delete_thumbs.php', {
        action   : 'start',
        folder   : folderInput.value,
        recursive: recursiveChk.checked ? '1' : '',
        mode     : modeSelect.value,
        dry_run  : isDryRun ? '1' : '',
      });
    } catch (err) {
      finishWithError((isDryRun ? 'Could not start dry run: ' : 'Could not start delete: ') + err.message);
      return;
    }

    if (!startResp.success) {
      finishWithError(startResp.message || 'Could not start.');
      return;
    }

    var jobId = startResp.job_id;

    while (true) {
      var batchResp;
      try {
        batchResp = await post('ajax_delete_thumbs.php', { action: 'batch', job_id: jobId });
      } catch (err) {
        finishWithError((isDryRun ? 'Scan interrupted: ' : 'Delete interrupted: ') + err.message
          + (isDryRun ? '' : ' — already-deleted files are not restored automatically; check Admin → Tools → Regenerate Missing Thumbnails.'));
        return;
      }

      if (!batchResp.success) {
        finishWithError(batchResp.message || 'Failed mid-way.');
        return;
      }

      setStatus(
        (isDryRun ? 'Scanning… ' : 'Deleting… ') + batchResp.processed_dirs + ' folder(s) checked, '
        + (isDryRun ? 'would delete ' : 'deleted ') + batchResp.total_deleted + ' of ' + batchResp.total_found + ' file(s) so far'
      );

      if (batchResp.done) {
        progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
        if (batchResp.total_found === 0) {
          setStatus('No thumb_* files found under that folder (checked ' + batchResp.processed_dirs + ' folder(s)).');
        } else {
          setStatus(isDryRun
            ? ('✓ Dry run: would delete ' + batchResp.total_deleted + ' of ' + batchResp.total_found
               + ' thumb_* file(s) found across ' + batchResp.processed_dirs + ' folder(s) checked.')
            : ('✓ Deleted ' + batchResp.total_deleted + ' of ' + batchResp.total_found
               + ' thumb_* file(s) found across ' + batchResp.processed_dirs + ' folder(s) checked. '
               + 'Recover any of these later via Admin → Tools → Regenerate Missing Thumbnails.')
          );
        }
        progressReset.classList.remove('d-none');
        return;
      }
    }
  }

  function finishWithError(message) {
    progressBar.classList.remove('progress-bar-animated');
    progressBar.classList.add('bg-danger');
    progressStat.innerHTML = '<span class="text-danger">✗ ' + message + '</span>';
    progressReset.classList.remove('d-none');
  }

  if (progressReset) {
    progressReset.addEventListener('click', function () {
      progressWrap.classList.add('d-none');
      progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated';
      delForm.classList.remove('d-none');
    });
  }

  if (delForm) {
    delForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var isDryRun     = !!(dryRun && dryRun.checked);
      var isAllAlbums  = folderInput.value.trim() === '';

      if (isAllAlbums && !recursiveChk.checked) {
        alert('Check Recursive to run against all of albums/, or enter a specific folder.');
        return;
      }

      if (!isDryRun) {
        var confirmMsg = isAllAlbums
          ? 'Delete thumbnails across EVERY folder under albums/ as configured above? This cannot be undone directly, but can be recovered via Regenerate Missing Thumbnails.'
          : 'Delete thumbnails as configured above? This cannot be undone directly, but can be recovered via Regenerate Missing Thumbnails.';
        if (!confirm(confirmMsg)) return;
      }
      runDelete(isDryRun);
    });
  }

  // Land on the section named by the URL fragment.
  var hash = window.location.hash.replace('#', '');
  if (hash) {
    var el = document.getElementById(hash);
    if (el) { el.scrollIntoView({behavior: 'smooth', block: 'start'}); }
  }
});
</script>
HTML;

lum_admin_page('On-Demand Thumbnails', $content, 'on_demand_thumbnails');
