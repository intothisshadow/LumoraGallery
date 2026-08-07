<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Gallery Service
 *
 * All category, album, image, statistics, and visitor-tracking queries.
 * Callers on public pages and in the admin panel use the legacy free-function
 * wrappers in include/functions.php; direct use of GalleryService:: is
 * preferred for new V2 code.
 *
 * All SQL uses {PREFIX} which LumoraDB::query() replaces at runtime.
 *
 * @package    LumoraGallery
 * @subpackage Core
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.5.0
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class GalleryService
{
    // ── Categories ────────────────────────────────────────────────────────────

    /**
     * Get direct children of a parent category (parent_id = 0 for root).
     * Each row includes album_count, subcategory_count, and image_count.
     * image_count covers only images in albums directly belonging to this
     * category (not images in sub-category albums).
     *
     * @return list<array{id: int, name: string, parent_id: int, pos: int, description: string,
     *                    thumb_image_id: int, album_count: int, subcategory_count: int, image_count: int}>
     */
    public static function getCategories(int $parent_id = 0): array
    {
        return LumoraDB::fetchAll(
            'SELECT c.*,
                (SELECT COUNT(*) FROM `{PREFIX}albums`     a  WHERE a.category_id = c.id) AS album_count,
                (SELECT COUNT(*) FROM `{PREFIX}categories` sc WHERE sc.parent_id  = c.id) AS subcategory_count,
                (SELECT COUNT(*) FROM `{PREFIX}images`     i
                 JOIN `{PREFIX}albums` ia ON ia.id = i.album_id
                 WHERE ia.category_id = c.id AND i.approved = 1)                          AS image_count
             FROM `{PREFIX}categories` c
             WHERE c.parent_id = ?
             ORDER BY c.pos ASC, c.name ASC',
            [$parent_id]
        );
    }

    /** Get a single category row, or null. */
    public static function getCategory(int $id): ?array
    {
        return LumoraDB::fetchOne(
            'SELECT * FROM `{PREFIX}categories` WHERE id = ?',
            [$id]
        );
    }

    /**
     * Get a flat list of all categories for admin dropdowns.
     *
     * @return list<array{id: int, name: string, parent_id: int, pos: int, description: string}>
     */
    public static function getAllCategoriesFlat(): array
    {
        return LumoraDB::fetchAll(
            'SELECT * FROM `{PREFIX}categories` ORDER BY parent_id ASC, pos ASC, name ASC'
        );
    }

    /**
     * Get all categories in proper depth-first hierarchical order, each row
     * augmented with a computed `depth` key (0 = root).
     *
     * getAllCategoriesFlat()'s `ORDER BY parent_id ASC` groups categories by
     * their raw parent_id value, not by where they actually sit in the tree —
     * a category whose parent_id happens to be numerically low can be listed
     * before its true ancestor chain, which produced incorrect groupings in
     * any dropdown that tried to indent from that flat SQL order alone (e.g.
     * sibling categories under different parents interleaving with each
     * other). This method instead builds a parent_id => children map once
     * and walks it depth-first from the root, so a caller can indent purely
     * by the returned `depth` value and get correct nesting at any depth.
     *
     * @return list<array{id: int, name: string, parent_id: int, pos: int,
     *                     description: string, depth: int}>
     */
    public static function getAllCategoriesTreeOrdered(): array
    {
        $flat = self::getAllCategoriesFlat();

        $children_of = [];
        foreach ($flat as $cat) {
            $children_of[(int) $cat['parent_id']][] = $cat;
        }

        $ordered = [];
        $visited = [];
        self::walkCategoryTree($children_of, 0, 0, $ordered, $visited);

        return $ordered;
    }

    /**
     * Depth-first walk helper for getAllCategoriesTreeOrdered(). Appends
     * each visited category (with its computed depth) to $ordered by
     * reference. $visited guards against infinite recursion caused by a
     * corrupt parent_id cycle.
     *
     * @param array<int, list<array<string, mixed>>> $children_of parent_id => [category rows]
     * @param list<array<string, mixed>>              $ordered     Accumulator, passed by ref.
     * @param array<int, true>                        $visited     Cycle guard, passed by ref.
     */
    private static function walkCategoryTree(
        array $children_of,
        int   $parent_id,
        int   $depth,
        array &$ordered,
        array &$visited
    ): void {
        foreach ($children_of[$parent_id] ?? [] as $cat) {
            $id = (int) $cat['id'];
            if (isset($visited[$id])) continue; // cycle guard
            $visited[$id] = true;

            $cat['depth'] = $depth;
            $ordered[]    = $cat;

            self::walkCategoryTree($children_of, $id, $depth + 1, $ordered, $visited);
        }
    }

    /**
     * Build the breadcrumb trail for a category, from root to $cat_id.
     * Returns array of ['id', 'name'] sorted root-first.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function getCategoryBreadcrumb(int $cat_id): array
    {
        $trail = [];
        $id    = $cat_id;
        $limit = 10; // guard against cycles
        while ($id > 0 && $limit-- > 0) {
            $cat = self::getCategory($id);
            if (!$cat) break;
            array_unshift($trail, ['id' => (int) $cat['id'], 'name' => $cat['name']]);
            $id = (int) $cat['parent_id'];
        }
        return $trail;
    }

    /**
     * Return combined album and image counts for a set of categories, including
     * all descendant subcategories at any depth.
     *
     * Uses three queries total regardless of tree depth or category count:
     *   1. Load the full category tree (id + parent_id only).
     *   2. Batch album counts by category_id for all descendant IDs.
     *   3. Batch image counts by category_id for all descendant IDs.
     *
     * @param list<int> $cat_ids  Root category IDs to aggregate.
     * @return array<int, array{album_count: int, image_count: int}>
     *         Keyed by each input cat_id; every input ID is present in the result.
     */
    public static function getCategorySubtreeCounts(array $cat_ids): array
    {
        if (empty($cat_ids)) return [];

        // 1. Load id + parent_id for the whole tree (two integer columns only).
        $all_rows    = LumoraDB::fetchAll('SELECT id, parent_id FROM `{PREFIX}categories`');
        $children_of = []; // parent_id => [child_id, ...]
        foreach ($all_rows as $row) {
            $children_of[(int) $row['parent_id']][] = (int) $row['id'];
        }

        // 2. BFS from each requested root to collect all descendant IDs (inclusive).
        $subtrees = []; // root_id => [id, id, ...]
        foreach ($cat_ids as $root_id) {
            $root_id = (int) $root_id;
            $ids     = [];
            $queue   = [$root_id];
            while (!empty($queue)) {
                $id    = array_shift($queue);
                $ids[] = $id;
                foreach ($children_of[$id] ?? [] as $child_id) {
                    $queue[] = $child_id;
                }
            }
            $subtrees[$root_id] = $ids;
        }

        // 3. Flatten all descendant IDs to a unique set for the batch queries.
        $all_ids = array_values(array_unique(array_merge(...array_values($subtrees))));
        if (empty($all_ids)) {
            return array_fill_keys(
                array_map('intval', $cat_ids),
                ['album_count' => 0, 'image_count' => 0]
            );
        }
        $ph = implode(',', array_fill(0, count($all_ids), '?'));

        // 4. Album count per leaf category_id.
        $album_per_cat = [];
        $rows = LumoraDB::fetchAll(
            "SELECT category_id, COUNT(*) AS cnt FROM `{PREFIX}albums`
             WHERE category_id IN ({$ph}) GROUP BY category_id",
            $all_ids
        );
        foreach ($rows as $row) {
            $album_per_cat[(int) $row['category_id']] = (int) $row['cnt'];
        }

        // 5. Image count per leaf category_id (via album join).
        $image_per_cat = [];
        $rows = LumoraDB::fetchAll(
            "SELECT a.category_id, COUNT(*) AS cnt
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE a.category_id IN ({$ph}) AND i.approved = 1
             GROUP BY a.category_id",
            $all_ids
        );
        foreach ($rows as $row) {
            $image_per_cat[(int) $row['category_id']] = (int) $row['cnt'];
        }

        // 6. Aggregate per-leaf counts back to each input root.
        $result = [];
        foreach ($cat_ids as $root_id) {
            $root_id = (int) $root_id;
            $albums  = 0;
            $images  = 0;
            foreach ($subtrees[$root_id] as $id) {
                $albums += $album_per_cat[$id] ?? 0;
                $images += $image_per_cat[$id] ?? 0;
            }
            $result[$root_id] = ['album_count' => $albums, 'image_count' => $images];
        }
        return $result;
    }

    /**
     * Return $cat_id and every descendant category ID at any depth
     * (inclusive), via one BFS over the full category tree loaded in a
     * single query. Single-root counterpart to getCategorySubtreeCounts()'s
     * own inline BFS (kept separate rather than shared, since that method's
     * documented "3 queries regardless of root count" behavior depends on
     * building the id/parent_id map once for a whole batch of roots — a
     * shared single-root helper would force it back to one tree-load query
     * per root instead).
     *
     * @return list<int>
     */
    private static function getCategorySubtreeIds(int $cat_id): array
    {
        $all_rows    = LumoraDB::fetchAll('SELECT id, parent_id FROM `{PREFIX}categories`');
        $children_of = [];
        foreach ($all_rows as $row) {
            $children_of[(int) $row['parent_id']][] = (int) $row['id'];
        }

        $ids   = [];
        $queue = [$cat_id];
        while (!empty($queue)) {
            $id    = array_shift($queue);
            $ids[] = $id;
            foreach ($children_of[$id] ?? [] as $child_id) {
                $queue[] = $child_id;
            }
        }
        return $ids;
    }

    /**
     * Most recently added approved images across a category's own albums
     * and every descendant sub-category's albums, at any depth (LG-041) —
     * the category-page equivalent of getLatestImages()'s gallery-wide
     * version. Only public albums are considered, matching every other
     * public image listing's visibility gate.
     *
     * @return list<array<string, mixed>>
     */
    public static function getLatestImagesInCategorySubtree(int $cat_id, int $limit = 8): array
    {
        $cat_ids = self::getCategorySubtreeIds($cat_id);
        if (empty($cat_ids)) return [];

        $ph     = implode(',', array_fill(0, count($cat_ids), '?'));
        $params = $cat_ids;
        $params[] = $limit;

        return LumoraDB::fetchAll(
            "SELECT i.*, a.folder, a.title AS album_title
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.approved = 1 AND a.visibility = 0 AND a.category_id IN ({$ph})
             ORDER BY i.added_at DESC
             LIMIT ?",
            $params
        );
    }

    // ── Admin Category Queries ────────────────────────────────────────────────

    /**
     * Count all categories.
     *
     * @return int Total category count.
     */
    public static function countAllCategories(): int
    {
        return (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}categories`');
    }

    /**
     * Get a paginated flat list of categories for the admin UI.
     *
     * Ordered identically to getAllCategoriesFlat() so the paginated list is
     * consistent with the full list used in dropdowns and parent lookups.
     *
     * @param int $page     1-based page number.
     * @param int $per_page Categories per page.
     * @return list<array{id: int, name: string, parent_id: int, pos: int, description: string}>
     */
    public static function getPaginatedCategoriesFlat(int $page, int $per_page): array
    {
        $offset = max(0, ($page - 1) * $per_page);
        return LumoraDB::fetchAll(
            'SELECT * FROM `{PREFIX}categories` ORDER BY parent_id ASC, pos ASC, name ASC
             LIMIT ? OFFSET ?',
            [$per_page, $offset]
        );
    }

    /**
     * Get all categories as a flat list with album and subcategory counts.
     *
     * Returns a superset of getAllCategoriesFlat() — every column from the
     * categories table plus two aggregates:
     *   album_count       — direct albums in this category (category_id = c.id)
     *   subcategory_count — direct children (parent_id = c.id)
     *
     * Used by the admin hierarchy tree (categories.php) and as the data source
     * for new/edit dropdowns, replacing getAllCategoriesFlat() wherever counts
     * are also needed.
     *
     * @return list<array{id: int, name: string, parent_id: int, pos: int, description: string,
     *                    thumb_image_id: int, album_count: int, subcategory_count: int}>
     */
    public static function getAllCategoriesWithCounts(): array
    {
        return LumoraDB::fetchAll(
            'SELECT c.*,
                (SELECT COUNT(*) FROM `{PREFIX}albums`     a  WHERE a.category_id = c.id) AS album_count,
                (SELECT COUNT(*) FROM `{PREFIX}categories` sc WHERE sc.parent_id  = c.id) AS subcategory_count
             FROM `{PREFIX}categories` c
             ORDER BY c.parent_id ASC, c.pos ASC, c.name ASC'
        );
    }

    // ── Category Write Operations ───────────────────────────────────────────────

    /**
     * Create a new category.
     *
     * Business rules enforced here (moved out of admin/categories.php so they
     * can be tested and reused independently of the page):
     *   - name is required.
     *   - thumb_image_id, if > 0, must reference an existing approved image;
     *     otherwise it's silently reset to 0 and a warning is returned.
     *
     * @param array{name: string, description?: string, parent_id?: int, pos?: int,
     *              thumb_image_id?: int} $data
     * @return array{id: int, warning: string|null}|string
     *         Array with the new category's ID and optional warning on
     *         success; an error message string on failure.
     */
    public static function createCategory(array $data): array|string
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return 'Category name is required.';
        }

        $thumb = self::resolveThumbImageId((int) ($data['thumb_image_id'] ?? 0));

        $id = (int) LumoraDB::insert('categories', [
            'parent_id'      => (int) ($data['parent_id'] ?? 0),
            'name'           => $name,
            'description'    => trim((string) ($data['description'] ?? '')),
            'pos'            => (int) ($data['pos'] ?? 0),
            'thumb_image_id' => $thumb['id'],
        ]);

        return ['id' => $id, 'warning' => $thumb['warning']];
    }

    /**
     * Update an existing category's editable fields.
     *
     * Business rules enforced here:
     *   - name is required.
     *   - A category may not be set as its own parent (silently forced to
     *     root — parent_id = 0 — rather than rejected, matching the previous
     *     inline page behavior).
     *   - thumb_image_id validated the same way as createCategory().
     *
     * @param array{name: string, description?: string, parent_id?: int, pos?: int,
     *              thumb_image_id?: int} $data
     * @return array{warning: string|null}|string true-shaped array on
     *         success (with an optional warning), an error message string
     *         on failure.
     */
    public static function updateCategory(int $id, array $data): array|string
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return 'Category name is required.';
        }

        $parent_id = (int) ($data['parent_id'] ?? 0);
        if ($parent_id === $id) {
            $parent_id = 0; // A category cannot be its own parent.
        }

        $thumb = self::resolveThumbImageId((int) ($data['thumb_image_id'] ?? 0));

        LumoraDB::update('categories', [
            'name'           => $name,
            'description'    => trim((string) ($data['description'] ?? '')),
            'parent_id'      => $parent_id,
            'pos'            => (int) ($data['pos'] ?? 0),
            'thumb_image_id' => $thumb['id'],
        ], 'id = ?', [$id]);

        return ['warning' => $thumb['warning']];
    }

    /**
     * Delete a category, reparenting its child categories and albums to the
     * deleted category's own parent (never deleting them).
     *
     * Returns null (no-op) when $id doesn't match an existing category,
     * matching the previous inline page behavior of silently doing nothing
     * — the page never flashed a message in that case either.
     *
     * @return string|null A human-readable result message, or null if the
     *                      category did not exist.
     */
    public static function deleteCategory(int $id): ?string
    {
        $cat = self::getCategory($id);
        if (!$cat) {
            return null;
        }

        $parent_id = (int) $cat['parent_id'];
        LumoraDB::query(
            'UPDATE `{PREFIX}categories` SET parent_id = ? WHERE parent_id = ?',
            [$parent_id, $id]
        );
        LumoraDB::query(
            'UPDATE `{PREFIX}albums` SET category_id = ? WHERE category_id = ?',
            [$parent_id, $id]
        );
        LumoraDB::delete('categories', 'id = ?', [$id]);

        return 'Category deleted. Child items moved to parent.';
    }

    /**
     * Reorder categories within a single parent bucket, and optionally
     * reparent one category into a different parent (drag-and-drop admin
     * UI — TODO.md #23).
     *
     * A category can only be moved to a parent that is not itself and not
     * one of its own descendants — moving a category into its own subtree
     * would create a parent_id cycle and silently detach the whole branch
     * from the tree. isCategoryWithinSubtree() below is what enforces this.
     *
     * $orderedIds is trusted to list every sibling that should end up under
     * $newParentId, in their intended final order; each listed ID has its
     * `pos` renumbered in 10-step increments (matching the gap-tolerant
     * scheme already used by admin/categories.php's manual Position field)
     * regardless of which parent it previously belonged to. Only $movedId's
     * `parent_id` column is actually changed — every other listed ID keeps
     * its existing parent_id, so a caller must not include an ID that
     * doesn't already belong to (or isn't $movedId itself moving into)
     * $newParentId.
     *
     * @param int       $movedId      Category being dragged.
     * @param int       $newParentId  Parent bucket to place it in (0 = root).
     * @param list<int> $orderedIds   Full ordered list of sibling IDs
     *                                (including $movedId) that should end up
     *                                under $newParentId, in display order.
     * @return string|null Error message on failure (category/parent not
     *                     found, or a cycle would be created), or null on
     *                     success.
     */
    public static function reorderCategories(int $movedId, int $newParentId, array $orderedIds): ?string
    {
        $moved = self::getCategory($movedId);
        if (!$moved) {
            return 'Category not found.';
        }

        if ($newParentId !== 0) {
            if (!self::getCategory($newParentId)) {
                return 'Target parent category not found.';
            }
            if ($newParentId === $movedId || self::isCategoryWithinSubtree($movedId, $newParentId)) {
                return 'Cannot move a category into one of its own descendants.';
            }
        }

        if ((int) $moved['parent_id'] !== $newParentId) {
            LumoraDB::update('categories', ['parent_id' => $newParentId], 'id = ?', [$movedId]);
        }

        $pos = 0;
        foreach ($orderedIds as $sibling_id) {
            $sibling_id = (int) $sibling_id;
            if ($sibling_id <= 0) continue;
            LumoraDB::update('categories', ['pos' => $pos], 'id = ?', [$sibling_id]);
            $pos += 10;
        }

        return null;
    }

    /**
     * Return true when $candidateId is $rootId itself, or is anywhere within
     * $rootId's descendant subtree. Used by reorderCategories() to reject a
     * drag-and-drop move that would make a category a child of its own
     * descendant (which would create a parent_id cycle).
     */
    private static function isCategoryWithinSubtree(int $rootId, int $candidateId): bool
    {
        if ($rootId === $candidateId) {
            return true;
        }

        $flat = self::getAllCategoriesFlat();
        $children_of = [];
        foreach ($flat as $cat) {
            $children_of[(int) $cat['parent_id']][] = (int) $cat['id'];
        }

        $queue   = [$rootId];
        $visited = [];
        while (!empty($queue)) {
            $id = array_shift($queue);
            if (isset($visited[$id])) continue; // cycle guard
            $visited[$id] = true;
            foreach ($children_of[$id] ?? [] as $child_id) {
                if ($child_id === $candidateId) return true;
                $queue[] = $child_id;
            }
        }

        return false;
    }

    // ── Albums ────────────────────────────────────────────────────────────────

    /**
     * Get albums in a category, with image_count and latest_added_at.
     * $sort: 'pos' | 'title' | 'newest' | 'hits'
     *
     * latest_added_at is the MAX(added_at) of this album's approved images
     * (null when the album has no approved images yet). Themes use it instead
     * of created_at to show when an album was last actually updated with new
     * content, rather than when the album row itself was created/imported.
     *
     * @return list<array{id: int, category_id: int, folder: string, title: string,
     *                    description: string, visibility: int, pos: int, hits: int,
     *                    thumb_image_id: int, created_at: string, image_count: int,
     *                    latest_added_at: string|null}>
     */
    public static function getAlbums(int $category_id, string $sort = 'pos'): array
    {
        $order = match($sort) {
            'title'  => 'a.title ASC',
            'newest' => 'a.created_at DESC',
            'hits'   => 'a.hits DESC',
            default  => 'a.pos ASC, a.title ASC',
        };
        return LumoraDB::fetchAll(
            "SELECT a.*,
                 (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count,
                 (SELECT MAX(i2.added_at) FROM `{PREFIX}images` i2 WHERE i2.album_id = a.id AND i2.approved = 1) AS latest_added_at
             FROM `{PREFIX}albums` a
             WHERE a.category_id = ? AND a.visibility = 0
             ORDER BY {$order}",
            [$category_id]
        );
    }

    /**
     * Get a single album row (with image_count), or null.
     *
     * @param bool $public_only When true, also requires visibility = 0
     *                          (public), returning null for a private album
     *                          even if the ID exists. Public-facing pages
     *                          (album.php) must pass true for any visitor
     *                          who is not staff, since private albums are
     *                          otherwise fully reachable by anyone who
     *                          guesses/enumerates the numeric album ID —
     *                          they are hidden from navigation only, not
     *                          access-controlled by default.
     */
    public static function getAlbum(int $id, bool $public_only = false): ?array
    {
        $sql = 'SELECT a.*,
                 (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count
             FROM `{PREFIX}albums` a
             WHERE a.id = ?';
        if ($public_only) {
            $sql .= ' AND a.visibility = 0';
        }
        return LumoraDB::fetchOne($sql, [$id]);
    }

    /** Increment album hit counter. */
    public static function incrementAlbumHits(int $album_id): void
    {
        LumoraDB::query('UPDATE `{PREFIX}albums` SET hits = hits + 1 WHERE id = ?', [$album_id]);
    }

    // ── Admin Album Queries ───────────────────────────────────────────────────

    /**
     * Count albums for the admin list, with optional category filter and title search.
     *
     * @param int    $cat_id Filter by category; 0 = all categories.
     * @param string $search Partial case-insensitive title match; '' = no filter.
     * @return int Total album count.
     */
    public static function countAdminAlbums(int $cat_id = 0, string $search = ''): int
    {
        $where  = [];
        $params = [];

        if ($cat_id > 0) {
            $where[]  = 'category_id = ?';
            $params[] = $cat_id;
        }
        if ($search !== '') {
            $where[]  = 'title LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $sql = 'SELECT COUNT(*) FROM `{PREFIX}albums`';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return (int) LumoraDB::fetchValue($sql, $params);
    }

    /**
     * Get a paginated list of albums for the admin UI.
     *
     * Includes cat_name (categories join) and image_count (approved images only).
     * Ordered by category name, album position, then title.
     * Optional $search filters by partial case-insensitive album title match.
     *
     * @param int    $cat_id   Filter by category; 0 = all categories.
     * @param int    $page     1-based page number.
     * @param int    $per_page Albums per page.
     * @param string $search   Partial case-insensitive title match; '' = no filter.
     * @return list<array{id: int, category_id: int, folder: string, title: string,
     *                    description: string, visibility: int, pos: int, hits: int,
     *                    thumb_image_id: int, created_at: string,
     *                    cat_name: string|null, image_count: int}>
     */
    public static function getAdminAlbums(int $cat_id, int $page, int $per_page, string $search = ''): array
    {
        $offset = max(0, ($page - 1) * $per_page);

        $where  = [];
        $params = [];

        if ($cat_id > 0) {
            $where[]  = 'a.category_id = ?';
            $params[] = $cat_id;
        }
        if ($search !== '') {
            $where[]  = 'a.title LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $params[] = $per_page;
        $params[] = $offset;

        return LumoraDB::fetchAll(
            "SELECT a.*, c.name AS cat_name,\n"
            . "        (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count\n"
            . 'FROM `{PREFIX}albums` a' . "\n"
            . 'LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id' . "\n"
            . "{$where_sql}\n"
            . 'ORDER BY c.name ASC, a.pos ASC, a.title ASC' . "\n"
            . 'LIMIT ? OFFSET ?',
            $params
        );
    }

    /**
     * Get all albums for the admin hierarchy tree view.
     *
     * Returns every album row with its category name (cat_name) and approved
     * image count (image_count), ordered by category ID then position then title.
     * No pagination — the complete result set is returned for in-PHP tree building.
     *
     * When $search is non-empty the result is filtered to albums whose title matches
     * a partial case-insensitive LIKE. This is the same match used by countAdminAlbums
     * and getAdminAlbums so both can be called independently.
     *
     * @param string $search Partial case-insensitive title match; '' = no filter.
     * @return list<array{id: int, category_id: int, folder: string, title: string,
     *                    description: string, visibility: int, pos: int, hits: int,
     *                    thumb_image_id: int, created_at: string,
     *                    cat_name: string|null, image_count: int}>
     */
    public static function getAllAdminAlbumsGrouped(string $search = ''): array
    {
        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = 'a.title LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        return LumoraDB::fetchAll(
            "SELECT a.*, c.name AS cat_name,\n"
            . "       (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count\n"
            . "FROM `{PREFIX}albums` a\n"
            . "LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id\n"
            . "{$where_sql}\n"
            . "ORDER BY a.category_id ASC, a.pos ASC, a.title ASC",
            $params
        );
    }

    // ── Album Write Operations ──────────────────────────────────────────────────

    /**
     * Create a new album, auto-generating a zero-padded numeric folder name
     * (e.g. "00042") when $data['folder'] is blank, and creating the backing
     * directory under LUMORA_ALBUMS_PATH.
     *
     * Business rules enforced here (moved out of admin/albums.php so they can
     * be tested and reused independently of the page):
     *   - title is required.
     *   - thumb_image_id, if > 0, must reference an existing approved image;
     *     otherwise it's silently reset to 0 and a warning is returned.
     *   - A caller-supplied folder must already be sanitized (via
     *     lumora_sanitize_folder()) by the caller and must be unique; a blank
     *     folder is replaced with lumora_generate_folder($id) derived from
     *     the new album's own auto-increment ID, matching the previous
     *     "insert with a temp folder, then rename" two-step used by the page.
     *   - The album's folder directory is created on disk if missing; a
     *     failure to create it is reported as a warning, not a hard error,
     *     since the album row itself was still saved successfully.
     *
     * @param array{category_id?: int, folder?: string, title: string, description?: string,
     *              visibility?: int, pos?: int, thumb_image_id?: int} $data
     * @return array{id: int, folder: string, warning: string|null}|string
     *         Array with the new album's ID, final folder name, and an
     *         optional warning on success; an error message string on
     *         failure (empty title, or a caller-supplied folder already in use).
     */
    public static function createAlbum(array $data): array|string
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return 'Album title is required.';
        }

        $thumb  = self::resolveThumbImageId((int) ($data['thumb_image_id'] ?? 0));
        $folder = (string) ($data['folder'] ?? '');

        $base_row = [
            'category_id'    => (int) ($data['category_id'] ?? 0),
            'title'          => $title,
            'description'    => trim((string) ($data['description'] ?? '')),
            'visibility'     => ((int) ($data['visibility'] ?? 0)) === 1 ? 1 : 0,
            'pos'            => (int) ($data['pos'] ?? 0),
            'thumb_image_id' => $thumb['id'],
            'created_at'     => date('Y-m-d H:i:s'),
        ];

        if ($folder === '') {
            $new_id = (int) LumoraDB::insert('albums', $base_row + ['folder' => '__tmp__']);
            $folder = lumora_generate_folder($new_id);
            LumoraDB::update('albums', ['folder' => $folder], 'id = ?', [$new_id]);
        } else {
            $exists = LumoraDB::fetchValue('SELECT id FROM `{PREFIX}albums` WHERE folder = ?', [$folder]);
            if ($exists) {
                return 'Folder name "' . $folder . '" is already in use.';
            }
            $new_id = (int) LumoraDB::insert('albums', $base_row + ['folder' => $folder]);
        }

        $warning = $thumb['warning'];
        $dir     = LUMORA_ALBUMS_PATH . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $dir_warning = 'Album saved but could not create directory albums/' . $folder . '/. Create it manually via FTP.';
            $warning     = $warning !== null ? $warning . ' ' . $dir_warning : $dir_warning;
        }

        return ['id' => $new_id, 'folder' => $folder, 'warning' => $warning];
    }

    /**
     * Update an existing album's editable fields. The folder is never
     * changed here — renaming it after creation would break the filesystem
     * path every stored image relies on, so the page never exposes it as
     * editable and this method has no folder parameter at all.
     *
     * @param array{title: string, description?: string, visibility?: int, pos?: int,
     *              thumb_image_id?: int, category_id?: int} $data
     * @param bool $allow_category_change Whether category_id may be changed —
     *             pass false for a contributor editing an assigned album
     *             (category reassignment is a 'manage_albums'-only
     *             capability); the existing category is left untouched when
     *             false, even if $data['category_id'] is present.
     * @return array{warning: string|null}|string An array with an optional
     *         warning on success, or an error message string on failure
     *         (empty title).
     */
    public static function updateAlbum(int $id, array $data, bool $allow_category_change = true): array|string
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return 'Album title is required.';
        }

        $thumb = self::resolveThumbImageId((int) ($data['thumb_image_id'] ?? 0));

        $updates = [
            'title'          => $title,
            'description'    => trim((string) ($data['description'] ?? '')),
            'visibility'     => ((int) ($data['visibility'] ?? 0)) === 1 ? 1 : 0,
            'pos'            => (int) ($data['pos'] ?? 0),
            'thumb_image_id' => $thumb['id'],
        ];
        if ($allow_category_change && array_key_exists('category_id', $data)) {
            $updates['category_id'] = (int) $data['category_id'];
        }

        LumoraDB::update('albums', $updates, 'id = ?', [$id]);

        return ['warning' => $thumb['warning']];
    }

    /**
     * Delete an album: its image rows, the album row itself, any contributor
     * album assignments (AlbumAssignmentService), and — only when the
     * backing folder exists on disk and is empty — the folder itself. A
     * non-empty folder's files are left on disk and the returned message
     * says so.
     *
     * Matches the previous inline page behavior exactly, including running
     * the delete queries even when $id doesn't match any row (a harmless
     * no-op DELETE affecting 0 rows) — the page never checked for existence
     * first either, so this method doesn't introduce a new "not found" case.
     *
     * @return string A human-readable result message describing what
     *                happened to the album's on-disk folder, suitable for a
     *                flash message.
     */
    public static function deleteAlbum(int $id): string
    {
        $album = LumoraDB::fetchOne('SELECT folder FROM `{PREFIX}albums` WHERE id = ?', [$id]);

        LumoraDB::delete('images', 'album_id = ?', [$id]);
        LumoraDB::delete('albums', 'id = ?', [$id]);
        AlbumAssignmentService::removeAllAssignmentsForAlbum($id);

        $folder_msg = ' Image files on disk were NOT removed.';
        if ($album && $album['folder'] !== '') {
            $dir = LUMORA_ALBUMS_PATH . $album['folder'];
            if (is_dir($dir)) {
                $scan     = scandir($dir);
                $is_empty = ($scan !== false && count($scan) === 2);
                if ($is_empty) {
                    if (rmdir($dir)) {
                        $folder_msg = ' Empty folder albums/' . $album['folder'] . '/ was removed.';
                    } else {
                        $folder_msg = ' Folder albums/' . $album['folder'] . '/ could not be removed — delete it manually via FTP.';
                    }
                } else {
                    $folder_msg = ' Folder albums/' . $album['folder'] . '/ is not empty — files kept on disk.';
                }
            } else {
                $folder_msg = ' No folder found on disk for albums/' . $album['folder'] . '/';
            }
        }

        return 'Album deleted.' . $folder_msg;
    }

    /**
     * Reorder albums within a single category bucket (drag-and-drop admin
     * UI — TODO.md #23). Albums are never reparented to a different category
     * via this method — only their relative position within the same
     * category changes; dragging an album into a different category section
     * is out of scope for this feature (use the album edit form's Category
     * field for that).
     *
     * Each ID's `pos` is renumbered in 10-step increments, matching the
     * gap-tolerant scheme reorderCategories() uses. The WHERE clause
     * defensively re-checks category_id = $categoryId for every update, so a
     * tampered request can't move an album's position value while it
     * silently lives in a different category than the one it's supposedly
     * being reordered within.
     *
     * @param int       $categoryId  Category bucket being reordered (0 = uncategorized).
     * @param list<int> $orderedIds  Full ordered list of album IDs belonging
     *                               to $categoryId, in display order.
     * @return string|null Error message on failure, or null on success.
     */
    public static function reorderAlbums(int $categoryId, array $orderedIds): ?string
    {
        if ($categoryId > 0 && !self::getCategory($categoryId)) {
            return 'Category not found.';
        }

        $pos = 0;
        foreach ($orderedIds as $album_id) {
            $album_id = (int) $album_id;
            if ($album_id <= 0) continue;
            LumoraDB::query(
                'UPDATE `{PREFIX}albums` SET pos = ? WHERE id = ? AND category_id = ?',
                [$pos, $album_id, $categoryId]
            );
            $pos += 10;
        }

        return null;
    }

    // ── Images ────────────────────────────────────────────────────────────────

    /**
     * Get a paginated set of approved images for an album.
     * $sort: 'pos' | 'newest' | 'oldest' | 'most_viewed' | 'filename'
     */
    public static function getAlbumImages(
        int    $album_id,
        int    $page     = 1,
        int    $per_page = 48,
        string $sort     = 'pos'
    ): array {
        $order = match($sort) {
            'newest'      => 'i.added_at DESC',
            'oldest'      => 'i.added_at ASC',
            'most_viewed' => 'i.hits DESC',
            'filename'    => 'i.filename ASC',
            default       => 'i.pos ASC, i.id ASC',
        };
        $offset = max(0, ($page - 1)) * $per_page;
        return LumoraDB::fetchAll(
            "SELECT i.*, a.folder
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.album_id = ? AND i.approved = 1
             ORDER BY {$order}
             LIMIT ? OFFSET ?",
            [$album_id, $per_page, $offset]
        );
    }

    /** Count approved images in an album. */
    public static function countAlbumImages(int $album_id): int
    {
        return (int) LumoraDB::fetchValue(
            'SELECT COUNT(*) FROM `{PREFIX}images` WHERE album_id = ? AND approved = 1',
            [$album_id]
        );
    }

    /**
     * Get a single approved image with its album folder and category_id.
     */
    public static function getImage(int $id): ?array
    {
        return LumoraDB::fetchOne(
            'SELECT i.*, a.folder, a.title AS album_title, a.category_id
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.id = ? AND i.approved = 1',
            [$id]
        );
    }

    /** Increment image hit counter. */
    public static function incrementImageHits(int $image_id): void
    {
        LumoraDB::query('UPDATE `{PREFIX}images` SET hits = hits + 1 WHERE id = ?', [$image_id]);
    }

    /**
     * Get the previous and next image IDs in an album relative to a given image.
     *
     * Loads all IDs in order once; efficient for typical album sizes.
     *
     * @return array{prev: int|null, next: int|null}
     */
    public static function getImageNeighbours(int $image_id, int $album_id, string $sort = 'pos'): array
    {
        $order = match($sort) {
            'newest'      => 'i.added_at DESC',
            'oldest'      => 'i.added_at ASC',
            'most_viewed' => 'i.hits DESC',
            'filename'    => 'i.filename ASC',
            default       => 'i.pos ASC, i.id ASC',
        };

        $ids = array_column(
            LumoraDB::fetchAll(
                "SELECT id FROM `{PREFIX}images` AS i WHERE i.album_id = ? AND i.approved = 1 ORDER BY {$order}",
                [$album_id]
            ),
            'id'
        );

        $pos = array_search((string) $image_id, array_map('strval', $ids), true);
        if ($pos === false) return ['prev' => null, 'next' => null];

        return [
            'prev' => $pos > 0                 ? (int) $ids[$pos - 1] : null,
            'next' => $pos < (count($ids) - 1) ? (int) $ids[$pos + 1] : null,
        ];
    }

    // ── Admin Image Search ────────────────────────────────────────────────────

    /**
     * Search images by filename or title (admin use; any approval status).
     *
     * Case-insensitive partial match against both `filename` and `title`
     * columns. Results include album title and category name so search results
     * across multiple albums can be displayed with full context.
     *
     * When $album_id > 0 the search is scoped to that album; when 0 it covers
     * all albums. For single-album searches the existing `album_approved` index
     * limits the scan to that album's rows, keeping performance acceptable even
     * at 500 K total images. Cross-album searches perform a full table scan on
     * the images table; see the migration note in CHANGELOG.md for an optional
     * FULLTEXT index that can be added on very large galleries.
     *
     * @param string $query    Search term; partial / multi-word; case-insensitive.
     * @param int    $album_id Restrict to this album; 0 = all albums.
     * @param int    $page     1-based page number.
     * @param int    $per_page Rows per page.
     * @param int    $owner_id Restrict to images uploaded by this user; 0 = no
     *                         ownership filter. Used to scope the contributor
     *                         role's 'edit_own_images' permission to its own
     *                         uploads on admin/images.php.
     * @return list<array{id: int, album_id: int, uploaded_by: int, filename: string,
     *                    title: string, filesize: int, width: int, height: int, hits: int,
     *                    approved: int, pos: int, added_at: string,
     *                    folder: string, album_title: string, cat_name: string}>
     */
    public static function searchImages(
        string $query,
        int    $album_id  = 0,
        int    $page      = 1,
        int    $per_page  = 24,
        int    $owner_id  = 0
    ): array {
        $like   = '%' . $query . '%';
        $offset = max(0, ($page - 1) * $per_page);
        $params = [$like, $like];

        $where = '(i.filename LIKE ? OR i.title LIKE ?)';
        if ($album_id > 0) {
            $where   .= ' AND i.album_id = ?';
            $params[] = $album_id;
        }
        if ($owner_id > 0) {
            $where   .= ' AND i.uploaded_by = ?';
            $params[] = $owner_id;
        }
        $params[] = $per_page;
        $params[] = $offset;

        return LumoraDB::fetchAll(
            "SELECT i.*, a.folder, a.title AS album_title,
                    COALESCE(c.name, '') AS cat_name
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             LEFT JOIN `{PREFIX}categories` c ON c.id = a.category_id
             WHERE {$where}
             ORDER BY a.title ASC, i.pos ASC, i.id ASC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * Count images matching a search query (admin use; any approval status).
     *
     * @param string $query    Search term; partial / multi-word; case-insensitive.
     * @param int    $album_id Restrict to this album; 0 = all albums.
     * @param int    $owner_id Restrict to images uploaded by this user; 0 = no
     *                         ownership filter.
     * @return int             Total matching image count.
     */
    public static function countSearchImages(string $query, int $album_id = 0, int $owner_id = 0): int
    {
        $like   = '%' . $query . '%';
        $params = [$like, $like];

        $where = '(i.filename LIKE ? OR i.title LIKE ?)';
        if ($album_id > 0) {
            $where   .= ' AND i.album_id = ?';
            $params[] = $album_id;
        }
        if ($owner_id > 0) {
            $where   .= ' AND i.uploaded_by = ?';
            $params[] = $owner_id;
        }

        return (int) LumoraDB::fetchValue(
            "SELECT COUNT(*)
             FROM `{PREFIX}images` i
             WHERE {$where}",
            $params
        );
    }

    /**
     * Get a paginated list of images in a single album for the admin UI
     * (any approval status), optionally scoped to a single uploader.
     *
     * Counterpart to searchImages()/countSearchImages() for the plain
     * (non-search) per-album listing on admin/images.php.
     *
     * @param int $album_id Album to list.
     * @param int $page     1-based page number.
     * @param int $per_page Rows per page.
     * @param int $owner_id Restrict to images uploaded by this user; 0 = no
     *                      ownership filter.
     * @return list<array{id: int, album_id: int, uploaded_by: int, filename: string,
     *                    title: string, filesize: int, width: int, height: int, hits: int,
     *                    approved: int, pos: int, added_at: string, folder: string}>
     */
    public static function getAdminAlbumImages(int $album_id, int $page, int $per_page, int $owner_id = 0): array
    {
        $offset = max(0, ($page - 1) * $per_page);
        $where  = 'i.album_id = ?';
        $params = [$album_id];

        if ($owner_id > 0) {
            $where   .= ' AND i.uploaded_by = ?';
            $params[] = $owner_id;
        }
        $params[] = $per_page;
        $params[] = $offset;

        return LumoraDB::fetchAll(
            "SELECT i.*, a.folder
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE {$where}
             ORDER BY i.pos ASC, i.id ASC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * Count images in a single album for the admin UI (any approval status),
     * optionally scoped to a single uploader.
     *
     * @param int $album_id Album to count.
     * @param int $owner_id Restrict to images uploaded by this user; 0 = no
     *                      ownership filter.
     */
    public static function countAdminAlbumImages(int $album_id, int $owner_id = 0): int
    {
        $where  = 'album_id = ?';
        $params = [$album_id];

        if ($owner_id > 0) {
            $where   .= ' AND uploaded_by = ?';
            $params[] = $owner_id;
        }

        return (int) LumoraDB::fetchValue(
            "SELECT COUNT(*) FROM `{PREFIX}images` WHERE {$where}",
            $params
        );
    }

    // ── Image Ownership ───────────────────────────────────────────────────────

    /**
     * Return true when the image identified by $imageId has uploaded_by = $userId.
     *
     * The single source of truth for the contributor role's 'edit_own_images'
     * permission, used by lumora_require_image_access() in auth.php and by the
     * per-ID checks in the bulk image AJAX handlers (ajax_image_delete.php,
     * ajax_image_move.php, ajax_image_rethumb.php). Returns false for an image
     * with uploaded_by = 0 (no recorded owner) — such images remain accessible
     * only to users holding 'manage_images'.
     */
    public static function imageBelongsToUser(int $imageId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return (int) LumoraDB::fetchValue(
            'SELECT COUNT(*) FROM `{PREFIX}images` WHERE id = ? AND uploaded_by = ?',
            [$imageId, $userId]
        ) > 0;
    }

    // ── Bulk Rename (LG-26) ───────────────────────────────────────────────────

    /**
     * Load specific images within a single album, in pos/id order.
     *
     * Used by the Bulk Rename feature to re-fetch exactly the set of images
     * an admin selected in the Image Manager, scoped to one album, in the
     * same order shown there — so sequential numbering in the preview
     * matches what gets applied.
     *
     * @param list<int> $ids Image IDs to load.
     * @return list<array{id: int, filename: string, pos: int, folder: string}>
     */
    public static function getAlbumImagesByIds(int $album_id, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $ph     = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$album_id], array_values($ids));
        return LumoraDB::fetchAll(
            "SELECT i.id, i.filename, i.pos, a.folder
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.album_id = ? AND i.id IN ({$ph})
             ORDER BY i.pos ASC, i.id ASC",
            $params
        );
    }

    /**
     * Get the filenames of every image in an album except the given IDs.
     *
     * Used by the Bulk Rename feature to detect a rename target that would
     * collide with an image outside the selected batch.
     *
     * @param list<int> $exclude_ids Image IDs to exclude from the result.
     * @return list<string> Existing filenames.
     */
    public static function getOtherAlbumFilenames(int $album_id, array $exclude_ids): array
    {
        if (empty($exclude_ids)) {
            return array_column(
                LumoraDB::fetchAll('SELECT filename FROM `{PREFIX}images` WHERE album_id = ?', [$album_id]),
                'filename'
            );
        }
        $ph     = implode(',', array_fill(0, count($exclude_ids), '?'));
        $params = array_merge([$album_id], array_values($exclude_ids));
        return array_column(
            LumoraDB::fetchAll(
                "SELECT filename FROM `{PREFIX}images` WHERE album_id = ? AND id NOT IN ({$ph})",
                $params
            ),
            'filename'
        );
    }

    // ── Gallery-wide image queries ────────────────────────────────────────────

    /**
     * Get albums with the most recently added images (public albums only).
     * Used on the home page when latest_albums_count > 0.
     *
     * @return array[] Each row is an album row plus image_count and latest_added_at.
     */
    public static function getLatestUpdatedAlbums(int $limit = 5): array
    {
        if ($limit <= 0) return [];
        return LumoraDB::fetchAll(
            'SELECT a.*,
                 (SELECT COUNT(*) FROM `{PREFIX}images` i WHERE i.album_id = a.id AND i.approved = 1) AS image_count,
                 (SELECT MAX(i2.added_at) FROM `{PREFIX}images` i2 WHERE i2.album_id = a.id AND i2.approved = 1) AS latest_added_at
             FROM `{PREFIX}albums` a
             WHERE a.visibility = 0
             HAVING latest_added_at IS NOT NULL
             ORDER BY latest_added_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    /**
     * Most-viewed approved images (public albums only), optionally scoped to
     * a single album or category. $album_id takes precedence over $cat_id
     * when both are given. $cat_id scoping includes every descendant
     * sub-category at any depth (not just albums directly in that
     * category) — a category that only organizes sub-categories, with no
     * albums of its own, still returns results from whichever
     * sub-category the most-viewed images actually live in.
     *
     * @return list<array<string, mixed>>
     */
    public static function getMostViewedImages(int $limit = 48, ?int $album_id = null, ?int $cat_id = null): array
    {
        $where  = ['i.approved = 1', 'a.visibility = 0'];
        $params = [];

        if ($album_id !== null) {
            $where[]  = 'i.album_id = ?';
            $params[] = $album_id;
        } elseif ($cat_id !== null) {
            $cat_ids = self::getCategorySubtreeIds($cat_id);
            $ph      = implode(',', array_fill(0, count($cat_ids), '?'));
            $where[] = "a.category_id IN ({$ph})";
            array_push($params, ...$cat_ids);
        }

        $params[] = $limit;

        return LumoraDB::fetchAll(
            'SELECT i.*, a.folder, a.title AS album_title
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.hits DESC
             LIMIT ?',
            $params
        );
    }

    /** Most recently added images (public albums only). */
    public static function getLatestImages(int $limit = 48): array
    {
        return LumoraDB::fetchAll(
            'SELECT i.*, a.folder, a.title AS album_title
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.approved = 1 AND a.visibility = 0
             ORDER BY i.added_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    /** Random images from public albums. */
    public static function getRandomImages(int $limit = 48): array
    {
        return LumoraDB::fetchAll(
            'SELECT i.*, a.folder, a.title AS album_title
             FROM `{PREFIX}images` i
             JOIN `{PREFIX}albums` a ON a.id = i.album_id
             WHERE i.approved = 1 AND a.visibility = 0
             ORDER BY RAND()
             LIMIT ?',
            [$limit]
        );
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Return basic gallery stats: categories, albums, images, total hits.
     *
     * @return array{categories: int, albums: int, images: int, total_hits: int}
     */
    public static function getGalleryStats(): array
    {
        return [
            'categories' => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}categories`'),
            'albums'     => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}albums`'),
            'images'     => (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}images` WHERE approved = 1'),
            'total_hits' => (int) LumoraDB::fetchValue('SELECT COALESCE(SUM(hits),0) FROM `{PREFIX}images`'),
        ];
    }

    // ── Who Is Online ─────────────────────────────────────────────────────────

    /**
     * Record (or refresh) the current visitor's IP in the online-tracking table.
     *
     * On each call:
     *   1. Deletes rows whose last_action is older than `who_is_online_duration` minutes.
     *   2. Upserts the current IP with last_action = NOW().
     *
     * Fails silently when the {PREFIX}online table is absent (pre-v5 installs).
     */
    public static function trackVisitor(): void
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        if ($ip === '') return;

        $duration = max(1, (int) LumoraConfig::get('who_is_online_duration', '5'));

        try {
            LumoraDB::query(
                'DELETE FROM `{PREFIX}online` WHERE last_action < NOW() - INTERVAL ? MINUTE',
                [$duration]
            );
            LumoraDB::query(
                'INSERT INTO `{PREFIX}online` (ip, last_action) VALUES (?, NOW())
                 ON DUPLICATE KEY UPDATE last_action = NOW()',
                [$ip]
            );
        } catch (\Throwable) {
            // {PREFIX}online absent on pre-v5 installs; fail silently.
        }
    }

    /**
     * Return the current online visitor count and the all-time record.
     *
     * Also updates `online_record_count` / `online_record_date` config keys
     * when the current count exceeds the stored record.
     *
     * Returns ['online' => 0, ...] on pre-v5 installs where the table is absent.
     *
     * @return array{online: int, record_count: int, record_date: string}
     */
    public static function getOnlineStats(): array
    {
        try {
            $count = (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}online`');
        } catch (\Throwable) {
            return ['online' => 0, 'record_count' => 0, 'record_date' => ''];
        }

        $record      = max(0, (int) LumoraConfig::get('online_record_count', '0'));
        $record_date = (string) LumoraConfig::get('online_record_date', '');

        if ($count > $record) {
            $record      = $count;
            $record_date = date('Y-m-d H:i:s');
            LumoraConfig::set('online_record_count', (string) $record);
            LumoraConfig::set('online_record_date',  $record_date);
        }

        return [
            'online'       => $count,
            'record_count' => $record,
            'record_date'  => $record_date,
        ];
    }

    // ── Folder Discovery (LG-040) ─────────────────────────────────────────────

    /**
     * List directories under LUMORA_ALBUMS_PATH that are not yet claimed by
     * any existing album row — used by admin/albums.php?action=new so an
     * admin can pick a folder that was already uploaded to disk (e.g. via
     * FTP) instead of retyping its exact path by hand.
     *
     * Scans at every depth (album folders can be nested, e.g.
     * "ShowName/Season2/EpisodeSlug"), but only a directory that directly
     * contains at least one file is offered as a candidate — a purely
     * organizational parent directory (e.g. "ShowName" or
     * "ShowName/Season2" holding only subfolders, no files of its own) is
     * still walked into but never suggested itself. Without this, a deep
     * show/season/episode tree would bury the handful of genuinely useful
     * leaf folders under a much larger pile of container-folder noise, and
     * clicking a bare container folder would be misleading since that's
     * never where images actually live in this app.
     *
     * Never leaves LUMORA_ALBUMS_PATH (every candidate is realpath()-resolved
     * and re-checked against the resolved albums root before being
     * included, guarding against a symlink pointing outside it), and
     * applies the exact same lumora_sanitize_folder() validation the form
     * submit path uses, so a folder offered here is guaranteed to also be
     * accepted on save.
     *
     * Fails soft: returns whatever was found so far (or an empty list)
     * rather than erroring out — this is a convenience lookup, not a
     * critical path. Capped at self::MAX_AVAILABLE_FOLDERS results.
     *
     * @return list<string> Relative folder paths, sorted alphabetically.
     */
    public static function listAvailableAlbumFolders(): array
    {
        $albums_root = realpath(LUMORA_ALBUMS_PATH);
        if ($albums_root === false || !is_dir($albums_root)) {
            return [];
        }

        $used = array_flip(array_column(
            LumoraDB::fetchAll('SELECT folder FROM `{PREFIX}albums`'),
            'folder'
        ));

        $found = [];
        self::scanAlbumFoldersRecursive($albums_root, $albums_root, $used, $found);

        sort($found, SORT_STRING);
        return $found;
    }

    /** Hard cap on how many candidate folders listAvailableAlbumFolders() will scan/return. */
    private const MAX_AVAILABLE_FOLDERS = 1000;

    /**
     * Recursive worker for listAvailableAlbumFolders(). Walks $dir (an
     * absolute, already realpath()-resolved directory) depth-first,
     * appending every unclaimed, safely-contained subdirectory's
     * albums-root-relative path to $found by reference. Stops early once
     * self::MAX_AVAILABLE_FOLDERS candidates have been collected.
     *
     * @param array<string, int> $used  Folder paths already in {PREFIX}albums, as a lookup set.
     * @param list<string>       $found Accumulator, passed by reference.
     */
    private static function scanAlbumFoldersRecursive(string $albums_root, string $dir, array $used, array &$found): void
    {
        if (count($found) >= self::MAX_AVAILABLE_FOLDERS) {
            return;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (count($found) >= self::MAX_AVAILABLE_FOLDERS) {
                return;
            }
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $real = realpath($path);
            if ($real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $albums_root . DIRECTORY_SEPARATOR)) {
                continue; // symlink escaped the albums root — skip it
            }

            $relative = substr($real, strlen($albums_root) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $clean    = lumora_sanitize_folder($relative);

            if ($clean !== '' && $clean === $relative && !isset($used[$clean]) && self::dirHasDirectFile($real)) {
                $found[] = $clean;
            }

            self::scanAlbumFoldersRecursive($albums_root, $real, $used, $found);
        }
    }

    /**
     * Return true if $dir directly contains at least one regular file
     * (thumbnails included — this is a cheap "does anything live here"
     * check, not an image-type check). Stops at the first match rather than
     * reading the whole directory listing.
     */
    private static function dirHasDirectFile(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                return true;
            }
        }

        return false;
    }

    // ── Private write helpers ────────────────────────────────────────────────

    /**
     * Validate a caller-supplied thumb_image_id: it must reference an
     * existing approved image, or it's silently reset to 0 with a warning
     * message. Shared by createAlbum()/updateAlbum()/createCategory()/
     * updateCategory() — all four accept a thumb_image_id with identical
     * validation rules.
     *
     * @return array{id: int, warning: string|null}
     */
    private static function resolveThumbImageId(int $thumb_image_id): array
    {
        if ($thumb_image_id <= 0) {
            return ['id' => 0, 'warning' => null];
        }

        $valid = LumoraDB::fetchValue(
            'SELECT id FROM `{PREFIX}images` WHERE id = ? AND approved = 1',
            [$thumb_image_id]
        );
        if ($valid) {
            return ['id' => $thumb_image_id, 'warning' => null];
        }

        return [
            'id'      => 0,
            'warning' => 'Cover image ID ' . $thumb_image_id . ' does not exist or is not approved. Cover cleared.',
        ];
    }
}
