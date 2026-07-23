# History — Lumora Gallery

Long-term archive of completed work, migrated from TODO.md on release.

---

## v1.12.0 — Released 2026-07-23

### Added

- **LiteSpeed/OpenLiteSpeed support** (LG-033): new `ServerEnvironmentService::detect()`
  identifies the current web server plus HTTP/2, HTTP/3, Brotli, and active-LSCache
  capabilities, shown on the admin Installation page's System Information panel. New
  `CacheHeaderService` manages two independent, additive `.htaccess` blocks: standard
  browser cache-control headers for static assets (long-lived immutable caching for
  images/thumbnails/fonts, a shorter window for CSS/JS — Apache/LiteSpeed compatible),
  and a separate opt-in LSCache page-caching block (`CacheEnable`/configurable `TTL`) for
  hosts without server-level cache configuration access (e.g. some DirectAdmin setups).
  A new opt-in `litespeed_cache_purge` config toggle (Admin → Configuration →
  Performance) sends an `X-LiteSpeed-Purge` header after any admin content change,
  wired once for every admin-panel POST request rather than at each individual
  mutation call site. Follow-up fixes found via live testing: public pages now start a
  PHP session lazily (only for admin requests or requests already carrying a session
  cookie) via new `lumora_ensure_session()`, since the previous unconditional
  `session_start()` sent `Cache-Control: no-store` on every response and defeated all
  caching regardless of the purge toggle; new `admin/.htaccess` (`CacheLookup off`)
  adds explicit LSCache exclusion for the admin panel as defense-in-depth.
- **`?view=most_viewed` now respects the current album or category context** (LG-33):
  `GalleryService::getMostViewedImages()` gained optional `$album_id`/`$cat_id`
  filters; the "Most Viewed" nav link and page heading now reflect whichever album or
  category is currently being browsed instead of always showing the gallery-wide list.
- **Admin sidebar reorganized into Gallery/Settings/Maintenance/Users sections**
  (LG-32), with Settings/Maintenance/Users collapsible — toggle button with a rotating
  chevron indicator, state persisted per-browser via `localStorage`, and the section
  containing the current page always auto-expanding regardless of stored state.
