# Lumora Press Shortcodes Plugin

Shows a ready-to-copy `[lumora_gallery_album]` shortcode on each album's and
image's own info page, for pasting into a
[Lumora Press](https://coding.unloved-heart.net/scripts/lumorapress) post or
page via that project's own companion plugin (`LPP-015`, "Lumora Gallery
Shortcodes").

This is a **feature plugin** — it hooks into core through `HookService`
(see `include/services/HookService.php` and `include/services/PluginService.php`)
rather than modifying any core file. It ships disabled by default; enable
it from **Admin → Plugins**.

Only ever matters to a site that also runs Lumora Press — with this plugin
disabled, or enabled but pasted into nothing, the album/image admin and
public pages render exactly as they do without it.

## What it shows

- **Admin → Albums → Edit**: a "Lumora Press Shortcode" field with
  `[lumora_gallery_album album_id="…"]`, ready to copy.
- **Admin → Images → Edit**: the equivalent single-image shortcode,
  `[lumora_gallery_album image_id="…"]`.
- **Public album page**: the same album shortcode, shown only to logged-in
  users (never to an anonymous visitor) — same gate as the existing
  "Direct image URL" lightbox panel.
- **Public image lightbox**: the same image shortcode, added as an extra
  field in that same "Direct image URL" info panel, logged-in users only.

This plugin does static text generation only — it has no settings screen,
no database table, and no live connection to a Lumora Press install. The
album/image's own `id` is the only piece of information the shortcode
needs.

## Files

- `plugin.json` — manifest (id, type `feature`, hook entry point).
- `version.php` — plugin version constant.
- `bootstrap.php` — registers this plugin's four filters on every request
  while enabled: two admin edit-form fields, two public displays.
- `LumoraPressShortcodesService.php` — the shortcode text/HTML builders;
  no database access.

## Disabling

Turn it off from **Admin → Plugins** — this stops the shortcode fields
from appearing immediately. There is no data to clean up: this plugin
never writes to the database.
