<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Updates
 *
 * Displays current version status and allows administrators to apply the
 * latest available Lumora release entirely from within the dashboard:
 *
 *   1. Update check — fetches metadata from the configured release provider
 *      (GitHub Releases API by default) and caches the result (24 hours for
 *      the 'daily' check-frequency setting, 7 days for 'weekly').
 *
 *   2. Standalone download & verify — an independent "Re-download release"
 *      action that downloads and SHA-256-verifies the release archive without
 *      committing to a full install, so an administrator can confirm a
 *      release is intact ahead of time. See UpdaterService::downloadStandalone().
 *
 *   3. One-click updater — multi-step workflow:
 *         Pre-flight → Download → Verify → Backup → Maintenance →
 *         Extract → Validate → Replace files → Migrate DB → Cleanup
 *      Each step is a separate AJAX call so the browser can report granular
 *      progress.  Failed stages offer a Rollback option that restores the
 *      database and config.php from the automatic "update backup" taken
 *      during Stage 4 (see item 5 below for the distinct, on-demand "full
 *      backup").
 *
 *   4. Database migrations — independent of the file updater; applies any
 *      pending SchemaService migrations via ajax_run_migrations.php.
 *
 *   5. Full backups — separate from the automatic "update backup" taken in
 *      Stage 4 above: manual, on-demand ZIP snapshots of the whole codebase +
 *      a database dump (see BackupService), with a create/restore/delete UI.
 *      Up to 3 are retained. Rendered as the "Full Backups" card.
 *
 *   6. System status — a live read of hosting-environment requirements
 *      (PHP version, extensions, permissions, disk space) shown as a
 *      pass/fail table (see UpdaterService::getSystemStatusChecks()).
 *
 *   7. Update settings — release channel (stable/prerelease), automatic
 *      check toggle + frequency, and an optional GitHub token, all plain
 *      POST-and-redirect actions handled at the top of this file (the same
 *      pattern as admin/config.php).
 *
 *   8. Update history — last 10 update attempts (installs, rollbacks, and
 *      backup restores) stored in the config table.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('view_updates');

// Actions that replace application files, run database migrations, manage
// backups, or change update settings (Install Update, Run Database Update,
// Back up now, Restore, Delete, Save settings, Re-download release,
// rollback/abort) require the stricter 'site_configuration' permission —
// 'view_updates' alone only grants visibility into version/status
// information. See ajax_update_perform.php and TODO-security.md #2 for the
// matching server-side enforcement on the multi-stage updater; the POST
// handler below enforces the same rule for every action added on this page.
$can_perform_updates = lumora_has_permission('site_configuration');
$self_url            = lumora_base_url() . 'admin/update.php';

// ── POST actions: settings, backups, standalone download ──────────────────────
// Plain POST-and-redirect handlers, matching the established pattern used by
// admin/config.php's export/import actions — no AJAX/JS required for these
// one-shot actions. The multi-stage "Update Now" workflow below remains
// AJAX-driven (ajax_update_perform.php) since it needs granular progress.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    lumora_csrf_validate();

    if (!$can_perform_updates) {
        lum_flash('This action requires the Site Configuration permission.', 'danger');
        lumora_redirect($self_url);
    }

    $post_action = (string) $_POST['action'];

    switch ($post_action) {
        case 'save_update_settings':
            LumoraConfig::set('update_channel', LumoraConfig::sanitizeValue('update_channel', $_POST['update_channel'] ?? 'stable'));
            LumoraConfig::set('update_check_frequency', LumoraConfig::sanitizeValue('update_check_frequency', $_POST['update_check_frequency'] ?? 'daily'));
            LumoraConfig::set('update_auto_check', LumoraConfig::sanitizeValue('update_auto_check', $_POST['update_auto_check'] ?? '0'));

            // Leave-blank-to-keep-current: only overwrite the stored token when
            // a new non-empty value was submitted, so re-saving the other
            // settings never accidentally blanks out an already-configured token.
            $token = trim((string) ($_POST['update_github_token'] ?? ''));
            if ($token !== '') {
                LumoraConfig::set('update_github_token', $token);
            }

            lum_flash('Update settings saved.');
            lumora_redirect($self_url);
            break;

        case 'backup_create':
            $r = BackupService::createBackup();
            lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
            lumora_redirect($self_url);
            break;

        case 'backup_restore':
            $r = BackupService::restoreBackup((string) ($_POST['filename'] ?? ''));
            lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
            lumora_redirect($self_url);
            break;

        case 'backup_delete':
            $r = BackupService::deleteBackup((string) ($_POST['filename'] ?? ''));
            lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
            lumora_redirect($self_url);
            break;

        case 'download_release':
            $ver   = (string) ($_POST['version'] ?? '');
            $force = isset($_POST['force']) && $_POST['force'] === '1';
            $r     = UpdaterService::downloadStandalone($ver, $force);
            lum_flash($r['message'], $r['success'] ? 'success' : 'danger');
            lumora_redirect($self_url);
            break;

        default:
            lum_flash('Unknown action.', 'danger');
            lumora_redirect($self_url);
    }
}

// ── Application update status (cache-only on page load) ───────────────────────
$upd           = UpdateService::getCachedStatus();
$cache_expired = UpdateService::isCacheExpired();
$upd_available = $upd['status'] === 'update_available' && $upd['latest'] !== null;

// ── Updater state ─────────────────────────────────────────────────────────────
$updater_running = UpdaterService::isUpdateRunning();
$updater_lock    = $updater_running ? UpdaterService::getLockInfo() : null;
$update_history  = UpdaterService::getUpdateHistory();

