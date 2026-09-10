# Lumora Gallery

**Lumora Gallery** is a modern PHP image gallery born from my own needs as a fan — specifically, wanting a clean, fast replacement for classic Coppermine Gallery that could keep up with massive fan archives *and* look great on modern devices.

Built from the ground up for fansites with huge collections, it combines a sleek, fully responsive layout with the heavy-lifting power needed for serious archiving — tested against scenarios with 9,000+ images per album and 500,000+ total images.

---

## Quick Start

1. Check the [requirements](#requirements) below.
2. Upload the `Lumora/` folder to your web server.
3. Open `/install/` in your browser and follow the three-step wizard.
4. Log in at `admin/` with the administrator account you just created.
5. Create your categories and albums — or [migrate an existing Coppermine gallery](#migrating-from-coppermine).
6. Upload images via FTP and run **Batch Add**, or upload a cover image directly from the category/album edit form.
7. Pick a theme under **Admin → Appearance**.

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

## Migrating from Coppermine

Because Lumora uses the same `albums/{folder}/thumb_*` structure as Coppermine, migration is a scan-and-index operation — **no file conversion needed**:

1. Copy (or symlink) your existing Coppermine `albums/` directory into Lumora's root.
2. Create matching categories and albums in Lumora Admin, setting each album's **Folder Path** to the same relative path Coppermine uses (e.g. `Xena/Season1/1x01-SinsOfThePast`) — the New Album form suggests folders it finds under `albums/` that aren't yet claimed by any album, so an already-copied Coppermine folder should appear as you type.
3. Run **Batch Add** on each album — Lumora indexes the images without touching the files.

The **Coppermine Importer** plugin (`plugins/coppermine-importer/`) automates this entirely — it connects to the Coppermine database directly and imports categories, albums, and image metadata in keyset-paginated AJAX chunks without touching any files. Navigate to **Admin → Import** to run it.

- **Auto-Detect** — the credentials form includes an Auto-Detect panel: supply the filesystem path to your Coppermine installation and the importer reads `include/config.inc.php` to fill in all five database fields automatically. If multiple Coppermine installations are found under the supplied path, a selection list is shown.
- Album and category cover-thumbnail selections are preserved automatically as part of the import wizard.
- The **Metadata Sync** tool (`Admin → Import → Metadata Sync`) remains available as a fallback for re-applying cover assignments after a stopped import or for galleries imported before that behaviour existed.

---

## Image & Thumbnail Storage

Images and their thumbnails are stored together in the same album folder. Album folders use **human-readable nested paths** that you define when creating the album, so your `albums/` directory mirrors your category tree and stays navigable over FTP:

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

Folder path rules: letters, digits, hyphens, underscores, dots; `/` for subfolders; no path traversal (`..`). Set once at album creation — cannot be renamed afterwards without moving files on disk.

A dedicated cover image uploaded for a category or album (see [Administration](#administration) above) is stored separately under `covers/categories/` or `covers/albums/`, so it never mixes in with an album's own images.

---

## Features

### Public gallery
- Home page: recently updated albums, root category grid, gallery stats, and a Who Is Online strip
- Category and album browsing with selectable layout (card grid or Coppermine-style list with recursive album and image counts)
- Album view with sortable thumbnails (position, newest, oldest, most viewed, filename)
- Pagination (configurable images per page)
- Full-image lightbox via [PhotoSwipe 5](https://photoswipe.com/); logged-in staff additionally see a copyable image info panel with a direct URL and ready-to-paste embed HTML snippet
- Image resolution displayed under each thumbnail
- Hit counter for albums and images (session-throttled)
- Special views: Most Viewed, Latest, Random
- **Light / dark / auto colour mode** — a ☀️/🌙/🖥️ toggle in the theme's navigation bar cycles Auto (follows the OS) → Dark → Light; applied before first paint and remembered across visits

### Administration
- **Dashboard** — stats overview, plus a widget from any enabled plugin (e.g. Visitor Stats' "Last 7 Days" summary)
- **Categories & Albums** — nested category tree with drag-and-drop reordering and reparenting (with touch-friendly Up/Down buttons as a mobile fallback); albums support custom or auto-generated folder paths, public/private visibility, and an optional cover image — upload one directly, or pick an existing gallery image, with the album/image edit screens both able to set it
- **Images** — per-album grid with filename/title search, edit title/position/visibility, replace the file in place, bulk delete/move between albums, per-image thumbnail regeneration, and pattern-based **Bulk Rename** with a duplicate-collision preview step
- **Batch Add** — scans an album's folder for new images already uploaded via FTP and indexes them in chunks (handles 9,000+ images per album without timing out)
- **Users & Groups** — staff accounts with role-based permissions (Admin, Moderator, and Contributor out of the box); create custom permission groups with any combination of permissions; contributors can be scoped to specific assigned albums
- **Appearance** — pick, preview, install, and update themes from the admin panel — see [Themes](#themes) below
- **Configuration** — all gallery settings in one place — see [Configuration](#configuration) below
- **Tools** — maintenance operations, each scoped to all albums or a single one: verify every image's file and thumbnail actually exist on disk, refresh stored dimensions/file sizes from disk, and regenerate thumbnails (all or missing-only)
- **Installation Settings** — update the site's base URL after moving to a new domain, subdirectory, or server, with a nine-item health check and guided migration steps
- **Updates** — check for and install new releases directly from the admin panel, sourced from either GitHub or an administrator-uploaded ZIP; downloads are SHA-256 verified, an automatic backup is taken before any file is touched, and one-click rollback is available if anything goes wrong; custom themes and plugins are preserved by default. On-demand full installation backups (code + config + database) are available separately, with up to 3 retained.
- **Plugins** — enable, disable, or permanently delete optional feature plugins — see [Plugins](#plugins) below

### Themes
Themes live in `themes/{name}/` and require only `template.html`. The active theme, and everything below, is managed from **Admin → Appearance**; multiple themes can be installed simultaneously and switched anytime.

The bundled theme (and every custom theme) fully supports light/dark mode, following the visitor's own OS preference by default. Logged-in staff get their colour-mode preference synced to their account so it follows them across devices; a **Default Colour Mode** setting sets the site-wide fallback for first-time visitors.

One theme is included:

- **`default`** — Bootstrap 5 responsive layout with a dark navbar. Clean and neutral; a good starting point for any site.

Themes can be installed or updated directly from a `.zip` upload in Admin → Appearance — no FTP needed — and every theme card has a **Preview** button: a logged-in administrator can preview any installed theme against the live gallery for their own session only, without changing what any other visitor sees, which is handy for trying out a theme (or a tweak to one) before switching everyone over to it.

Building your own theme? See [`docs/THEME_DEVELOPMENT.md`](docs/THEME_DEVELOPMENT.md) for the full guide — template tokens, the dark mode system, an accessibility checklist, and the optional theme metadata header/screenshot conventions.

### Thumbnail generation
- **Imagick PHP extension** preferred — auto-detected, no path configuration needed. Uses IM7 Q16-HDRI for high-quality Lanczos resizing, EXIF auto-orientation, and metadata stripping.
- **GD library** fallback if the Imagick extension is not loaded.
- Configurable max width/height (aspect ratio preserved, never upscaled) and JPEG/WebP quality.
- Thumbnails are generated on Batch Add and never regenerated automatically once they exist — use **Admin → Tools** to regenerate on demand.

---

## Plugins

Lumora supports optional plugins that extend the gallery without modifying any core files. Feature plugins ship **disabled by default** and are managed from **Admin → Plugins** — enable, disable, or permanently delete one (deletion is only available once a plugin is disabled, and removes its files from disk).

Two feature plugins are included:

- **Visitor Stats** (`plugins/lumora-visitor-stats/`) — a Jetpack-style traffic overview: a daily pageview trend chart, top images/albums/referrers, and a compact Dashboard widget. Filters out common bot traffic and stores only a hashed IP (never the raw address), pruned automatically after 90 days. See `plugins/lumora-visitor-stats/README.md`.
- **Lumora Press Shortcodes** (`plugins/lumora-press-shortcodes/`) — shows a ready-to-copy shortcode on each album's and image's admin/public page, for pasting into a companion [Lumora Press](https://coding.unloved-heart.net/scripts/lumorapress) site. Generates text only, with no live connection to a Lumora Press install. See `plugins/lumora-press-shortcodes/README.md`.

The Coppermine Importer described [above](#migrating-from-coppermine) is a separate, on-demand "importer" plugin type, unaffected by the enable/disable model above.

**Building your own plugin?** See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md#plugin-system) for the hook API, manifest format, and extension points.

---

## Configuration

All settings are managed in **Admin → Configuration**, grouped into the panels below, plus a couple of display settings on **Admin → Appearance**.

**Basic Information** — gallery name, description, and base URL.

**Images & Thumbnails** — thumbnail dimensions, images per page, allowed file extensions for Batch Add, and a read-only image processor status.

**Gallery Behavior** — time zone, logging mode, the album view counter toggle, a maintenance ("Gallery Offline") mode, how many albums/images appear in the home page's "recently updated"/"latest additions" sections, and the Who Is Online tracking window.

**Privacy** — the opt-in Anonymous Install Ping (see [below](#privacy-anonymous-install-ping)).

**Performance** — the opt-in LiteSpeed Cache purge integration (see [LiteSpeed Support](#litespeed-support) below).

**Upload & Image Limits** — thumbnail JPEG quality, and optional maximum file size/dimensions for images added via Batch Add (oversized originals are downscaled in place; oversized files are skipped).

**Appearance → Display Settings** — default colour mode, category browse layout (grid or list), and the "Powered by Lumora Gallery" footer credit.

**Config export/import** — back up your entire configuration as JSON, or restore it on another install (the base URL is excluded from import, so it never overwrites a different site's URL by accident).

> Advanced: every setting above is stored as a key in the `{PREFIX}config` database table. The full raw key reference — for anyone editing the database directly or writing an import script — lives in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md#full-configuration-key-reference).

---

## Privacy: Anonymous Install Ping

Lumora includes an opt-in, off-by-default mechanism to anonymously count active installs. This provides the developer with a rough, privacy-respecting understanding of real-world adoption, including which PHP versions are still in active use. The information may help guide future compatibility decisions, such as determining when support for older PHP versions can be safely phased out, while preserving the privacy expectations of Lumora's self-hosted, fansite-oriented community.

- **Off by default.** Nothing is ever sent unless you explicitly enable **Anonymous Install Ping** in Admin → Configuration → Privacy.
- **What is sent, and nothing else:**
  - A randomly generated install UUID, created the first time the feature is enabled. It has no relationship to your domain, gallery contents, admin account, or any other data — there is no way to trace it back to your specific site from the ping alone.
  - Your installed Lumora version.
  - Your PHP version.
- **What is never sent:** your domain or site name, admin email, gallery contents, visitor data, IP addresses, or anything else.
- **Cadence.** The ping fires once immediately when you enable the feature, then at most roughly once a month afterward. It never fires on every page load.
- **Independence from the update checker.** This uses a completely separate request from the version-update check — enabling or disabling one never affects the other.
- **Failure handling.** If the request fails for any reason (network issue, unreachable endpoint, etc.), it fails silently. It never shows an error and never blocks any admin action.
- **Disabling it** at any time in Admin → Configuration simply stops all future pings; the previously generated UUID is left in place (but unused) so re-enabling later doesn't change your install's identifier.

---

## Cookies

Lumora Gallery sets only two cookies, both strictly necessary — useful reference for writing your own site's privacy/cookie policy.

- **Session cookie** (`PHPSESSID` or your server's configured session cookie name). Keeps a logged-in admin session working; set only for a logged-in user, never for an anonymous gallery visitor.
- **Remember-me cookie** (`LUMORA_REMEMBER_COOKIE`), set only when a user checks "Remember me" on the login screen — never set otherwise, and cleared again on logout.

Nothing else in Lumora Gallery core sets a cookie, and there is no visitor-facing login/commenting on the public gallery pages themselves to set one for. A plugin you install may set its own — check its own documentation.

---

## Security Notes

- `config.php` contains database credentials — ensure your web server does not serve it as plain text. Adding an `.htaccess` rule to deny direct access is recommended.
- **Unique table prefix** — the installer auto-generates a random `lum_XXXXXXXX_` prefix for every new installation, making database table names harder to guess in shared-database environments. Advanced users can override the prefix during installation. Existing installations using `lum_` or any other prefix are entirely unaffected.
- The `install/` directory is automatically removed by the installer after a successful fresh install, and by the built-in updater after a successful upgrade. Verify it is gone after either operation; if not, delete it manually via FTP or your hosting control panel.
- All admin actions use CSRF tokens, and every admin route requires an authenticated session.
- **Login rate limiting** — the admin login page and the emergency reset script (below) share a per-IP failure lockout. After 5 failures within a 15-minute window the form is locked and a lockout message is shown.
- Passwords are hashed with PHP's `password_hash()` / `PASSWORD_DEFAULT`.
- The **Remember Me** cookie uses a split-token scheme rotated on every use, with all tokens for a user revoked on explicit logout.
- **Password recovery** — "Forgot password?" on the login page emails a single-use, 1-hour reset link to the recovery account's address, if one is set. When outbound mail isn't configured, **`reset-password.php`** in the gallery root provides an unauthenticated emergency reset with the same trust model as `install/index.php` (reaching the file at all requires filesystem/FTP access); it self-deletes after a successful reset and is nagged about in the admin panel until removed.

---

## LiteSpeed Support

Lumora automatically detects LiteSpeed and OpenLiteSpeed and can take advantage of a couple of server-specific optimizations, while remaining fully functional on Apache, nginx, Caddy, or any other web server — none of this requires LiteSpeed, and nothing here is required for normal operation.

- **Detection** (Admin → Installation → System Information) — reports the detected web server (with a LiteSpeed/OpenLiteSpeed badge when applicable) and, where detectable, HTTP/2, HTTP/3, and Brotli support.
- **Static asset cache headers** (Admin → Installation) — a one-click action installs long-lived cache headers for images, thumbnails, and theme assets into your site's `.htaccess`, read identically by Apache and LiteSpeed/OpenLiteSpeed. It never touches any other content already in your `.htaccess`, and can be removed with the same one-click control. Has no effect on nginx/Caddy, which don't read `.htaccess` files at all.
- **LiteSpeed Cache purge** (Admin → Configuration → Performance) — an opt-in, off-by-default toggle that purges LiteSpeed Cache automatically after any content change, so visitors never see a stale page. A complete no-op unless both the toggle is on and the current server is detected as LiteSpeed/OpenLiteSpeed, so it's safe to leave on regardless of your hosting.
- **Page caching** (Admin → Installation → "LiteSpeed Page Caching (Advanced)") — an opt-in, off-by-default option to turn on LiteSpeed page caching for hosts that don't otherwise expose that control to the site admin. LiteSpeed-only, a no-op elsewhere.

---

## Developer Information

If you're modifying Lumora's own PHP/CSS, building a plugin, or creating a theme, see [`docs/`](docs/) for technical documentation:

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — directory structure, the service layer, the plugin hook API, the updater's internal pipeline, and the full raw configuration key reference.
- [`docs/THEME_DEVELOPMENT.md`](docs/THEME_DEVELOPMENT.md) — building a custom theme, including the dark mode system and an accessibility checklist.
- [`docs/CHANGELOG.md`](docs/CHANGELOG.md) / [`docs/HISTORY.md`](docs/HISTORY.md) — what's changed, release by release.

| | |
|---|---|
| Developer | Ariane |
| Repository | <https://coding.unloved-heart.net/scripts/lumoragallery> |

---

## Changelog

See [`docs/CHANGELOG.md`](docs/CHANGELOG.md).

---

## License

Lumora Gallery is released under the [GNU General Public License v3.0](LICENSE). You are free to use, modify, and distribute it under the terms of that license.
