<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Event Log Service
 *
 * Writes to and queries {PREFIX}admin_log (Migration0010, DB version 16) —
 * a small, always-on audit trail of security/admin events: login successes
 * and failures, and staff account and plugin changes. Distinct from the
 * existing {PREFIX}log table (see `lumora_log()` in functions.php), which
 * only records entries when `log_mode` is 'all' and mixes in every page
 * visit; this table is written unconditionally and only ever holds
 * security/audit-relevant rows, so it stays small and readable regardless
 * of that setting.
 *
 * Every write is wrapped in a try/catch and fails silently on a pre-
 * Migration0010 install where {PREFIX}admin_log does not yet exist, the
 * same defensive pattern InstallationService::logConfigChange() uses for
 * {PREFIX}config_changes — an audit-log write must never break the action
 * it is recording.
 *
 * @package    LumoraGallery
 * @subpackage Core
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.0
 * @see        Migration0010_CreateAdminLogTable Creates the backing table.
 * @see        InstallationService::getRecentChanges() The related config-change audit log, shown alongside this one on Admin → Logs.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class LogService
{
    /**
     * Canonical catalog of every event type this service writes, with a
     * human-readable label for the Admin → Logs filter dropdown. Order
     * defines display order in that dropdown.
     *
     * @var array<string, string>
     */
    const EVENT_TYPES = [
        'login_success' => 'Login — Success',
        'login_failure' => 'Login — Failure',
        'user_created'  => 'User — Created',
        'user_updated'  => 'User — Updated',
        'user_deleted'  => 'User — Deleted',
        'role_changed'  => 'User — Role Changed',
        'user_enabled'  => 'User — Enabled',
        'user_disabled' => 'User — Disabled',
        'plugin_enabled'  => 'Plugin — Enabled',
        'plugin_disabled' => 'Plugin — Disabled',
        'plugin_updated'  => 'Plugin — Updated',
        'theme_updated'   => 'Theme — Updated',
    ];

    /** Default retention window, in days, when `admin_log_retention_days` is unset. */
    const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Record one event. $userId is 0 for events with no authenticated actor
     * (e.g. a failed login attempt for a username that doesn't exist).
     */
    public static function log(
        string $eventType,
        int    $userId,
        string $username,
        string $ip,
        string $message
    ): void {
        try {
            LumoraDB::insert('admin_log', [
                'event_type' => substr($eventType, 0, 32),
                'user_id'    => max(0, $userId),
                'username'   => substr($username, 0, 50),
                'ip'         => substr($ip, 0, 45),
                'message'    => substr($message, 0, 65535),
            ]);
        } catch (\Throwable) {
            // {PREFIX}admin_log absent on pre-Migration0010 installs; fail silently.
        }
    }

    /**
     * Return a filtered, paginated page of events, newest first.
     *
     * @param array{event_type?: string, search?: string, date_from?: string, date_to?: string} $filters
     * @return list<array{id: string, event_type: string, user_id: string, username: string, ip: string, message: string, created_at: string}>
     */
    public static function query(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = self::buildWhere($filters);
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset  = ($page - 1) * $perPage;

        try {
            return LumoraDB::fetchAll(
                "SELECT * FROM `{PREFIX}admin_log` {$where}
                  ORDER BY created_at DESC, id DESC
                  LIMIT {$perPage} OFFSET {$offset}",
                $params
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Count events matching the given filters — used to build pagination
     * for query() above.
     *
     * @param array{event_type?: string, search?: string, date_from?: string, date_to?: string} $filters
     */
    public static function countByFilters(array $filters): int
    {
        [$where, $params] = self::buildWhere($filters);
        try {
            return (int) LumoraDB::fetchValue(
                "SELECT COUNT(*) FROM `{PREFIX}admin_log` {$where}",
                $params
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Delete events older than the configured retention window. Returns the
     * number of rows removed, or 0 if the table doesn't exist yet.
     */
    public static function pruneOlderThan(int $days): int
    {
        $days = max(1, $days);
        try {
            return LumoraDB::query(
                'DELETE FROM `{PREFIX}admin_log` WHERE created_at < (NOW() - INTERVAL ? DAY)',
                [$days]
            )->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Build a shared WHERE clause + params array for query() and
     * countByFilters() so the two can never drift out of sync.
     *
     * @param array{event_type?: string, search?: string, date_from?: string, date_to?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = [];
        $params     = [];

        $eventType = trim((string) ($filters['event_type'] ?? ''));
        if ($eventType !== '' && array_key_exists($eventType, self::EVENT_TYPES)) {
            $conditions[] = 'event_type = ?';
            $params[]     = $eventType;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(username LIKE ? OR ip LIKE ? OR message LIKE ?)';
            $like         = '%' . $search . '%';
            $params[]     = $like;
            $params[]     = $like;
            $params[]     = $like;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $conditions[] = 'created_at >= ?';
            $params[]     = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $conditions[] = 'created_at <= ?';
            $params[]     = $dateTo . ' 23:59:59';
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }
}