// ── Schema migration status ───────────────────────────────────────────────────
$mig_status  = SchemaService::getMigrationStatus();
$mig_pending = count($mig_status['pending']);
$mig_applied = count($mig_status['applied']);
$mig_total   = count(SchemaService::discoverMigrations());

// ── Release provider / source info ────────────────────────────────────────────
$provider      = AbstractUpdateProvider::createFromConfig();
$provider_h    = h($provider->getName());
$repo_h        = h($provider->getSourceLabel());
$releases_url_h = h($provider->getReleasesUrl());

// ── JS variables ──────────────────────────────────────────────────────────────
$csrf_js       = json_encode(lumora_csrf_token());
$csrf_h        = h(lumora_csrf_token());
$ajax_base_js  = json_encode(lumora_base_url() . 'admin/');
$auto_check_js = ($cache_expired && UpdateService::isAutoCheckEnabled()) ? 'true' : 'false';

// ── Latest available version info (for "Update Now" target) ──────────────────
$latest_h      = $upd['latest'] !== null ? h($upd['latest']) : '';
$latest_js     = json_encode($upd['latest'] ?? '');
$rel_date_h    = $upd['release_date'] !== null ? h($upd['release_date']) : 'N/A';
$release_name_h = $upd['release_name'] !== null ? h($upd['release_name']) : null;
$dl_url_h      = $upd['download_url']  !== null ? h($upd['download_url'])  : null;
$cl_url_h      = $upd['changelog_url'] !== null ? h($upd['changelog_url']) : null;
$min_php_h     = $upd['minimum_php']   !== null ? h($upd['minimum_php'])   : null;
$stability_badge = $upd['prerelease'] === true
    ? '<span class="badge bg-warning text-dark">Prerelease</span>'
    : ($upd['prerelease'] === false ? '<span class="badge bg-success">Stable</span>' : '');

// PHP version compatibility warning.
$php_compat_warn = ($upd['minimum_php'] !== null && version_compare(PHP_VERSION, $upd['minimum_php'], '<'))
    ? '<div class="alert alert-danger py-2 mt-2 small">⚠ Lumora '
      . $latest_h . ' requires PHP ' . $min_php_h
      . '. Your server is running PHP ' . h(PHP_VERSION)
      . '. <strong>Do not update</strong> until you have upgraded PHP.</div>'
    : '';
$php_ok = $php_compat_warn === '';

// ── Standalone download/verify info ───────────────────────────────────────────
$last_download = UpdaterService::getLastDownloadInfo();
$checksum_bar   = '';
if ($last_download !== null && $upd['latest'] !== null && $last_download['version'] === $upd['latest']) {
    $dl_at_h   = h(date('M j, Y g:i A', $last_download['downloaded_at'])) . ' UTC';
    $dl_size_h = h(lumora_format_bytes((int) $last_download['size']));
    $verified_line = $last_download['verified']
        ? '<div class="lum-upd-checksum-bar mb-2">✓ Downloaded and verified (' . $dl_size_h . ') at ' . $dl_at_h . '.</div>'
        : '<div class="lum-upd-checksum-bar mb-2">✓ Downloaded (' . $dl_size_h . ') at ' . $dl_at_h
          . '. <span class="text-muted">No checksum was available from the release to verify against.</span></div>';
    $sha_line = $last_download['sha256'] !== null
        ? '<div class="lum-upd-checksum-hash mb-2">SHA-256: ' . h($last_download['sha256']) . '</div>'
        : '';
    $checksum_bar = $verified_line . $sha_line;
}

// ── Build DB updates card ──────────────────────────────────────────────────────
if ($mig_pending === 0) {
    $db_updates_block = '<table class="table table-sm mb-0" style="max-width:400px">'
        . '<tr><th class="text-muted fw-normal" style="width:160px">Schema status</th>'
        . '<td><span class="badge bg-success">✓ Up to date</span></td></tr>'
        . '<tr><th class="text-muted fw-normal">Applied</th>'
        . '<td class="text-muted small">' . $mig_applied . ' migration(s)</td></tr>'
        . '</table>';
} else {
    $pending_items_html = '';
    foreach ($mig_status['pending'] as $m) {
        $pending_items_html .= '<li class="small font-monospace">' . h($m) . '</li>';
    }
    $db_updates_block = '<table class="table table-sm mb-2" style="max-width:400px">'
        . '<tr><th class="text-muted fw-normal" style="width:160px">Schema status</th>'
        . '<td><span class="badge bg-warning text-dark">⚠ ' . $mig_pending . ' pending</span></td></tr>'
        . '<tr><th class="text-muted fw-normal">Applied</th>'
        . '<td class="text-muted small">' . $mig_applied . ' migration(s)</td></tr>'
        . '</table>'
        . '<p class="small text-muted mb-1">Pending migrations:</p>'
        . '<ul class="mb-3">' . $pending_items_html . '</ul>';
    if ($can_perform_updates) {
        $db_updates_block .= '<div>'
            . '<button id="lum-migrate-btn" class="btn btn-warning">🗄 Run Database Update</button>'
            . '<span id="lum-migrate-spinner" class="text-muted small ms-2 d-none">Running…</span>'
            . '</div>'
            . '<div id="lum-migrate-result" class="mt-3"></div>';
    } else {
        $db_updates_block .= '<div class="alert alert-info py-2 small mb-0">'
            . 'Running database updates requires the Site Configuration permission. '
            . 'Ask an administrator to apply this update.'
            . '</div>';
    }
}

