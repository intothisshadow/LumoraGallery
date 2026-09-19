<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin: Logs
 *
 * Three read-only views of what's been happening on this install:
 *
 *   - Security & Admin Events — filterable/paginated {PREFIX}admin_log rows
 *     written by LogService: login successes/failures, and staff account
 *     and plugin changes. Always recorded, independent of `log_mode`.
 *   - Config Changes — the existing {PREFIX}config_changes audit trail
 *     (InstallationService::logConfigChange()), previously written but
 *     never surfaced anywhere in the admin UI.
 *   - System Log — recent error/info entries from the existing {PREFIX}log
 *     table (see `lumora_log()` in functions.php), only populated when
 *     `log_mode` is 'errors' or 'all' in Configuration.
 *
 * Retention for the Security & Admin Events table is configurable
 * (`admin_log_retention_days`, default LogService::DEFAULT_RETENTION_DAYS)
 * and pruned opportunistically on each load of this page — the table stays
 * small enough that a dedicated cron job isn't warranted.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.0
 * @see        LogService Reads/writes {PREFIX}admin_log.
 * @see        InstallationService::getRecentChanges() Reads {PREFIX}config_changes.
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';
require_once __DIR__ . '/includes/admin_helpers.php';
lumora_require_permission('site_configuration');

$base   = lumora_base_url() . 'admin/logs.php';
$base_h = h($base);
$csrf_h = h(lumora_csrf_token());

// ── POST: save retention setting ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    if (($_POST['action'] ?? '') === 'save_retention') {
        $days = lumora_int($_POST['retention_days'] ?? LogService::DEFAULT_RETENTION_DAYS, LogService::DEFAULT_RETENTION_DAYS, 1, 3650);
        LumoraConfig::set('admin_log_retention_days', (string) $days);
        lum_flash('Retention updated to ' . $days . ' days.');
    }
    lumora_redirect($base . '#security');
}

// Opportunistic prune — cheap on an indexed, small table; runs on every GET.
$retention_days = lumora_int(lumora_config('admin_log_retention_days', LogService::DEFAULT_RETENTION_DAYS), LogService::DEFAULT_RETENTION_DAYS, 1, 3650);
LogService::pruneOlderThan($retention_days);

// ── Pagination preferences (Security & Admin Events tab) ────────────────────
$per_page_opts = [25, 50, 100];
if (isset($_GET['per_page'])) {
    $pp = lumora_int($_GET['per_page'], 25, 1, 200);
    if (in_array($pp, $per_page_opts, true)) {
        $_SESSION['lum_adm_per_page_logs'] = $pp;
    }
}
$per_page = (int) ($_SESSION['lum_adm_per_page_logs'] ?? 25);
if (!in_array($per_page, $per_page_opts, true)) {
    $per_page = 25;
}
$page = lumora_int($_GET['page'] ?? 1, 1, 1);

$filters = [
    'event_type' => trim((string) ($_GET['event_type'] ?? '')),
    'search'     => trim((string) ($_GET['q'] ?? '')),
    'date_from'  => trim((string) ($_GET['from'] ?? '')),
    'date_to'    => trim((string) ($_GET['to'] ?? '')),
];
$preserve = [
    'event_type' => $filters['event_type'],
    'q'          => $filters['search'],
    'from'       => $filters['date_from'],
    'to'         => $filters['date_to'],
];

// URL pattern for pagination links — built manually (each value urlencoded)
// rather than via http_build_query(), matching albums.php's own filtered-list
// pagination convention, since the pattern string is later fed through
// sprintf() with a %d placeholder for the page number.
$url_params = 'per_page=' . $per_page;
foreach ($preserve as $pk => $pv) {
    if ($pv !== '') {
        $url_params = $pk . '=' . urlencode($pv) . '&' . $url_params;
    }
}
$url_pattern = $base . '?' . $url_params . '&page=%d#security';

$total = LogService::countByFilters($filters);
$pag   = lumora_pagination($total, $per_page, $page, $url_pattern);
$events = LogService::query($filters, $pag['current_page'], $per_page);

// ── Event type filter options ────────────────────────────────────────────────
$type_opts = '<option value="">All event types</option>';
foreach (LogService::EVENT_TYPES as $slug => $label) {
    $sel = ($filters['event_type'] === $slug) ? ' selected' : '';
    $type_opts .= '<option value="' . h($slug) . '"' . $sel . '>' . h($label) . '</option>';
}

