# Lumora Press Shortcodes Plugin

Shows a ready-to-copy `[lumora_gallery_album]` shortcode on each album's and image's own info page, for pasting into a [Lumora Press](https://coding.unloved-heart.net/scripts/lumorapress) post or page via that project's own companion "Lumora Gallery Shortcodes" plugin.

This is a **feature plugin** — it extends the gallery without modifying any core file. It ships disabled by default; enable it from **Admin → Plugins**.

Only ever matters to a site that also runs Lumora Press — with this plugin disabled, or enabled but pasted into nothing, the album/image admin and public pages render exactly as they do without it.

## What it shows

- **Admin → Albums → Edit**: a "Lumora Press Shortcode" field with `[lumora_gallery_album album_id="…"]`, ready to copy.
- **Admin → Images → Edit**: the equivalent single-image shortcode, `[lumora_gallery_album image_id="…"]`.
- **Public album page**: the same album shortcode, shown only to logged-in users (never to an anonymous visitor) — same gate as the existing "Direct image URL" lightbox panel.
- **Public image lightbox**: the same image shortcode, added as an extra field in that same "Direct image URL" info panel, logged-in users only.

This plugin does static text generation only — it has no settings screen, no database table, and no live connection to a Lumora Press install.

## Disabling

Turn it off from **Admin → Plugins** — this stops the shortcode fields from appearing immediately. There is no data to clean up: this plugin never writes to the database.

---

**Building or modifying plugins?** See [`docs/ARCHITECTURE.md`](../../docs/ARCHITECTURE.md#plugin-system) for the hook API this plugin is built on.
