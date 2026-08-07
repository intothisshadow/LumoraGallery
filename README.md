# Lumora Gallery

**Lumora Gallery** is a modern PHP image gallery born from my own needs as a fan—specifically, wanting a clean, fast replacement for classic Coppermine Gallery that could keep up with massive fan archives *and* look great on modern devices.

Built from the ground up for fansites with huge collections, it combines a sleek, fully responsive layout with the heavy-lifting power needed for serious archiving. Built for fansites with large collections — tested against scenarios with 9,000+ images per album and 500,000+ total images.

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.2+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| PHP extensions | PDO, PDO_MySQL, Imagick (preferred) or GD |
| Web server | Apache 2.4+, LiteSpeed/OpenLiteSpeed, or Nginx |

No Composer required. Upload and go.

---

## Installation

1. **Upload** the `Lumora/` folder to your web server (e.g. `public_html/gallery/`).
2. **Make `albums/` writable** by the web server (`chmod 755 albums/` or as needed by your host).
3. **Visit `/install/`** in your browser and follow the three-step wizard:
   - Step 1: Requirements check + database credentials
   - Step 2: Database setup + admin account creation
   - Step 3: `config.php` written — installation complete
4. **Delete or protect the `install/` directory** after installation. (The installer attempts this automatically; verify it is gone.)
5. Log in at `admin/` with the credentials you created.

### Manual install (advanced)

Copy `config.sample.php` to `config.php` and fill in your database details, then import `install/schema.sql` into your database.

---

## Directory Structure

