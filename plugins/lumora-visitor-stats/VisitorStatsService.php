<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Visitor Stats Plugin — Data Service
 *
 * All read/write access to this plugin's own {PREFIX}stats_hits table
 * (created by activate.php, never touched by core). Hooked into core
 * exclusively via HookService — see bootstrap.php — so nothing outside this
 * plugin's own folder references this class.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class VisitorStatsService
{
    /**
     * User-agent substrings (case-insensitive) treated as bot/crawler
     * traffic and excluded from the pageview log.
     */
    private const BOT_USER_AGENT_PATTERNS = [
        'bot', 'crawl', 'spider', 'slurp', 'archive.org', 'facebookexternalhit',
        'preview', 'headless', 'monitor', 'pingdom', 'uptime',
    ];

    /**
     * Log one pageview to {PREFIX}stats_hits.
     *
     * No-ops on an empty User-Agent or one matching a known bot pattern. The
     * visitor's IP is stored only as a SHA-256 hash (never raw), and the
     * referrer is reduced to its host only (never a full URL/query string).
     * Also opportunistically prunes rows older than
     * LUMORA_VISITOR_STATS_RETENTION_DAYS (roughly 1 in 200 calls).
     *
     * Registered on the 'lumora_pageview' action — see bootstrap.php.
     *
     * @param string $type    'site' | 'category' | 'album' | 'image'
     * @param int    $item_id FK to the relevant table's id, or 0 for 'site'.
     */
    public static function logPageview(string $type, int $item_id = 0): void
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') return;

        $ua_lower = strtolower($ua);
        foreach (self::BOT_USER_AGENT_PATTERNS as $pattern) {
            if (str_contains($ua_lower, $pattern)) return;
        }

        $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip_hash = $ip !== '' ? hash('sha256', $ip) : null;

        $referrer_host = null;
        $referer       = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if (is_string($host) && $host !== '' && $host !== ($_SERVER['HTTP_HOST'] ?? '')) {
                $referrer_host = substr($host, 0, 255);
            }
        }

        try {
            LumoraDB::insert('stats_hits', [
                'item_type'     => substr($type, 0, 16),
                'item_id'       => max(0, $item_id),
                'referrer_host' => $referrer_host,
                'ip_hash'       => $ip_hash,
            ]);
        } catch (\Throwable) {
            // Table missing (activation never ran, or ran before an older
            // version of this plugin) — fail silently rather than break the
            // page that triggered this hook.
            return;
        }

        if (random_int(1, 200) === 1) {
            try {
                LumoraDB::query(
                    'DELETE FROM `{PREFIX}stats_hits` WHERE viewed_at < NOW() - INTERVAL ? DAY',
                    [LUMORA_VISITOR_STATS_RETENTION_DAYS]
                );
            } catch (\Throwable) {
                // Best-effort cleanup; ignore failures.
            }
        }
    }

    /**
     * Return pageview totals for today, the last 7 days, the last 30 days,
     * and all time.
     *
     * @return array{today: int, week: int, month: int, all_time: int}
     */
    public static function getVisitSummary(): array
    {
        try {
            return [
                'today'    => (int) LumoraDB::fetchValue(
                    'SELECT COUNT(*) FROM `{PREFIX}stats_hits` WHERE DATE(viewed_at) = CURDATE()'
                ),
                'week'     => (int) LumoraDB::fetchValue(
                    'SELECT COUNT(*) FROM `{PREFIX}stats_hits` WHERE viewed_at >= NOW() - INTERVAL 7 DAY'
                ),
                'month'    => (int) LumoraDB::fetchValue(
                    'SELECT COUNT(*) FROM `{PREFIX}stats_hits` WHERE viewed_at >= NOW() - INTERVAL 30 DAY'
                ),
                'all_time' => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}stats_hits`'),
            ];
        } catch (\Throwable) {
            return ['today' => 0, 'week' => 0, 'month' => 0, 'all_time' => 0];
        }
    }

    /**
     * Return daily pageview counts for the last $days days (oldest first),
     * with every date present even when it has zero views.
     *
     * @return list<array{date: string, count: int}>
     */
    public static function getVisitsOverTime(int $days): array
    {
        $days = max(1, min(365, $days));

        try {
            $rows = LumoraDB::fetchAll(
                'SELECT DATE(viewed_at) AS d, COUNT(*) AS c
                   FROM `{PREFIX}stats_hits`
                  WHERE viewed_at >= CURDATE() - INTERVAL ? DAY
                  GROUP BY DATE(viewed_at)',
                [$days - 1]
            );
        } catch (\Throwable) {
            $rows = [];
        }

        $by_date = [];
        foreach ($rows as $row) {
            $by_date[(string) $row['d']] = (int) $row['c'];
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = ['date' => $date, 'count' => $by_date[$date] ?? 0];
        }
        return $out;
    }

    /**
     * Return the most-viewed images over the last $days days.
     *
     * @return list<array{id: int, album_id: int, title: string, filename: string, views: int}>
     */
    public static function getTopImages(int $days, int $limit): array
    {
        try {
            return LumoraDB::fetchAll(
                'SELECT i.id, i.album_id, i.title, i.filename, COUNT(*) AS views
                   FROM `{PREFIX}stats_hits` s
                   JOIN `{PREFIX}images` i ON i.id = s.item_id
                  WHERE s.item_type = \'image\' AND s.viewed_at >= NOW() - INTERVAL ? DAY
                  GROUP BY i.id, i.album_id, i.title, i.filename
                  ORDER BY views DESC
                  LIMIT ?',
                [max(1, $days), max(1, $limit)]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return the most-viewed albums over the last $days days.
     *
     * @return list<array{id: int, title: string, views: int}>
     */
    public static function getTopAlbums(int $days, int $limit): array
    {
        try {
            return LumoraDB::fetchAll(
                'SELECT a.id, a.title, COUNT(*) AS views
                   FROM `{PREFIX}stats_hits` s
                   JOIN `{PREFIX}albums` a ON a.id = s.item_id
                  WHERE s.item_type = \'album\' AND s.viewed_at >= NOW() - INTERVAL ? DAY
                  GROUP BY a.id, a.title
                  ORDER BY views DESC
                  LIMIT ?',
                [max(1, $days), max(1, $limit)]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return the top external referrer hosts over the last $days days.
     *
     * @return list<array{host: string, count: int}>
     */
    public static function getTopReferrers(int $days, int $limit): array
    {
        try {
            return LumoraDB::fetchAll(
                'SELECT referrer_host AS host, COUNT(*) AS count
                   FROM `{PREFIX}stats_hits`
                  WHERE referrer_host IS NOT NULL AND viewed_at >= NOW() - INTERVAL ? DAY
                  GROUP BY referrer_host
                  ORDER BY count DESC
                  LIMIT ?',
                [max(1, $days), max(1, $limit)]
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
