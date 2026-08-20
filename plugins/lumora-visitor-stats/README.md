# Visitor Stats Plugin

A Jetpack-style traffic overview for Lumora Gallery: a daily pageview
chart, top images, top albums, and top referrers on their own admin page,
plus a compact "Last 7 Days" widget on the Dashboard.

This is a **feature plugin** — it hooks into core through `HookService`
(see `include/services/HookService.php` and `include/services/PluginService.php`)
rather than modifying any core file. It ships disabled by default; enable
it from **Admin → Plugins**.

## What it tracks

One row per pageview in its own `{PREFIX}stats_hits` table: the page type
(`site` | `category` | `album` | `image`), the relevant item's ID, a
SHA-256 hash of the visitor's IP (never the raw address), the referring
host only (never a full URL or query string), and a timestamp. Rows older
than `LUMORA_VISITOR_STATS_RETENTION_DAYS` (`version.php`, default 90) are
pruned automatically. Common bot/crawler user agents are excluded.

## Files

- `plugin.json` — manifest (id, type `feature`, hook entry points).
- `version.php` — plugin version + retention-days constant.
- `activate.php` — creates `{PREFIX}stats_hits` the first time the plugin
  is enabled. Never runs automatically otherwise.
- `bootstrap.php` — registers this plugin's hooks on every request while
  enabled: logs pageviews, adds its Dashboard widget, adds its nav item.
- `VisitorStatsService.php` — all database reads/writes.
- `admin/stats.php` — the full Visitor Stats admin page.

## Disabling

Turn it off from **Admin → Plugins** — this stops all hooks from running
immediately but leaves `{PREFIX}stats_hits` and its data in place, so
re-enabling later picks up right where it left off. To remove the data
entirely, drop `{PREFIX}stats_hits` by hand after disabling.