```
Lumora/
├── admin/                      Admin panel
│   ├── includes/               Admin-only helpers (flash messages, per-page selector, pagination controls, page renderer)
│   ├── index.php               Admin entry point — redirects unauthenticated requests to login
│   ├── account.php             Account management (username, email, password)
│   ├── users.php               User management (create/edit/delete staff accounts, roles, enable/disable, password reset); "Assign Albums" link for contributor rows
│   ├── groups.php              Permission group management (view/create/rename/delete groups, grant/revoke permissions per group)
│   ├── user_albums.php         Assign specific albums to a contributor account (checkbox picker, filterable)
│   ├── albums.php              Album management — hierarchy/flat views for admin/moderator; scoped "assigned albums" view for contributors
│   ├── batch.php               Batch-add images from FTP
│   ├── ajax_batch.php          AJAX endpoint for chunked batch processing
│   ├── ajax_image_delete.php    AJAX endpoint for bulk image deletion
│   ├── ajax_image_move.php      AJAX endpoint for bulk image move between albums
│   ├── ajax_image_rethumb.php   AJAX endpoint for single-image thumbnail regeneration
│   ├── ajax_missing_thumbs.php  AJAX endpoint for missing-thumbnail regeneration (Tool 4)
│   ├── ajax_integrity.php      AJAX endpoint for integrity scan chunks
│   ├── ajax_integrity_delete.php  AJAX endpoint for deleting orphaned records
│   ├── ajax_dimensions.php     AJAX endpoint for reload-dimensions chunks
│   ├── ajax_thumbs.php         AJAX endpoint for thumbnail regeneration chunks
│   ├── ajax_update_check.php   AJAX endpoint for forced update check
│   ├── ajax_update_perform.php AJAX endpoint for multi-stage in-dashboard update (run_stage / rollback / abort)
│   ├── ajax_run_migrations.php AJAX endpoint for running schema migrations
│   ├── ajax_installation_health.php  AJAX endpoint for installation health check (9 system checks)
│   ├── ajax_reorder_categories.php  AJAX endpoint for drag-and-drop category reorder/reparent
│   ├── ajax_reorder_albums.php AJAX endpoint for drag-and-drop album reorder within a category
│   ├── ajax_list_folders.php   AJAX endpoint listing unclaimed server folders under albums/ (New Album Folder Path suggestions)
│   ├── categories.php          Category management
│   ├── config.php              Gallery settings, export/import
│   ├── dashboard.php           Stats overview
│   ├── images.php              Image management (edit, delete, move, bulk actions)
│   ├── installation.php        Installation Settings — update base URL after domain/server migration; nine-item health check; configuration change log; web server/capability detection; static asset cache header installer
│   ├── migrate.php             Migration hub — discovers and launches importer plugins
│   ├── tools.php               Admin tools (File Integrity Check, Reload Dimensions, Regenerate Thumbnails, Regenerate Missing Thumbnails)
│   ├── update.php              Updates page — consolidated status/source metadata grid, interactive Latest Release card (checksum bar, Markdown release notes, re-download), Full Backups panel (create/restore/delete ZIP snapshots), System Status checks, Update Settings (channel/frequency/token), in-dashboard updater (10-stage AJAX workflow with automatic update backup + rollback), schema migrations, update history
│   ├── forgot_password.php  Password recovery — generates a reset link to lumora_recovery.txt
│   ├── reset_password.php   Password reset — validates token, sets new password
│   ├── login.php / logout.php
│   └── admin.css
├── albums/                     Image storage — original + thumb_* thumbnails
├── docs/                       CHANGELOG.md, HISTORY.md, THEME_DEVELOPMENT.md
├── include/                    Core PHP includes
│   ├── services/               Static service classes (business logic layer)
│   │   ├── LumoraConfig.php    Config cache — load(), get(), set()
│   │   ├── GalleryService.php  Category, album, image, stats, visitor-tracking queries
│   │   ├── ThumbnailService.php Thumbnail generation, resizing, metadata, batch-add
│   │   ├── ThemeRenderer.php   All HTML output: pages, grids, breadcrumbs, lightbox
│   │   ├── MigrationService.php Import status tracking, plugin discovery, event logging
│   │   ├── UpdateService.php   Release check via the configured provider (GitHub Releases by default), version comparison, cache TTL follows the check-frequency setting (24h/7d)
│   │   ├── SchemaService.php   Schema migration engine — discover, run, rollback PHP class migrations
│   │   ├── AbstractUpdateProvider.php  Provider interface — fetchMetadata(), buildArchiveUrl(), factory
│   │   ├── GitHubUpdateProvider.php    GitHub Releases API provider — metadata, SHA-256, curated ZIP asset URL (falls back to raw archive URL)
│   │   ├── UpdaterService.php  Update orchestrator — 10-stage workflow, lock file, backup, rollback, standalone download+verify, system status checks
│   │   ├── BackupService.php   Full-installation ZIP backups (code + config + DB dump, excluding albums/cache) — create/restore/delete, up to 3 retained
│   │   ├── InstallationService.php  Installation settings detection, migration helpers, health checks, audit logging
│   │   ├── ServerEnvironmentService.php  Web server detection (LiteSpeed/OpenLiteSpeed/Apache/nginx/Caddy) and HTTP/2, HTTP/3, Brotli, active-LSCache capability flags
│   │   ├── CacheHeaderService.php  Managed .htaccess cache-control block for static assets (Apache/LiteSpeed-compatible) and opt-in LiteSpeed Cache purge-header integration
│   │   ├── UserService.php     User CRUD, role constants, permission framework (delegates to GroupService)
│   │   ├── GroupService.php    Permission groups — CRUD, permission catalog (ALL_PERMISSIONS), system-group safeguards
│   │   ├── AlbumAssignmentService.php  Per-contributor album assignments — assign/unassign/set, userCanAccessAlbum() access check, cascade cleanup
│   │   └── InstallPingService.php  Opt-in anonymous install ping — UUID generation, ~monthly cadence, dedicated endpoint separate from UpdateService
│   ├── migrations/             Versioned PHP schema migration classes
│   │   ├── AbstractMigration.php              Base class — up(), down(), tableExists(), columnExists(), indexExists()
│   │   ├── Migration0001_CreateMigrationsTable.php  Self-bootstrapping first migration — creates {PREFIX}migrations table
│   │   ├── Migration0002_CreateConfigChangesTable.php  Creates {PREFIX}config_changes audit table for installation setting changes
│   │   ├── Migration0003_UpdateUsersTableForRoles.php  Adds is_active column; updates role ENUM to admin/moderator/contributor
│   │   ├── Migration0004_AddColorModeToUsers.php  Adds color_mode column (auto/light/dark) to users
│   │   ├── Migration0005_CreateAlbumAssignmentsTable.php  Creates {PREFIX}album_assignments table for contributor album access
│   │   ├── Migration0006_AddUploadedByToImages.php  Adds uploaded_by column to images for per-image ownership enforcement
│   │   └── Migration0007_CreateGroupsTables.php  Creates {PREFIX}groups / {PREFIX}group_permissions tables; widens users.role from ENUM to varchar
│   ├── bootstrap.php           Load order, constants
│   ├── db.php                  PDO singleton (LumoraDB)
│   ├── functions.php           Utility helpers and legacy forwarding wrappers
│   ├── auth.php                Login, CSRF, session, password management
│   ├── thumb.php               Legacy forwarding wrapper → ThumbnailService
│   └── template.php            Legacy forwarding wrapper → ThemeRenderer
├── install/                    Web-based installer (delete after use)
│   ├── index.php
│   └── schema.sql
├── plugins/                    Optional plugins
│   └── coppermine-importer/    Official Coppermine → Lumora migration plugin
│       ├── CoppermineImporter.php  Core importer class (categories, albums, images, cover sync)
│       ├── plugin.json         Plugin manifest (consumed by admin/migrate.php)
│       ├── version.php         Single source of truth for plugin version
│       ├── README.md           Plugin documentation and Metadata Sync tool reference
│       └── admin/              Plugin admin pages
│           ├── index.php       Four-step import wizard
│           ├── ajax_import.php AJAX chunk processor for import steps
│           └── sync_metadata.php Post-import cover-thumbnail sync tool
├── themes/                     Theme folders
│   ├── default/
│   │   ├── template.html       Bootstrap 5 base template
│   │   └── style.css           Gallery styles
│   └── classic-fansite/
│       ├── template.html       Classic fansite layout (banner, sticky nav, centred panel)
│       ├── style.css           Fully variable-driven styles with fandom colour presets
│       ├── custom.css          Optional per-site CSS overrides (loaded after style.css)
│       └── README.md           Customisation guide + theme creation walkthrough
├── ajax_hit.php                Public image view counter endpoint (fire-and-forget POST)
├── album.php                   Public album view (pagination, sort, lightbox)
├── index.php                   Public home, category browse, special views
├── migrate.php                 CLI-only schema migration runner (--dry-run, --status, --rollback)
├── config.sample.php           Template for manual config.php
└── version.php                 Version constants
```