// ── Build Security & Admin Events rows ───────────────────────────────────────
$event_rows = '';
foreach ($events as $e) {
    $type_slug  = (string) $e['event_type'];
    $type_label = LogService::EVENT_TYPES[$type_slug] ?? $type_slug;
    $badge_cls  = str_starts_with($type_slug, 'login_failure') ? 'bg-danger'
                : (str_starts_with($type_slug, 'login_success') ? 'bg-success' : 'bg-secondary');
    $event_rows .= '<tr>'
        . '<td class="small text-muted align-middle">' . h((string) $e['created_at']) . '</td>'
        . '<td class="align-middle"><span class="badge ' . $badge_cls . '">' . h($type_label) . '</span></td>'
        . '<td class="align-middle small">' . (($e['username'] ?? '') !== '' ? h((string) $e['username']) : '<span class="text-muted">—</span>') . '</td>'
        . '<td class="align-middle small d-none d-md-table-cell">' . h((string) $e['ip']) . '</td>'
        . '<td class="align-middle small">' . h((string) $e['message']) . '</td>'
        . '</tr>';
}
if ($event_rows === '') {
    $event_rows = '<tr><td colspan="5" class="text-center text-muted py-4">No events match the current filters.</td></tr>';
}

$summary = 'Showing ' . number_format($pag['start_item']) . '–' . number_format($pag['end_item'])
         . ' of ' . number_format($total) . ' ' . ($total === 1 ? 'event' : 'events');
$per_page_sel = lum_per_page_selector($base . '#security', $preserve, $per_page, $per_page_opts);
$pag_ctrl     = lum_admin_pagination($pag);

// ── Config Changes tab ───────────────────────────────────────────────────────
$config_changes = InstallationService::getRecentChanges(100);
$config_rows    = '';
foreach ($config_changes as $c) {
    $config_rows .= '<tr>'
        . '<td class="small text-muted align-middle">' . h((string) $c['changed_at']) . '</td>'
        . '<td class="align-middle small">' . (($c['username'] ?? '') !== '' ? h((string) $c['username']) : '<span class="text-muted">—</span>') . '</td>'
        . '<td class="align-middle small d-none d-md-table-cell">' . h((string) $c['ip']) . '</td>'
        . '<td class="align-middle small"><code>' . h((string) $c['key']) . '</code></td>'
        . '<td class="align-middle small">' . h((string) $c['old_value']) . '</td>'
        . '<td class="align-middle small">' . h((string) $c['new_value']) . '</td>'
        . '</tr>';
}
if ($config_rows === '') {
    $config_rows = '<tr><td colspan="6" class="text-center text-muted py-4">No configuration changes recorded yet.</td></tr>';
}

// ── System Log tab (existing {PREFIX}log, error/info only — visits omitted) ─
$log_mode = (string) lumora_config('log_mode', 'off');
$system_rows = '';
if ($log_mode !== 'off') {
    try {
        $sys_entries = LumoraDB::fetchAll(
            "SELECT * FROM `{PREFIX}log` WHERE type IN ('error', 'info')
              ORDER BY created_at DESC, id DESC LIMIT 100"
        );
    } catch (\Throwable) {
        $sys_entries = [];
    }
    foreach ($sys_entries as $s) {
        $badge_cls = $s['type'] === 'error' ? 'bg-danger' : 'bg-info text-dark';
        $system_rows .= '<tr>'
            . '<td class="small text-muted align-middle">' . h((string) $s['created_at']) . '</td>'
            . '<td class="align-middle"><span class="badge ' . $badge_cls . '">' . h((string) $s['type']) . '</span></td>'
            . '<td class="align-middle small d-none d-md-table-cell">' . h((string) $s['ip']) . '</td>'
            . '<td class="align-middle small">' . h((string) $s['message']) . '</td>'
            . '</tr>';
    }
}
$filter_search_h = h($filters['search']);
$filter_from_h   = h($filters['date_from']);
$filter_to_h     = h($filters['date_to']);

if ($system_rows === '') {
    $config_url_h = h(lumora_base_url() . 'admin/config.php');
    $system_rows  = '<tr><td colspan="4" class="text-center text-muted py-4">'
        . ($log_mode === 'off'
            ? 'System logging is currently off. Enable it under <a href="' . $config_url_h . '">Configuration → Log Mode</a> to see error/info entries here.'
            : 'No error/info entries recorded yet.')
        . '</td></tr>';
}