// ── Build consolidated metadata grid (header) ─────────────────────────────────
$installed_h  = h($upd['installed']);
$status_badge = match ($upd['status']) {
    'up_to_date'       => '<span class="badge bg-success fs-6">✓ Up to date</span>',
    'update_available' => '<span class="badge bg-warning text-dark fs-6">🔔 Update available</span>',
    'error'            => '<span class="badge bg-danger fs-6">⚠ Error</span>',
    default            => '<span class="badge bg-secondary fs-6">— Not checked yet</span>',
};
$checked_str  = $upd['checked_at'] !== null
    ? h(date('M j, Y g:i A', $upd['checked_at'])) . ' UTC'
    : 'Never';
$error_block  = $upd['error'] !== null
    ? '<div class="alert alert-warning py-2 mt-3 small">' . h($upd['error']) . '</div>'
    : '';

$channel_h = ((string) LumoraConfig::get('update_channel', 'stable')) === 'prerelease'
    ? 'Prerelease'
    : 'Stable';

$installed_at_h = h(rtrim(LUMORA_ROOT, DIRECTORY_SEPARATOR));
$schema_status_h = $mig_pending === 0
    ? 'v' . $mig_applied . ' <span class="text-muted small">(up to date)</span>'
    : 'v' . $mig_applied . ' <span class="text-muted small">(' . $mig_pending . ' of ' . $mig_total . ' pending)</span>';

$header_grid = <<<HTML
<div class="row g-4">
  <div class="col-md-4 lum-upd-meta-col">
    <dl>
      <dt>Installed version</dt>
      <dd class="fw-semibold fs-6">Lumora Gallery {$installed_h}</dd>
      <dt>Database schema version</dt>
      <dd>{$schema_status_h}</dd>
      <dt>Installed at</dt>
      <dd class="small font-monospace text-muted">{$installed_at_h}</dd>
    </dl>
  </div>
  <div class="col-md-4 lum-upd-meta-col">
    <dl>
      <dt>Update status</dt>
      <dd>{$status_badge}</dd>
      <dt>Release channel</dt>
      <dd>{$channel_h}</dd>
      <dt>Last checked</dt>
      <dd class="small text-muted">{$checked_str}</dd>
    </dl>
  </div>
  <div class="col-md-4 lum-upd-meta-col">
    <dl>
      <dt>Update source</dt>
      <dd>Provider: <strong>{$provider_h}</strong></dd>
      <dd>Repository: <code>{$repo_h}</code></dd>
      <dd><a href="{$releases_url_h}" target="_blank" rel="noopener">View all releases ↗</a></dd>
    </dl>
  </div>
</div>
{$error_block}
HTML;

// ── Build "Latest release" interactive card ────────────────────────────────────
$latest_release_card = '';
if ($upd['latest'] !== null) {
    $release_title_h = $release_name_h !== null ? $release_name_h : ('v' . $latest_h);
    $release_notes_html = UpdateService::renderReleaseNotesHtml($upd['release_notes']);

    $notes_btn = $cl_url_h
        ? '<a href="' . $cl_url_h . '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">View release notes on GitHub</a>'
        : '';
    $dl_btn = $dl_url_h
        ? '<a href="' . $dl_url_h . '" class="btn btn-outline-secondary btn-sm">Download release ZIP</a>'
        : '';

    $redownload_form = '';
    if ($can_perform_updates) {
        $redownload_form = <<<HTML
<form method="post" action="{$self_url}" class="d-inline">
  <input type="hidden" name="action" value="download_release">
  <input type="hidden" name="csrf_token" value="{$csrf_h}">
  <input type="hidden" name="version" value="{$latest_h}">
  <input type="hidden" name="force" value="1">
  <button type="submit" class="btn btn-outline-secondary btn-sm">Re-download release</button>
</form>
HTML;
    }

    $latest_release_card = <<<HTML
<div class="lum-adm-card mb-4">
  <h5 class="mb-2">Latest release</h5>
  <p class="mb-2">
    <strong>{$release_title_h}</strong> — version {$latest_h} {$stability_badge}
  </p>
  <p class="text-muted small mb-3">Published {$rel_date_h}</p>
  <div class="d-flex gap-2 flex-wrap mb-3">
    {$notes_btn}
    {$dl_btn}
    {$redownload_form}
  </div>
  {$checksum_bar}
  {$php_compat_warn}
  <p class="small text-muted mt-3 mb-1">Release notes:</p>
  <div class="lum-upd-release-notes">{$release_notes_html}</div>
</div>

HTML;
}

