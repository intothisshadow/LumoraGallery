# On-Demand Thumbnails (Experimental)

Generates thumbnails when a visitor actually requests them, instead of storing every thumbnail as its own file under `albums/`. This exists to reduce inode usage on very large galleries — each stored original + thumbnail pair normally costs two files on disk; this plugin removes the thumbnail file from that count entirely, since a thumbnail is generated in memory, streamed to the browser, and discarded.

**This plugin is experimental.** It does not touch any other part of the gallery, and enabling it does nothing on its own — you also have to manually add a rewrite rule at the top of `albums/`, following the steps below. That one rule covers every album folder site-wide, but nothing actually changes until you start deleting individual `thumb_*` files: the real opt-in happens per thumbnail, not per folder or site-wide, since a thumbnail still on disk keeps being served exactly as before regardless of whether the rule is in place.

## Before you test this anywhere with real traffic

A thumbnail generated this way is not stored anywhere by default, so every visitor who is the *first* to request a given thumbnail after this plugin takes over triggers a real image-resize operation on your server. For a small test folder this is negligible. For a full, actively-browsed gallery, this plugin is **not safe to run on its own** — it needs a shared cache in front of it (Cloudflare's free CDN caching layer is the recommended option) so that only the first visitor to a given gallery page costs any CPU, and every visitor after that is served from the cache instead of hitting this plugin again. Testing without a cache in front is fine for confirming the mechanism works; it is not a preview of production-safe behaviour.

## Setting up Cloudflare (recommended before wide rollout)

Since this plugin needs a shared cache in front of it to be safe at real traffic levels (see above), set up Cloudflare's free plan for the domain before deleting thumbnails beyond a small test batch:

1. Go to [cloudflare.com](https://cloudflare.com) and sign up for a free account — one account is enough even if you plan to do this for more than one of your domains; each domain you add becomes its own independent zone with its own settings and its own free-tier allowance, so multiple sites don't compete with each other for limits.
2. From the Cloudflare dashboard, **Add a Site**, enter the domain (e.g. `gameofthrones.seven-kingdoms.net`), and pick the **Free** plan.
3. Cloudflare scans the domain's existing DNS records and shows you what it found — confirm the records look right (the `A`/`CNAME` record pointing at your hosting account's IP should already be there), then continue.
4. Cloudflare gives you two nameservers to set at your domain's registrar (wherever you registered/manage the domain itself — this may or may not be the same company as your reseller host). Update the domain's nameservers there to the two Cloudflare gave you. This step happens outside your hosting account entirely, at your registrar.
5. Nameserver changes can take anywhere from a few minutes to (rarely) up to 24 hours to propagate. Cloudflare emails you once it detects the domain is active on its network, and the dashboard shows the zone's status as **Active**.
6. Once active, open **DNS** in the Cloudflare dashboard for that zone and confirm the proxy status (the little cloud icon) on your domain's `A`/`CNAME` record is **orange** (proxied), not grey (DNS-only). Orange is what actually routes traffic through Cloudflare's cache — a grey cloud means Cloudflare is only handling DNS, not caching anything, which defeats the point here.
7. With the record proxied, request a thumbnail URL twice in a row (e.g. `curl -I <thumbnail-url>` or your browser's network tab) and check the `cf-cache-status` response header: `MISS` on the first request, `HIT` on the second confirms Cloudflare is actually caching this plugin's output.

Nothing about this plugin's code or the `albums/.htaccess` rule changes based on whether Cloudflare is active — Cloudflare just sits in front of the domain and caches whatever `Cache-Control` header the origin (your PHP) already sends, which this plugin already sets correctly regardless.

## How it works

1. Delete a thumbnail file. Its `<img src="thumb_whatever.jpg">` tag in the page is unchanged — nothing in the theme or templates needs to know this plugin exists.
2. A `.htaccess` rewrite rule you add once, at the top of `albums/`, catches requests for `thumb_*` files that no longer exist on disk anywhere under it, and redirects them internally to this plugin's `thumb.php`.
3. `thumb.php` checks the plugin is enabled, validates the requested folder/filename against directory traversal, generates a thumbnail into a temp file using Lumora's own `ThumbnailService` (the exact same resizing code used everywhere else), streams it to the browser with a long `Cache-Control` header, then deletes the temp file.
4. Nothing is ever written under `albums/`. The album folder's file/inode count only ever reflects original photos.

## Testing steps

1. **Admin → Plugins** — enable **On-Demand Thumbnails**.
2. **Admin → On-Demand Thumbnails** — click **Install Rule** under **albums/.htaccess Rewrite Rule**. This writes the rewrite rule below to `albums/.htaccess` for you (creating the file if it doesn't exist yet, or appending to it without touching anything already there if it does):

   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_FILENAME} !-f
   RewriteRule ^(.+)/thumb_(.+)$ /plugins/on-demand-thumbnails/thumb.php?folder=$1&file=$2 [L,QSA]
   ```

   This one rule covers every album folder under `albums/`, including nested ones (`Season8/8x03-TheLongNight`) — it captures whatever folder path and filename were actually requested, so there's nothing per-folder to edit or repeat. The leading path is derived automatically from your configured base URL, so it's correct even if your Lumora install doesn't live at your domain's web root. If the web server user can't write to `albums/`, the page shows the block above so you can add it manually instead.

   Installing this rule changes nothing by itself: the `!-f` condition only matches requests for thumbnail files that are already missing. Every thumbnail that still exists on disk keeps being served exactly as before, untouched by this plugin — you can install this rule once, site-wide, and nothing changes in practice until you start deleting individual `thumb_*` files. **Remove Rule** on the same page reverses this, removing only this plugin's block and leaving any other content in `albums/.htaccess` untouched.

3. Pick **one** thumbnail file in **one** low-traffic album folder and delete it (not the original — only the `thumb_` file), as a first test.
4. Load that album's gallery page in a browser. The photo whose thumbnail you deleted should still render normally.
5. Confirm it's actually going through this plugin, not a stale browser cache: open your browser's network tab, find that thumbnail's request, and check the response headers for `Cache-Control: public, max-age=2592000, immutable`. A `curl -I` against the thumbnail's URL works too.
6. Once you're confident it works, you can delete more `thumb_*` files — a batch within one folder, a whole folder, or a portion across several folders — at whatever pace you're comfortable with, either from **Admin → On-Demand Thumbnails** (see below) or over SSH. Nothing about the `.htaccess` rule needs to change as you do this; it already covers every folder and reacts per-file to whatever you've deleted. Expect the first load of any gallery page containing a freshly-deleted thumbnail to be slightly slower (those thumbnails are being generated for the first time), and subsequent loads faster (served from the visitor's own browser cache, though not yet from any shared cache — see the warning above).

## Batch-deleting thumbnails

**From Admin → On-Demand Thumbnails** (the easiest way): pick a folder relative to `albums/` (e.g. `Season8/8x03-TheLongNight`, or `albums` itself with **Recursive** checked for every album at once), choose **Delete all** or **Delete every other**, and click **Run**. **Dry run** is checked by default so the first click just previews what would happen — uncheck it and confirm to actually delete. This runs the exact same logic as the two SSH scripts below.

**Over SSH**, two scripts are provided, both under `tools/`. Neither ever touches original photos, and anything either one deletes can always be undone via **Admin → Tools → Regenerate Missing Thumbnails**. Both support `--dry-run` (preview only, nothing deleted) and `--yes` (skip the confirmation prompt).

Run these using the script's **full path on the server**, not a relative one — your SSH session's working directory is your account home, not the Lumora install folder, so a bare `tools/delete-all-thumbs.sh` won't be found unless you've already `cd`'d into the install. If you don't know that full path offhand, **Admin → Updates** shows it under **Installed at**; the two scripts live at `<that path>/plugins/on-demand-thumbnails/tools/`.

*Tip:* if a script isn't executable yet (`Permission denied`), you don't need `chmod +x` or sudo/a password to work around it — just run it through `bash` directly, e.g. `bash /full/path/to/tools/delete-all-thumbs.sh albums --recursive --dry-run`. This works regardless of the file's permission bits, since you're asking `bash` to read and execute the script rather than asking the shell to run it as a standalone program.

`delete-every-other-thumb.sh` deletes every other `thumb_*` file **currently present** in one folder — meant for a cautious partial migration where you deliberately want to keep roughly half of a folder's thumbnails as a fallback. Running it again on the same folder deletes half of *what's left*, not half of the original total, so repeated runs converge toward — but never actually reach — zero (run 1 leaves ~50%, run 2 leaves ~25%, run 3 leaves ~12.5%, and so on). It is not a way to fully clear a folder; for that, use `delete-all-thumbs.sh` instead.

```bash
tools/delete-every-other-thumb.sh albums/Season8/8x03-TheLongNight --dry-run
tools/delete-every-other-thumb.sh albums/Season8/8x03-TheLongNight
```

Add `--recursive` to apply this across every album subfolder under a top-level directory (e.g. `albums/` itself) in one run, instead of one folder at a time — each subfolder is still treated independently, so every album keeps roughly half its own thumbnails as a fallback, exactly as if you'd run the script separately on each one:

```bash
tools/delete-every-other-thumb.sh albums --recursive --dry-run
tools/delete-every-other-thumb.sh albums --recursive
```

`delete-all-thumbs.sh` deletes every `thumb_*` file in one folder in a single pass — a full migration of that folder to on-demand generation:

```bash
tools/delete-all-thumbs.sh albums/Season8/8x03-TheLongNight --dry-run
tools/delete-all-thumbs.sh albums/Season8/8x03-TheLongNight
```

It also supports `--recursive`, deleting every `thumb_*` file found anywhere under a top-level directory (e.g. `albums/` itself) — a full migration of every album at once:

```bash
tools/delete-all-thumbs.sh albums --recursive --dry-run
tools/delete-all-thumbs.sh albums --recursive
```

## Rolling back

Delete the `albums/.htaccess` file, then use **Admin → Tools → Regenerate Missing Thumbnails** to recreate any stored thumbnail files you'd deleted. Everything returns to normal static-file thumbnail serving with no other trace of this plugin having been there.

## Monitoring for errors

Every failure — the plugin being disabled, a request that fails its path/traversal validation, a disallowed file type, or a thumbnail that actually failed to generate — is logged to `plugins/on-demand-thumbnails/errors.log`, right next to this file. It's kept separate from your account's shared server error log deliberately, since a reseller account hosting several domains otherwise mixes every site's unrelated errors into one place, making it hard to see just this plugin's activity. The log file itself isn't web-accessible — it's blocked by the same root `.htaccess` rule that denies all `*.log` files site-wide.

To check it, SSH in and tail it while you test:

```bash
tail -f domains/<yourdomain>/public_html/plugins/on-demand-thumbnails/errors.log
```

Nothing in this file's entries is ever shown to visitors — failures always return a bare HTTP status code to the browser (404 or 500) with no filesystem paths or internal detail in the response, per Lumora's usual error-handling rules. The log file is only for you.

An empty or missing log file is a good sign — it means every thumbnail request that reached this plugin was served successfully. A steady stream of "path validation failed" entries usually means the `albums/.htaccess` rewrite rule isn't matching correctly for your folder structure; a "disallowed file type" entry means a file with an unexpected extension had its thumbnail deleted; entries mentioning `generateThumb() failed` mean the original image itself couldn't be processed (corrupt file, unsupported format, missing file, etc.) and are worth checking against that specific photo.

## Known limitations (v0.2, experimental)

- No shared cache of its own — without a CDN like Cloudflare in front of the site, every unique visitor to a freshly-served gallery page regenerates that page's thumbnails from scratch. Not yet safe for full-site rollout.
- Only the gallery's single configured thumbnail size (`thumb_width` / `thumb_height`) is ever generated — this is deliberate (accepting caller-supplied dimensions would let a single image be requested at many sizes purely to force repeated CPU-heavy regeneration), not a missing feature.

## Rate limiting

`thumb.php` is rate-limited per IP address over a fixed rolling 60-second window. From **Admin → On-Demand Thumbnails**, the limiter can be turned off entirely, or its threshold set to 30, 60, 120 (the default), or 240 requests per window — only the count is configurable, not the 60-second window itself. A request over the limit gets a bare `429 Too Many Requests` (no filesystem paths or internal detail in the response, same as every other failure this plugin logs) and is recorded in `errors.log`. This is a backstop for direct-origin abuse, not the primary defense — a CDN in front of the site (see the Cloudflare section above) is what actually keeps most traffic from reaching this endpoint at all, since it serves repeat requests for the same thumbnail straight from its own edge cache. The default is generous enough for real browsing (multiple full gallery-page loads per minute from one visitor) while still bounding worst-case CPU cost from a single IP hammering freshly-deleted thumbnails.

The limiter is a separate mechanism from Lumora Gallery's own admin login lockout (`RateLimitService`) — a burst of thumbnail requests can never trip, or be affected by, the admin login rate limit, and vice versa. Its own counters live in `cache/.odt_ratelimit.json`; deleting that file just resets every IP's count to zero, nothing else depends on it.
