<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Album Assignment Service
 *
 * Manages per-user album assignments backed by {PREFIX}album_assignments
 * (Migration0005). This is the mechanism behind the 'manage_assigned_albums'
 * permission: any account whose group holds that permission — the built-in
 * contributor group by default, or a custom group (see GroupService) — may
 * only view/edit albums an admin or moderator has explicitly assigned to
 * them. Eligibility for assignment is checked by permission, not by the
 * literal 'contributor' role slug, so a custom group with
 * 'manage_assigned_albums' works identically. See TODO-security.md #7.
 *
 * userCanAccessAlbum() is the single source of truth for album-scoped access
 * checks and is used by lumora_require_album_access() (include/auth.php) as
 * well as by admin/albums.php, admin/batch.php, and admin/ajax_batch.php.
 *
 * All queries against {PREFIX}album_assignments are wrapped in try/catch so
 * that installations pending Migration0005 fail closed (no assignments, no
 * access) instead of throwing — consistent with the remember-me / password-
 * reset token pattern in include/auth.php.
 *
 * @package    LumoraGallery
 * @subpackage Core
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.10.0
 * @see        GalleryService Album/category queries this scopes access to.
 * @see        GroupService Grants the 'manage_assigned_albums' permission checked here.
 * @see        UserService Owns the accounts assignments are made against.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class AlbumAssignmentService
{
    // ── Write operations ──────────────────────────────────────────────────────

    /**
     * Assign a single album to a contributor account.
     *
     * A duplicate assignment (already granted) is treated as a no-op success
     * rather than an error.
     *
     * @return true|string true on success, error message on failure.
     */
    public static function assignAlbum(int $userId, int $albumId, int $assignedBy): true|string
    {
        $user = UserService::getUser($userId);
        if (!$user) {
            return 'User not found.';
        }
        if (!UserService::roleHasPermission((string) $user['role'], 'manage_assigned_albums')) {
            return 'Only accounts whose group holds the "manage_assigned_albums" permission can be assigned albums.';
        }

        $album = LumoraDB::fetchValue('SELECT id FROM `{PREFIX}albums` WHERE id = ?', [$albumId]);
        if (!$album) {
            return 'Album not found.';
        }

        try {
            $exists = LumoraDB::fetchValue(
                'SELECT id FROM `{PREFIX}album_assignments` WHERE user_id = ? AND album_id = ?',
                [$userId, $albumId]
            );
            if ($exists) {
                return true;
            }

            LumoraDB::insert('album_assignments', [
                'user_id'     => $userId,
                'album_id'    => $albumId,
                'assigned_by' => $assignedBy,
            ]);
            return true;
        } catch (\Throwable) {
            return 'Could not save the assignment (the album_assignments table may be '
                 . 'missing — run pending database updates from Admin → Updates).';
        }
    }

    /**
     * Remove a single album assignment from a contributor account.
     *
     * @return true|string true on success, error message on failure.
     */
    public static function unassignAlbum(int $userId, int $albumId): true|string
    {
        try {
            LumoraDB::delete('album_assignments', 'user_id = ? AND album_id = ?', [$userId, $albumId]);
            return true;
        } catch (\Throwable) {
            return 'Could not remove the assignment (the album_assignments table may be '
                 . 'missing — run pending database updates from Admin → Updates).';
        }
    }

    /**
     * Replace the full set of album assignments for a contributor in one call
     * (diff against the current set, then insert/delete inside a transaction).
     * Used by the checkbox-list "Assign Albums" save form.
     *
     * Album IDs that don't exist are silently dropped rather than rejected —
     * the checkbox list is always built from real albums, so this only
     * matters if an album was deleted between page load and submit.
     *
     * @param list<int> $albumIds
     * @return true|string true on success, error message on failure.
     */
    public static function setAssignedAlbums(int $userId, array $albumIds, int $assignedBy): true|string
    {
        $user = UserService::getUser($userId);
        if (!$user) {
            return 'User not found.';
        }
        if (!UserService::roleHasPermission((string) $user['role'], 'manage_assigned_albums')) {
            return 'Only accounts whose group holds the "manage_assigned_albums" permission can be assigned albums.';
        }

        $albumIds = array_values(array_unique(array_filter(
            array_map('intval', $albumIds),
            static fn(int $id): bool => $id > 0
        )));

        if (!empty($albumIds)) {
            $ph    = implode(',', array_fill(0, count($albumIds), '?'));
            $valid = LumoraDB::fetchAll("SELECT id FROM `{PREFIX}albums` WHERE id IN ({$ph})", $albumIds);
            $albumIds = array_map('intval', array_column($valid, 'id'));
        }

        try {
            $current  = self::getAssignedAlbumIds($userId);
            $toAdd    = array_diff($albumIds, $current);
            $toRemove = array_diff($current, $albumIds);

            LumoraDB::beginTransaction();
            foreach ($toRemove as $id) {
                LumoraDB::delete('album_assignments', 'user_id = ? AND album_id = ?', [$userId, $id]);
            }
            foreach ($toAdd as $id) {
                LumoraDB::insert('album_assignments', [
                    'user_id'     => $userId,
                    'album_id'    => $id,
                    'assigned_by' => $assignedBy,
                ]);
            }
            LumoraDB::commit();
            return true;
        } catch (\Throwable) {
            try { LumoraDB::rollBack(); } catch (\Throwable) {}
            return 'Could not save album assignments (the album_assignments table may be '
                 . 'missing — run pending database updates from Admin → Updates).';
        }
    }

    // ── Read queries ──────────────────────────────────────────────────────────

    /**
     * Return the album IDs assigned to a user.
     *
     * @return list<int>
     */
    public static function getAssignedAlbumIds(int $userId): array
    {
        try {
            $rows = LumoraDB::fetchAll(
                'SELECT album_id FROM `{PREFIX}album_assignments` WHERE user_id = ?',
                [$userId]
            );
            return array_map('intval', array_column($rows, 'album_id'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return the assigned albums for a user, joined with album/category data
     * for display in admin/albums.php's contributor-scoped list view.
     *
     * @return list<array{id: int, title: string, folder: string, category_id: int,
     *                    visibility: int, cat_name: string|null, image_count: int}>
     */
    public static function getAssignedAlbums(int $userId): array
    {
        try {
            return LumoraDB::fetchAll(
                'SELECT a.id, a.title, a.folder, a.category_id, a.visibility,
                        c.name AS cat_name,
                        (SELECT COUNT(*) FROM `{PREFIX}images` i
                          WHERE i.album_id = a.id AND i.approved = 1) AS image_count
                 FROM `{PREFIX}album_assignments` aa
                 JOIN `{PREFIX}albums` a ON a.id = aa.album_id
                 LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
                 WHERE aa.user_id = ?
                 ORDER BY c.name ASC, a.title ASC',
                [$userId]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return true when $userId may access $albumId — either because their
     * role holds 'manage_albums' (full access to every album) or because the
     * album has been explicitly assigned to them.
     *
     * This is the single source of truth for album-scoped access checks;
     * lumora_require_album_access() (include/auth.php) delegates to it.
     */
    public static function userCanAccessAlbum(int $userId, int $albumId): bool
    {
        $user = UserService::getUser($userId);
        if (!$user) {
            return false;
        }
        if (UserService::roleHasPermission((string) $user['role'], 'manage_albums')) {
            return true;
        }

        try {
            return LumoraDB::fetchValue(
                'SELECT id FROM `{PREFIX}album_assignments` WHERE user_id = ? AND album_id = ?',
                [$userId, $albumId]
            ) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return the user IDs a given album is assigned to (reverse lookup).
     *
     * @return list<int>
     */
    public static function getAssignedUserIds(int $albumId): array
    {
        try {
            $rows = LumoraDB::fetchAll(
                'SELECT user_id FROM `{PREFIX}album_assignments` WHERE album_id = ?',
                [$albumId]
            );
            return array_map('intval', array_column($rows, 'user_id'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return the users a given album is assigned to, with usernames, for
     * display on the album edit screen ("Assigned to: alice, bob").
     *
     * @return list<array{id: int, username: string}>
     */
    public static function getAssignedUsers(int $albumId): array
    {
        try {
            return LumoraDB::fetchAll(
                'SELECT u.id, u.username
                 FROM `{PREFIX}album_assignments` aa
                 JOIN `{PREFIX}users` u ON u.id = aa.user_id
                 WHERE aa.album_id = ?
                 ORDER BY u.username ASC',
                [$albumId]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** Count the albums assigned to a user (shown next to contributor rows in users.php). */
    public static function countAssignedAlbums(int $userId): int
    {
        try {
            return (int) LumoraDB::fetchValue(
                'SELECT COUNT(*) FROM `{PREFIX}album_assignments` WHERE user_id = ?',
                [$userId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    // ── Cascade cleanup ────────────────────────────────────────────────────────

    /**
     * Delete all assignment rows for a user. Called from
     * UserService::deleteUser() alongside remember-token / reset-token cleanup.
     * Fails silently when the table is absent (pre-Migration0005 installs).
     */
    public static function removeAllAssignmentsForUser(int $userId): void
    {
        try {
            LumoraDB::delete('album_assignments', 'user_id = ?', [$userId]);
        } catch (\Throwable) {
            // {PREFIX}album_assignments absent on pre-Migration0005 installs; fail silently.
        }
    }

    /**
     * Delete all assignment rows for an album. Called from admin/albums.php's
     * delete handler so deleting an album doesn't leave orphaned assignments.
     * Fails silently when the table is absent (pre-Migration0005 installs).
     */
    public static function removeAllAssignmentsForAlbum(int $albumId): void
    {
        try {
            LumoraDB::delete('album_assignments', 'album_id = ?', [$albumId]);
        } catch (\Throwable) {
            // {PREFIX}album_assignments absent on pre-Migration0005 installs; fail silently.
        }
    }
}
