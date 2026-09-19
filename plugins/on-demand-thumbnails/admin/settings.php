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
 *     instead of over SSH.
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

$base   = lumora_base_url() . 'plugins/on-demand-thumbnails/admin/settings.php';
$base_h = h($base);
$csrf_h = h(lumora_csrf_token());

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

            // Resolve and confine to albums/ — this is a web-triggered action,
            // unlike the CLI scripts (shell access already implies trust), so
            // the target must be verified to actually live under albums/
            // before anything is touched.
            $albums_real = realpath(LUMORA_ALBUMS_PATH);
            $target_real = $folder_input !== '' ? realpath(LUMORA_ALBUMS_PATH . $folder_input) : false;

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
    instead of over SSH.
  </p>
  <form method="post" action="{$base_h}" id="lum-odt-delete-form">
    <input type="hidden" name="action" value="delete_thumbnails">
    <input type="hidden" name="csrf_token" value="{$csrf_h}">
    <div class="row g-2 align-items-end mb-2">
      <div class="col-md-5">
        <label class="form-label small text-muted mb-1">Folder (relative to albums/)</label>
        <input type="text" name="folder" class="form-control form-control-sm" placeholder="Season8/8x03-TheLongNight" required>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Mode</label>
        <select name="mode" class="form-select form-select-sm">
          <option value="all">Delete all</option>
          <option value="every_other">Delete every other</option>
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
</div>

<script>
(function () {
  'use strict';
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });

  var delForm = document.getElementById('lum-odt-delete-form');
  var dryRun  = document.getElementById('lum-odt-dryrun');
  if (delForm) {
    delForm.addEventListener('submit', function (e) {
      if (dryRun && dryRun.checked) return; // Dry run needs no confirmation.
      if (!confirm('Delete thumbnails as configured above? This cannot be undone directly, but can be recovered via Regenerate Missing Thumbnails.')) {
        e.preventDefault();
      }
    });
  }

  // Land on the section named by the URL fragment.
  var hash = window.location.hash.replace('#', '');
  if (hash) {
    var el = document.getElementById(hash);
    if (el) { el.scrollIntoView({behavior: 'smooth', block: 'start'}); }
  }
}());
</script>
HTML;

lum_admin_page('On-Demand Thumbnails', $content, 'on_demand_thumbnails');
