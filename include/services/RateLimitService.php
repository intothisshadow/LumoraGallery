<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Rate Limit Service
 *
 * Shared per-IP failure-lockout store for the admin panel's unauthenticated
 * auth surfaces (admin/login.php and reset-password.php), so an IP that
 * trips one surface's failure limit is locked out of the other too.
 *
 * Failures are tracked in cache/.login_ratelimit.json, keyed by IP, with
 * timestamps pruned to a sliding window. The entire read-prune-decide-write
 * cycle happens inside a single exclusive flock() hold (see withLock())
 * rather than separate unlocked reads and writes, so two concurrent
 * requests from the same IP can never both read a stale failure count and
 * slip past the lockout together. Degrades to an in-memory map (no
 * persistence) if the cache file can't be opened/locked, so a filesystem
 * hiccup fails open rather than blocking login entirely.
 *
 * @package    LumoraGallery
 * @subpackage Core
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.17.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class RateLimitService
{
    /** Sliding window, in seconds, that failures are counted within. */
    private const WINDOW_SECONDS = 900; // 15 minutes

    /** Failures within the window before an IP is locked out. Public so
     *  callers can tell whether a just-recorded failure tripped the lockout
     *  without duplicating the threshold. */
    public const MAX_FAILURES = 5;

    private static function storeFile(): string
    {
        return LUMORA_ROOT . 'cache' . DIRECTORY_SEPARATOR . '.login_ratelimit.json';
    }

    /**
     * Open the store, acquire an exclusive lock for the lifetime of
     * $callback, prune stale entries, and hand the pruned map to $callback
     * along with a $write closure that persists a replacement map before the
     * lock is released.
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
     * True when $ip has MAX_FAILURES or more recorded failures within the
     * current sliding window.
     */
    public static function isLocked(string $ip): bool
    {
        return self::withLock(
            static fn(array $data): bool => count($data[$ip] ?? []) >= self::MAX_FAILURES
        );
    }

    /**
     * Record one failed attempt for $ip (read, append, write, all under one
     * lock hold) and return the failure count within the window afterward.
     */
    public static function recordFailure(string $ip): int
    {
        $now = time();
        return self::withLock(
            static function (array $data, callable $write) use ($ip, $now): int {
                $data[$ip][] = $now;
                $write($data);
                return count($data[$ip]);
            }
        );
    }

    /**
     * Clear $ip's failure history — called on a successful login or a
     * successful emergency reset so a legitimate admin who mistyped a few
     * times isn't left partway toward a lockout the next time they need in.
     */
    public static function clearFailures(string $ip): void
    {
        self::withLock(static function (array $data, callable $write) use ($ip): null {
            unset($data[$ip]);
            $write($data);
            return null;
        });
    }
}
