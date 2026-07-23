# Lumora Gallery — Theme Development Guide

This guide covers everything needed to build a Lumora theme, with a focus on
the built-in dark mode system. It complements
[`themes/classic-fansite/README.md`](../themes/classic-fansite/README.md),
which walks through deriving a new fandom theme from that starter step by
step — read this guide first for the underlying mechanics, then use the
Classic Fansite README as a hands-on tutorial.

---

## What a theme needs

| File | Required | Notes |
|---|---|---|
| `template.html` | **Yes** | Must include `{CONTENT}` at minimum |
| `*.css` | No | Linked from `template.html` via `{THEME_URL}` |
| `theme.php` | No | Loaded before token replacement; can define helper functions |
| `README.md` | No | Documentation only |

Lumora discovers any folder inside `themes/` that contains a `template.html`
file — no registration step is required. The active theme is chosen in
**Admin → Configuration**.

### Template tokens

| Token | Contains |
|---|---|
| `{CHARSET}` | Always `utf-8` |
| `{PAGE_TITLE}` | Page-specific prefix, e.g. `"Season 1 — "` |
| `{GALLERY_NAME}` | Gallery name from config |
| `{GALLERY_DESCRIPTION}` | Gallery description from config (may be empty) |
| `{THEME_URL}` | URL to this theme's directory, with trailing slash |
| `{BASE_URL}` | Gallery root URL, with trailing slash |
| `{LUMORA_VERSION}` | Version string, e.g. `"1.9.2"` |
| `{NAVIGATION}` | Bootstrap navbar-nav `<ul>` (used by the default theme) |
| `{ADMIN_LINK}` | Admin panel `<a>` link (empty for non-admin visitors) |
| `{POWERED_BY}` | "Powered by Lumora Gallery" credit (empty when disabled in config) |
| `{CONTENT}` | Main page HTML |
| `{COLOR_MODE_INIT}` | Inline `<script>` that must go first inside `<head>` — see below |
| `{COLOR_MODE_TOGGLE}` | Toggle button HTML — place it in the nav area |

A theme can identify itself with a CSS header comment at the top of its
primary stylesheet (the first `{THEME_URL}*.css` link in `template.html`):

```css
/*
 * Theme Name: My Theme
 * Author: Your Name
 * Design URI: https://example.com
 */
```

`Theme Name`, `Author`, and `Design URI` are optional; when present they
appear in the Active Theme dropdown and in Admin → Configuration →
Appearance.

---

## Dark mode architecture

Lumora's dark mode is built entirely on **Bootstrap 5.3's native
`data-bs-theme` attribute** plus a small set of CSS custom properties per
theme. There are no separate light/dark stylesheets to maintain.

### How it works

1. **`{COLOR_MODE_INIT}`** — an inline `<script>` that must be the *first*
   thing inside `<head>`, before any stylesheet link. It resolves the
   visitor's preference and sets `data-bs-theme="dark"` or
   `data-bs-theme="light"` on `<html>` before first paint, so there is no
   flash of the wrong theme. Resolution order:
   1. The visitor's explicit `localStorage` preference (`auto` / `light` / `dark`)
   2. The logged-in user's per-account database preference (admin panel only)
   3. The site-wide `default_color_mode` config value
   4. The system preference via `prefers-color-scheme: dark`
2. **`{COLOR_MODE_TOGGLE}`** — a button that cycles Auto → Dark → Light →
   Auto and persists the choice to `localStorage`. Place it in the nav area,
   typically next to `{ADMIN_LINK}`.
3. **CSS custom properties** — every colour a theme uses should be a
   variable defined once in `:root` (light defaults) and overridden inside
   `html[data-bs-theme="dark"] { ... }`. Because the attribute lives on
   `<html>`, the override cascades to the entire page automatically —
   component rules never need a `dark` variant of their own; they just
   reference the variable.

### Minimal example for a new theme

```css
:root {
  color-scheme: light dark;

  --my-bg:     #ffffff;
  --my-text:   #212529;
  --my-accent: #4a1f6e;
}

html[data-bs-theme="dark"] {
  color-scheme: dark;

  --my-bg:   #1a1a1a;
  --my-text: #e8e8e8;
  /* --my-accent usually stays the same in both modes unless it needs
     more contrast against a dark background */
}

body {
  background: var(--my-bg);
  color: var(--my-text);
}
```

The `color-scheme` declaration is not optional decoration — without it,
native browser UI (scrollbars, form control chrome, date pickers) stays
light even when your theme is dark. Set it once in `:root` as `light dark`
(tells the browser both are supported) and override it to the single active
value inside your `html[data-bs-theme="dark"]` block.

### Inherit the standard variables where possible

The two bundled themes use two different naming conventions, both of which
are legitimate patterns to copy from:

- **`default` theme** — generic `--lum-*` tokens (`--lum-bg`, `--lum-surface`,
  `--lum-border`, `--lum-text`, `--lum-muted`, `--lum-head-text`, `--lum-accent`,
  plus semantic tint tokens like `--lum-card-tint-blue`). Good starting point
  for a clean, neutral theme.
- **`classic-fansite` theme** — `--fs-*` tokens documented in full in
  `themes/classic-fansite/README.md`, including fandom colour presets.

New themes should pick **one** prefix and use it consistently rather than
hard-coding hex colours inside component rules. Hard-coded colours are the
single most common reason a theme breaks in dark mode — a color that looks
fine on a white card becomes unreadable once `--*-bg` flips dark, and every
occurrence has to be found and fixed individually instead of overridden in
one place.

### Required Lumora CSS classes

