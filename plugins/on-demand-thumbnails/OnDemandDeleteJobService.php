<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Batch-Delete Job Store
 *
 * A real (non-dry-run) batch delete on the Admin → On-Demand Thumbnails
 * settings page is driven in small chunks over several AJAX calls (see
 * ajax_delete_thumbs.php) instead of one long synchronous request, so a
 * large recursive delete can never silently die mid-way against PHP's
 * max_execution_time with no feedback shown to the admin.
 *
 * The list of files a job will delete is computed once, server-side, by
 * OnDemandThumbnailService::planDeletion() and persisted here — the browser
 * only ever sends back a job ID on subsequent calls, never file paths, so a
 * tampered request can't be used to make the server delete something
 * outside the originally planned, already-validated folder.
 *
 * One JSON file per job under cache/.odt_delete/, named by a random job ID.
 * No flock: each job file is only ever touched by the one browser tab that
 * created it, at one request at a time (the client waits for a batch
 * response before requesting the next), so concurrent writes to the same
 * job file don't happen in practice — the same reasoning UpdaterService's
 * own single lock file relies on.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.19.1
 * @see        OnDemandThumbnailService::planDeletion()/deleteFiles() Do the actual planning/deleting.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class OnDemandDeleteJobService
{
    /** Files processed per batch() call — bounds worst-case per-request unlink() work. */
    public const BATCH_SIZE = 200;

    /** Job files older than this are treated as abandoned and pruned on the next start(). */
    private const STALE_SECONDS = 3600;

    private static function jobDir(): string
    {
        return LUMORA_ROOT . 'cache' . DIRECTORY_SEPARATOR . '.odt_delete' . DIRECTORY_SEPARATOR;
    }

    private static function jobFile(string $jobId): string
    {
        return self::jobDir() . $jobId . '.json';
    }

    /**
     * Create a new job from an already-computed deletion plan (see
     * OnDemandThumbnailService::planDeletion()) and return its ID.
     *
     * @param array{to_delete: list<string>, total_found: int, folders: list<string>} $plan
     */
    public static function create(array $plan): string
    {
        $dir = self::jobDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::pruneStale();

        $jobId = bin2hex(random_bytes(16));
        $job   = [
            'to_delete'    => $plan['to_delete'],
            'total_found'  => $plan['total_found'],
            'folder_count' => count($plan['folders']),
            'offset'       => 0,
            'deleted'      => 0,
            'created_at'   => time(),
        ];

        file_put_contents(self::jobFile($jobId), json_encode($job, JSON_UNESCAPED_SLASHES));
        return $jobId;
    }

    /**
     * Process the next BATCH_SIZE files of $jobId, delete the job file once
     * exhausted, and return the running/final totals.
     *
     * @return array{success: bool, done: bool, message?: string, total_found?: int,
     *               to_delete_count?: int, processed?: int, deleted?: int, folder_count?: int}
     */
    public static function processBatch(string $jobId): array
    {
        $job = self::read($jobId);
        if ($job === null) {
            return ['success' => false, 'done' => true, 'message' => 'Unknown or expired delete job.'];
        }

        $slice = array_slice($job['to_delete'], $job['offset'], self::BATCH_SIZE);
        $deletedInBatch = OnDemandThumbnailService::deleteFiles($slice);

        $job['offset']  += count($slice);
        $job['deleted'] += $deletedInBatch;

        $toDeleteCount = count($job['to_delete']);
        $done          = $job['offset'] >= $toDeleteCount;

        if ($done) {
            @unlink(self::jobFile($jobId));
        } else {
            file_put_contents(self::jobFile($jobId), json_encode($job, JSON_UNESCAPED_SLASHES));
        }

        return [
            'success'         => true,
            'done'            => $done,
            'total_found'     => $job['total_found'],
            'to_delete_count' => $toDeleteCount,
            'processed'       => $job['offset'],
            'deleted'         => $job['deleted'],
            'folder_count'    => $job['folder_count'],
        ];
    }

    /**
     * @return array{to_delete: list<string>, total_found: int, folder_count: int,
     *               offset: int, deleted: int, created_at: int}|null
     */
    private static function read(string $jobId): ?array
    {
        // job IDs are always our own bin2hex(random_bytes(16)) output; reject
        // anything else before it ever reaches a filesystem path.
        if (!preg_match('/^[0-9a-f]{32}$/', $jobId)) {
            return null;
        }

        $path = self::jobFile($jobId);
        if (!is_file($path)) {
            return null;
        }

        $raw  = (string) @file_get_contents($path);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Remove job files left behind by an abandoned/closed browser tab. */
    private static function pruneStale(): void
    {
        $dir = self::jobDir();
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - self::STALE_SECONDS;
        foreach (glob($dir . '*.json') ?: [] as $file) {
            if ((@filemtime($file) ?: 0) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
