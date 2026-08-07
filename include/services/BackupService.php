<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Backup Service
 *
 * Full-installation ZIP backups ("full backups") for the admin "Full Backups"
 * panel (Admin → Updates), distinct from the lightweight "update backup"
 * (config.php + database.sql only) UpdaterService creates automatically as
 * part of Stage 4 of every in-dashboard update.
 *
 * A backup created here is a ZIP snapshot of the application's own code and
 * configuration (everything under LUMORA_ROOT except `albums/` and `cache/`),
 * plus a `database.sql` dump appended at the archive root — never the
 * gallery's uploaded images. Up to MAX_BACKUPS are retained; creating a new
 * one automatically removes the oldest beyond that limit.
 *
 * Metadata (filename, version, created-at timestamp, size) for the retained
 * backups is stored as a JSON list under the `backup_history` config key.
 * The ZIP files themselves live in cache/.updates/backups/, denied direct web
 * access the same way cache/.updates/ itself is.
 *
 * @package    LumoraGallery
 * @subpackage Core
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.12.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class BackupService
{
    /** Maximum number of backups retained; oldest is deleted beyond this. */
    private const MAX_BACKUPS = 3;

    /** Config key — JSON list of backup metadata, newest first. */
    private const META_KEY = 'backup_history';

    /** Top-level entries under LUMORA_ROOT that are never included in a backup. */
    private const EXCLUDED_TOP_LEVEL = ['albums', 'cache'];

    // ── Path helpers ──────────────────────────────────────────────────────────

    /** Directory where backup ZIP archives are stored. */
    private static function backupsDir(): string
    {
        return UpdaterService::updatesDir() . 'backups' . DIRECTORY_SEPARATOR;
    }

    /**
     * Create the backups directory if it does not exist, denying direct web
     * access on Apache hosts — same pattern as UpdaterService::ensureUpdatesDir().
     */
    private static function ensureBackupsDir(): void
    {
        UpdaterService::ensureUpdatesDir();
        $dir = self::backupsDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
            file_put_contents($dir . '.htaccess', "Deny from all\nOptions -Indexes\n");
            file_put_contents($dir . 'index.html', '');
        }
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    /**
     * Return backup metadata, newest first, for entries whose ZIP file still
     * exists on disk. Entries whose file has gone missing (e.g. removed
     * manually via FTP) are silently dropped from the returned list without
     * rewriting the stored metadata, so a transient read never loses history.
     *
     * @return list<array{filename: string, version: string, created_at: int, size: int}>
     */
    public static function listBackups(): array
    {
        $meta = self::readMeta();
        $dir  = self::backupsDir();

        return array_values(array_filter(
            $meta,
            static fn(array $entry): bool => file_exists($dir . $entry['filename'])
        ));
    }

    /**
     * @return list<array{filename: string, version: string, created_at: int, size: int}>
     */
    private static function readMeta(): array
    {
        $raw = (string) LumoraConfig::get(self::META_KEY, '');
        if ($raw === '') return [];
        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * @param list<array{filename: string, version: string, created_at: int, size: int}> $meta
     */
    private static function writeMeta(array $meta): void
    {
        try {
            LumoraConfig::set(self::META_KEY, json_encode($meta, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Non-fatal — the ZIP files themselves remain on disk and can
            // still be found/cleaned up manually if metadata ever fails to persist.
        }
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new full-installation backup ZIP and prune old backups beyond
     * MAX_BACKUPS.
     *
     * @return array{success: bool, message: string, details: list<string>}
     */
    public static function createBackup(): array
    {
        set_time_limit(180);
        self::ensureBackupsDir();

        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension is not available.', 'details' => []];
        }

        // Require at least 50 MB free — a backup excludes albums/ (usually the
        // largest part of an installation) so the codebase itself is normally small,
        // but a large custom themes/plugins directory could still add up.
        $free = @disk_free_space(self::backupsDir());
        if ($free !== false && $free < 52428800) {
            return [
                'success' => false,
                'message' => 'Insufficient disk space: ' . lumora_format_bytes((int) $free)
                    . ' available; at least 50 MB is recommended for a backup.',
                'details' => [],
            ];
        }

        $version   = LUMORA_VERSION;
        $timestamp = time();
        $filename  = 'lumora-backup-v' . preg_replace('/[^A-Za-z0-9._-]/', '_', $version)
            . '-' . date('Ymd-His', $timestamp) . '.zip';
        $path      = self::backupsDir() . $filename;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['success' => false, 'message' => 'Could not create the backup archive.', 'details' => []];
        }

        try {
            self::addDirectoryToZip($zip, LUMORA_ROOT, '');
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($path);
            return ['success' => false, 'message' => 'Backup failed while archiving files: ' . $e->getMessage(), 'details' => []];
        }

        // Embed a database dump at the archive root.
        $tmpSql = self::backupsDir() . 'tmp-dump-' . $timestamp . '.sql';
        $dbResult = UpdaterService::dumpDatabase($tmpSql);
        if (!$dbResult['success']) {
            $zip->close();
            @unlink($path);
            return ['success' => false, 'message' => 'Backup failed: database dump error — ' . $dbResult['error'], 'details' => []];
        }
        $zip->addFile($tmpSql, 'database.sql');
        $zip->close();
        @unlink($tmpSql);

        if (!file_exists($path) || filesize($path) === 0) {
            return ['success' => false, 'message' => 'Backup archive was not created correctly.', 'details' => []];
        }

        // Validate the archive we just wrote.
        $check = new \ZipArchive();
        if ($check->open($path, \ZipArchive::CHECKCONS) !== true) {
            @unlink($path);
            return ['success' => false, 'message' => 'Backup archive failed integrity validation and was discarded.', 'details' => []];
        }
        $check->close();

        $size = (int) filesize($path);

        // Prepend the new entry and prune beyond MAX_BACKUPS.
        $meta = self::readMeta();
        array_unshift($meta, [
            'filename'   => $filename,
            'version'    => $version,
            'created_at' => $timestamp,
            'size'       => $size,
        ]);

        $removed = [];
        while (count($meta) > self::MAX_BACKUPS) {
            $old = array_pop($meta);
            $oldPath = self::backupsDir() . $old['filename'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
            $removed[] = $old['filename'];
        }

        self::writeMeta($meta);

        UpdaterService::logUpdate('info', "Full backup created: {$filename} (" . lumora_format_bytes($size) . ')' .
            (!empty($removed) ? '; pruned: ' . implode(', ', $removed) : ''));

        return [
            'success' => true,
            'message' => 'Full backup created (' . lumora_format_bytes($size) . ').',
            'details' => [],
        ];
    }

    /**
     * Recursively add $src's contents to $zip under $zipPrefix, skipping the
     * top-level excluded directories (albums/, cache/) when $zipPrefix is ''
     * (i.e. we are at the LUMORA_ROOT level).
     */
    private static function addDirectoryToZip(\ZipArchive $zip, string $src, string $zipPrefix): void
    {
        foreach (new \DirectoryIterator($src) as $item) {
            if ($item->isDot()) continue;

            $name = $item->getFilename();

            if ($zipPrefix === '' && in_array($name, self::EXCLUDED_TOP_LEVEL, true)) {
                continue;
            }

            $entryPath = $zipPrefix === '' ? $name : $zipPrefix . '/' . $name;

            if ($item->isDir()) {
                $zip->addEmptyDir($entryPath);
                self::addDirectoryToZip($zip, $item->getPathname() . DIRECTORY_SEPARATOR, $entryPath);
            } elseif ($item->isFile()) {
                $zip->addFile($item->getPathname(), $entryPath);
            }
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Delete a backup by filename.
     *
     * @return array{success: bool, message: string, details: list<string>}
     */
    public static function deleteBackup(string $filename): array
    {
        $filename = basename($filename);
        $meta     = self::readMeta();

        $index = null;
        foreach ($meta as $i => $entry) {
            if ($entry['filename'] === $filename) { $index = $i; break; }
        }

        if ($index === null) {
            return ['success' => false, 'message' => 'Backup not found.', 'details' => []];
        }

        $path = self::backupsDir() . $filename;
        if (file_exists($path) && !@unlink($path)) {
            return ['success' => false, 'message' => 'Could not delete the backup file. Check permissions.', 'details' => []];
        }

        unset($meta[$index]);
        self::writeMeta(array_values($meta));

        UpdaterService::logUpdate('info', "Full backup deleted: {$filename}");

        return ['success' => true, 'message' => 'Full backup deleted.', 'details' => []];
    }

    // ── Restore ───────────────────────────────────────────────────────────────

    /**
     * Restore the application code, configuration, and database from a
     * backup ZIP created by createBackup().
     *
     * Refuses to run while a full update is in progress (UpdaterService's
     * lock is held) to avoid two file-replacement operations racing each
     * other. Preserves albums/ and cache/ exactly like the updater's own
     * Replace stage — a restore never touches uploaded images.
     *
     * @return array{success: bool, message: string, details: list<string>}
     */
    public static function restoreBackup(string $filename): array
    {
        $filename = basename($filename);
        $path     = self::backupsDir() . $filename;

        if (!file_exists($path)) {
            return ['success' => false, 'message' => 'Backup file not found.', 'details' => []];
        }

        if (UpdaterService::isUpdateRunning()) {
            return [
                'success' => false,
                'message' => 'A full update is currently in progress; cannot restore a backup while it runs.',
                'details' => [],
            ];
        }

        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension is not available.', 'details' => []];
        }

        set_time_limit(180);

        $details    = [];
        $stagingDir = self::backupsDir() . 'restore-staging' . DIRECTORY_SEPARATOR;

        if (is_dir($stagingDir)) {
            UpdaterService::removeDirectory($stagingDir);
        }
        if (!mkdir($stagingDir, 0755, true)) {
            return ['success' => false, 'message' => 'Could not create a staging directory for the restore.', 'details' => []];
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            UpdaterService::removeDirectory($stagingDir);
            return ['success' => false, 'message' => 'Backup archive failed integrity validation.', 'details' => []];
        }

        // Security: reject unsafe entry names before extracting a single byte,
        // matching UpdaterService::stageExtract()'s validation.
        $numEntries = $zip->count();
        for ($i = 0; $i < $numEntries; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;
            if (
                str_contains($name, '..')
                || str_starts_with($name, '/')
                || str_contains($name, '\\')
                || str_contains($name, "\0")
            ) {
                $zip->close();
                UpdaterService::removeDirectory($stagingDir);
                return [
                    'success' => false,
                    'message' => 'Backup archive contains an unsafe path entry: ' . $name . '. Aborting for security.',
                    'details' => [],
                ];
            }
        }

        $ok = $zip->extractTo($stagingDir);
        $zip->close();

        if (!$ok) {
            UpdaterService::removeDirectory($stagingDir);
            return ['success' => false, 'message' => 'Backup archive extraction failed.', 'details' => []];
        }

        // Post-extraction realpath check, matching UpdaterService::stageExtract().
        $canonStaging = rtrim((string) (realpath($stagingDir) ?: $stagingDir), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;
        $postIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($postIterator as $extractedItem) {
            $resolved = realpath($extractedItem->getPathname());
            if ($resolved !== false && !str_starts_with($resolved . DIRECTORY_SEPARATOR, $canonStaging)) {
                UpdaterService::removeDirectory($stagingDir);
                return [
                    'success' => false,
                    'message' => 'Backup archive produced a path outside the staging directory. Aborting for security.',
                    'details' => [],
                ];
            }
        }

        if (!file_exists($stagingDir . 'version.php')) {
            UpdaterService::removeDirectory($stagingDir);
            return [
                'success' => false,
                'message' => 'This does not look like a valid Lumora backup (version.php missing).',
                'details' => [],
            ];
        }

        // Copy application files back over LUMORA_ROOT, preserving albums/ and cache/.
        $stats = ['copied' => 0, 'skipped' => 0, 'errors' => []];
        try {
            UpdaterService::copyDirectory($stagingDir, LUMORA_ROOT, self::EXCLUDED_TOP_LEVEL, $stats);
        } catch (\Throwable $e) {
            $stats['errors'][] = 'Copy operation failed: ' . $e->getMessage();
        }

        if (!empty($stats['errors'])) {
            UpdaterService::removeDirectory($stagingDir);
            UpdaterService::logUpdate('error', 'Restore failed: ' . implode('; ', $stats['errors']));
            return ['success' => false, 'message' => 'Restore failed while copying files.', 'details' => $stats['errors']];
        }
        $details[] = $stats['copied'] . ' files restored; albums/ and cache/ left untouched.';

        // Restore the database from the embedded dump.
        $dbSqlInStaging = $stagingDir . 'database.sql';
        if (file_exists($dbSqlInStaging)) {
            $dbResult = UpdaterService::restoreDatabase($dbSqlInStaging);
            if (!$dbResult['success']) {
                UpdaterService::removeDirectory($stagingDir);
                UpdaterService::logUpdate('error', 'Restore: database restore failed — ' . $dbResult['error']);
                return [
                    'success' => false,
                    'message' => 'Application files were restored, but the database restore failed: ' . $dbResult['error'],
                    'details' => $details,
                ];
            }
            $details[] = 'Database restored (' . $dbResult['statements'] . ' statements).';
        } else {
            $details[] = 'No database dump found in this backup; database was not touched.';
        }

        UpdaterService::removeDirectory($stagingDir);

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // The backup's own recorded metadata already knows which version this
        // was — no need to re-parse the restored version.php.
        $version = 'unknown';
        $meta = self::readMeta();
        foreach ($meta as $entry) {
            if ($entry['filename'] === $filename) { $version = $entry['version']; break; }
        }

        UpdaterService::recordUpdateHistory($version, true, 'Restored from full backup ' . $filename . '.');
        UpdaterService::logUpdate('info', "Restored from full backup: {$filename}");

        return [
            'success' => true,
            'message' => 'Full backup restored successfully.',
            'details' => $details,
        ];
    }
}
