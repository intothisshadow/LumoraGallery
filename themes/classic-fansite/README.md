# Classic Fansite — Lumora Gallery Theme

A traditional fansite starter theme for Lumora Gallery, designed for fansites
dedicated to TV shows, films, games, celebrities, and other fandoms. Inspired by
the gallery sites of the 2000s–2010s fandom era.

---

## What's included

| File | Purpose |
|---|---|
| `template.html` | Page structure — banner, sticky nav, content area, footer |
| `style.css` | All styles; every design decision is a CSS custom property |
| `README.md` | This file |

---

## Quick-start customisation

All colours, sizes, and fonts are controlled by CSS custom properties in the `:root`
block at the top of `style.css`. **Do not edit `style.css` directly** — create a
file called `custom.css` in this folder and override only the variables you want.
Then add one line to `template.html` after the existing `style.css` link:

```html
<link rel="stylesheet" href="{THEME_URL}custom.css">
```

### Colour variables

| Variable | Default | What it controls |
|---|---|---|
| `--fs-accent` | `#4a1f6e` | Buttons, filled backgrounds (section headers, stat-box top border, category-list header) |
| `--fs-accent-light` | `#6d3a9e` | Hover colour for filled accent buttons |
| `--fs-accent-text` | `#ffffff` | Text on accent-coloured backgrounds |
| `--fs-accent-ink` | `= --fs-accent` (light) / `#b98ee8` (dark) | Links, card titles, and borders where the accent sits directly on the page background rather than as a fill — kept separate from `--fs-accent` so dark mode can swap in a lighter tint for WCAG AA contrast |
| `--fs-accent-ink-hover` | `= --fs-accent-light` (light) / `#d4b8f0` (dark) | Hover colour for `--fs-accent-ink` uses |
| `--fs-page-bg` | `#1a1a2a` | Outer page background (visible on wide screens) |
| `--fs-body-bg` | `#f0eef5` | Content panel background |
| `--fs-panel-bg` | `#ffffff` | Cards and thumbnail backgrounds |
| `--fs-panel-border` | `#d4c8e8` | Card and thumbnail borders |
| `--fs-nav-bg` | `#2a1040` | Navigation bar background |
| `--fs-nav-link` | `rgba(255,255,255,.80)` | Nav link default colour |
| `--fs-nav-link-hover` | `#ffffff` | Nav link hover colour |
| `--fs-banner-bg` | purple gradient | Banner gradient (replaced by image when set) |
| `--fs-banner-height` | `220px` | Minimum height of the banner area |
| `--fs-banner-title-color` | `#ffffff` | Gallery name text colour in banner |
| `--fs-banner-title-size` | `2.2rem` | Gallery name font size in banner |
| `--fs-footer-bg` | `#2a1040` | Footer background |
| `--fs-footer-text` | `rgba(255,255,255,.65)` | Footer text colour |
| `--fs-max-width` | `980px` | Maximum panel width |

---

## Fandom colour presets

Copy one of these blocks into your `custom.css` file.

