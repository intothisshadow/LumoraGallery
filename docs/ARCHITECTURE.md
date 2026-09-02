# Lumora Gallery — Architecture Reference

Internal reference for anyone modifying Lumora Gallery's own PHP/CSS —
directory layout, the service layer, the plugin hook system, the updater's
internal pipeline, and the full raw configuration key reference. If you're
installing, configuring, or running a gallery, you don't need any of this —
see [`README.md`](../README.md) instead.

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
│   ├── ajax_plugin_delete.php   AJAX endpoint for single/bulk disabled-plugin deletion
│   ├── ajax_missing_thumbs.php  AJAX endpoint for missing-thumbnail regeneration (Tool 4)
│   ├── ajax_integrity.php      AJAX endpoint for integrity scan chunks
│   ├── ajax_integrity_delete.php  AJAX endpoint for deleting orphaned records
│   ├── ajax_dimensions.php     AJAX endpoint for reload-dimensions chunks
│   ├── ajax_thumbs.php         AJAX endpoint for thumbnail regeneration chunks
│   ├── ajax_update_check.php   AJAX endpoint for forced update check
│   ├── ajax_update_perform.php AJAX endpoint for multi-stage in-dashboard update (run_stage / rollback / abort)
│   ├── ajax_update_upload.php  AJAX endpoint accepting an administrator-uploaded release ZIP, staging it into the same update pipeline as a GitHub-fetched release
│   ├── ajax_run_migrations.php AJAX endpoint for running schema migrations
│   ├── ajax_installation_health.php  AJAX endpoint for installation health check (9 system checks)
│   ├── ajax_reorder_categories.php  AJAX endpoint for drag-and-drop category reorder/reparent
│   ├── ajax_reorder_albums.php AJAX endpoint for drag-and-drop album reorder within a category
│   ├── ajax_list_folders.php   AJAX endpoint listing unclaimed server folders under albums/ (New Album Folder Path suggestions)
│   ├── appearance.php          Theme card grid — activate, preview, install/update/delete from ZIP; colour mode, category layout, "Powered by" credit
│   ├── categories.php          Category management
│   ├── config.php              Gallery settings, export/import
│   ├── dashboard.php           Stats overview
│   ├── images.php              Image management (edit, delete, move, bulk actions)
│   ├── installation.php        Installation Settings — update base URL after domain/server migration; nine-item health check; configuration change log; web server/capability detection; static asset cache header installer
│   ├── migrate.php             Migration hub — discovers and launches importer plugins
│   ├── plugins.php             Feature plugin manager — enable/disable/delete plugins discovered under plugins/
│   ├── tools.php               Admin tools (File Integrity Check, Reload Dimensions, Regenerate Thumbnails, Regenerate Missing Thumbnails)
│   ├── update.php              Updates page — consolidated status/source metadata grid, interactive Latest Release card (checksum bar, Markdown release notes, re-download), Full Backups panel (create/restore/delete ZIP snapshots), System Status checks, Update Settings (channel/frequency/token), in-dashboard updater (10-stage AJAX workflow with automatic update backup + rollback) sourced from either a GitHub release or an administrator-uploaded ZIP, schema migrations, update history
│   ├── forgot_password.php  Password recovery — emails a reset link (no mail-free fallback; see reset-password.php)
│   ├── reset_password.php   Password reset — validates token, sets new password
│   ├── delete_reset_script.php  One-click authenticated delete for a still-present reset-password.php
│   ├── login.php / logout.php
│   └── admin.css
├── albums/                     Image storage — original + thumb_* thumbnails
├── covers/                     Admin-uploaded category/album cover images — original + thumb_* thumbnails
│   ├── categories/
│   └── albums/
├── docs/                       CHANGELOG.md, HISTORY.md, THEME_DEVELOPMENT.md, ARCHITECTURE.md (this file)
├── include/                    Core PHP includes
│   ├── services/               Static service classes (business logic layer)
│   │   ├── LumoraConfig.php    Config cache — load(), get(), set()
│   │   ├── GalleryService.php  Category, album, image, stats, visitor-tracking queries
│   │   ├── ThumbnailService.php Thumbnail generation, resizing, metadata, batch-add, category/album cover upload validation and storage
│   │   ├── ThemeRenderer.php   All HTML output: pages, grids, breadcrumbs, lightbox
│   │   ├── ThemeService.php    Theme card grid metadata, screenshot discovery, install/update/delete from ZIP
│   │   ├── MigrationService.php Import status tracking, plugin discovery, event logging
│   │   ├── UpdateService.php   Release check via the configured provider (GitHub Releases by default), version comparison, cache TTL follows the check-frequency setting (24h/7d)
│   │   ├── SchemaService.php   Schema migration engine — discover, run, rollback PHP class migrations
│   │   ├── AbstractUpdateProvider.php  Provider interface — fetchMetadata(), buildArchiveUrl(), factory
│   │   ├── GitHubUpdateProvider.php    GitHub Releases API provider — metadata, SHA-256, curated ZIP asset URL (falls back to raw archive URL)
│   │   ├── UpdaterService.php  Update orchestrator — 10-stage workflow, lock file, backup, rollback, standalone download+verify, system status checks; accepts a release from either a GitHub-fetched archive or an administrator-uploaded ZIP (acquireLockFromUpload())
│   │   ├── BackupService.php   Full-installation ZIP backups (code + config + DB dump, excluding albums/cache) — create/restore/delete, up to 3 retained
│   │   ├── InstallationService.php  Installation settings detection, migration helpers, health checks, audit logging
│   │   ├── ServerEnvironmentService.php  Web server detection (LiteSpeed/OpenLiteSpeed/Apache/nginx/Caddy) and HTTP/2, HTTP/3, Brotli, active-LSCache capability flags
│   │   ├── CacheHeaderService.php  Managed .htaccess cache-control block for static assets (Apache/LiteSpeed-compatible) and opt-in LiteSpeed Cache purge-header integration
│   │   ├── UserService.php     User CRUD, role constants, permission framework (delegates to GroupService), getRecoveryAccounts() for password-recovery target lookup
│   │   ├── RateLimitService.php  Shared per-IP failure lockout (login.php + reset-password.php)
│   │   ├── GroupService.php    Permission groups — CRUD, permission catalog (ALL_PERMISSIONS), system-group safeguards
│   │   ├── AlbumAssignmentService.php  Per-contributor album assignments — assign/unassign/set, userCanAccessAlbum() access check, cascade cleanup
│   │   ├── InstallPingService.php  Opt-in anonymous install ping — UUID generation, ~monthly cadence, dedicated endpoint separate from UpdateService
│   │   ├── HookService.php     Minimal action/filter registry — the extension points feature plugins hook into
│   │   └── PluginService.php   Feature-plugin discovery, enable/disable state, delete (disabled plugins only), loads enabled plugins' bootstrap.php each request
│   ├── migrations/             Versioned PHP schema migration classes
│   │   ├── AbstractMigration.php              Base class — up(), down(), tableExists(), columnExists(), indexExists()
│   │   ├── Migration0001_CreateMigrationsTable.php  Self-bootstrapping first migration — creates {PREFIX}migrations table
│   │   ├── Migration0002_CreateConfigChangesTable.php  Creates {PREFIX}config_changes audit table for installation setting changes
│   │   ├── Migration0003_UpdateUsersTableForRoles.php  Adds is_active column; updates role ENUM to admin/moderator/contributor
│   │   ├── Migration0004_AddColorModeToUsers.php  Adds color_mode column (auto/light/dark) to users
│   │   ├── Migration0005_CreateAlbumAssignmentsTable.php  Creates {PREFIX}album_assignments table for contributor album access
│   │   ├── Migration0006_AddUploadedByToImages.php  Adds uploaded_by column to images for per-image ownership enforcement
│   │   ├── Migration0007_CreateGroupsTables.php  Creates {PREFIX}groups / {PREFIX}group_permissions tables; widens users.role from ENUM to varchar
│   │   └── Migration0008_AddCoverImageToCategoriesAndAlbums.php  Adds cover_image column to categories/albums for admin-uploaded dedicated covers
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
│   ├── coppermine-importer/    Official Coppermine → Lumora migration plugin (type: importer)
│   │   ├── CoppermineImporter.php  Core importer class (categories, albums, images, cover sync)
│   │   ├── plugin.json         Plugin manifest (consumed by admin/migrate.php)
│   │   ├── version.php         Single source of truth for plugin version
│   │   ├── README.md           Plugin documentation and Metadata Sync tool reference
│   │   └── admin/              Plugin admin pages
│   │       ├── index.php       Four-step import wizard
│   │       ├── ajax_import.php AJAX chunk processor for import steps
│   │       └── sync_metadata.php Post-import cover-thumbnail sync tool
│   ├── lumora-visitor-stats/   Jetpack-style traffic overview (type: feature — hooks into core via HookService; disabled by default)
│   │   ├── VisitorStatsService.php  Pageview logging + summary/trend/top-content/referrer queries, own {PREFIX}stats_hits table
│   │   ├── plugin.json         Plugin manifest (consumed by admin/plugins.php)
│   │   ├── version.php         Plugin version + pageview retention constant
│   │   ├── activate.php        Creates {PREFIX}stats_hits — runs once, only when the plugin is enabled
│   │   ├── bootstrap.php       Registers this plugin's hooks — runs on every request while enabled
│   │   ├── README.md           Plugin documentation
│   │   └── admin/
│   │       └── stats.php       Visitor Stats admin page (trend chart, top images/albums, top referrers, who's online)
│   └── lumora-press-shortcodes/ Ready-to-copy [lumora_gallery_album] shortcode display (type: feature — hooks into core via HookService; disabled by default)
│       ├── LumoraPressShortcodesService.php  Stateless shortcode text/HTML builders — no database access
│       ├── plugin.json         Plugin manifest (consumed by admin/plugins.php)
│       ├── version.php         Plugin version constant
│       ├── bootstrap.php       Registers this plugin's hooks — runs on every request while enabled
│       └── README.md           Plugin documentation
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
├── reset-password.php          Unauthenticated emergency admin password reset (same trust model as install/index.php); self-deletes after use
├── config.sample.php           Template for manual config.php
└── version.php                 Version constants
```

---

## Service Layer

Business logic, database queries, HTML rendering, and image processing live
in focused static service classes under `include/services/` — see the table
above for what each one owns. New business logic is added there, not as a
free function; see the file header comments in each service and
`CLAUDE.md`'s "Service Layer" section for the conventions new code follows.

---

## Plugin System

Lumora has two kinds of plugin, both living under `plugins/{id}/plugin.json`:

- **Importer plugins** (`"type": "importer"`, e.g. `coppermine-importer`) — discovered by
  `admin/migrate.php` and run on demand.
- **Feature plugins** (`"type": "feature"`, e.g. `lumora-visitor-stats`) — extend core
  behaviour by registering hooks, without patching any core file. Managed from
  **Admin → Plugins**; every feature plugin ships **disabled by default** (opt-in).

Feature plugins hook into two small core services:

- **`HookService`** — a minimal action/filter registry. `HookService::doAction($hook, ...$args)`
  fires every callback registered on `$hook`; `HookService::applyFilters($hook, $value, ...$args)`
  passes `$value` through every registered callback and returns the (possibly modified) result.
- **`PluginService`** — discovers plugin manifests, tracks each feature plugin's
  enabled/disabled state (stored in `{PREFIX}config`, no dedicated table), and — once per
  request, after config is loaded — requires the `bootstrap.php` of every enabled, compatible
  feature plugin so it can register its hooks. `PluginService::deletePlugin()` permanently
  removes a disabled plugin's directory from disk (refuses to touch an enabled plugin, and
  verifies the target directory resolves to a direct child of `LUMORA_PLUGINS_PATH` first).

A feature plugin's manifest may declare, relative to its own folder:

| Key | Runs | Purpose |
|-----|------|---------|
| `bootstrap` | Every request, while enabled | Registers hooks only — must never write to the database itself |
| `activate` | Once, the moment the plugin is enabled | Creates the plugin's own database table(s) |
| `deactivate` | Once, the moment the plugin is disabled | Optional cleanup that stops short of dropping data |

Current extension points: `lumora_pageview` (action — fires on every public pageview with
`(string $type, int $item_id)`), `admin_nav_sections` (filter — a plugin can add its own
sidebar item to the admin nav), `admin_dashboard_widgets_html` (filter — a plugin can
append its own widget HTML to the Dashboard), `admin_album_edit_extra_fields` /
`admin_image_edit_extra_fields` (filters — a plugin can append extra HTML to the Album/Image
admin edit forms, passed the current album/image row), and `public_album_info_html` /
`public_image_shortcode` (filters — the public-facing equivalents, on the album page and in
the image lightbox's info panel respectively, both logged-in-users-only).

---

## Importer Plugins

The other plugin type (`"type": "importer"`, e.g. `coppermine-importer`) migrates data
from another gallery system into Lumora. Unlike feature plugins, importers have no
enable/disable state and run entirely on demand from **Admin → Import**
(`admin/migrate.php`), which discovers every importer plugin's manifest automatically.

To build a new importer plugin:

1. Create `plugins/{your-importer}/plugin.json` with `"type": "importer"`.
2. Set `"admin_url"` to your plugin's entry-point PHP file path.
3. Set `"source"` to a unique identifier string.
4. Implement your import logic; use `MigrationService::saveMigrationStatus()` and
   `MigrationService::logEvent()` to record results, so the migration hub can show
   them.

Importer plugins share two core tables — created by the Lumora installer, not by any
individual importer plugin: `{PREFIX}migration_status` (one row per source, tracking
counts and the last-imported timestamp) and `{PREFIX}migration_log` (a per-source event
log). See `install/schema.sql` for their exact shape.

**Plugin versioning convention** (used by `coppermine-importer` and expected of any new
importer plugin): a single `LUMORA_{X}_VERSION` constant in the plugin's own
`version.php` is the source of truth, referenced throughout that plugin's code for
migration-status records, cache-busting query strings, and compatibility checks against
its own `LUMORA_{X}_MIN_LUMORA` constant. `plugin.json`'s `"version"` field must be kept
in sync with it by hand when releasing a new plugin version — nothing enforces this
automatically.

---

## Update System Internals

The in-dashboard updater (`UpdaterService`, driven from **Admin → Updates**) runs as a
10-stage AJAX workflow, each stage its own request so progress can be reported in real time:

```
preflight → download → verify → backup → maintenance → extract → validate → replace → migrate → cleanup
```

- Accepts a release from either a GitHub-fetched archive (`GitHubUpdateProvider`) or an
  administrator-uploaded ZIP (`UpdaterService::acquireLockFromUpload()`), feeding both into
  the same pipeline from `verify` onward.
- An automatic **update backup** (database + `config.php` only) runs before any file
  replacement; one-click rollback restores it on failure.
- After `replace`, any file a previous version installed that the new release no longer ships
  is automatically removed, tracked via `cache/.updates/file-manifest.json` — this never
  touches `albums/`, `covers/`, `config.php`, `cache/`, or (when preserved) `themes/`/`plugins/`.
- `update_preserve_themes` / `update_preserve_plugins` config keys control whether custom
  themes and plugins survive a version replace (on by default).

---

## Full Configuration Key Reference

Every setting exposed in **Admin → Configuration** and **Admin → Appearance**, stored in the
`{PREFIX}config` database table and cached by the `LumoraConfig` static class per request. See
[`README.md`](../README.md#configuration) for the same settings grouped by their actual admin UI
location; this table is the raw key reference for anyone editing the database directly or
building a plugin/import script.

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
| `install_ping_enabled` | 0 | Opt-in anonymous install ping (`0` = off by default, `1` = on) |
| `litespeed_cache_purge` | 0 | Opt-in LiteSpeed Cache (LSCache) purge-header integration (`0` = off by default, `1` = on) — no effect unless the server is detected as LiteSpeed/OpenLiteSpeed |

The image processor (Imagick or GD) is detected automatically at runtime and shown as a
read-only status in Admin → Configuration — no path or binary configuration is required.
