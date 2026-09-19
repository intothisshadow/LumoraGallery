<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Rate Limiter
 *
 * Per-IP sliding-window request limiter for thumb.php, separate from core's
 * RateLimitService — that one is purpose-built for the admin login/
 * reset-password failure lockout (fixed threshold, "record a failure"
 * semantics, its own store), the wrong shape for a plain request-volume
 * limit, and reusing its class name would collide since both load in the
 * same request.
 *
 * A CDN in front of the site (see README) is what actually prevents most
 * traffic from reaching this endpoint at all; this is a defense-in-depth
 * backstop for direct-origin requests, a missing/misconfigured CDN, or a
 * cache-miss storm — bounding worst-case CPU cost from a single IP
 * hammering freshly-deleted thumbnails, rather than leaving it unbounded.
 *
 * Same file+flock sliding-window pattern as core's RateLimitService, kept
 * in its own store file so a burst of thumbnail generation can never
 * contribute toward, or be affected by, the separate admin login lockout.
 *
 * The limiter can be turned off entirely, and its threshold adjusted, from
 * the plugin's own Admin → On-Demand Thumbnails settings page (LG-068) —
 * see the `odt_rate_limit_enabled` / `odt_rate_limit_max_requests`
 * LumoraConfig keys read by allow() below. The window itself (60 seconds)
 * is not configurable, only the request count within it.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.18.5
 * @see        RateLimitService Core's admin login/reset-password limiter — a different purpose, not reused here.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class OnDemandRateLimitService
{
    /** Sliding window, in seconds, that requests are counted within. Not configurable. */
    private const WINDOW_SECONDS = 60;

    /** Default requests-within-window threshold, used until the admin sets one explicitly. */
    public const DEFAULT_MAX_REQUESTS = 120;

    /** Selectable thresholds offered on the settings page. */
    public const SELECTABLE_MAX_REQUESTS = [30, 60, 120, 240];

    private static function storeFile(): string
    {
        return LUMORA_ROOT . 'cache' . DIRECTORY_SEPARATOR . '.odt_ratelimit.json';
    }

    /**
     * Open the store, acquire an exclusive lock for the lifetime of
     * $callback, prune stale entries, and hand the pruned map to $callback
     * along with a $write closure that persists a replacement map before the
     * lock is released. Degrades to an in-memory map (no persistence, so
     * effectively unlimited) if the cache file can't be opened/locked, so a
     * filesystem hiccup fails open rather than blocking every thumbnail
     * request.
     *
     * @template T
     * @param callable(array<string, list<int>>, callable(array<string, list<int>>): void): T $callback
     * @return T
     */
    private static function withLock(callable $callback): mixed
    {
        $noop_write = static function (array $ignored): void {};

        $file = self::storeFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            return $callback([], $noop_write);
        }

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return $callback([], $noop_write);
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return $callback([], $noop_write);
        }

        $now = time();

        try {
            $raw     = stream_get_contents($fh);
            $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
            $data    = is_array($decoded) ? $decoded : [];

            // Prune timestamps outside the window.
            foreach ($data as $ip => &$times) {
                $times = array_values(array_filter(
                    is_array($times) ? $times : [],
                    static fn(mixed $t): bool => is_int($t) && ($now - $t) < self::WINDOW_SECONDS
                ));
                if (empty($times)) {
                    unset($data[$ip]);
                }
            }
            unset($times);

            $write = static function (array $newData) use ($fh): void {
                rewind($fh);
                ftruncate($fh, 0);
                fwrite($fh, json_encode($newData, JSON_UNESCAPED_SLASHES));
                fflush($fh);
            };

            return $callback($data, $write);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Record one request for $ip and return true if it should be allowed
     * through (still within the configured threshold for the current
     * window), or false if $ip has exceeded it and this request should be
     * throttled. Always returns true without recording anything when the
     * limiter is turned off via the settings page.
     */
    public static function allow(string $ip): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        $max = self::maxRequests();
        $now = time();
        return self::withLock(
            static function (array $data, callable $write) use ($ip, $now, $max): bool {
                $data[$ip][] = $now;
                $count = count($data[$ip]);
                $write($data);
                return $count <= $max;
            }
        );
    }

    /** Whether the limiter is turned on. Enabled by default. */
    public static function isEnabled(): bool
    {
        return ((string) LumoraConfig::get('odt_rate_limit_enabled', '1')) === '1';
    }

    /** The configured requests-per-window threshold. */
    public static function maxRequests(): int
    {
        $configured = (int) LumoraConfig::get('odt_rate_limit_max_requests', self::DEFAULT_MAX_REQUESTS);
        return in_array($configured, self::SELECTABLE_MAX_REQUESTS, true) ? $configured : self::DEFAULT_MAX_REQUESTS;
    }
}