---

## Image & Thumbnail Storage

Images and their thumbnails are stored together in the same album folder. Album folders
use **human-readable nested paths** that you define when creating the album, so your
`albums/` directory mirrors your category tree and stays navigable over FTP:

```
albums/
  Xena/
    Season1/
      1x01-SinsOfThePast/
          extant_XWP_1x01_01808.jpg       ← original
          thumb_extant_XWP_1x01_01808.jpg ← thumbnail (thumb_ prefix)
          extant_XWP_1x01_01809.jpg
          thumb_extant_XWP_1x01_01809.jpg
    Season2/
      2x01-RevelationsOfTheBirthOfANew/
          ...
  00042/       ← numeric fallback when no folder path was supplied
      photo.jpg
      thumb_photo.jpg
```

Folder path rules: letters, digits, hyphens, underscores, dots; `/` for subfolders;
no path traversal (`..`). Set once at album creation — cannot be renamed afterwards
without moving files on disk.

This layout is compatible with Coppermine's `albums/` directory structure, making
migration straightforward — point Lumora at the same `albums/` directory and run
**Batch Add** to index everything.

---

## Features

### Public gallery
- Home page: recently updated albums, root category grid, gallery stats, and a Who Is Online strip
- Category and album browsing with selectable layout (card grid or Coppermine-style list with recursive album and image counts)
- Album view with sortable thumbnails (position, newest, oldest, most viewed, filename)
- Pagination (configurable images per page)
- Full-image lightbox via [PhotoSwipe 5](https://photoswipe.com/) (ESM, no global namespace); logged-in staff (admin, moderator, contributor) additionally see a copyable image info panel with a direct URL and ready-to-paste embed HTML snippet, with a one-click Copy HTML button
- Image resolution displayed under each thumbnail
- Hit counter for albums and images (session-throttled; image counts recorded via lightbox `change` event → `ajax_hit.php`)
- Special views: Most Viewed, Latest, Random
- **Light / dark / auto colour mode** — a ☀️/🌙/🖥️ toggle in the theme's navigation bar cycles Auto (follows the OS) → Dark → Light; the preference is applied before first paint (no flash of the wrong theme) and remembered in `localStorage`

### Admin panel
- **Dashboard** — stats cards + latest images
- **Users** — paginated staff account list (10/25/50 per page); create accounts with role selector; edit username, email, and role; reset any account's password (no current-password check); enable/disable accounts; delete accounts; guards prevent self-deletion, self-deactivation, and removal of the last active administrator; migration guard redirects to the Updates page if schema migration hasn't run yet. **Role-based page access:** admin, moderator, and contributor accounts can all log in; each admin page and its AJAX endpoints are gated on the permission the role's group holds — moderators get Categories, Albums, Images, and Tools but not Configuration, Installation, Import, Updates, or Users; contributors get Batch Add and Images, plus Albums scoped to whichever albums they've been assigned. The sidebar navigation only shows the pages the current role can access. **Groups** — a companion page (`admin/groups.php`, gated on `user_management`) lets administrators view every permission group (the three built-in system groups plus any custom ones), create new custom groups, rename any group, grant or revoke individual permissions per group, and delete unused custom groups; system groups can't be deleted, a group with active members can't be deleted until they're reassigned, and the admin group can never lose the permissions needed to reach Users/Groups or Configuration. **Contributor album assignments:** an "Assign Albums" link next to each contributor row (and a matching card on the edit-user screen) opens a filterable checkbox picker (`admin/user_albums.php`) for granting access to specific albums — gated on `manage_albums`, so moderators can manage assignments without needing the Users page itself.
- **Categories** — full parent/child hierarchy tree view (root categories at top; children indented with `└ ` connectors and depth steps; subcategory count indicator); drag-and-drop reordering and reparenting (drag a row onto another category to move it under that category; dragging into one of its own descendants is rejected); create, edit, delete; nested (parent/child); re-parents children on delete; optional cover image (ID-based, falls back to first image in category's albums)
- **Albums** — for admin/moderator: hierarchy view by default (albums grouped under their category with subcategories nested beneath their parent; uncategorized albums in a dedicated section; falls back to flat paginated table when search or category filter is active); drag-and-drop reordering within a category in hierarchy view; search albums by name (case-insensitive partial match; search preserved across pagination); paginated flat list (25/50/100 per page, session-persisted; item count summary; category filter); create, edit, delete; auto-generated folder names or custom; filesystem directory creation; empty folder removed automatically on album delete. For contributors: a flat, unpaginated list of only their assigned albums, with no New Album button, no category filter, and no per-row Delete button; they can edit an assigned album's metadata and cover image but not reassign its category. The album edit screen shows an "Assigned to: …" note (visible to admin/moderator only) when the album has contributor assignments.
- **Images** — per-album paginated image grid (24/page); search images by filename or title (scoped to an album or across all albums; cross-album results include the category › album path); edit title, sort position, and visibility; optional file replacement via multipart upload (validates type, size, image integrity; regenerates thumbnail and updates dimensions/filesize); single-image delete cleans up disk files and resets album/category cover references; bulk delete and bulk move to another album (up to 500 images per AJAX call); per-image thumbnail regeneration; **Bulk Rename** (`manage_images` only) — select images within one album and rename them by pattern (prefix, suffix, sequential numbering via `{num}`, and/or the original name via `{name}`), with a preview step that flags duplicate or colliding filenames before anything is applied; original and thumbnail files are renamed together, extensions are always preserved, and the whole batch is rolled back automatically if any file operation fails partway through
- **Batch Add** — scan `albums/{folder}/` for new images, process in 50-image AJAX chunks (handles 9000+ without timeout); album picker scoped to assigned albums for contributors
- **Configuration** — all settings in one form; theme selector; live image processor status; gallery behavior and upload limit controls
- **Config export/import** — JSON backup; import excludes `base_url` to protect other installs
- **Tools** — four maintenance operations, each scoped to all albums or a single album:
  - **File Integrity Check** — verifies both the original file and thumbnail exist on disk for every image record; runs in 500-image AJAX chunks (handles 500 000+ images); missing files listed in a results table with checkboxes; bulk-delete orphaned DB records in one click (disk files are never touched)
  - **Reload Dimensions** — re-reads pixel dimensions and file sizes from disk and updates the database; runs in 100-image AJAX chunks; useful after manual file operations or migrations
  - **Regenerate Thumbnails** — regenerates thumbnails via `lumora_generate_thumb()` for every image; runs in 20-image AJAX chunks; respects Imagick/GD availability
  - **Regenerate Missing Thumbnails** — regenerates thumbnails only for images where the thumbnail file is missing or empty, leaving existing valid thumbnails untouched; runs in 500-image AJAX chunks; significantly faster than a full regeneration when only a small fraction of thumbnails are absent (e.g. after manual file additions or a partial batch-add failure)
- **Account** — update username and email address; change password with current-password verification; **Forgot password** link on the login page generates a secure reset link written to `lumora_recovery.txt` in the gallery root (1-hour single-use token, email attempted if address is set)
- **Installation Settings** — update the base URL and other installation-specific settings after moving to a new domain, subdirectory, or server; nine-item health check (database connectivity, albums and cache directories, config.php, site URL, PHP version, image processor, PDO MySQL, ZipArchive) runnable on demand via AJAX; configuration change log with full audit trail (last 15 entries from `{PREFIX}config_changes`); JSON environment snapshot export; CSRF and password re-authentication required for all setting changes; Migration Helpers accordion with guided steps for domain changes, subdirectory changes, HTTPS enablement, and server migrations; **System Information** panel showing the detected web server (with a LiteSpeed/OpenLiteSpeed badge) and detected HTTP/2, HTTP/3, Brotli, and active-LSCache capabilities; a **Static Asset Cache Headers** card that installs (or removes) a clearly-marked, additive `mod_expires`/`mod_headers` block in the site's root `.htaccess` for long-lived image/thumbnail/font caching and shorter-lived CSS/JS caching — read identically by Apache and LiteSpeed/OpenLiteSpeed, inert on nginx/Caddy
- **LiteSpeed Cache (LSCache) purge** — an opt-in toggle (Admin → Configuration → Performance, off by default) that sends an `X-LiteSpeed-Purge` header after admin content changes (image/album/category/theme/configuration changes) so LSCache never serves a stale page; a complete no-op unless both the toggle is on and the current server is detected as LiteSpeed/OpenLiteSpeed
- **Updates** — a consolidated Installed/Status/Source metadata grid (installed version, database schema version, installed filesystem path, release channel, provider/repository, link to all releases); a Latest Release card with a stability badge (Stable/Prerelease), Markdown-rendered release notes, a checksum verification bar, and a **Re-download release** action that downloads and SHA-256-verifies the archive independently of a full install; when a new release is available, an **⬆ Install Update** card with a 10-stage progress UI (`preflight → download → verify → backup → maintenance → extract → validate → replace → migrate → cleanup`) — each stage is a separate AJAX call so progress is reported in real time, with an automatic **update backup** (database + `config.php` only) before any file replacement, one-click Rollback on failure, and an Abort option for stuck sessions; a separate **Full Backups** panel for on-demand full-installation ZIP snapshots (code + config + a database dump, excluding `albums/` and `cache/`) with create/restore/delete actions, keeping up to 3; a **System Status** table (PHP version, ZIP/cURL extensions, file permissions, disk space, temp directory writability) as a live pass/fail check independent of update status; **Update Settings** for the release channel (stable/prerelease), automatic-check toggle and frequency (daily/weekly), and an optional GitHub token for a higher API rate limit; update history table shows the last 10 attempts (installs, rollbacks, and backup restores); custom themes and plugins are preserved by default during an install (`update_preserve_themes` / `update_preserve_plugins` config keys); after replacing application files, any file a previous version installed that the new release no longer ships is automatically removed, tracked via a small manifest (`cache/.updates/file-manifest.json`) — this never touches `albums/`, `config.php`, `cache/`, or (when preserved) `themes/`/`plugins/`, regardless of manifest history

### Themes
Themes live in `themes/{name}/` and require only `template.html`. The active theme is selected in Admin → Configuration. Multiple themes can be installed simultaneously.

Building a new theme? See [`docs/THEME_DEVELOPMENT.md`](docs/THEME_DEVELOPMENT.md) for the full theming guide, including how the built-in dark mode system works and an accessibility checklist to run through before shipping a theme.

Both bundled themes (and all four custom themes) fully support dark mode via a
shared `--lum-*`/`--fs-*` CSS custom-property layer and Bootstrap 5.3's
`data-bs-theme` attribute. Logged-in staff additionally get their colour-mode
preference synced to their account (`{PREFIX}users.color_mode`) so it follows
them across devices; a **Default Colour Mode** setting in Admin →
Configuration sets the site-wide fallback for first-time visitors.

Two themes are included:

- **`default`** — Bootstrap 5 responsive layout with a dark navbar. Clean and neutral; good starting point for any site.
- **`classic-fansite`** — Traditional fixed-width fansite layout (2000s–2010s fandom era). Features a full-bleed banner image area, sticky navigation bar, and a centred content panel against a dark outer background. Every design decision is exposed as a CSS custom property, with five ready-made fandom colour presets (dark red/fantasy, ocean blue/sci-fi, forest green/nature, rose gold/pop, midnight gold/historical) documented in `themes/classic-fansite/README.md`. The same file covers how to create a new derived theme in four steps.

A theme can optionally declare itself via a CSS header comment at the top of its primary stylesheet (the first `{THEME_URL}*.css` link found in `template.html`), in the same spirit as WordPress theme headers:

```css
/*
 * Theme Name: My Theme
 * Author: Your Name
 * Design URI: https://example.com
 */
```

Recognized fields are `Theme Name`, `Author`, and `Design URI`. When present, they're shown as the theme's display name in the Active Theme dropdown and in a reference table in Admin → Configuration → Appearance. The header is entirely optional — themes without one still work normally, falling back to the folder name.

**Admin-only theme preview.** A logged-in administrator can append `?theme=folder-name` to any public gallery URL — the home page, a category page, or an album page — to render that theme for the current request only; the site's configured theme is never changed and no other visitor is affected. An unknown or invalid folder name falls back to the real active theme with an admin-only notice explaining why; a valid preview shows a small admin-only banner as a reminder it's temporary. The preview follows you as you keep browsing: every nav link, breadcrumb, category/album card, pagination link, and sort control generated while a preview is active carries the `theme` parameter forward, so navigating between categories and albums stays on the previewed theme for the whole session instead of reverting after one click. Admin → Configuration's Themes table includes a **Preview** column that opens this URL in a new tab for each installed theme.

### Thumbnail generation
- **Imagick PHP extension** preferred — auto-detected, no path configuration needed. Uses IM7 Q16-HDRI for high-quality Lanczos resizing, EXIF auto-orientation, and metadata stripping.
- **GD library** fallback if the Imagick extension is not loaded.
- Configurable max width and height (aspect ratio preserved, never upscaled).
- Configurable JPEG/WebP quality (`thumb_quality`).
- Thumbnails generated on Batch Add; never regenerated if `thumb_*` already exists.

---

## Configuration

All settings are managed in **Admin → Configuration**. Key options:

| Setting | Default | Description |
|---|---|---|
| `gallery_name` | Lumora Gallery | Displayed in page titles and nav |
| `base_url` | Auto-detected | Public URL with trailing slash |
| `theme` | default | Active theme folder name |
| `thumb_width` / `thumb_height` | 250 | Max thumbnail dimensions (px) |
| `per_page` | 48 | Thumbnails per page |
| `category_layout` | grid | Category browser layout: `grid` (card grid) or `list` (row-based with recursive album and image counts) |
| `allowed_extensions` | jpg,jpeg,png,gif,webp | Accepted image types for Batch Add |
| `timezone` | UTC | PHP timezone identifier (e.g. `Europe/Helsinki`); applied at bootstrap |
| `thumb_quality` | 85 | JPEG/WebP thumbnail quality 1–100 |
| `max_upload_size_mb` | 0 | Max file size in MB for Batch Add; 0 = unlimited |
| `max_image_width` | 0 | Max width for stored originals in px; 0 = no limit |
| `max_image_height` | 0 | Max height for stored originals in px; 0 = no limit |
| `count_album_views` | 1 | Toggle album hit counter (`0` = off, `1` = on) |
| `log_mode` | off | Logging: `off`, `errors` (PHP error log), or `all` (error log + DB) |
| `gallery_offline` | 0 | Maintenance mode — shows HTTP 503 to non-admins when `1` |
| `latest_albums_count` | 5 | Number of recently updated albums shown on the home page; `0` = hide section |
| `latest_images_count` | 8 | Number of images shown in the "Latest Additions" grid on the home page; `0` = hide section |
| `who_is_online_duration` | 5 | Visitor window in minutes for the Who Is Online strip (1–60); `0` = disable tracking |
| `show_powered_by` | 1 | Show a "Powered by Lumora Gallery" credit in the footer (`0` = hidden); uses `{POWERED_BY}` theme token |
| `default_color_mode` | auto | Site-wide fallback colour mode (`auto` / `light` / `dark`) for visitors with no stored preference |
| `install_ping_enabled` | 0 | Opt-in anonymous install ping (`0` = off by default, `1` = on) — see **Privacy: Anonymous Install Ping** below |
| `litespeed_cache_purge` | 0 | Opt-in LiteSpeed Cache (LSCache) purge-header integration (`0` = off by default, `1` = on) — no effect unless the server is detected as LiteSpeed/OpenLiteSpeed; see **LiteSpeed Support** below |

Settings are stored in the `{PREFIX}config` database table and cached by the `LumoraConfig` static class per request.

The image processor (Imagick or GD) is detected automatically at runtime and shown as a read-only status in Admin → Configuration. No path or binary configuration is required.

---

## Coppermine Migration

Because Lumora uses the same `albums/{folder}/thumb_*` structure as Coppermine,
migration is a scan-and-index operation — no file conversion needed:

1. Copy (or symlink) your existing Coppermine `albums/` directory into Lumora's root.
2. Create matching categories and albums in Lumora Admin, setting each album's **Folder Path** to the same relative path Coppermine uses
   (e.g. `Xena/Season1/1x01-SinsOfThePast`) — the New Album form suggests folders it finds under `albums/` that aren't yet claimed by any album, so an already-copied Coppermine folder should appear as you type.
3. Run **Batch Add** on each album — Lumora indexes the images without touching the files.

The **Coppermine Importer** plugin (`plugins/coppermine-importer/`) automates this entirely — it connects to the Coppermine database directly and imports categories, albums, and image metadata in keyset-paginated AJAX chunks without touching any files. Navigate to **Admin → Import** to run it.

- **Plugin v1.3.0+** — the credentials form includes an **Auto-Detect** panel: supply the filesystem path to your Coppermine installation and the importer reads `include/config.inc.php` to fill in all five database fields automatically. If multiple Coppermine installations are found under the supplied path, a selection list is shown.
- **Plugin v1.1.0+** — album and category cover-thumbnail selections are preserved automatically as part of the import wizard itself.
- The **Metadata Sync** tool (`Admin → Import → Metadata Sync`) remains available as a fallback for re-applying cover assignments after a stopped import or for galleries imported before v1.1.0.

---

## Privacy: Anonymous Install Ping

Lumora includes an opt-in, off-by-default mechanism to anonymously count active installs. This provides the developer with a rough, privacy-respecting understanding of real-world adoption, including which PHP versions are still in active use. The information may help guide future compatibility decisions, such as determining when support for older PHP versions can be safely phased out, while preserving the privacy expectations of Lumora’s self-hosted, fansite-oriented community.

- **Off by default.** Nothing is ever sent unless you explicitly enable **Anonymous Install Ping** in Admin → Configuration → Privacy.
- **What is sent, and nothing else:**
  - A randomly generated install UUID, created the first time the feature is enabled. It has no relationship to your domain, gallery contents, admin account, or any other data — there is no way to trace it back to your specific site from the ping alone.
  - Your installed Lumora version.
  - Your PHP version.
- **What is never sent:** your domain or site name, admin email, gallery contents, visitor data, IP addresses, or anything else.
- **Cadence.** The ping fires once immediately when you enable the feature, then at most roughly once a month afterward. It never fires on every page load.
- **Independence from the update checker.** This uses a completely separate request from the version-update check described below (`UpdateService`) — enabling or disabling one never affects the other.
- **Failure handling.** If the request fails for any reason (network issue, unreachable endpoint, etc.), it fails silently. It never shows an error and never blocks any admin action.
- **Disabling it** at any time in Admin → Configuration simply stops all future pings; the previously generated UUID is left in place (but unused) so re-enabling later doesn't change your install's identifier.

---

## LiteSpeed Support

Lumora automatically detects LiteSpeed and OpenLiteSpeed and can take advantage of a couple of server-specific optimizations, while remaining fully functional on Apache, nginx, Caddy, or any other web server — none of this requires LiteSpeed, and nothing here is required for normal operation.

- **Detection** (Admin → Installation → System Information) — reports the detected web server (with a LiteSpeed/OpenLiteSpeed badge when applicable) and, where detectable from PHP alone, HTTP/2, HTTP/3, Brotli, and an active LiteSpeed Cache (LSCache). Brotli is a heuristic (LiteSpeed detected + the requesting client advertises `br` support), not a guarantee — verify your server's actual compression modules if it matters for your setup.
- **Static asset cache headers** (Admin → Installation) — images, thumbnails, and theme CSS/JS are served directly by the web server rather than through PHP, so their Cache-Control/Expires headers have to come from the web server itself. A one-click action installs a clearly-marked, additive `mod_expires`/`mod_headers` block into the site's root `.htaccess` (long-lived immutable caching for images/thumbnails/fonts, a shorter window for CSS/JS) — read identically by Apache and LiteSpeed/OpenLiteSpeed. It never touches any other content already in your `.htaccess`, and can be removed with the same one-click control. Has no effect on nginx/Caddy, which don't read `.htaccess` files at all.
- **LiteSpeed Cache purge** (Admin → Configuration → Performance) — an opt-in, off-by-default toggle. When enabled, Lumora sends an `X-LiteSpeed-Purge: *` header after admin content changes (image uploads/edits/deletes, album and category changes, theme changes, and configuration saves — anything that reaches an admin page via POST) so LSCache never serves a stale page after you make a change. It's a complete no-op unless both the toggle is on and the current server is detected as LiteSpeed/OpenLiteSpeed, so it's safe to leave on regardless of your hosting.
- **Lazy public-page sessions** — a first-time anonymous visitor to a public page (home, category, album, latest/most-viewed/random) gets no PHP session at all: no `Set-Cookie`, no session cache-limiter headers. PHP's session start otherwise sends `Cache-Control: no-store` on every response, which would make page-level caching (LiteSpeed Cache or otherwise) impossible regardless of the purge toggle above. A session still starts immediately for the admin panel and for any request that already carries one (an existing login, or an in-progress album/image view hit-count throttle); public code that needs to write to the session on demand (CSRF token generation, hit-count throttling, remember-me auto-login) starts one itself at that point via `lumora_ensure_session()`. Note that a *cached* page response never re-executes PHP, so hit counters and "Who Is Online" naturally undercount for visits served from cache within whatever TTL your LSCache/server-level cache rules use — an inherent tradeoff of page caching, not a bug.
- **Admin panel cache exclusion** — every admin request starts a session (unaffected by the laziness above) and therefore already carries `Set-Cookie`, which LSCache's default behavior already excludes from caching. `admin/.htaccess` adds an explicit `CacheLookup off` (LiteSpeed-only, silently ignored elsewhere) as defense-in-depth beyond that implicit exclusion, in case a front-end proxy strips cookies or LSCache is ever configured to cache private responses.
- **Page caching** (Admin → Installation → "LiteSpeed Page Caching (Advanced)") — an opt-in, off-by-default `CacheEnable public /` block with a configurable TTL, for hosts that don't expose LiteSpeed's own cache configuration to the site admin (some DirectAdmin installs and other shared-hosting LiteSpeed setups have no WebAdmin console access). Everything above this point removes Lumora's own blockers to caching; this is the piece that actually turns LSCache page caching on where server-level configuration isn't otherwise available. LiteSpeed-only, a no-op block elsewhere. Installable/removable one-click, independent of the static asset cache-headers block (both live in the same root `.htaccess`, in their own separately-managed sections). Carries the same hit-counter/"Who Is Online" undercounting tradeoff noted above — choose a TTL that balances cache benefit against how much that undercounting matters to you.

---

## Security Notes

- `config.php` contains database credentials — ensure your web server does not serve it as plain text. Adding an `.htaccess` rule to deny direct access is recommended.
- **Unique table prefix** — the installer auto-generates a random `lum_XXXXXXXX_` prefix for every new installation, making database table names harder to guess in shared-database environments. Advanced users can override the prefix during installation. Existing installations using `lum_` or any other prefix are entirely unaffected.
- The `install/` directory is automatically removed by the installer after a successful fresh install, and by the built-in updater after a successful upgrade. Verify it is gone after either operation; if not, delete it manually via FTP or your hosting control panel.
- All POST actions use CSRF tokens. Admin routes require an authenticated session.
- **Login rate limiting** — the admin login page tracks failed attempts per IP address in `cache/.login_ratelimit.json`. After 5 failures within a 15-minute window the form is locked, a 2-second server-side delay is enforced, and a lockout message is shown. Individual failures each add a 1-second delay. The IP record is cleared after a successful login.
- Passwords are hashed with `password_hash()` / `PASSWORD_DEFAULT`.
- The **Remember Me** cookie uses a split-token scheme: the validator is stored as
  `SHA-256(validator)` in the database only; the plain value travels only in the
  browser cookie. Tokens are rotated on every use and all tokens for a user are
  revoked on explicit logout.
- **Password recovery** uses the same split-token scheme. The reset URL is written
  to `lumora_recovery.txt` in the gallery root — protect or delete this file after
  use. The token expires after 1 hour and is single-use.

---

## Development

| | |
|---|---|
| Developer | Ariane |
| Repository | <https://coding.unloved-heart.net/scripts/lumoragallery> |

---

## Changelog

See [`docs/CHANGELOG.md`](docs/CHANGELOG.md).

---

## License

Lumora Gallery is released under the [GNU General Public License v3.0](LICENSE).  
You are free to use, modify, and distribute it under the terms of that license.
