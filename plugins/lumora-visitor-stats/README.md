# Visitor Stats Plugin

A Jetpack-style traffic overview for Lumora Gallery: a daily pageview chart, top images, top albums, and top referrers on their own admin page, plus a compact "Last 7 Days" widget on the Dashboard.

This is a **feature plugin** — it extends the gallery without modifying any core file. It ships disabled by default; enable it from **Admin → Plugins**.

## What it tracks

One row per pageview: the page type (site, category, album, or image), the relevant item, a SHA-256 hash of the visitor's IP (never the raw address), and the referring host only (never a full URL or query string). Common bot/crawler traffic is excluded. Rows older than 90 days are pruned automatically.

## Disabling

Turn it off from **Admin → Plugins** — this stops it running immediately but leaves its collected data in place, so re-enabling later picks up right where it left off. To remove the data entirely, delete the plugin instead of just disabling it (**Admin → Plugins**, available once disabled).

---

**Building or modifying plugins?** See [`docs/ARCHITECTURE.md`](../../docs/ARCHITECTURE.md#plugin-system) for the hook API this plugin is built on.