Whatever variable names a theme chooses internally, it must still style the
public Lumora component classes so a visitor gets a working gallery in both
modes: `.lum-thumbgrid` / `.lum-thumb-item` / `.lum-thumb-caption` (thumbnail
grid), `.lum-catgrid` / `.lum-catcard` / `.lum-catlist` (album & category
grids/lists), `.lum-section-title`, `.lum-stat-box`, `.lum-album-desc` /
`.lum-cat-desc`, `.lum-sort-bar`, `.lum-pagination`, `.breadcrumb`,
`.lum-who-is-online`, `.lum-empty`. See `themes/default/style.css` and
`themes/classic-fansite/style.css` for the full reference implementation —
both are organised under named section banners (Layout, Typography,
Navigation, Albums & Categories, Image Pages, Forms, Buttons, Tables,
Messages, Utility Components, Media, Loading indicator, Print styles,
Responsive) that a new theme can mirror.

---

## Accessibility checklist

Run through this list before shipping a theme, in both light and dark mode:

- **Contrast** — body text, muted/small text, and link colours should meet
  WCAG AA (4.5:1 for normal text, 3:1 for large text) against their
  background in both modes. Card tint backgrounds (used behind
  `.lum-card-images`, `.lum-card-views`, etc.) need enough contrast against
  the text colour drawn on top of them, not just against the page background.
- **Icons** — any inline SVG icon should use `fill="currentColor"` (never a
  hard-coded hex fill) so it automatically follows the surrounding text
  colour in both modes. Both bundled themes' icons (the online-visitors icon,
  the category/album placeholder icon, and the PhotoSwipe download-button
  icon) already follow this pattern.
- **Forms and buttons** — verify `.form-control`, `.form-select`,
  `.form-check-input`, and both `.btn-primary` / `.btn-outline-primary` read
  clearly against your dark background token, including the focus ring
  (`box-shadow` on `:focus`) and the disabled state (`opacity` should still
  leave text legible, not just faded to invisible).
- **Focus outlines** — do not add `outline: none` to interactive elements
  without supplying a visible replacement focus style. Neither bundled theme
  suppresses the default focus outline; Bootstrap's own focus-visible styles
  are sufficient as long as a theme doesn't override them away.
- **Hover states** — check `.lum-catcard:hover`, `.lum-thumb-item:hover`,
  and `.lum-catlist-row:hover` remain visually distinct from the resting
  state in dark mode; a hover tint calculated for a light background can
  disappear entirely on a dark one.
- **Code blocks and tables** — `pre`/`code` and `.table` should read cleanly
  in both modes; reuse the same variable set as the rest of your content
  area rather than a separate hard-coded scheme.
- **Galleries and captions** — thumbnail captions (`.lum-thumb-caption`)
  sit on a slightly different background from the card itself
  (`--lum-thumb-cap-bg` / `--fs-thumb-cap-bg`); make sure that background
  still contrasts with the surrounding card in dark mode.
- **Modal dialogs** — neither bundled theme currently uses a Bootstrap
  modal on the public site, so no custom modal styling exists yet. If a
  future component or third-party theme adds one, Bootstrap 5.3's own
  dark-mode-aware modal styles apply automatically as long as
  `data-bs-theme` is set on `<html>` (which it always is) and the modal
  markup isn't given hard-coded background/text colours of its own.

---

## Nice-to-have patterns

### Smooth mode transitions (without a flash on first load)

Only add a CSS `transition` for colour/background changes **after** the
page has already painted once — never inside `{COLOR_MODE_INIT}` itself, or
the very first paint will visibly animate from the browser's default white
background to your theme's colours. The bundled toggle script demonstrates
the correct pattern: it sets `document.documentElement.style.transition`
immediately before switching `data-bs-theme`, inside the toggle's `click`
handler only, so the *first* page load is instant and only manual
switches animate.

### Theme-aware logo (light/dark variants)

There is no dedicated `{LOGO_URL}` token — add your logo markup directly to
`template.html` (the sanctioned customisation point for markup like this). To
swap a logo image between light and dark variants without any PHP changes,
include both images in `template.html` and toggle their visibility with CSS
attribute selectors on `data-bs-theme`:

```html
<!-- template.html -->
<img class="my-logo my-logo-light" src="{THEME_URL}logo-light.png" alt="Gallery logo">
<img class="my-logo my-logo-dark"  src="{THEME_URL}logo-dark.png"  alt="Gallery logo">
```

```css
.my-logo-dark  { display: none; }
html[data-bs-theme="dark"] .my-logo-light { display: none; }
html[data-bs-theme="dark"] .my-logo-dark  { display: block; }
```

### Print styles

Both bundled themes ship a `@media print` block that hides navigation,
footer, sort bar, pagination, the admin link, and the colour-mode toggle so
a printed page shows only gallery content. New themes should do the same —
copy the block from `themes/default/style.css` or
`themes/classic-fansite/style.css` and adjust the selector list to match
your own chrome elements.

---

## Testing a theme

1. Toggle through Auto → Dark → Light → Auto using `{COLOR_MODE_TOGGLE}` and
   confirm every page (home, category, album, special views) looks correct
   in all three states.
2. Reload the page after switching to Dark or Light and confirm there is no
   flash of the wrong theme before `{COLOR_MODE_INIT}` applies.
3. Test with your OS set to both light and dark system themes while your
   toggle is on "Auto".
4. Test with a long album/category name and a long description to make sure
   card and description backgrounds still look correct in dark mode at
   awkward text lengths.
5. Run the accessibility checklist above in both modes.
