<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Group Service
 *
 * Manages permission groups backed by {PREFIX}groups / {PREFIX}group_permissions
 * (Migration0007, DB version 13). Groups replace the formerly fixed
 * admin/moderator/contributor role ENUM: `{PREFIX}users`.`role` now stores a
 * group *slug* rather than an ENUM value, so administrators can create,
 * rename, and delete additional groups with any combination of permissions
 * from ALL_PERMISSIONS, in addition to the three built-in system groups.
 *
 * The three system groups (admin, moderator, contributor) are seeded by
 * Migration0007 with the exact permission sets that were previously
 * hardcoded in UserService::ROLE_PERMISSIONS, so existing installs see no
 * behavioural change immediately after upgrading. UserService::roleHasPermission()
 * / getRolePermissions() / roleOptions() / roleBadge() all delegate to this
 * class; UserService::ROLES / ROLE_LABELS / ROLE_PERMISSIONS are kept only as
 * legacy fallback constants for pre-migration installs (see groupExists()).
 *
 * All queries are wrapped in try/catch so installations pending Migration0007
 * fail closed to the legacy hardcoded three-role behaviour instead of
 * throwing, consistent with the AlbumAssignmentService pattern.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class GroupService
{
    /**
     * Canonical catalog of every permission slug a group can be granted, with
     * a human-readable label for the admin UI. Order defines display order
     * on the group edit form. New pages that gate on a new permission must
     * add it here so it is selectable when building or editing a group.
     *
     * @var array<string, string>
     */
    const ALL_PERMISSIONS = [
        'site_configuration'      => 'Site Configuration — Configuration, Installation Settings, Import',
        'user_management'         => 'User Management — Users, Groups',
        'manage_albums'           => 'Manage Albums — Categories and Albums (all)',
        'manage_images'           => 'Manage Images — Images, Batch Add (all)',
        'moderate_comments'       => 'Moderate Comments (reserved for a future comment system)',
        'maintenance_tools'       => 'Maintenance Tools',
        'batch_add'               => 'Batch Add — upload images from FTP',
        'view_updates'            => 'View Updates',
        'edit_own_images'         => 'Edit Own Images — row-scoped image access for uploaders',
        'manage_assigned_albums'  => 'Manage Assigned Albums — row-scoped album access',
    ];

    /** Slugs of the three built-in groups. These can never be deleted. */
    const SYSTEM_GROUPS = ['admin', 'moderator', 'contributor'];

    /**
     * Permissions that can never be removed from the 'admin' system group,
     * so at least one account can always reach Users/Groups and
     * Configuration — otherwise a mistaken save could permanently lock every
     * administrator out of the control panel.
     */
    const ADMIN_LOCKED_PERMISSIONS = ['user_management', 'site_configuration'];

    /** Per-request cache: group slug => list<string> permissions. Null until first load. */
    private static ?array $permissionsCache = null;

    // ── Internal cache ────────────────────────────────────────────────────────

    /**
     * @return array<string, list<string>>
     */
    private static function loadPermissionsCache(): array
    {
        if (self::$permissionsCache === null) {
            self::$permissionsCache = [];
            try {
                $rows = LumoraDB::fetchAll(
                    'SELECT g.slug, gp.permission
                       FROM `{PREFIX}group_permissions` gp
                       JOIN `{PREFIX}groups` g ON g.id = gp.group_id'
                );
                foreach ($rows as $row) {
                    self::$permissionsCache[$row['slug']][] = $row['permission'];
                }
            } catch (\Throwable) {
                // {PREFIX}groups / group_permissions absent on pre-Migration0007
                // installs — leave the cache empty; callers fall back to the
                // legacy hardcoded UserService constants.
                self::$permissionsCache = [];
            }
        }
        return self::$permissionsCache;
    }

    /** Clear the per-request permissions cache after any write. */
    public static function clearCache(): void
    {
        self::$permissionsCache = null;
    }

    // ── Read queries ──────────────────────────────────────────────────────────

    /**
     * Return true when a group with the given slug exists. Falls back to the
     * legacy hardcoded role list on pre-Migration0007 installs (table absent)
     * so account creation/editing and remember-me session restoration keep
     * working before the migration has run.
     */
    public static function groupExists(string $slug): bool
    {
        try {
            return LumoraDB::fetchValue(
                'SELECT id FROM `{PREFIX}groups` WHERE slug = ?',
                [$slug]
            ) !== null;
        } catch (\Throwable) {
            return in_array($slug, UserService::ROLES, true);
        }
    }

    /**
     * Return the permission slugs granted to a group. Falls back to the
     * legacy hardcoded UserService::ROLE_PERMISSIONS map when the groups
     * tables don't exist yet (pre-Migration0007) or the slug isn't found in
     * either source.
     *
     * @return list<string>
     */
    public static function getGroupPermissions(string $slug): array
    {
        $cache = self::loadPermissionsCache();
        if (isset($cache[$slug])) {
            return $cache[$slug];
        }
        if (empty($cache) && isset(UserService::ROLE_PERMISSIONS[$slug])) {
            return UserService::ROLE_PERMISSIONS[$slug];
        }
        return [];
    }

    /** Return true when the given group holds the named permission. */
    public static function groupHasPermission(string $slug, string $permission): bool
    {
        return in_array($permission, self::getGroupPermissions($slug), true);
    }

    /**
     * Return every group, with its permissions and current user count, for
     * display on admin/groups.php. Falls back to a synthesised list built
     * from the legacy hardcoded constants on pre-Migration0007 installs so
     * the page still renders something meaningful before the migration runs.
     *
     * @return list<array{id: int, slug: string, name: string, is_system: bool,
     *                     created_at: string|null, permissions: list<string>,
     *                     user_count: int}>
     */
    public static function getAllGroups(): array
    {
        try {
            $rows = LumoraDB::fetchAll(
                'SELECT id, slug, name, is_system, created_at
                   FROM `{PREFIX}groups`
                  ORDER BY is_system DESC, name ASC'
            );
        } catch (\Throwable) {
            $rows = [];
        }

        if (empty($rows)) {
            $out = [];
            foreach (UserService::ROLE_LABELS as $slug => $label) {
                $out[] = [
                    'id'          => 0,
                    'slug'        => $slug,
                    'name'        => $label,
                    'is_system'   => true,
                    'created_at'  => null,
                    'permissions' => UserService::ROLE_PERMISSIONS[$slug] ?? [],
                    'user_count'  => self::getGroupUserCount($slug),
                ];
            }
            return $out;
        }

        $out = [];
        foreach ($rows as $g) {
            $slug  = (string) $g['slug'];
            $out[] = [
                'id'          => (int) $g['id'],
                'slug'        => $slug,
                'name'        => (string) $g['name'],
                'is_system'   => (bool) $g['is_system'],
                'created_at'  => $g['created_at'],
                'permissions' => self::getGroupPermissions($slug),
                'user_count'  => self::getGroupUserCount($slug),
            ];
        }
        return $out;
    }

    /**
     * @return array{id: int, slug: string, name: string, is_system: bool,
     *                created_at: string|null, permissions: list<string>,
     *                user_count: int}|null
     */
    public static function getGroup(int $id): ?array
    {
        if ($id <= 0) return null;
        foreach (self::getAllGroups() as $g) {
            if ($g['id'] === $id) return $g;
        }
        return null;
    }

    /**
     * @return array{id: int, slug: string, name: string, is_system: bool,
     *                created_at: string|null, permissions: list<string>,
     *                user_count: int}|null
     */
    public static function getGroupBySlug(string $slug): ?array
    {
        foreach (self::getAllGroups() as $g) {
            if ($g['slug'] === $slug) return $g;
        }
        return null;
    }

    /** Count how many user accounts currently belong to a group. */
    public static function getGroupUserCount(string $slug): int
    {
        try {
            return (int) LumoraDB::fetchValue(
                'SELECT COUNT(*) FROM `{PREFIX}users` WHERE role = ?',
                [$slug]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Filter an arbitrary list of permission strings down to only the ones
     * recognised in ALL_PERMISSIONS, de-duplicated.
     *
     * @param list<string> $permissions
     * @return list<string>
     */
    public static function validatePermissions(array $permissions): array
    {
        return array_values(array_intersect(
            array_unique($permissions),
            array_keys(self::ALL_PERMISSIONS)
        ));
    }

    // ── Write operations ──────────────────────────────────────────────────────

    /**
     * Create a new custom group.
     *
     * @param list<string> $permissions
     * @return int|string New group ID on success, error message on failure.
     */
    public static function createGroup(string $name, array $permissions): int|string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Group name cannot be empty.';
        }
        if (!preg_match('/^[\p{L}\p{N} _.\-]{2,100}$/u', $name)) {
            return 'Group name must be 2–100 characters (letters, digits, spaces, '
                 . 'and _ . - only).';
        }

        $slug = self::slugify($name);
        if ($slug === '') {
            return 'Could not derive a valid identifier from that name. Try including '
                 . 'a letter or digit.';
        }
        if (self::groupExists($slug)) {
            return 'A group with a matching identifier already exists. Choose a more '
                 . 'distinct name.';
        }

        $permissions = self::validatePermissions($permissions);

        try {
            LumoraDB::beginTransaction();
            $id = (int) LumoraDB::insert('groups', [
                'slug'      => $slug,
                'name'      => $name,
                'is_system' => 0,
            ]);
            foreach ($permissions as $p) {
                LumoraDB::insert('group_permissions', ['group_id' => $id, 'permission' => $p]);
            }
            LumoraDB::commit();
        } catch (\Throwable) {
            try { LumoraDB::rollBack(); } catch (\Throwable) {}
            return 'Could not create the group (the groups tables may be missing — '
                 . 'run pending database updates from Admin → Updates).';
        }

        self::clearCache();
        return $id;
    }

    /**
     * Rename a group's display name. The slug (used in {PREFIX}users.role) is
     * never changed after creation, so renaming never requires touching
     * existing user rows.
     *
     * @return true|string true on success, error message on failure.
     */
    public static function renameGroup(int $id, string $newName): true|string
    {
        $group = self::getGroup($id);
        if (!$group) {
            return 'Group not found.';
        }

        $newName = trim($newName);
        if ($newName === '') {
            return 'Group name cannot be empty.';
        }
        if (!preg_match('/^[\p{L}\p{N} _.\-]{2,100}$/u', $newName)) {
            return 'Group name must be 2–100 characters (letters, digits, spaces, '
                 . 'and _ . - only).';
        }

        try {
            LumoraDB::update('groups', ['name' => $newName], 'id = ?', [$id]);
        } catch (\Throwable) {
            return 'Could not rename the group (the groups table may be missing — '
                 . 'run pending database updates from Admin → Updates).';
        }

        self::clearCache();
        return true;
    }

    /**
     * Replace the full permission set for a group.
     *
     * Safety: the 'admin' system group always retains ADMIN_LOCKED_PERMISSIONS
     * regardless of what was submitted, so administrators can never lock
     * themselves out of Users/Groups or Configuration.
     *
     * @param list<string> $permissions
     * @return true|string true on success, error message on failure.
     */
    public static function updateGroupPermissions(int $id, array $permissions): true|string
    {
        $group = self::getGroup($id);
        if (!$group) {
            return 'Group not found.';
        }

        $permissions = self::validatePermissions($permissions);

        if ($group['slug'] === 'admin') {
            foreach (self::ADMIN_LOCKED_PERMISSIONS as $p) {
                if (!in_array($p, $permissions, true)) {
                    $permissions[] = $p;
                }
            }
        }

        try {
            LumoraDB::beginTransaction();
            LumoraDB::delete('group_permissions', 'group_id = ?', [$id]);
            foreach ($permissions as $p) {
                LumoraDB::insert('group_permissions', ['group_id' => $id, 'permission' => $p]);
            }
            LumoraDB::commit();
        } catch (\Throwable) {
            try { LumoraDB::rollBack(); } catch (\Throwable) {}
            return 'Could not update permissions (the groups tables may be missing — '
                 . 'run pending database updates from Admin → Updates).';
        }

        self::clearCache();
        return true;
    }

    /**
     * Permanently delete a custom group.
     *
     * Guards:
     *   - System groups (admin, moderator, contributor) can never be deleted.
     *   - A group with one or more user accounts still assigned to it cannot
     *     be deleted — reassign those accounts to a different group first.
     *
     * @return true|string true on success, error message on failure.
     */
    public static function deleteGroup(int $id): true|string
    {
        $group = self::getGroup($id);
        if (!$group) {
            return 'Group not found.';
        }
        if ($group['is_system']) {
            return 'System groups (Administrator, Moderator, Contributor) cannot be deleted.';
        }

        $userCount = self::getGroupUserCount($group['slug']);
        if ($userCount > 0) {
            return 'Cannot delete this group while ' . $userCount . ' user account'
                 . ($userCount === 1 ? '' : 's') . ' still belong to it. Reassign '
                 . ($userCount === 1 ? 'it' : 'them') . ' to a different group first.';
        }

        try {
            LumoraDB::beginTransaction();
            LumoraDB::delete('group_permissions', 'group_id = ?', [$id]);
            LumoraDB::delete('groups', 'id = ?', [$id]);
            LumoraDB::commit();
        } catch (\Throwable) {
            try { LumoraDB::rollBack(); } catch (\Throwable) {}
            return 'Could not delete the group (the groups tables may be missing — '
                 . 'run pending database updates from Admin → Updates).';
        }

        self::clearCache();
        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Derive a stable, URL/DB-safe slug from a display name: lowercase ASCII
     * letters/digits with runs of anything else collapsed to a single
     * underscore, capped at 50 characters (matches the `slug` column width).
     */
    private static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        return substr($slug, 0, 50);
    }
}