$content = <<<HTML
<ul class="nav nav-tabs mb-3" id="lum-logs-tabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="lum-tab-security-btn" data-bs-toggle="tab" data-bs-target="#lum-tab-security" type="button" role="tab">Security &amp; Admin Events</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="lum-tab-config-btn" data-bs-toggle="tab" data-bs-target="#lum-tab-config" type="button" role="tab">Config Changes</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="lum-tab-system-btn" data-bs-toggle="tab" data-bs-target="#lum-tab-system" type="button" role="tab">System Log</button>
  </li>
</ul>

<div class="tab-content">

  <!-- ── Security & Admin Events ────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="lum-tab-security" role="tabpanel">

    <div class="lum-adm-card mb-3">
      <form method="get" action="{$base_h}" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Event type</label>
          <select name="event_type" class="form-select form-select-sm">
            {$type_opts}
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted mb-1">Search (username / IP / message)</label>
          <input type="text" name="q" class="form-control form-control-sm" value="{$filter_search_h}">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">From</label>
          <input type="date" name="from" class="form-control form-control-sm" value="{$filter_from_h}">
        </div>
        <div class="col-md-2">
          <label class="form-label small text-muted mb-1">To</label>
          <input type="date" name="to" class="form-control form-control-sm" value="{$filter_to_h}">
        </div>
        <div class="col-md-2 d-flex gap-1">
          <button type="submit" class="btn btn-sm btn-primary flex-fill">Filter</button>
          <a href="{$base_h}" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
      </form>
    </div>

    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div class="text-muted small">{$summary}</div>
      {$per_page_sel}
    </div>

    {$pag_ctrl}

    <div class="lum-adm-card p-0 mt-2 mb-3">
      <div class="table-responsive">
        <table class="table table-hover lum-adm-table-stack mb-0">
          <thead>
            <tr>
              <th style="width:160px">When</th>
              <th>Event</th>
              <th>User</th>
              <th class="d-none d-md-table-cell">IP</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
            {$event_rows}
          </tbody>
        </table>
      </div>
    </div>

    {$pag_ctrl}

    <div class="lum-adm-card mt-3">
      <h5 class="mb-1">Retention</h5>
      <p class="text-muted small mb-3">Security &amp; admin events older than this many days are pruned automatically.</p>
      <form method="post" action="{$base_h}" class="d-flex align-items-center gap-2">
        <input type="hidden" name="action"     value="save_retention">
        <input type="hidden" name="csrf_token" value="{$csrf_h}">
        <input type="number" name="retention_days" class="form-control form-control-sm" style="max-width:120px"
               min="1" max="3650" value="{$retention_days}">
        <span class="text-muted small">days</span>
        <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
      </form>
    </div>

  </div>

  <!-- ── Config Changes ─────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="lum-tab-config" role="tabpanel">
    <p class="text-muted small">Most recent 100 configuration changes made via Installation Settings.</p>
    <div class="lum-adm-card p-0 mb-3">
      <div class="table-responsive">
        <table class="table table-hover lum-adm-table-stack mb-0">
          <thead>
            <tr>
              <th style="width:160px">When</th>
              <th>User</th>
              <th class="d-none d-md-table-cell">IP</th>
              <th>Key</th>
              <th>Old Value</th>
              <th>New Value</th>
            </tr>
          </thead>
          <tbody>
            {$config_rows}
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── System Log ─────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="lum-tab-system" role="tabpanel">
    <p class="text-muted small">Most recent 100 error/info entries from the system log. Page-visit entries are omitted here — this is a diagnostic view, not traffic reporting.</p>
    <div class="lum-adm-card p-0 mb-3">
      <div class="table-responsive">
        <table class="table table-hover lum-adm-table-stack mb-0">
          <thead>
            <tr>
              <th style="width:160px">When</th>
              <th>Type</th>
              <th class="d-none d-md-table-cell">IP</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
            {$system_rows}
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
(function () {
  'use strict';
  // Land on the tab named by the URL fragment (e.g. "#config"), default Security.
  var hash = window.location.hash.replace('#', '');
  var map  = { security: 'lum-tab-security-btn', config: 'lum-tab-config-btn', system: 'lum-tab-system-btn' };
  if (hash && map[hash]) {
    var btn = document.getElementById(map[hash]);
    if (btn) { new bootstrap.Tab(btn).show(); }
  }
}());
</script>
HTML;

lum_admin_page('Logs', $content, 'logs');
