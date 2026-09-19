<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Service
 *
 * Generates a single thumbnail in a temp file, streams it to the browser,
 * then deletes the temp file — no thumbnail is ever written under albums/,
 * so this never adds to the album folder's inode count. Every generated
 * response carries a long-lived Cache-Control header so a CDN (Cloudflare's
 * free tier, or similar) sitting in front of the site can cache the result
 * per-URL and stop most repeat requests from reaching this script again.
 *
 * This plugin only ever produces the gallery's one configured thumbnail
 * size (thumb_width / thumb_height) — it never accepts caller-supplied
 * dimensions, which would otherwise let a single image be requested at
 * many distinct sizes purely to force repeated CPU-heavy regeneration.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.18.5
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class OnDemandThumbnailService
{
    /** Markers delimiting this plugin's block within albums/.htaccess (LG-068). */
    private const HTACCESS_MARKER_START = '# BEGIN Lumora On-Demand Thumbnails';
    private const HTACCESS_MARKER_END   = '# END Lumora On-Demand Thumbnails';

    /**
     * Validate the requested album folder + original filename, generate a
     * thumbnail for it, and stream the result. Always ends the request
     * (sends a response and exits) — never returns to the caller.
     */
    public static function serve(string $folder, string $filename): void
    {
        self::initLogging();
        self::releaseSessionAndCacheHeaders();

        if (!PluginService::isEnabled('on-demand-thumbnails')) {
            self::fail(404, "plugin disabled (folder={$folder}, file={$filename})");
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!OnDemandRateLimitService::allow($ip)) {
            header('Retry-After: 60');
            self::fail(429, "rate limit exceeded (ip={$ip}, folder={$folder}, file={$filename})");
        }

        $original_path = self::resolveOriginalPath($folder, $filename);
        if ($original_path === null) {
            self::fail(404, "path validation failed (folder={$folder}, file={$filename})");
        }

        if (!ThumbnailService::isAllowedImage($filename)) {
            self::fail(404, "disallowed file type (file={$filename})");
        }

        $thumb_w = max(1, (int) LumoraConfig::get('thumb_width',  250));
        $thumb_h = max(1, (int) LumoraConfig::get('thumb_height', 250));

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
             . 'lum_odt_' . bin2hex(random_bytes(8)) . '.' . $ext;

        $ok = ThumbnailService::generateThumb($original_path, $tmp, $thumb_w, $thumb_h);

        if (!$ok || !file_exists($tmp)) {
            if (file_exists($tmp)) unlink($tmp);
            self::fail(500, "generateThumb() failed for {$original_path}");
        }

        self::stream($tmp, $ext);
    }

    /**
     * Route this request's error_log() output to a dedicated file in this
     * plugin's own folder, protected from direct web access by the same
     * root .htaccess rule that denies all *.log files site-wide. Keeps
     * this plugin's activity separate from the shared server error log,
     * which on a multi-domain reseller account is otherwise full of
     * unrelated noise from other sites on the same account.
     */
    private static function initLogging(): void
    {
        ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'errors.log');
    }

    /**
     * If bootstrap.php started a session for this request (an admin-path
     * request, or a visitor carrying a session/remember-me cookie), close
     * it immediately and strip the Expires/Pragma headers PHP's default
     * session cache limiter adds. Otherwise those headers ride along with
     * every response this endpoint sends, which can defeat both a CDN's
     * edge cache and any server-side page cache (e.g. LiteSpeed's
     * LSCache) sitting in front of the site — and holding the session
     * open for the rest of this request would otherwise serialize every
     * other thumbnail request sharing that same session behind PHP's
     * exclusive session-file lock.
     */
    private static function releaseSessionAndCacheHeaders(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header_remove('Pragma');
        header_remove('Expires');
    }

    /**
     * Resolve $folder/$filename to an absolute, verified-safe path to the
     * original image, or null if it fails any check. Guards against
     * directory traversal by resolving real paths and confirming both the
     * album folder and the original file land inside LUMORA_ALBUMS_PATH.
     */
    private static function resolveOriginalPath(string $folder, string $filename): ?string
    {
        if ($folder === '' || $filename === '') return null;
        if (str_contains($folder, "\0") || str_contains($filename, "\0")) return null;

        $albums_real = realpath(LUMORA_ALBUMS_PATH);
        if ($albums_real === false) return null;
        $albums_real .= DIRECTORY_SEPARATOR;

        $dir_real = realpath(lumora_album_path($folder));
        if ($dir_real === false) return null;
        $dir_real .= DIRECTORY_SEPARATOR;

        if (!str_starts_with($dir_real, $albums_real)) return null;

        // basename() strips any path component the caller tried to smuggle
        // in via $filename before we ever touch the filesystem with it.
        $candidate = $dir_real . basename($filename);
        $file_real = realpath($candidate);
        if ($file_real === false || !is_file($file_real)) return null;
        if (!str_starts_with($file_real, $albums_real)) return null;

        return $file_real;
    }

    /**
     * Stream a generated thumbnail file to the browser with cache headers,
     * then delete it — it never persists beyond this one request.
     */
    private static function stream(string $tmp_path, string $ext): void
    {
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($tmp_path));
        header('Cache-Control: public, max-age=' . LUMORA_ODT_CACHE_SECONDS . ', immutable');

        readfile($tmp_path);
        unlink($tmp_path);
        exit;
    }

    /**
     * Fail securely: a bare status code in the response, with no filesystem
     * paths or internal detail ever sent to the browser. $reason is
     * server-side only, written to this plugin's own log file.
     */
    private static function fail(int $status, string $reason): never
    {
        error_log("Lumora on-demand-thumbnails: {$status} — {$reason}");
        http_response_code($status);
        exit;
    }

    // ── albums/.htaccess install/remove (LG-068) ────────────────────────────
    //
    // Additive: this plugin's rewrite rule is wrapped in BEGIN/END markers
    // and appended to — never overwriting — whatever albums/.htaccess
    // already contains, matching the project's convention of never
    // clobbering foreign content in a shared file. Removing the block
    // deletes the whole file only when nothing else is left in it.

    private static function htaccessPath(): string
    {
        return LUMORA_ALBUMS_PATH . '.htaccess';
    }

    /** The rewrite rule block this plugin needs, wrapped in its markers. */
    private static function htaccessRuleBlock(): string
    {
        // The rewrite target must include whatever subdirectory prefix this
        // install lives under (e.g. "/gallery"), not just assume web root —
        // derived from the configured base URL rather than hardcoded, so it
        // stays correct after an Installation Settings base-URL change.
        $prefix = rtrim((string) parse_url(lumora_base_url(), PHP_URL_PATH), '/');

        return self::HTACCESS_MARKER_START . "\n"
            . "RewriteEngine On\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteRule ^(.+)/thumb_(.+)$ {$prefix}/plugins/on-demand-thumbnails/thumb.php?folder=\$1&file=\$2 [L,QSA]\n"
            . self::HTACCESS_MARKER_END . "\n";
    }

    /** Whether this plugin's block is currently present in albums/.htaccess. */
    public static function isHtaccessInstalled(): bool
    {
        $path = self::htaccessPath();
        if (!is_file($path)) return false;
        $content = (string) @file_get_contents($path);
        return str_contains($content, self::HTACCESS_MARKER_START);
    }

    /**
     * Append this plugin's rewrite rule block to albums/.htaccess, creating
     * the file if it doesn't exist yet. No-ops (returns true) if the block
     * is already present. Never touches any other content in the file.
     *
     * @return true|string true on success, a user-facing error (with a
     *                      copy-pasteable manual fallback) on failure.
     */
    public static function installHtaccess(): true|string
    {
        $path     = self::htaccessPath();
        $existing = is_file($path) ? (string) @file_get_contents($path) : '';

        if (str_contains($existing, self::HTACCESS_MARKER_START)) {
            return true; // Already installed.
        }

        $new = rtrim($existing) . ($existing !== '' ? "\n\n" : '') . self::htaccessRuleBlock();

        if (@file_put_contents($path, $new) === false) {
            return 'Could not write to albums/.htaccess — the web server user may not have write '
                 . 'access to albums/. Add this block to albums/.htaccess manually instead:'
                 . "\n\n" . self::htaccessRuleBlock();
        }

        return true;
    }

    /**
     * Remove this plugin's rewrite rule block from albums/.htaccess. Leaves
     * any other content in the file untouched; deletes the file entirely
     * only when removing the block leaves it empty. No-ops (returns true)
     * if the block isn't present.
     *
     * @return true|string true on success, a user-facing error on failure.
     */
    public static function removeHtaccess(): true|string
    {
        $path = self::htaccessPath();
        if (!is_file($path)) {
            return true;
        }

        $content = (string) @file_get_contents($path);
        if (!str_contains($content, self::HTACCESS_MARKER_START)) {
            return true; // Nothing to remove.
        }

        $pattern = '/\n?' . preg_quote(self::HTACCESS_MARKER_START, '/')
                 . '.*?' . preg_quote(self::HTACCESS_MARKER_END, '/') . '\n?/s';
        $remaining = trim((string) preg_replace($pattern, '', $content));

        if ($remaining === '') {
            if (!@unlink($path)) {
                return 'Could not remove albums/.htaccess — check file permissions.';
            }
            return true;
        }

        if (@file_put_contents($path, $remaining . "\n") === false) {
            return 'Could not update albums/.htaccess — check file permissions.';
        }

        return true;
    }

    // ── Batch thumbnail deletion (LG-068) ───────────────────────────────────
    //
    // Shared implementation behind both the Admin → On-Demand Thumbnails
    // settings page and tools/delete-all-thumbs.sh / delete-every-other-
    // thumb.sh, which are now thin CLI wrappers around this method — see
    // those scripts for the original standalone logic this replicates.
    // Only files named thumb_* are ever touched; original photos are never
    // considered. Every deletion here can be recovered later via
    // Admin → Tools → Regenerate Missing Thumbnails, as long as the
    // original photo is still in place.

    /**
     * Delete (or, with $dryRun, just report) thumb_* files under $path, in
     * one call. Used by the CLI wrappers, where there is no request time
     * limit to worry about. The admin settings page instead drives a real
     * (non-dry-run) delete through planDeletion() + deleteFiles() below, in
     * small chunks over several AJAX calls, so a large recursive delete can
     * never silently die mid-way against PHP's max_execution_time with no
     * feedback — see ajax_delete_thumbs.php.
     *
     * @param string $path       Directory to operate on — an album folder, or
     *                           (with $recursive) a top-level directory
     *                           containing many album subfolders.
     * @param bool   $recursive  Apply to every subfolder under $path that
     *                           contains thumb_* files, each treated
     *                           independently, instead of just $path itself.
     * @param bool   $everyOther Delete only every other file (natural-sorted,
     *                           0-indexed even positions) per folder, instead
     *                           of all of them — a cautious partial migration
     *                           that keeps roughly half as a fallback.
     * @param bool   $dryRun     Report what would be deleted without deleting.
     * @return array{total_found: int, total_deleted: int, dry_run: bool,
     *               folders: array<string, array{found: int, deleted: int}>}
     */
    public static function deleteThumbnails(string $path, bool $recursive, bool $everyOther, bool $dryRun): array
    {
        $plan = self::planDeletion($path, $recursive, $everyOther);

        $result = ['total_found' => $plan['total_found'], 'total_deleted' => 0, 'dry_run' => $dryRun, 'folders' => []];

        $to_delete_by_folder = [];
        foreach ($plan['to_delete'] as $f) {
            $to_delete_by_folder[dirname($f)][] = $f;
        }

        foreach ($plan['per_folder_found'] as $dir => $found) {
            $selected = $to_delete_by_folder[$dir] ?? [];
            $deleted  = $dryRun ? count($selected) : self::deleteFiles($selected);
            $result['folders'][$dir]  = ['found' => $found, 'deleted' => $deleted];
            $result['total_deleted'] += $deleted;
        }

        return $result;
    }

    /**
     * Read-only planning step: every folder that will be touched, the exact
     * files selected for deletion in each (after $everyOther filtering), and
     * total counts — no filesystem writes happen here. This is fast even for
     * a large recursive tree (a directory scan + sort, no I/O per file), so
     * it's safe to run synchronously; deleteFiles() below does the actual
     * (potentially slow) unlink() work in caller-controlled chunks.
     *
     * @return array{folders: list<string>, to_delete: list<string>,
     *               total_found: int, per_folder_found: array<string, int>}
     */
    public static function planDeletion(string $path, bool $recursive, bool $everyOther): array
    {
        if (!is_dir($path)) {
            return ['folders' => [], 'to_delete' => [], 'total_found' => 0, 'per_folder_found' => []];
        }

        $to_delete        = [];
        $total_found      = 0;
        $per_folder_found = [];

        foreach (self::listFoldersForDeletion($path, $recursive) as $dir) {
            $files = self::sortedThumbFiles($dir);
            if ($files === []) {
                continue;
            }

            $per_folder_found[$dir] = count($files);
            $total_found            += count($files);

            $selected = $everyOther
                ? array_values(array_filter($files, static fn($i) => $i % 2 === 0, ARRAY_FILTER_USE_KEY))
                : $files;
            array_push($to_delete, ...$selected);
        }

        return [
            'folders'          => array_keys($per_folder_found),
            'to_delete'        => $to_delete,
            'total_found'      => $total_found,
            'per_folder_found' => $per_folder_found,
        ];
    }

    /**
     * Delete exactly the given files — no further validation, no directory
     * traversal, no globbing. Callers (deleteThumbnails() above, and the
     * chunked AJAX delete) are responsible for only ever passing paths that
     * came from planDeletion()'s own output, never anything client-supplied.
     * Returns the number actually deleted (a file already gone, or an
     * unwritable permission, is simply not counted rather than failing the
     * whole batch).
     *
     * @param list<string> $files
     */
    public static function deleteFiles(array $files): int
    {
        $deleted = 0;
        foreach ($files as $f) {
            if (@unlink($f)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * The ordered list of directories planDeletion() and deleteThumbnails()
     * operate over: every subfolder under $path with at least one thumb_*
     * file when $recursive, or just $path itself otherwise.
     *
     * @return list<string>
     */
    private static function listFoldersForDeletion(string $path, bool $recursive): array
    {
        return $recursive ? self::findThumbFolders($path) : [rtrim($path, '/\\')];
    }

    /**
     * Every subfolder under $root that directly contains at least one
     * thumb_* file, each treated independently by deleteThumbnails() above
     * — mirrors delete-every-other-thumb.sh's `find ... -exec dirname` step.
     *
     * @return list<string>
     */
    private static function findThumbFolders(string $root): array
    {
        $dirs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_starts_with($file->getFilename(), LUMORA_THUMB_PREFIX)) {
                $dirs[$file->getPath()] = true;
            }
        }
        $dirs = array_keys($dirs);
        sort($dirs);
        return $dirs;
    }

    /**
     * thumb_* files directly inside $dir, natural-sorted (matches `sort -V`
     * in the original shell scripts) so "every other" selects a stable,
     * predictable half regardless of filesystem listing order.
     *
     * @return list<string>
     */
    private static function sortedThumbFiles(string $dir): array
    {
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . LUMORA_THUMB_PREFIX . '*') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        natsort($files);
        return array_values($files);
    }
}