// ── Build "Update Now" card (shown only when update is available) ──────────────
$update_now_card = '';
if ($upd_available && !$can_perform_updates) {
    // Visible confirmation this update exists, but no interactive controls —
    // running the updater requires 'site_configuration' (see
    // ajax_update_perform.php). Showing a disabled/interactive form here
    // would only invite a confusing 403 on click.
    $update_now_card = <<<HTML
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">⬆ Install Update</h5>
  <div class="alert alert-info py-2 small mb-0">
    Installing this update requires the Site Configuration permission.
    Ask an administrator to apply it.
  </div>
</div>

HTML;
} elseif ($upd_available) {
    // Stage list HTML — rendered in PHP so CSS works without JS running first.
    $stage_rows = '';
    foreach (UpdaterService::STAGE_SEQUENCE as $s) {
        $label        = UpdaterService::STAGE_LABELS[$s] ?? $s;
        $stage_rows  .= '<div id="lum-stage-' . h($s) . '" class="d-flex align-items-start gap-2 mb-2 lum-upd-stage">'
            . '<span class="lum-upd-stage-icon mt-1" style="min-width:1.1rem;text-align:center">⊙</span>'
            . '<div>'
            . '<span class="lum-upd-stage-label small">' . h($label) . '</span>'
            . '<div class="lum-upd-stage-details small text-muted mt-1" style="display:none"></div>'
            . '</div>'
            . '</div>';
    }

    // Stuck-session notice (lock held but no update running from this browser).
    $stuck_notice = '';
    if ($updater_running && $updater_lock !== null) {
        $stuck_ver  = h($updater_lock['version'] ?? 'unknown');
        $stuck_strt = $updater_lock['started_at'] ?? 0;
        $stuck_age  = $stuck_strt > 0 ? h(human_time_diff((int) $stuck_strt)) . ' ago' : 'unknown time';
        $stuck_notice = '<div class="alert alert-warning py-2 mb-3 small">'
            . '⚠ An update session for v' . $stuck_ver . ' was started ' . $stuck_age . ' and may be stuck. '
            . '<button id="lum-upd-abort-stuck" class="btn btn-sm btn-outline-danger ms-2">Abort stuck session</button>'
            . '</div>';
    }

    $btn_disabled   = $updater_running ? ' disabled' : '';
    $php_warn_note  = !$php_ok
        ? '<div class="alert alert-danger py-2 mb-3 small">⚠ PHP version mismatch — see above. Do not update.</div>'
        : '';
    $btn_classes    = !$php_ok ? 'btn btn-danger disabled' : 'btn btn-success';

    $update_now_card = <<<HTML
<!-- ── "Update Now" card ───────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">⬆ Install Update</h5>
  {$stuck_notice}
  {$php_warn_note}

  <!-- Confirmation & options -->
  <div id="lum-upd-confirm-area" class="mb-3">
    <p class="small text-muted mb-2">
      The updater will download the release archive, verify its integrity, create an automatic
      update backup (database + configuration), replace application files, and run any pending
      database migrations — all without SSH access.  <strong>Custom themes and plugins are
      preserved by default.</strong>
    </p>

    <div class="lum-upd-confirm-box mb-3">
      <div class="form-check mb-0">
        <input class="form-check-input" type="checkbox" id="lum-upd-confirm-chk">
        <label class="form-check-label fw-semibold" for="lum-upd-confirm-chk">
          ⚠️ I understand that this will replace application files. I have verified my server
          has a backup or am relying on the automatic update backup (config + database only —
          see Full Backups below for a complete codebase snapshot) created during the update.
        </label>
      </div>
    </div>

    <hr class="my-3">

    <p class="small text-muted mb-2">
      <strong>Optional:</strong> select whether to replace plugins and themes with the versions
      bundled in the new release. Both are left unchanged by default.
    </p>

    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" id="lum-upd-replace-plugins">
      <label class="form-check-label small" for="lum-upd-replace-plugins">
        Replace installed plugins with versions bundled in this release
        <span class="text-muted">(plugins are preserved by default)</span>
      </label>
    </div>

    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" id="lum-upd-replace-themes">
      <label class="form-check-label small" for="lum-upd-replace-themes">
        Replace installed themes with versions bundled in this release
        <span class="text-muted">(themes are preserved by default; uncheck to keep customised themes)</span>
      </label>
    </div>

    <button id="lum-upd-start-btn" class="{$btn_classes}" disabled{$btn_disabled}>
      ⬆ Update to {$latest_h} Now
    </button>
    <span class="text-muted small ms-2">Provider: <span id="lum-upd-provider-label">{$provider_h}</span></span>
  </div>

  <!-- Progress area (hidden until update starts) -->
  <div id="lum-upd-progress" class="d-none">
    <h6 class="mb-3">Update progress</h6>

    <!-- Stage indicators -->
    <div id="lum-upd-stages" class="mb-3 ps-1">
      {$stage_rows}
    </div>

    <!-- Detail log -->
    <div id="lum-upd-log" class="small font-monospace"
         style="max-height:180px;overflow-y:auto;background:var(--bs-gray-100,#f8f9fa);
                border:1px solid var(--bs-border-color,#dee2e6);padding:.5rem .75rem;
                border-radius:.35rem;white-space:pre-wrap;word-break:break-word"></div>

    <!-- Controls -->
    <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
      <button id="lum-upd-rollback-btn" class="btn btn-danger btn-sm d-none">↩ Rollback</button>
      <button id="lum-upd-abort-btn"   class="btn btn-outline-secondary btn-sm d-none">⏹ Abort</button>
      <span   id="lum-upd-status-msg"  class="text-muted small"></span>
    </div>
  </div>
</div>

HTML;
}

// ── Build Full Backups card ──────────────────────────────────────────────────────
$backups = BackupService::listBackups();
$backup_rows = '';
foreach ($backups as $b) {
    $b_created_h = h(date('M j, Y g:i A', $b['created_at'])) . ' UTC';
    $b_size_h    = h(lumora_format_bytes((int) $b['size']));
    $b_ver_h     = h($b['version']);
    $b_fn_h      = h($b['filename']);

    $restore_btn = $can_perform_updates
        ? '<form method="post" action="' . $self_url . '" class="d-inline" onsubmit="return confirm(\'Restore this backup? Current application files and database will be overwritten (albums/ and cache/ are left untouched).\');">'
          . '<input type="hidden" name="action" value="backup_restore">'
          . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
          . '<input type="hidden" name="filename" value="' . $b_fn_h . '">'
          . '<button type="submit" class="btn btn-sm btn-outline-secondary">Restore</button>'
          . '</form>'
        : '';
    $delete_btn = $can_perform_updates
        ? '<form method="post" action="' . $self_url . '" class="d-inline ms-1" onsubmit="return confirm(\'Delete this backup permanently?\');">'
          . '<input type="hidden" name="action" value="backup_delete">'
          . '<input type="hidden" name="csrf_token" value="' . $csrf_h . '">'
          . '<input type="hidden" name="filename" value="' . $b_fn_h . '">'
          . '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>'
          . '</form>'
        : '';

    $backup_rows .= '<tr><td>' . $b_ver_h . '</td><td class="small">' . $b_created_h . '</td>'
        . '<td class="small">' . $b_size_h . '</td>'
        . '<td class="text-nowrap">' . $restore_btn . $delete_btn . '</td></tr>';
}
$backups_table = $backup_rows !== ''
    ? '<table class="table table-sm mb-0"><thead><tr><th>Version</th><th>Created</th><th>Size</th><th></th></tr></thead><tbody>' . $backup_rows . '</tbody></table>'
    : '<p class="text-muted small mb-0">No backups yet.</p>';

$backup_now_btn = $can_perform_updates
    ? <<<HTML
<form method="post" action="{$self_url}" class="mb-3">
  <input type="hidden" name="action" value="backup_create">
  <input type="hidden" name="csrf_token" value="{$csrf_h}">
  <button type="submit" class="btn btn-primary btn-sm">Back up now</button>
</form>
HTML
    : '<div class="alert alert-info py-2 small mb-3">Creating backups requires the Site Configuration permission.</div>';

$backups_card = <<<HTML
<div class="lum-adm-card mb-4">
  <h5 class="mb-2">Full Backups</h5>
  <p class="text-muted small mb-3">
    A full backup is a ZIP snapshot of the application's own code, configuration, and database —
    not your uploaded images, which a backup or update never touches. This is separate from the
    lightweight update backup (config + database only) created automatically before every update;
    create one here on demand for a complete, restorable snapshot. Up to 3 full backups are kept;
    creating a new one automatically removes the oldest beyond that.
  </p>
  {$backup_now_btn}
  {$backups_table}
</div>

HTML;

// ── Build System status card ───────────────────────────────────────────────────
$status_checks = UpdaterService::getSystemStatusChecks();
$status_rows = '';
foreach ($status_checks as $check) {
    $badge = $check['status'] === 'pass'
        ? '<span class="lum-upd-status-pass">✓ Pass</span>'
        : '<span class="lum-upd-status-fail">✗ Fail</span>';
    $status_rows .= '<tr><td>' . h($check['name']) . '</td><td>' . $badge . '</td>'
        . '<td class="small text-muted">' . h($check['detail']) . '</td></tr>';
}

$system_status_card = <<<HTML
<div class="lum-adm-card mb-4">
  <h5 class="mb-2">System status</h5>
  <p class="text-muted small mb-3">
    These reflect this server's current environment — they can change independently of
    anything above (e.g. if a host changes a PHP setting or free disk space runs low).
  </p>
  <table class="table table-sm mb-0">
    <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
    <tbody>{$status_rows}</tbody>
  </table>
</div>

HTML;

// ── Build Update settings card ─────────────────────────────────────────────────
$sel_channel_stable     = $channel_h === 'Stable' ? ' selected' : '';
$sel_channel_prerelease = $channel_h === 'Prerelease' ? ' selected' : '';
$chk_auto_check         = UpdateService::isAutoCheckEnabled() ? ' checked' : '';
$freq                   = (string) LumoraConfig::get('update_check_frequency', 'daily');
$sel_freq_daily         = $freq === 'daily'  ? ' selected' : '';
$sel_freq_weekly        = $freq === 'weekly' ? ' selected' : '';
$has_token              = trim((string) LumoraConfig::get('update_github_token', '')) !== '';
$token_placeholder      = $has_token ? 'Set — leave blank to keep unchanged' : 'Not set';

$update_settings_card = '';
if ($can_perform_updates) {
    $update_settings_card = <<<HTML
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">Update settings</h5>
  <form method="post" action="{$self_url}">
    <input type="hidden" name="action" value="save_update_settings">
    <input type="hidden" name="csrf_token" value="{$csrf_h}">

    <div class="mb-3">
      <label class="form-label fw-semibold">Release channel</label>
      <select name="update_channel" class="form-select" style="max-width:340px">
        <option value="stable"{$sel_channel_stable}>Stable (only stable releases)</option>
        <option value="prerelease"{$sel_channel_prerelease}>Prerelease (include beta/pre-release versions)</option>
      </select>
      <div class="form-text">Stable checks GitHub's latest full release only. Prerelease also considers beta/pre-release tags.</div>
    </div>

    <div class="mb-3">
      <div class="form-check form-switch">
        <input type="hidden" name="update_auto_check" value="0">
        <input class="form-check-input" type="checkbox" id="update_auto_check"
               name="update_auto_check" value="1"{$chk_auto_check}>
        <label class="form-check-label fw-semibold" for="update_auto_check">Automatically check for updates</label>
      </div>
      <div class="form-text">There's no cron available on typical shared hosting, so this checks opportunistically — the next time this admin page loads a check is due, rather than on a fixed schedule.</div>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">Check frequency</label>
      <select name="update_check_frequency" class="form-select" style="max-width:200px">
        <option value="daily"{$sel_freq_daily}>Daily</option>
        <option value="weekly"{$sel_freq_weekly}>Weekly</option>
      </select>
    </div>

    <div class="mb-3">
      <label class="form-label fw-semibold">GitHub token (optional)</label>
      <input type="password" name="update_github_token" class="form-control" style="max-width:340px"
             placeholder="{$token_placeholder}" autocomplete="off">
      <div class="form-text">Only needed if update checks start hitting GitHub's unauthenticated rate limit. A fine-grained personal access token with no permissions (public read access only) is sufficient. Leave blank to keep the current token unchanged.</div>
    </div>

    <button type="submit" class="btn btn-primary">Save settings</button>
  </form>
</div>

HTML;
}

// ── Build update history card ─────────────────────────────────────────────────
$history_card = '';
if (!empty($update_history)) {
    $rows = '';
    foreach ($update_history as $entry) {
        $icon   = $entry['success'] ? '✓' : '✗';
        $cls    = $entry['success'] ? 'text-success' : 'text-danger';
        $ver_h  = h($entry['version']);
        $msg_h  = h($entry['message']);
        $date_h = h($entry['updated_at']);
        $rows  .= "<tr><td class=\"{$cls}\">{$icon} v{$ver_h}</td><td class=\"small\">{$date_h}</td><td class=\"small text-muted\">{$msg_h}</td></tr>";
    }
    $history_card = <<<HTML
<!-- ── Update history card ─────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">📋 Update History</h5>
  <table class="table table-sm mb-0">
    <thead><tr><th>Version</th><th>Date (UTC)</th><th>Result</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>

HTML;
}

// ── Page content ──────────────────────────────────────────────────────────────
$content = <<<HTML
<!-- ── Application version status / metadata grid ───────────────────────────── -->
<div id="lum-update-status" class="lum-adm-card mb-4">
  {$header_grid}
</div>

{$latest_release_card}
{$update_now_card}

<!-- ── Database schema updates ──────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-3">🗄 Database Updates</h5>
  {$db_updates_block}
</div>

<!-- ── Check for updates ─────────────────────────────────────────────────────── -->
<div class="lum-adm-card mb-4">
  <h5 class="mb-2">🔄 Check for Updates</h5>
  <p class="text-muted small mb-3">
    Checks the configured release source for a new Lumora release.  Results are cached
    (see Update settings below for the check frequency). No gallery content, user data, or
    identifying information is ever transmitted — only a plain GET request is made to the
    release API.
  </p>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <button id="lum-check-btn" class="btn btn-primary">🔄 Check for Updates Now</button>
    <span id="lum-check-spinner" class="text-muted small d-none">Checking…</span>
  </div>
  <div id="lum-check-result" class="mt-3"></div>
</div>

{$backups_card}
{$system_status_card}
{$update_settings_card}
{$history_card}

<!-- ── Info ──────────────────────────────────────────────────────────────────── -->
<div class="lum-adm-card">
  <h5 class="mb-2">ℹ️ About Updates</h5>
  <ul class="small text-muted mb-0">
    <li>Update check results are cached; use the button above to force a refresh, or adjust the check frequency in Update settings.</li>
    <li>No gallery content, images, or user data is ever transmitted during an update check.</li>
    <li>Release source: <code>{$provider_h} ({$repo_h})</code></li>
    <li>Custom themes and plugins are preserved by default during an update. To replace them with the
        versions bundled in a release, tick the relevant checkboxes on the Install Update form above.</li>
    <li>An automatic <strong>update backup</strong> (database + <code>config.php</code> only) is created before
        any file replacement, stored in <code>cache/.updates/backup/</code>. It's what Rollback restores from
        if a stage fails. Separately, the <strong>Full Backups</strong> panel above lets you create and restore
        complete codebase + database ZIP snapshots on demand — use those for a full, restorable copy of the
        installation, not just the update-time safety net.</li>
    <li>If the <code>install/</code> directory is present when an update completes, it is automatically
        removed during the cleanup step.</li>
    <li>Cryptographic signature verification is a planned future security enhancement;
        SHA-256 checksum verification is used when the release source provides a checksum.</li>
  </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const CSRF      = {$csrf_js};
  const AJAX_BASE = {$ajax_base_js};
  const AUTO_CHECK = {$auto_check_js};
  const TARGET_VER = {$latest_js};

  // Per-update replacement preferences captured when the start button is clicked.
  // Stored here so runUpdateStage() can access them without DOM re-reads after
  // the confirmation area is hidden.
  let replacePlugins = '0';
  let replaceThemes  = '0';

  // ── Utility ────────────────────────────────────────────────────────────────

  function esc(val) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(val)));
    return d.innerHTML;
  }

  function escAttr(val) {
    return String(val)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  async function post(endpoint, body) {
    const params = new URLSearchParams(Object.assign({ csrf_token: CSRF }, body));
    const resp   = await fetch(AJAX_BASE + endpoint, {
      method : 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body   : params.toString(),
    });
    if (!resp.ok) throw new Error('Server returned HTTP ' + resp.status);
    return resp.json();
  }

  // ── Application update check ───────────────────────────────────────────────

  const \$checkBtn  = document.getElementById('lum-check-btn');
  const \$checkSpin = document.getElementById('lum-check-spinner');
  const \$checkRes  = document.getElementById('lum-check-result');

  if (\$checkBtn) {
    \$checkBtn.addEventListener('click', function () { runCheck(); });
  }
  if (AUTO_CHECK) runCheck();

  async function runCheck() {
    if (\$checkBtn)  { \$checkBtn.disabled = true; \$checkBtn.textContent = 'Checking\u2026'; }
    if (\$checkSpin)   \$checkSpin.classList.remove('d-none');
    if (\$checkRes)    \$checkRes.innerHTML = '';
    try {
      const d = await post('ajax_update_check.php', {});
      if (d.error && !d.latest) throw new Error(d.error);
      if (d.error && \$checkRes) {
        \$checkRes.innerHTML = '<div class="alert alert-warning py-2 small">\u26a0 ' + esc(d.error) + '</div>';
      }
      // Reload to reflect the fresh status everywhere on the page (metadata
      // grid, Latest release card, Install Update card, etc.) rather than
      // re-rendering each piece client-side.
      if (d.status === 'update_available' || d.status === 'up_to_date') {
        \$checkRes.innerHTML = '<div class="alert alert-success py-2 small">Status updated \u2014 reloading\u2026</div>';
        setTimeout(function () { location.reload(); }, 700);
      }
    } catch (err) {
      if (\$checkRes) {
        \$checkRes.innerHTML = '<div class="alert alert-danger py-2 small">Error: ' + esc(err.message) + '</div>';
      }
    } finally {
      if (\$checkBtn)  { \$checkBtn.disabled = false; \$checkBtn.textContent = '\ud83d\udd04 Check for Updates Now'; }
      if (\$checkSpin)   \$checkSpin.classList.add('d-none');
    }
  }

  // ── Database migration runner ──────────────────────────────────────────────

  const \$migrateBtn = document.getElementById('lum-migrate-btn');
  if (\$migrateBtn) {
    \$migrateBtn.addEventListener('click', async function () {
      const \$sp  = document.getElementById('lum-migrate-spinner');
      const \$res = document.getElementById('lum-migrate-result');
      \$migrateBtn.disabled = true; \$migrateBtn.textContent = 'Running\u2026';
      if (\$sp)  \$sp.classList.remove('d-none');
      if (\$res) \$res.innerHTML = '';
      try {
        const d = await post('ajax_run_migrations.php', {});
        if (d.success) {
          if (\$res) \$res.innerHTML = '<div class="alert alert-success py-2">\u2713 ' + esc(d.message) + '</div>';
          setTimeout(function () { location.reload(); }, 1500);
        } else {
          let html = '<div class="alert alert-danger py-2">\u2717 ' + esc(d.message);
          if (d.errors && d.errors.length) {
            html += '<ul class="mb-0 mt-1">'
              + d.errors.map(function (e) { return '<li class="small font-monospace">' + esc(e) + '</li>'; }).join('')
              + '</ul>';
          }
          html += '</div>';
          if (\$res) \$res.innerHTML = html;
          \$migrateBtn.disabled = false; \$migrateBtn.textContent = '\ud83d\uddc4 Run Database Update';
        }
      } catch (err) {
        if (\$res) \$res.innerHTML = '<div class="alert alert-danger py-2">Error: ' + esc(err.message) + '</div>';
        \$migrateBtn.disabled = false; \$migrateBtn.textContent = '\ud83d\uddc4 Run Database Update';
      } finally {
        if (\$sp) \$sp.classList.add('d-none');
      }
    });
  }

  // ── "Update Now" workflow ──────────────────────────────────────────────────

  const \$confirmChk      = document.getElementById('lum-upd-confirm-chk');
  const \$replacePlugins  = document.getElementById('lum-upd-replace-plugins');
  const \$replaceThemes   = document.getElementById('lum-upd-replace-themes');
  const \$startBtn        = document.getElementById('lum-upd-start-btn');
  const \$confirmArea     = document.getElementById('lum-upd-confirm-area');
  const \$progressArea    = document.getElementById('lum-upd-progress');
  const \$logEl           = document.getElementById('lum-upd-log');
  const \$statusMsg       = document.getElementById('lum-upd-status-msg');
  const \$rollbackBtn     = document.getElementById('lum-upd-rollback-btn');
  const \$abortBtn        = document.getElementById('lum-upd-abort-btn');
  const \$abortStuck      = document.getElementById('lum-upd-abort-stuck');

  // Enable start button only when confirmation checkbox is ticked.
  if (\$confirmChk && \$startBtn) {
    \$confirmChk.addEventListener('change', function () {
      \$startBtn.disabled = !this.checked;
    });
  }

  // Abort stuck session button.
  if (\$abortStuck) {
    \$abortStuck.addEventListener('click', async function () {
      \$abortStuck.disabled = true; \$abortStuck.textContent = 'Aborting\u2026';
      try { await post('ajax_update_perform.php', { action: 'abort' }); } catch (e) {}
      location.reload();
    });
  }

  // Start update: capture replacement options then launch preflight.
  if (\$startBtn) {
    \$startBtn.addEventListener('click', async function () {
      if (!TARGET_VER) { alert('No target version known.'); return; }

      // Capture the optional replacement preferences before hiding the form.
      replacePlugins = (\$replacePlugins && \$replacePlugins.checked) ? '1' : '0';
      replaceThemes  = (\$replaceThemes  && \$replaceThemes.checked)  ? '1' : '0';

      // Transition UI to progress mode.
      if (\$confirmArea)  \$confirmArea.classList.add('d-none');
      if (\$progressArea) \$progressArea.classList.remove('d-none');

      await runUpdateStage('preflight', TARGET_VER);
    });
  }

  // Rollback button.
  if (\$rollbackBtn) {
    \$rollbackBtn.addEventListener('click', async function () {
      \$rollbackBtn.disabled = true; \$rollbackBtn.textContent = 'Rolling back\u2026';
      if (\$abortBtn) \$abortBtn.classList.add('d-none');
      setStatusMsg('Restoring backup\u2026', 'warning');
      try {
        const d = await post('ajax_update_perform.php', { action: 'rollback' });
        appendLog(d.details || []);
        if (d.success) {
          setStatusMsg('\u2713 ' + esc(d.message), 'success');
        } else {
          setStatusMsg('\u2717 ' + esc(d.message) + ' \u2014 check the update log.', 'danger');
        }
        setTimeout(function () { location.reload(); }, 2000);
      } catch (err) {
        setStatusMsg('Rollback request failed: ' + esc(err.message), 'danger');
        \$rollbackBtn.disabled = false; \$rollbackBtn.textContent = '\u21a9 Rollback';
      }
    });
  }

  // Manual abort (force-reset without restore).
  if (\$abortBtn) {
    \$abortBtn.addEventListener('click', async function () {
      if (!confirm('Force-abort the update session without restoring? Only use this if no files have been replaced yet.')) return;
      \$abortBtn.disabled = true;
      try { await post('ajax_update_perform.php', { action: 'abort' }); } catch (e) {}
      location.reload();
    });
  }

  // ── Stage runner ───────────────────────────────────────────────────────────

  // Ordered stage list — matches UpdaterService::STAGE_SEQUENCE.
  const STAGES = [
    'preflight','download','verify','backup','maintenance',
    'extract','validate','replace','migrate','cleanup'
  ];
  // Stages that are "destructive" — once entered, offer rollback on failure.
  const DESTRUCTIVE = new Set(['maintenance','replace','migrate','cleanup']);

  async function runUpdateStage(stage, version) {
    markStageActive(stage);

    const body = { action: 'run_stage', stage: stage };
    if (version) body.version = version;

    // Attach per-update replacement preferences in the preflight POST only.
    // The updater writes them to the lock file; all later stages read from there.
    if (stage === 'preflight') {
      body.replace_plugins = replacePlugins;
      body.replace_themes  = replaceThemes;
    }

    let data;
    try {
      data = await post('ajax_update_perform.php', body);
    } catch (err) {
      markStageFailed(stage);
      setStatusMsg('Network error at stage ' + stage + ': ' + esc(err.message), 'danger');
      showAbortBtn();
      return;
    }

    // Append detail lines to the log.
    if (data.details && data.details.length) {
      appendLog(data.details);
    }

    if (!data.success) {
      markStageFailed(stage);
      setStatusMsg('\u2717 ' + esc(data.message), 'danger');
      // Offer rollback for destructive stages; abort-only for earlier ones.
      if (DESTRUCTIVE.has(stage)) {
        showRollbackBtn();
      } else {
        showAbortBtn();
      }
      return;
    }

    markStageDone(stage);
    setStatusMsg('\u27f3 ' + esc(data.message), 'muted');

    if (data.next) {
      // Small pause between stages so the UI updates are visible.
      await sleep(300);
      await runUpdateStage(data.next, '');
    } else {
      // Workflow complete.
      setStatusMsg('\ud83c\udf89 ' + esc(data.message), 'success');
      if (\$rollbackBtn) \$rollbackBtn.classList.add('d-none');
      if (\$abortBtn)    \$abortBtn.classList.add('d-none');
      setTimeout(function () { location.reload(); }, 3000);
    }
  }

  // ── Stage DOM helpers ──────────────────────────────────────────────────────

  function stageEl(stage) { return document.getElementById('lum-stage-' + stage); }
  function iconEl(stage)  { const el = stageEl(stage); return el ? el.querySelector('.lum-upd-stage-icon') : null; }
  function detailEl(stage){ const el = stageEl(stage); return el ? el.querySelector('.lum-upd-stage-details') : null; }

  function markStageActive(stage) {
    const ic = iconEl(stage);
    if (ic) { ic.textContent = '\u27f3'; ic.style.color = ''; }
  }

  function markStageDone(stage) {
    const ic = iconEl(stage);
    if (ic) { ic.textContent = '\u2713'; ic.style.color = 'var(--bs-success,#198754)'; }
  }

  function markStageFailed(stage) {
    const ic = iconEl(stage);
    if (ic) { ic.textContent = '\u2717'; ic.style.color = 'var(--bs-danger,#dc3545)'; }
  }

  function appendLog(lines) {
    if (!Array.isArray(lines) || !lines.length || !\$logEl) return;
    lines.forEach(function (line) {
      const div = document.createElement('div');
      div.textContent = line;
      \$logEl.appendChild(div);
    });
    \$logEl.scrollTop = \$logEl.scrollHeight;
  }

  function setStatusMsg(html, type) {
    if (!\$statusMsg) return;
    \$statusMsg.innerHTML = html;
    \$statusMsg.className = 'small align-self-center'
      + (type === 'muted'   ? ' text-muted'    : '')
      + (type === 'success' ? ' text-success'  : '')
      + (type === 'danger'  ? ' text-danger'   : '')
      + (type === 'warning' ? ' text-warning'  : '');
  }

  function showRollbackBtn() {
    if (\$rollbackBtn) \$rollbackBtn.classList.remove('d-none');
  }

  function showAbortBtn() {
    if (\$abortBtn) \$abortBtn.classList.remove('d-none');
  }

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

});
</script>
HTML;

// Persistent security notice when install/ still exists on disk after update.
$install_dir_notice = is_dir(LUMORA_ROOT . 'install')
    ? '<div class="alert alert-warning mb-4">&#x26A0;&#xFE0F; <strong>Security notice:</strong>'
      . ' The <code>install/</code> directory still exists on this server.'
      . ' Automatic removal after the last update did not complete — check file permissions.'
      . ' <strong>Delete it manually via FTP or your hosting file manager</strong> to prevent'
      . ' unauthorised access to the installation wizard.</div>'
    : '';

lum_admin_page('Updates', $install_dir_notice . $content, 'updates');