- **Admin → Updates page redesigned to align with FanUpdate Redux** (TODO #10):
  consolidated Installed/Status/Source metadata grid, an interactive Latest Release
  card (stability badge, Markdown-rendered release notes, checksum verification,
  re-download action), a Backups panel (create/restore/delete ZIP snapshots, up to 3
  retained), a System Status table, and an Update Settings form (release channel,
  automatic-check toggle/frequency, optional GitHub token).
- **Theme preview (`?theme=`) now persists across category and album navigation**
  (TODO #9): every internal category/album/nav/pagination link now carries the
  `theme` query parameter forward for the rest of the preview session.

### Fixed

- **Configuration page's "Save Settings" button moved out of "Upload & Image Limits"**
  (LG-34) into its own clearly-labeled, distinctly-styled card, so it no longer reads
  as scoped to just that one section despite always having saved the whole page.
- **Dead forwarding wrappers removed from `include/template.php`**
  (`lumora_custom_header()`/`lumora_custom_footer()`) — leftover from the Custom HTML
  removal below; would have fatally errored if ever called.
- **Classic Fansite theme: section titles unreadable against their background bar** —
  a CSS specificity conflict between `.lum-section-title` and the theme's own heading
  rule was silently overriding the intended light text colour.

### Removed

- **"Custom HTML (optional)" config feature** (Custom Header/Footer File Path,
  `{CUSTOM_HEADER}`/`{CUSTOM_FOOTER}` template tokens) — theme authors now edit
  `template.html` directly, which every existing custom theme already did in practice.

---

## v1.11.0 — Released 2026-07-13

### Changed

- **Update check now goes through the GitHub Releases provider exclusively** (TODO #12): `UpdateService` previously queried its own separate, hardcoded JSON endpoint (`coding.unloved-heart.net/lumora/update.json`), entirely independent of the `GitHubUpdateProvider`/`AbstractUpdateProvider` abstraction the in-dashboard updater already used for download URLs and checksums — two sources of truth that could disagree. `UpdateService::fetch()` now delegates to `AbstractUpdateProvider::createFromConfig()`, so release discovery, download URL, and checksum verification all come from one GitHub-backed source; the old endpoint dependency was removed entirely. `AbstractUpdateProvider` gained `getSourceLabel()` so the admin Updates page shows the release source (e.g. "GitHub Releases (owner/repo)") in place of the retired endpoint URL.
- **Update confirmation checkbox on `admin/update.php` made visually prominent** (TODO #24): now sits inside a bordered, tinted callout box with a larger checkbox, bold label, and warning icon, so it's far less likely to be overlooked before an update replaces application files. Checkbox id, label association, and the JS gating "Update Now" on it are unchanged.
- **`admin/config.php` reorganised into clearly separated sections** (TODO #25): each former sub-heading (plus a new "Basic Information" heading) is now its own card with a prominent heading and inline SVG icon, matching `admin/tools.php`/`admin/installation.php`. All field names, ids, validation attributes, and the single shared form submission are unchanged — layout-only.

### Added

- **Admin-only theme preview via URL** (TODO #29): a logged-in administrator can append `?theme=folder-name` to the public gallery URL to render any installed theme for that request only, without changing the site's configured theme or affecting other visitors. An invalid/unknown theme name falls back to the real active theme with an admin-only notice; a valid preview shows an admin-only banner reminding them it's temporary. Admin → Configuration's Themes table gained a **Preview** column/button per theme.
- **Copyable image info box in the frontend lightbox, Admin/Staff only** (TODO #28): logged-in staff see an extra PhotoSwipe toolbar button opening a panel with the image's direct URL and a ready-to-paste embed snippet, plus a one-click Copy HTML button with a "Copied!" confirmation.
- **Drag-and-drop reordering for categories and albums in the admin panel** (TODO #23): `admin/categories.php`'s hierarchy tree and `admin/albums.php`'s hierarchy view can be dragged to reorder; categories can additionally be dragged onto another category to reparent (guarded against moving into a descendant). New `GalleryService::reorderCategories()`/`reorderAlbums()` and two new AJAX endpoints back this, with saving/error toast feedback.
- **Opt-in anonymous install ping** (TODO #27): a new, off-by-default Privacy section in Admin → Configuration sends a minimal anonymous ping (install UUID, Lumora version, PHP version — nothing else) roughly once a month, fully independent of the update-check request, failing silently on any network error.

### Fixed

- **Dark mode: accent colour used as link/border text failed WCAG AA contrast** in both built-in themes (TODO #16): both themes now have separate `--*-accent-ink`/`--*-accent-ink-hover` tokens for foreground (text/link/border) use, WCAG AA-verified (5.8:1–7.3:1) against dark surfaces, while filled backgrounds keep the original accent unchanged.
- **Dark mode: no visible keyboard focus outline** in either built-in theme (TODO #16): added a theme-aware `:focus-visible` outline (adaptive per colour mode), plus a fixed light-coloured outline for the always-dark navbar/footer.
- **Category dropdown in the album creation/edit form displayed categories in the wrong hierarchical order** (TODO #15): `GalleryService::getAllCategoriesTreeOrdered()` now walks the category tree depth-first from the root so every child appears immediately after its own parent at the correct indent level, at any nesting depth. Both `admin/albums.php`'s Category dropdown and `admin/categories.php`'s Parent Category dropdown now use this instead of the flat, unordered list.

---

## v1.10.0 — Released 2026-07-08

### Added

- **User Group Management** (TODO #21): Roles are now dynamic permission
  groups instead of a fixed admin/moderator/contributor ENUM. New
  `{PREFIX}groups` / `{PREFIX}group_permissions` tables (Migration0007, DB
  version 13) back a new `GroupService` (`getAllGroups()`, `getGroup()`,
  `getGroupBySlug()`, `groupExists()`, `getGroupPermissions()`,
  `groupHasPermission()`, `createGroup()`, `renameGroup()`,
  `updateGroupPermissions()`, `deleteGroup()`). The three system groups
  (admin, moderator, contributor) are seeded with the exact permission sets
  previously hardcoded in `UserService::ROLE_PERMISSIONS`, so no
  installation sees a behavioural change until an administrator deliberately
  edits a group. New admin page `admin/groups.php` (gated on
  `user_management`) lists every group with permission/user counts and
  supports create/rename/edit-permissions/delete, with safeguards: system
  groups can never be deleted, a group with active members can't be deleted
  until they're reassigned, and the `admin` system group can never lose
  `user_management`/`site_configuration`.

- **Per-Image Ownership Enforcement** (TODO #19): The `edit_own_images`
  permission (contributor role) is now enforced at the row level, not just
  the page level. New `uploaded_by` column on `{PREFIX}images`
  (Migration0006, DB version 12), backfilled to the primary administrator
  for all pre-existing images. New `GalleryService::imageBelongsToUser()`
  and `lumora_require_image_access()` are the single source of truth,
  applied throughout `admin/images.php` and its AJAX handlers (bulk
  handlers check ownership per-ID rather than gating the whole request).
  `ThumbnailService::batchAddImage()` now records the uploading user
  automatically.

- **Contributor Album Assignments** (TODO #18): Implements the
  `manage_assigned_albums` permission that was previously defined but
  unused. New `{PREFIX}album_assignments` table (Migration0005, DB version
  11) and `AlbumAssignmentService` (`assignAlbum()`, `unassignAlbum()`,
  `setAssignedAlbums()`, `userCanAccessAlbum()` as the single source of
  truth, plus cascade-cleanup on user/album delete). `admin/albums.php` now
  serves a scoped "assigned albums" view for contributors (no create/
  delete/category-reassignment); new page `admin/user_albums.php` lets an
  admin/moderator assign albums via a filterable checklist.

- **User Management — page-level access enforcement** (TODO #2): Moderator
  and contributor accounts can now log in to the admin panel (previously
  admin-only). New `lumora_require_login()`, `lumora_require_permission()`,
  `lumora_require_any_permission()` helpers in `include/auth.php` enforce
  page-level access via the existing role→permission mapping; every admin
  page and its AJAX handlers now gate on the specific permission that
  matches its function instead of a blanket admin-only check. The sidebar
  nav and dashboard Quick Links now hide items the current role can't use.
  Remember-me now works for all three roles.

- **Optional theme/plugin replacement during core upgrade** (TODO #5): Two
  unchecked checkboxes in the Install Update confirmation area (one for
  plugins, one for themes) let an administrator opt in to replacing
  existing customisations with the versions bundled in a release, without
  ever touching the persistent `update_preserve_themes`/`update_preserve_plugins`
  config values — the choice is per-update only.

- **Dark mode support** (TODO #6): Full light/dark/auto colour-scheme
  support for both the public gallery and admin panel, via Bootstrap 5.3's
  `data-bs-theme` plus a `--lum-*`/`--fs-*` CSS custom-property layer. An
  inline pre-CSS `<script>` prevents flash-of-wrong-theme. Preference
  cycles Auto→Dark→Light via a toggle button; stored in `localStorage`,
  additionally synced to a new `{PREFIX}users.color_mode` column
  (Migration0004, DB version 10) for logged-in staff so it follows them
  across devices. New **Default Colour Mode** admin setting for first-time
  visitors. All four custom themes received additive dark-mode overrides.
  Also added `color-scheme: light dark` support so native browser UI
  (scrollbars, form chrome) matches the active theme.

- **Coppermine importer now assigns image ownership** (TODO #20): every
  imported image gets a real `uploaded_by` value instead of `0`. The import
  wizard defaults to the running administrator, with an "Assign imported
  images to" dropdown to choose a different existing Lumora user.
  Per-original-uploader mapping (via a future Coppermine user import)
  remains out of scope.

### Changed

- **Album/category create, edit, delete moved into `GalleryService`**: this
  logic previously lived inline in `admin/albums.php`/`admin/categories.php`
  — the one place in the admin panel that didn't follow the project's
  service-layer convention. Six new methods now own every validation rule;
  the pages only handle permission checks, CSRF, request parsing, and
  redirects. Purely an internal refactor — no behavioural change.

- **Core themes expanded for full custom styling** (TODO #17): both bundled
  theme stylesheets reorganised under named section banners so every
  public-facing component can be restyled from CSS alone. New CSS-only
  hooks added for components without a page yet (forms, tables, alerts,
  panels, badges, a hover-overlay utility, a loading spinner, print
  styles). No visual output changed and no PHP templates were touched.

- **Bulk image move now scoped to a contributor's assigned albums** (TODO
  #22): the "Move Selected" target dropdown in `admin/images.php` is now
  filtered to the contributor's assigned albums (mirroring the existing
  batch-add scoping), with server-side re-validation in
  `admin/ajax_image_move.php` so the restriction can't be bypassed by
  editing the request directly.

### Documentation

- **New theme development guide** (`docs/THEME_DEVELOPMENT.md`): documents
  the full dark-mode architecture, a minimal dark-mode-ready theme example,
  an accessibility checklist, and recipes for colour-mode transitions,
  theme-aware logos, and print styles.

### Security

11 findings from a full codebase security & code quality audit, all fixed
this release (see `TODO-security.md` for the complete write-up of each):

1. **Private albums are now actually access-controlled** — a "Private"
   album previously remained fully viewable to anyone who guessed/
   enumerated its numeric ID; `getAlbum()` gained a `$public_only`
   parameter and now 404s for non-staff, same as a nonexistent album.
2. **`view_updates` no longer allows performing updates** — the endpoint
   that downloads/extracts/migrates/rolls back updates now requires
   `site_configuration`, matching the equivalent migration endpoint.
3. **Open redirect fixed on login** — new `lumora_safe_redirect_target()`
   rejects protocol-relative `redirect` values like `//evil.com`.
4. **Installer `?force=1` reinstall now requires authentication** —
   previously bypassed the "already installed" guard with nothing but an
   unauthenticated query parameter.
5. **Password recovery no longer hardcoded to `role = 'admin'`** — now
   resolves by permission via `GroupService`, so recovery keeps working
   even after the administrator's account moves to a custom group.
6. **Login rate limiter TOCTOU race closed** — replaced separate
   unlocked-read/write with one exclusive `flock()` across the whole
   read-prune-decide-write cycle.
7. **Album-assignment eligibility generalised beyond the `'contributor'`
   slug** — now checks the `manage_assigned_albums` permission via
   `GroupService` rather than a literal role name.
8. **Removed dead `@`-suppressed code block** in `admin/migrate.php`.
9. **Coppermine importer's table prefix is now sanitised** before SQL
   interpolation, matching the core installer's own validation.
10. **Coppermine config-detector AJAX no longer leaks exception
    internals** to the client — logs full details server-side instead.
11. **Config import now enforces the same validation as manual save** — a
    new `LumoraConfig::sanitizeValue()` closes a gap where a hand-edited
    export file could store an out-of-range config value.

### Fixed

- **`LUMORA_DB_VERSION` was stale at `9`** despite the schema having
  reached version 13 — the constant was never incremented across
  Migrations 0004–0007. Misreported in `InstallationService::exportSettings()`'s
  diagnostic snapshot; bumped to `13` to match reality.
- **`GalleryService::getImageNeighbours()` was broken for every call, on
  any sort order** — its query had no table alias while every sort branch
  referenced one. Found only once the full test suite was actually run to
  completion for the first time.
- **`LumoraDB` transactions are now nesting-safe**, including surviving a
  DDL statement's implicit commit mid-transaction without throwing.
- **Coppermine config parser silently failed on double-quoted array
  keys** — a real Coppermine config using `$CONFIG["key"]` syntax would be
  rejected outright despite being valid.
- **`lumora_sanitize_folder()` hardened** against a disallowed character
  (e.g. a null byte) fusing two path segments together in a way that could
  slip a `..` substring past the traversal filter.
- **`Migration0003` no longer unconditionally re-narrows `users.role` to a
  fixed ENUM** on an already-upgraded install, which would have rejected or
  truncated custom group slugs.

---

## v1.9.2 — Released 2026-06-29

### Added

- [x] **Hierarchy tree view in Album Manager** (`admin/albums.php`, `GalleryService.php`, `admin/admin.css`): Albums page opens in a hierarchy view by default. Albums grouped under their category; subcategories indented with `└ ` connector glyph and 20 px depth steps. Uncategorized albums in a dedicated *(No Category)* section. Category section headers show direct-album badge. Built from two queries (`getAllCategoriesWithCounts`, `getAllAdminAlbumsGrouped`); ref-array cycle guard prevents infinite recursion on corrupt `parent_id` values. Falls back to flat paginated table when search or category filter is active (✕ Clear returns to hierarchy mode). New `render_album_row()` and `render_album_tree()` helper functions added.

- [x] **Hierarchy tree view in Category Manager** (`admin/categories.php`, `GalleryService.php`, `admin/admin.css`): Categories page displays full parent/child tree instead of flat paginated list. Root categories at top level; children indented with `└ ` connector glyph (20 px per depth level). Each row shows name, direct-album badge, position, and Edit/Delete buttons. *(N ↳)* subcategory indicator shown alongside names with direct children. Built from single `getAllCategoriesWithCounts()` query; same data drives both tree view and parent dropdown. Cycle guard in `render_category_tree_rows()`. Pagination removed from list view.

- [x] **Album name search in Album Manager** (`admin/albums.php`, `GalleryService.php`): Search field above the album list. Case-insensitive partial match via parameterized `LIKE` query on `a.title`. Summary line shows match count and term when active. ✕ Clear button resets the list. Search preserved in pagination links and per-page selector. Friendly "no albums" message when no results. `countAdminAlbums` and `getAdminAlbums` extended with optional `$search` parameter.

- [x] **Album description styling** (`album.php`, `themes/default/style.css`, `themes/classic-fansite/style.css`): Album descriptions on album pages now rendered with `lum-album-desc` class — left accent border, subtle background tint, padded text, relaxed line-height in both bundled themes.

- [x] **Category description styling** (`index.php`, `themes/default/style.css`, `themes/classic-fansite/style.css`): Category descriptions on category pages now rendered with `lum-cat-desc` class — left accent border, subtle background tint, padded text, relaxed line-height in both bundled themes, matching the `.lum-album-desc` pattern.

### Fixed

- [x] **Album card descriptions no longer cut off** (`themes/default/style.css`, `themes/classic-fansite/style.css`): The `.lum-card-desc` rule previously applied `-webkit-line-clamp: 2`, truncating album and category descriptions in the card grid to two lines. Clamp removed so full description is always visible.

- [x] **Album card descriptions now styled** (`include/services/ThemeRenderer.php`): Album descriptions in the card grid were rendered with plain Bootstrap utility classes producing unstyled grey text. Renderer now picks correct semantic class per item type: `.lum-album-desc` for album cards, `.lum-cat-desc` for category cards — both receive the left-border, background-tint, and padding styling applied elsewhere.

### Changed

- [x] **Albums shown before sub-categories on category pages** (`index.php`): Within a category, albums now listed first, followed by sub-categories.

- [x] **Admin sidebar: Users link moved after Account** (`admin/includes/admin_helpers.php`): Users item now appears at the bottom of the sidebar navigation, after Account, grouping user-management separately from the main gallery workflow items.

- [x] **Coppermine Importer version corrected to v1.3.0**: Plugin files (`version.php`, `plugin.json`) had already been bumped to v1.3.0 in code; all documentation references updated from v1.2.0 to v1.3.0 to match.

- [x] **CHANGELOG.md structural repair**: v1.9.0 entries for Staff Account Management, Auto-delete install/ (UpdaterService), and Coppermine auto-detect were left without a `### Added` section header. Header restored.

---

## v1.9.1 — Released 2026-06-28

### Fixed

- [x] **Bug #1 — Select All checkbox in Image Manager** (TODO #15 Bug 1): The "Select All" button in `admin/images.php` did nothing when clicked — no error, no response. Root cause chain: (1) Event listeners registered via `addEventListener` / `DOMContentLoaded` were silently dropped. Rewritten as globally-defined functions (`lumSelAll`, `lumUpdCount`, `lumBulkDelete`, `lumBulkMove`, `lumSingleDelete`, `lumRethumb`) called via inline `onclick`/`onchange` attributes. (2) A `\n` escape sequence inside a PHP heredoc is a literal newline; the `confirm()` string in `lumBulkDelete` contained `'...files?\n\nThis cannot be undone.'`, producing an unconditional JS `SyntaxError` that prevented the entire `<script>` block from parsing — fixed by doubling to `\\n`. (3) The per-row delete confirmation message was passed via `json_encode()` into a double-quoted HTML `onclick` attribute; `json_encode()`’s surrounding double-quotes terminated the attribute prematurely — fixed by storing the message in a `data-confirm` attribute (HTML-escaped with `h()`) and reading it back via `this.dataset.confirm`.

- [x] **Bug #2 — Auto-delete `install/` directory** (TODO #15 Bug 2): `ins_delete_installer()` in `install/index.php` returned `false` on the first `unlink()` failure and stopped trying, leaving the directory intact when FTP-uploaded files are owned by a different user than the PHP process. Fix: (1) `@chmod($file, 0666)` before each `@unlink()` to handle restrictive file permissions; (2) all files attempted regardless of individual failures; (3) hidden files covered by a second `'.*'` glob pass; (4) security fallback — if the directory still exists after deletion, `index.php` is overwritten with `header('Location: ../'); exit` so the installer is inaccessible even when PHP cannot remove the file. The completion page message was updated to explain this fallback. `UpdaterService::stageCleanup()` also improved with an `is_writable()` pre-check and specific failure logging.

- [x] **Bug #3 — `ℹ` icon in `update.php`** (TODO #15 Bug 3): The plain text character `ℹ` (U+2139) in the "About Updates" heading was replaced with `ℹ️` (U+2139 + U+FE0F variation selector), rendering as a colour emoji consistent with all other section headings on the page.

### Changed

- [x] **Theme CSS standardised to `style.css`** (TODO #14): Renamed `lumora.css` → `style.css` (default theme) and `fansite.css` → `style.css` (classic-fansite theme). Both `template.html` files updated; block-comment filename annotations updated; `custom.css` untouched. `themes/classic-fansite/README.md` and root `README.md` updated throughout. `lumora_theme_primary_stylesheet()` required no change — it discovers the primary stylesheet dynamically from `template.html`.

- [x] **Test suite relocated outside repository** (TODO #13 Directory Location): Moved `tests/`, `composer.json`, and `phpunit.xml` from `Lumora/tests/` (inside the git repo) to the workspace root (`../tests/` relative to the repo), keeping tests out of the gallery source tree. `tests/bootstrap.php` updated: `LUMORA_ROOT` now resolves to `dirname(__DIR__) . '/Lumora/'`. `phpunit.xml` source paths updated to `Lumora/include` and `Lumora/plugins`. `.github/workflows/tests.yml` simplified to PHP syntax validation only (PHPUnit tests cannot run in CI when the test suite lives outside the repository).

---

## v1.9.0 — Released 2026-06-25

### Security

- [x] **Security Audit Remediation** (TODO item 13): Full code review of 57 files against a 2026-06-25 static-analysis scan (117 Critical + 291 High reported; majority confirmed scanner false positives). Genuine confirmed issues resolved:
  - **SQL identifier escaping** (`include/services/UpdaterService.php`): `dumpDatabase()` applies `str_replace('`', '``', $table)` before interpolating table names into `SHOW CREATE TABLE`, `SELECT *`, and `INSERT INTO` queries.
  - **ZipArchive path traversal** (`include/services/UpdaterService.php`): `stageExtract()` adds null-byte pre-check and a post-extraction `realpath()` scan verifying every extracted path resolves within the canonical extraction directory; cleans up and aborts on any escape.
  - **File upload double-extension bypass** (`include/services/ThumbnailService.php`): `isAllowedImage()` rejects filenames where any dot-separated segment matches a server-executable extension (`php`, `php3`–`php7`, `phtml`, `phar`, `shtml`); `scanNewImages()` updated to call `isAllowedImage()` consistently.
  - **GD image dimension bomb** (`include/services/ThumbnailService.php`): `thumbGd()` validates source dimensions from `getimagesize()` before calling any `imagecreatefrom*()` function; rejects images exceeding 50 MP total or 15 000 px per axis.
  - **Login rate limiting** (`admin/login.php`): IP-based brute-force protection via `cache/.login_ratelimit.json` — 5-failure/15-minute sliding window; 1-second per-failure `usleep()` delay; 2-second delay + form and submit button disabled client-side on lockout; IP record cleared on successful authentication.
  - **Password-change timing hardening** (`admin/account.php`): `usleep(500_000)` added on `password_verify()` failure in the password-change handler.
  - All remaining audit findings confirmed as scanner false positives (scanner fired on `require_once`, `echo json_encode()`, `lumora_int()`-guarded reads, and the CSRF-check lines themselves); documented in Phase A of the audit item.

### Added

- [x] **Admin Tool: Installation Settings** (TODO item 2): `admin/installation.php` allows administrators to update Lumora's installation configuration after moving to a new domain, subdirectory, or server — no manual `config.php` editing or raw SQL required. New `InstallationService` (`include/services/InstallationService.php`) provides `detectEnvironment()` (live protocol/host/path detection with reverse-proxy header support), `getStoredConfig()`, `detectChanges()` (stored vs. detected mismatch list), `validateUrl()`, `applySettings()` (validated write + audit log + cache clear, requires password re-auth), `clearCaches()` (opcache + LumoraConfig), `runHealthCheck()` (9 checks: DB connectivity, albums dir, cache dir, config.php, site URL, PHP version, image processor, PDO MySQL, ZipArchive), `logConfigChange()`, `getRecentChanges()`, and `exportSettings()` (JSON snapshot, DB password excluded). Page sections: Current Installation Information, Auto-Detected Changes (shown only on mismatch), Migration Helpers accordion (domain change, subdirectory change, HTTPS enablement, full server migration), Update Installation Settings form (password re-auth required, live change-preview), AJAX Health Check panel, Configuration Change Log (last 15 entries). `{PREFIX}config_changes` audit table added via `Migration0002_CreateConfigChangesTable` (`LUMORA_DB_VERSION` bumped from 7 to 8). `InstallationService` loaded in `bootstrap.php` step 7. Installation (🖥️) nav item added to sidebar.

- [x] **Dashboard Update System — Phase 2** (TODO item 12): In-dashboard update installer. 10-stage AJAX workflow: `preflight → download → verify → backup → maintenance → extract → validate → replace → migrate → cleanup`. `AbstractUpdateProvider` (provider interface with `fetchMetadata()`, `buildArchiveUrl()`, `getName()`, static `createFromConfig()` factory), `GitHubUpdateProvider` (GitHub Releases API — maps tag name, date, release notes, SHA-256 from release assets, configurable via `update_github_repo` config key), `UpdaterService` (JSON lock file at `cache/.updates/lock.json` persists state across AJAX calls; per-stage `set_time_limit(180)`; streaming download with 120 s timeout; SHA-256 verification; `config.php` + full DB dump backup in 100-row chunks; path-traversal-safe ZipArchive extraction with pre-extraction string check + post-extraction `realpath()` scan; file replacement preserving `config.php`/`albums/`/`cache/` and optionally `themes/`+`plugins/`; `SchemaService::runPendingMigrations()` after replace; `rollback()` restoring config + DB backup; `forceAbort()` for stuck sessions; append-only log + last-10-attempt JSON history). `admin/ajax_update_perform.php` AJAX endpoint (actions: `run_stage`, `rollback`, `abort`). `admin/update.php` extended with ⬆ Install Update card (confirmation checkbox, PHP compatibility warning, 10-row stage progress list with ⊙/⟳/✓/✗ icons, scrollable detail log, Rollback/Abort buttons on failure) and 📋 Update History table. `human_time_diff()` helper added to `include/functions.php`. `bootstrap.php` updated to require the three new service files. New config keys: `update_provider_type` (`github`), `update_github_repo` (`intothisshadow/Lumora`), `update_preserve_themes` (`1`), `update_preserve_plugins` (`1`), `update_history` (JSON array).

- [x] **Staff Account Management — User Management UI** (TODO item 2, `admin/users.php`, `include/services/UserService.php`, `Migration0003_UpdateUsersTableForRoles`): Lumora now supports multiple staff accounts with three distinct roles. `admin/users.php` — new admin page: paginated user list with role badges, create/edit/delete/enable-disable, reset-password. `UserService` — role constants (`admin`, `moderator`, `contributor`), permission framework (`roleHasPermission()`, `currentUserHasPermission()`), `createUser()`, `updateUser()`, `resetPassword()` (revokes all remember-me tokens), `setActive()` (guards last active admin), `deleteUser()` (guards self-deletion and last admin), `getPaginatedUsers()`, `roleBadge()`, `roleOptions()`. `Migration0003_UpdateUsersTableForRoles` adds `is_active` column and updates role ENUM; both directions are idempotent. `lumora_login()` and `lumora_check_remember_cookie()` now reject disabled accounts. `lumora_has_permission()` helper added to `include/auth.php`. DB version bumped v8 → v9.

- [x] **Auto-delete `install/` directory after successful upgrade** (TODO item 1, `include/services/UpdaterService.php`): `stageCleanup()` now automatically removes the `install/` directory when an upgrade completes successfully, using the existing `removeDirectory()` helper. A success or failure detail line is added to the cleanup stage log; a warning is logged on failure; the existing per-page security banner in `admin_helpers.php` remains visible until the directory is gone.

- [x] **Coppermine Importer — Auto-detect database settings** (TODO item 3, plugin bumped to v1.3.0): Adds an **Auto-Detect from Coppermine Installation** panel to the credentials step of both the main import wizard and the Metadata Sync tool. `CoppermineConfigDetector` (new static class) — `findInstallations()` searches for `include/config.inc.php` up to 4 directory levels deep, returning all found paths; `parseConfig()` reads credentials as plain text (never `include`d or `eval`’d); `hasConfigFile()` for non-destructive checks. `ajax_detect_config.php` AJAX endpoint: `find` action parses and returns the full config on a single match, or returns metadata only (paths stored server-side in session) on multiple matches; `select` action resolves a choice by session index. Passwords never appear in error messages, server logs, or the multi-install list response.

### Changed

- [x] **Updated Lumora Gallery website URL** (TODO item 1): All references to the official Lumora Gallery website standardised to `https://coding.unloved-heart.net/scripts/Lumora` in `ThemeRenderer.php`, `UpdateService.php`, and `README.md`.

### Fixed

- [x] **`admin/installation.php` — Health Check button (and all JS-driven buttons on the page) did nothing** (TODO item 3): `$v_stored_url` and `$v_detected_url` were interpolated bare into JavaScript (`const STORED = {$v_stored_url};`), where `h()`-escaped values like `https://example.com/Lumora/` caused the JS engine to parse `https:` as a statement label and the rest as a comment, producing a `SyntaxError` that aborted the entire `<script>` block silently. Fixed by adding `json_encode()`d counterparts (`$stored_url_js`, `$detected_url_js`) for JS contexts; `h()`-escaped variables retained for HTML attribute use only.

---

## v1.8.0 — Released 2026-06-20

### Added

- [x] **Admin UI Pagination — Albums and Categories** (TODO item 2): Database-level `LIMIT / OFFSET` pagination added to both Admin → Albums and Admin → Categories list pages. Page size selector (25 / 50 / 100 items per page) auto-submits and persists selection in `$_SESSION` (`lum_adm_per_page_albums`, `lum_adm_per_page_categories`). Bootstrap 5 pagination `<nav>` rendered above and below each table with previous/next, a ±2 page-number window, and ellipsis indicators. Item count summary shows "Showing X–Y of N items" on every page. Category filter preserved across album-list pages. Out-of-range page numbers clamped safely. `GalleryService::countAdminAlbums()`, `getAdminAlbums()`, `countAllCategories()`, and `getPaginatedCategoriesFlat()` added. `lum_per_page_selector()` and `lum_admin_pagination()` helpers added to `admin/includes/admin_helpers.php`.

- [x] **Coppermine Importer — In-wizard cover image assignment** (TODO item 5, plugin bumped to v1.1.0): Album and category cover images (`cpg_albums.thumb`, `cpg_categories.thumb`) are now preserved automatically as part of the main import wizard. A dedicated **Cover images** phase (`apply_covers`) runs after all images have been imported and the full CPG→Lumora ID maps are in session. `CoppermineImporter::importCovers(array $cat_id_map, array $album_id_map): array` added — resolves each CPG thumb via `pid → (aid, filename) → album_id_map[aid] → Lumora image_id`, writes all updates in a single transaction with per-row rollback on failure, returns `{updated, skipped, warnings}`. `case 'apply_covers':` added to `ajax_import.php`. Wizard JS gains a `'covers'` phase between `'images'` and `'finish'`; the Stop button halts mid-images but cover assignment always runs once all images are complete (single fast call). Step 3 UI gains a **Cover images** status row. Plugin files bumped: `version.php`, `plugin.json`, `README.md`.

- [x] **Automated Database Migrations — Phase 1** (TODO item 8): Schema migration engine automating database changes between releases. `SchemaService` static service class (`include/services/SchemaService.php`) with `discoverMigrations()`, `getAppliedMigrations()`, `getPendingMigrations()`, `hasPendingMigrations()`, `runPendingMigrations(): array{applied, errors}`, `getMigrationStatus()`, and `rollback()`. `AbstractMigration` abstract base class (`include/migrations/AbstractMigration.php`) with `up()`, `down()`, `tableExists()`, `columnExists()`, `indexExists()`. Self-bootstrapping `Migration0001_CreateMigrationsTable` creates and records itself in `{PREFIX}migrations` on first run. Admin → Updates page extended with a Database Updates section (green ✓ when current, amber ⚠ + Run button when pending). Dashboard shows amber dismissible banner when migrations are pending. Updates nav badge fires when migrations are pending. `admin/ajax_run_migrations.php` AJAX endpoint. CLI entry point `migrate.php` (`--dry-run`, `--status`, `--rollback <ClassName>`). `SchemaService` loaded in `bootstrap.php` step 7.

- [x] **Unique Database Table Prefix Generation** (TODO item 9): Installer now auto-generates a cryptographically random table prefix (`lum_XXXXXXXX_`, 8 lowercase hex chars via `bin2hex(random_bytes(5))`) for every new installation. Generated prefix stored in `$_SESSION['ins_suggested_prefix']` for the install session lifetime; page refreshes keep the same value. Force-reinstall (`?force=1`) regenerates a fresh prefix. Advanced users may override via the editable prefix field (pattern `[a-zA-Z0-9_]+`). Step 2 confirmation card shows the confirmed prefix in `<code>`. Session key cleared on successful completion. Existing installations using `lum_` or any other prefix are entirely unaffected — prefix is read from `config.php` at runtime. `config.sample.php` updated to document the new format.

---

## v1.7.1 — Released 2026-06-19

### Bug Fixes

- [x] Albums missing added/updated date in album info display — regression from a prior fix lost on file overwrite; re-implemented in `ThemeRenderer::renderCatgrid()` and both core theme stylesheets (`.lum-card-date`).
- [x] Thumbnails missing added/updated date in thumbnail info display — regression from a prior fix lost on file overwrite; re-implemented in `ThemeRenderer::renderThumbgrid()` and both core theme stylesheets (`.lum-thumb-date`).
- [x] Album cards showed the Lumora import date (`created_at`) as the "Updated" date instead of when content was actually last added; `GalleryService::getAlbums()` now selects `MAX(images.added_at)` as `latest_added_at`; `ThemeRenderer::renderCatgrid()` prefers this field over `created_at` and relabels the span from "Added" to "Updated".
- [x] Sort bar overflowed past the viewport edge on narrow phones — fixed with `flex-wrap: wrap` in the `@media (max-width: 575px)` block of both core theme stylesheets (`default/lumora.css`, `classic-fansite/fansite.css`).
- [x] Category list header labels overflowed past the viewport edge on narrow phones — fixed by shrinking header cell font-size and padding to match the data cells at the same breakpoint in both core themes.
- [x] Corrected the official Lumora Gallery website URL in `ThemeRenderer.php`.

### Added

- [x] **Admin Password Recovery** (`admin/forgot_password.php`, `admin/reset_password.php`, `include/auth.php`, `admin/login.php`): Admins who have lost their password can generate a secure reset link without SMTP. Link is written to `lumora_recovery.txt` in the gallery root; best-effort `mail()` send is also attempted when an email is configured. Same split-token scheme as Remember Me. New DB table `{PREFIX}password_reset_tokens`; `LUMORA_DB_VERSION` bumped to 7.
- [x] **Regenerate Missing Thumbnails** (`admin/tools.php`, `admin/ajax_missing_thumbs.php`): Tool 4 on Admin → Tools. Scans all images in scope (entire gallery or a selected album) and regenerates thumbnails only where the thumbnail file is missing or empty, skipping images with valid thumbnails. Keyset-paginated AJAX handler. JSON response: `{ checked, regenerated, skipped, no_orig, last_id, errors[], done }`.
- [x] **Admin Image Search** (`admin/images.php`, `include/services/GalleryService.php`, `install/schema.sql`): Administrators can search images by filename or title from Admin → Images, scoped to a selected album or across all albums. Cross-album results include the category › album path. `GalleryService::searchImages()` and `GalleryService::countSearchImages()` added. Pagination, bulk delete, bulk move, and single-image actions all preserve the active search term. Optional B-tree prefix indexes for `filename(191)` and `title(191)` documented.
- [x] **Theme Metadata from CSS Headers** (`include/functions.php`, `admin/config.php`, `themes/default/lumora.css`, `themes/classic-fansite/fansite.css`): Theme display names, author, and design URI can be declared in a WordPress-style CSS header comment at the top of the primary stylesheet. `lumora_theme_primary_stylesheet()` and `lumora_get_theme_meta()` added to `include/functions.php`. Admin → Configuration → Appearance shows `Theme Name` in the Active Theme dropdown and a reference table for all installed themes. Both core themes updated with standardised metadata headers.
- [x] **Coppermine Importer — Metadata Sync tool** (`plugins/coppermine-importer/admin/sync_metadata.php`, `plugins/coppermine-importer/CoppermineImporter.php`, `plugins/coppermine-importer/admin/index.php`, `plugins/coppermine-importer/version.php`, `plugins/coppermine-importer/README.md`): Standalone companion to the main import wizard. Syncs category and album cover-thumbnail selections from an existing Coppermine database to an already-imported Lumora gallery, without a full re-import. Albums matched by folder path; categories matched by full name-path from root. Three-step page: Credentials → Preview → Report. Preview mode shows per-record status badges; apply step runs inside a single transaction with rollback on failure and writes a timestamped audit log to `plugins/coppermine-importer/logs/`.

### Changed

- [x] Album and category card metadata restructured from a single inline string into individually styled rows (`ThemeRenderer::renderCatgrid()`, `.lum-card-meta`, `.lum-card-images`, `.lum-card-views`, `.lum-card-subcats`, `.lum-card-albums`). All core themes updated with matching CSS.

---

## v1.7.0 — Released 2026-06-16

### Update Checker (Phase 1)

- [x] `UpdateService` static service class — fetches remote update endpoint, caches result
  in config table for 24 hours, exposes `check()`, `getCachedStatus()`, `hasCachedUpdate()`,
  and `isCacheExpired()`. Uses `version_compare()` for semantic comparison. Falls back to
  stale cache on network failure. No gallery data is ever transmitted.
- [x] `admin/update.php` — Updates admin page showing installed version, status badge,
  last-checked timestamp, changelog/download links when an update is available, and
  PHP-version compatibility warning. Renders from cache only at PHP time; JS auto-triggers
  AJAX check when cache is expired to avoid server-side blocking.
- [x] `admin/ajax_update_check.php` — AJAX endpoint for forced update check; returns full
  status array as JSON; validates CSRF and admin authentication.
- [x] `admin/includes/admin_helpers.php` — Updates (🔔) nav item added between Import and
  Account. Red `!` badge shown whenever cached status indicates an update (no HTTP call).
- [x] `admin/dashboard.php` — Dismissible info-bar shown when cached status indicates an
  update is available; includes changelog/download links; no HTTP call at render time.
- [x] `include/bootstrap.php` — `UpdateService.php` loaded in step 7 alongside other
  service classes.

### Bug Fixes

- [x] Albums missing added/updated date in album info display.
- [x] Thumbnails missing added/updated date in thumbnail info display.
- [x] Album and thumbnail info stats (views, resolution, image counts) restructured
  from a single inline string into individually styled rows.

---

## v1.6.0 — Released 2026-06-15

### Coppermine Importer Plugin (`plugins/coppermine-importer/`)

- [x] Official migration plugin for importing Coppermine Gallery (CPG 1.4–1.6) categories,
  albums, and image metadata into Lumora. Metadata-first; image files are not moved.
- [x] `version.php` — single source of truth for plugin version (`LUMORA_CPG_IMPORTER_VERSION`).
- [x] `plugin.json` — plugin manifest for discovery by the migration hub.
- [x] `CoppermineImporter` class — separate PDO connection to CPG database; keyset-paginated
  `importCategories()`, `importAlbums()`, `importImages()`, and `validate()` methods;
  schema-adaptive SELECT handles CPG column name variations across versions.
- [x] `admin/index.php` — four-step admin wizard: Credentials → Preview → Import → Done.
  Stores state and ID maps in `$_SESSION`; re-import warning with confirmation checkbox.
- [x] `admin/ajax_import.php` — AJAX chunk processor for three import actions plus `finish`;
  CSRF and admin auth validated on every call; integer-key-preserving session maps.
- [x] Stop Import button — halts after current in-flight batch without data loss.
- [x] Import status tracking — records source, date, counts, and plugin version in
  `{PREFIX}migration_status` after successful import.
- [x] Re-import protection — detects prior migration and requires confirmation before
  re-running; warns that duplicates may result.
- [x] Preserve existing Coppermine `albums/` folder structure — no file renaming or moves
  required; album paths derived from `cpg_pictures.filepath` with `keyword` fallback.

### Migration Framework (Core)

- [x] `MigrationService` static service class — import status tracking, event logging,
  plugin discovery (scans `LUMORA_PLUGINS_PATH/*/plugin.json`), and version compatibility
  checking.
- [x] `admin/migrate.php` — migration hub: discovers importer plugins, shows each as a card
  with name, description, version, compatibility badge, and previous migration status.
- [x] `admin/includes/admin_helpers.php` — Import (📥) nav entry added.
- [x] `include/bootstrap.php` — `LUMORA_PLUGINS_PATH` constant and `MigrationService.php`
  loaded in step 7.
- [x] `install/schema.sql` DB v6 — `{PREFIX}migration_status` and `{PREFIX}migration_log`
  tables.

### Bug Fixes

- [x] Album cards missing view count — `renderCatgrid()` displayed image count but omitted
  `hits`; fixed by rendering both values with spans for per-theme styling.

---

## v1.5.0 — Released 2026-06-15

### Technical Debt (V1 → V2 Prerequisites)

- [x] **0.1 Migrate business logic from free functions to service classes** — Introduced
  `GalleryService`, `ThemeRenderer`, `ThumbnailService`, and `LumoraConfig` in
  `include/services/`. Legacy free functions retained as thin forwarding wrappers;
  no callers required changes. Bootstrap load order updated to require service classes
  before the legacy include files.

- [x] **0.2 Replace global `$LUMORA_CONFIG` with a config service class** — `LumoraConfig`
  static class (private `$cache` array, `load()`, `get()`, `set()`) replaces the
  module-level global. `lumora_config()` and `lumora_set_config()` forwarding wrappers
  preserved for backward compatibility.

- [x] **0.3 Replace GET-based CSRF token on config export** — Config export anchor link
  (`?export=1&csrf_token=...`) replaced with a POST form (`action="export"`). CSRF
  token now travels in the request body only; validation delegated to the existing
  `lumora_csrf_validate()` call at the top of the POST block. Token no longer appears
  in browser history, server logs, or `Referer` headers.



## v1.0.0 — Released 2026-06-13

### Maintenance

- [x] Reload file dimensions and size information
- [x] Update thumbnails
- [x] File integrity check
- [x] Add album selector (all albums / specific album) for maintenance tools

---

### Bug Fixes

#### 1. Fix broken root category option in `admin/categories.php`

- [x] Correct the HTML markup.
- [x] Verify the "Root (no parent)" option appears correctly in the parent category dropdown.
- [x] Confirm category creation and editing work as expected after the fix.

#### 2. Fix undefined `$new_count` in `admin/batch.php`

- [x] Ensure `$new_count` is always defined before being used.
- [x] Prevent PHP undefined-variable notices on initial page load.
- [x] Ensure generated JavaScript remains valid when no album is selected.
- [x] Verify the batch page loads correctly both before and after album selection.

#### 3. Fix unreachable Step 1 form processing in `install/index.php`

- [x] Review installer control flow.
- [x] Ensure Step 1 form submissions are processed correctly.
- [x] Verify database credentials can be submitted and validated.
- [x] Confirm the installer progresses normally to the next step.

#### 4. Fix ineffective output escaping in `admin/config.php`

- [x] Review all configuration values rendered in the page.
- [x] Apply escaping before output is generated.
- [x] Remove or replace the ineffective `str_replace()` logic.
- [x] Ensure configuration values are safely displayed in form fields and page content.

#### 5. Add `declare(strict_types=1)` to all PHP files

- [x] Add `declare(strict_types=1);` immediately after the opening PHP tag in all applicable files.
- [x] Review for any type-related issues introduced by strict mode.
- [x] Resolve any compatibility problems discovered during testing.
- [x] Verify the application continues to function correctly after the update.

#### 6. Fix non-functional maintenance actions in `maintenance.php`

- [x] Investigate frontend and backend execution flow.
- [x] Determine why requests are not being processed.
- [x] Restore functionality for all three maintenance operations.
- [x] Add error handling and user feedback where appropriate.
- [x] Verify each action completes successfully and reports progress/results to the user.

#### 7. Clean up undefined `$s_total` usage in `admin/categories.php`

- [x] Remove the undefined variable usage.
- [x] Refactor content generation to avoid temporary invalid state.
- [x] Ensure functionality remains unchanged.

#### 8. Improve installer timestamp output in `install/index.php`

- [x] Replace the timestamp with a human-readable date/time format.
- [x] Use a consistent format such as `date('Y-m-d H:i:s')`.
- [x] Verify generated configuration files contain readable creation timestamps.

#### 9. "Powered by" invisible in some themes

- [x] Remove the Bootstrap-specific `text-muted` class from the `<small>` element in `lumora_render_powered_by()`.
- [x] Add an explicit `color` to `.lum-footer` in the default theme's `lumora.css` so its visual appearance is unchanged.
- [x] Verify the classic-fansite theme inherits `color: var(--fs-footer-text)` from `.fs-footer` (already set) — no change needed there.
- [x] Confirm the credit is legible in both themes.

---

### Code Audit Against PHP Development Standards

- [x] Deprecated patterns
- [x] Legacy coding styles
- [x] Naming convention violations
- [x] Missing type declarations
- [x] Missing return types
- [x] Inconsistent error handling
- [x] Direct database access patterns that violate current architecture
- [x] Security concerns
- [x] Input validation issues
- [x] Output escaping issues
- [x] Documentation deficiencies
- [x] Any code that conflicts with current project guidelines
- [x] Produce report grouped by: Critical issues, Recommended fixes, Style/compliance issues, Technical debt items
- [x] Apply straightforward low-risk fixes automatically.
- [x] Document deferred architectural issues with recommended remediation options.
- [x] Provide summary of files reviewed, files modified, remaining compliance issues, recommended future cleanup tasks.

---

### Dashboard

- [x] Change text "Lumora Admin" to "Lumora Gallery Admin"
- [x] Add current version after "Lumora Gallery Admin" text

---

### Albums

- [x] Delete physical folder if it is empty when album is deleted
- [x] Category thumbnail support
- [x] Album thumbnail support

---

### Authentication

- [x] Stay logged in feature
- [x] Remember me checkbox

---

### Installation

- [x] Automatically delete `/install` after successful installation
- [x] If deletion fails, display Admin warning

---

### Front Page

- [x] Move statistics boxes to bottom
- [x] Add "Who is online" feature based on `coppermine_onlinestats`
- [x] Show last updated Albums above Categories
- [x] Make the number of last updated Albums selectable in config

---

### Legal

- [x] Add GPLv3 license
- [x] Add developer credits

---

### Themes

- [x] Create a "Classic Fansite" starter theme inspired by the classic fansite layout reference image.
- [x] Create a reusable fansite theme framework that can be easily customized for different fandoms, celebrities, TV shows, movies, games, and communities.
- [x] Implement a traditional fansite-style homepage layout:
  - [x] Header/banner image area
  - [x] Main navigation menu
  - [x] Latest Updated Albums section
  - [x] Categories section
  - [x] Latest Additions (images) section
  - [x] Ensure the theme is fully responsive while preserving the classic fansite appearance on desktop.
  - [x] Document how to create and customize new themes based on the Classic Fansite starter theme.

---

### Planned Configuration Options

- [x] Timezone difference relative to GMT
- [x] Quality for JPG thumbnails
- [x] Selectable max size for uploaded files
- [x] Selectable max width or height for uploaded pictures
- [x] Count Album Views
- [x] Coppermine-style logging mode
- [x] Gallery offline mode

---

### Add "Move to Another Album" Functionality

- [x] Add a "Move to Album" action within image/file management.
- [x] Support moving a single image/file to another album.
- [x] Support bulk-moving multiple selected images/files.
- [x] Provide an album selection interface.
- [x] Preserve image metadata, comments, views, favorites, and other related data during the move.
- [x] Update album counts automatically after the operation.
- [x] Display confirmation and success/error messages.
- [x] Verify moved items appear correctly in the destination album and are removed from the source album.

---

### Enhance Image Management Feature

- [x] Edit image details
- [x] Move images between albums
- [x] Delete images
- [x] Bulk actions on selected images
- [x] Replace image
- [x] Thumbnail regeneration (where applicable)

---

### Move Powered By Credit from Themes to Gallery Configuration

- [x] Remove Powered By credit handling from theme files.
- [x] Move credit rendering to the core gallery/template system.
- [x] Ensure the credit is displayed consistently across all themes.
- [x] Allow future configuration of the credit from gallery settings if desired.
- [x] Verify existing themes continue to function correctly after the change.
- [x] Eliminate duplicated Powered By code across theme templates.

---

### Miscellaneous

- [x] Rename Maintenance to Tools
- [x] Display image id on images.php
- [x] Rename maintenance.php to tools.php
- [x] Remove version number from credit footer

---

### Category Structure

- [x] Current layout (as-is) — existing category display preserved.
- [x] Table/row layout — one category per row with thumbnail, name/description, album count, image count.
- [x] Add user-selectable option (setting) to switch between current category layout and category-per-row table layout.
- [x] Preserve existing functionality and sorting behavior.
- [x] Ensure responsive behavior on mobile, tablet, and desktop screens.
- [x] Reuse existing category metadata (thumbnail, title, description, album count, file/image count).
- [x] Make the new layout match the overall structure shown in the reference screenshot.
- [x] All CSS and template changes implemented across all existing themes (default + classic-fansite).
- [x] Ensure visual consistency within each theme using existing theme variables, colors, spacing, typography, borders, and component styles.
- [x] Verify that switching themes does not break the new layout.
- [x] The layout option is available and functions identically regardless of the active theme.

---

