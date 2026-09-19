<?php
declare(strict_types=1);
/**
 * Lumora Gallery — On-Demand Thumbnails Plugin — Batch-Delete Job Store
 *
 * A batch delete (or its dry run) on the Admin → On-Demand Thumbnails
 * settings page is driven in small chunks over several AJAX calls (see
 * ajax_delete_thumbs.php) instead of one long synchronous request, so a
 * large recursive scan can never silently die mid-way with no feedback
 * shown to the admin.
 *
 * The job is a breadth-first work queue of directories, discovered one
 * level at a time rather than enumerated up front: each batch() call pops
 * exactly one directory off the queue, processes that directory's own
 * thumb_* files (the same bounded shape already proven to complete
 * comfortably within a normal request even for a single large album), and
 * — if this is a recursive job — pushes that directory's immediate
 * subdirectories onto the back of the queue for a later call to expand in
 * turn. Two earlier versions of this job instead tried to enumerate
 * everything up front in the 'start' action: first every matched file path
 * across the whole scope (planDeletion()), then just every matching folder
 * path (listFoldersForDeletion()/findThumbFolders()) — both still walk the
 * *entire* recursive tree in one request, and for a large enough section
 * that alone could exceed request time/memory limits and die with no HTTP
 * response at all, before a job was ever created. On hosts that lock those
 * limits (a runtime override from within the script has no effect there),
 * there is no way to raise that ceiling at all — so this design instead
 * keeps every single request's filesystem work bounded to "one directory's
 * immediate contents", regardless of how large the overall tree is.
 *
 * Since the queue grows as it's expanded, the total number of directories
 * isn't known until the job finishes — there is no fixed "N of M" to
 * report, only a running count of directories checked so far.
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
 * @see        OnDemandThumbnailService::processFolder()/immediateSubdirectories() Do the actual scanning/deleting.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class OnDemandDeleteJobService
{
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
     * Create a new job with just the root directory queued — nothing is
     * scanned yet; the first batch() call processes the root itself and,
     * if $recursive, discovers its immediate children for later calls to
     * work through in turn.
     */
    public static function create(string $rootPath, bool $recursive, bool $everyOther, bool $dryRun): string
    {
        $dir = self::jobDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::pruneStale();

        $jobId = bin2hex(random_bytes(16));
        $job   = [
            'queue'          => [$rootPath],
            'recursive'      => $recursive,
            'every_other'    => $everyOther,
            'dry_run'        => $dryRun,
            'processed_dirs' => 0,
            'total_found'    => 0,
            'total_deleted'  => 0,
            'created_at'     => time(),
        ];

        file_put_contents(self::jobFile($jobId), json_encode($job, JSON_UNESCAPED_SLASHES));
        return $jobId;
    }

    /**
     * Process the next directory off $jobId's queue — scanning/deleting its
     * own thumb_* files and, if recursive, discovering its immediate
     * subdirectories for later calls — delete the job file once the queue
     * is empty, and return the running/final totals.
     *
     * @return array{success: bool, done: bool, message?: string, dry_run?: bool,
     *               processed_dirs?: int, total_found?: int, total_deleted?: int}
     */
    public static function processBatch(string $jobId): array
    {
        $job = self::read($jobId);
        if ($job === null) {
            return ['success' => false, 'done' => true, 'message' => 'Unknown or expired delete job.'];
        }

        $dir = array_shift($job['queue']);
        if ($dir !== null) {
            $result = OnDemandThumbnailService::processFolder($dir, $job['every_other'], $job['dry_run']);
            $job['total_found']   += $result['found'];
            $job['total_deleted'] += $result['deleted'];
            $job['processed_dirs']++;

            if ($job['recursive']) {
                array_push($job['queue'], ...OnDemandThumbnailService::immediateSubdirectories($dir));
            }
        }

        $done = $job['queue'] === [];

        if ($done) {
            @unlink(self::jobFile($jobId));
        } else {
            file_put_contents(self::jobFile($jobId), json_encode($job, JSON_UNESCAPED_SLASHES));
        }

        return [
            'success'        => true,
            'done'           => $done,
            'dry_run'        => $job['dry_run'],
            'processed_dirs' => $job['processed_dirs'],
            'total_found'    => $job['total_found'],
            'total_deleted'  => $job['total_deleted'],
        ];
    }

    /**
     * @return array{queue: list<string>, recursive: bool, every_other: bool, dry_run: bool,
     *               processed_dirs: int, total_found: int, total_deleted: int, created_at: int}|null
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