**Dark mode note:** each preset below only sets `--fs-accent`/`--fs-accent-light`,
which cover buttons and other filled backgrounds. Dark mode's link/border colour
(`--fs-accent-ink`) defaults to a neutral lavender tint chosen purely for guaranteed
WCAG AA contrast against the shared dark content background — it won't automatically
pick up a preset's own hue. For full colour-matching in dark mode, add an
`html[data-bs-theme="dark"]` block overriding `--fs-accent-ink`/`--fs-accent-ink-hover`
with a lighter tint of your preset's colour, as shown for the Dark Red preset below
(and already included in this theme's bundled `custom.css`).

### Dark red / fantasy / horror

```css
:root {
  --fs-accent:       #8b0000;
  --fs-accent-light: #b22222;
  --fs-page-bg:      #1a0000;
  --fs-nav-bg:       #2d0000;
  --fs-footer-bg:    #2d0000;
  --fs-banner-bg:    linear-gradient(135deg, #2d0000 0%, #8b0000 50%, #2d0000 100%);
  --fs-body-bg:      #f5eeee;
  --fs-panel-border: #e8c8c8;
}

/* Optional: colour-match links/borders in dark mode too (see note above) */
html[data-bs-theme="dark"] {
  --fs-accent-ink:       #ff8a80;
  --fs-accent-ink-hover: #ffb3a8;
}
```

### Ocean blue / sci-fi / space

```css
:root {
  --fs-accent:       #005f8e;
  --fs-accent-light: #0080bf;
  --fs-page-bg:      #001a2e;
  --fs-nav-bg:       #00273d;
  --fs-footer-bg:    #00273d;
  --fs-banner-bg:    linear-gradient(135deg, #001a2e 0%, #005f8e 50%, #001a2e 100%);
  --fs-body-bg:      #eef4f8;
  --fs-panel-border: #b8d4e6;
}
```

### Forest green / nature / fantasy

```css
:root {
  --fs-accent:       #2d6a2d;
  --fs-accent-light: #3d8a3d;
  --fs-page-bg:      #0f1a0f;
  --fs-nav-bg:       #1a2e1a;
  --fs-footer-bg:    #1a2e1a;
  --fs-banner-bg:    linear-gradient(135deg, #0f1a0f 0%, #2d6a2d 50%, #0f1a0f 100%);
  --fs-body-bg:      #eef4ee;
  --fs-panel-border: #c8e0c8;
}
```

### Rose gold / celebrity / pop

```css
:root {
  --fs-accent:       #b5606e;
  --fs-accent-light: #d4788a;
  --fs-page-bg:      #1a0d10;
  --fs-nav-bg:       #2e1018;
  --fs-footer-bg:    #2e1018;
  --fs-banner-bg:    linear-gradient(135deg, #2e1018 0%, #b5606e 50%, #2e1018 100%);
  --fs-body-bg:      #fdf5f6;
  --fs-panel-border: #e8c8cd;
}
```

### Midnight gold / period drama / historical

```css
:root {
  --fs-accent:       #8b6914;
  --fs-accent-light: #b58a1e;
  --fs-page-bg:      #110e00;
  --fs-nav-bg:       #1e1800;
  --fs-footer-bg:    #1e1800;
  --fs-banner-bg:    linear-gradient(135deg, #1e1800 0%, #8b6914 50%, #1e1800 100%);
  --fs-body-bg:      #faf7ee;
  --fs-panel-border: #ddd3a8;
}
```

---

## Adding a banner image

The banner area displays the gallery name over a CSS gradient by default. To replace
the gradient with a custom image, edit `template.html` directly — it's the sanctioned
customisation point for markup like this (no config option or admin-supplied file path
is involved):

1. **Upload your banner** into the theme folder or anywhere inside the gallery
   (e.g. `themes/classic-fansite/images/banner.jpg`).

2. **Add an image inside the banner header** in `template.html`, right before
   `.fs-banner-text`:

   ```html
   <header class="fs-banner">
     <div class="fs-banner-bg">
       <img src="{THEME_URL}images/banner.jpg" alt="">
     </div>
     <div class="fs-banner-text">
       <h1 class="fs-banner-title">{GALLERY_NAME}</h1>
       <p class="fs-banner-desc">{GALLERY_DESCRIPTION}</p>
     </div>
   </header>
   ```

   `.fs-banner-bg` (defined in `style.css`) already positions and sizes an image
   placed inside it to fill the banner area with `object-fit: cover` and
   `object-position: center top`, so the top edge stays visible; you don't need
   any extra CSS for this. Adjust the `src` path to match wherever you uploaded
   the image.

The gallery name stays overlaid on top via a subtle dark scrim for legibility.

### Adjusting banner height

```css
/* custom.css */
:root { --fs-banner-height: 300px; }
```

### Hiding the gallery name from the banner

If your banner image already contains the gallery name, you can hide the HTML title:

```css
/* custom.css */
.fs-banner-title { display: none; }
```

---

## Creating a new theme from this starter

The easiest way to make a fully custom theme is to copy this folder:

1. **Copy** `themes/classic-fansite/` and rename it (e.g. `themes/my-fandom/`).
2. **Edit `style.css`** in the copy — or create `custom.css` — and set your
   colour variables.
3. **Replace the banner image** (optional).
4. In **Admin → Config → Theme**, select your new theme name.

The template engine discovers any folder inside `themes/` that contains a
`template.html` file, so no registration step is needed.

### What a theme must contain

| File | Required | Notes |
|---|---|---|
| `template.html` | **Yes** | Must include `{CONTENT}` at minimum |
| `*.css` | No | Linked from `template.html` via `{THEME_URL}` |
| `theme.php` | No | Loaded before token replacement; can define helper functions |
| `README.md` | No | Documentation only |

### Theme metadata (optional)

You can identify your theme by adding a CSS header comment to the very top of
its primary stylesheet — the first `{THEME_URL}*.css` link in `template.html`
(for this theme, that's `style.css` itself, even if you override values via
`custom.css`):

```css
/*
 * Theme Name: My Fandom Theme
 * Author: Your Name
 * Design URI: https://example.com
 */
```

`Theme Name`, `Author`, and `Design URI` are the recognized fields; all are
optional and any other lines in the comment are ignored. When set, `Theme Name`
becomes the label shown in the Active Theme dropdown (instead of the raw folder
name), and all three fields appear in a reference table in Admin → Configuration
→ Appearance. Skipping the header entirely is fine — the folder name is used as
a fallback display name.

### Available template tokens

| Token | Contains |
|---|---|
| `{CHARSET}` | Always `utf-8` |
| `{PAGE_TITLE}` | Page-specific prefix, e.g. `"Season 1 — "` |
| `{GALLERY_NAME}` | Gallery name from config |
| `{GALLERY_DESCRIPTION}` | Gallery description from config (may be empty) |
| `{THEME_URL}` | URL to this theme's directory, with trailing slash |
| `{BASE_URL}` | Gallery root URL, with trailing slash |
| `{LUMORA_VERSION}` | Version string, e.g. `"1.0.0"` |
| `{NAVIGATION}` | Site nav links (Home/Latest/Most Viewed/Random) |
| `{ADMIN_LINK}` | Admin panel `<a>` link (empty for non-admin visitors) |
| `{POWERED_BY}` | "Powered by Lumora Gallery" credit (empty when disabled in config) |
| `{CONTENT}` | Main page HTML |

`{NAVIGATION}` renders as a plain `<ul class="navbar-nav">`/`<li class="nav-item">`/
`<a class="nav-link">` structure — this theme's `style.css` restyles those
generic classes (scoped under `.fs-nav-inner`) to look like this theme's own
`.fs-nav-link` chip design, rather than building the nav links directly with
`{BASE_URL}`. Using the token (instead of hand-building each link) is what
lets the "Most Viewed" link automatically carry the current album/category
forward when browsing one (LG-33) — a hand-built link would always point at
the gallery-wide most-viewed list.

---

## File structure

```
themes/
└── classic-fansite/
    ├── template.html   — page structure (banner, nav, content, footer)
    ├── style.css       — styles and customisation variables
    ├── custom.css      — (optional) your overrides; not shipped by default
    └── README.md       — this file
```
