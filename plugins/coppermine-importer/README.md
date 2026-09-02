# Coppermine Importer

An official Lumora Gallery plugin that migrates categories, albums, and image metadata from Coppermine Gallery (CPG 1.4–1.6) to Lumora.

**Plugin version:** 1.3.0 **Requires Lumora:** 1.5.0+ **License:** GPL-3.0-or-later

---

## What it imports

| Data                         | Imported | Notes                                                        |
|------------------------------|----------|--------------------------------------------------------------|
| Categories                   | ✅       | Hierarchy (parent/child) fully preserved                     |
| Albums                       | ✅       | Title, description, position, visibility, hit count          |
| Image metadata               | ✅       | Filename, title, dimensions, filesize, hits, date            |
| Album cover images           | ✅       | Assigned automatically during import                         |
| Category cover images        | ✅       | Assigned automatically during import                         |
| Image files                  | ❌       | Files are not moved (see File Migration below)               |
| Thumbnails                   | ❌       | Not regenerated — existing `thumb_` files are used            |
| Comments                     | ❌       | Not supported in this version                                |
| User accounts                | ❌       | Not supported (Lumora is single-admin)                       |

---

## Cover image import

Album and category cover images are assigned automatically at the end of every import run, as part of the main wizard — no separate step needed. Covers that can't be resolved (e.g. the source image itself wasn't imported) simply fall back to Lumora's normal auto-pick behaviour, so nothing breaks if a cover can't be matched.

| Approach | When to use |
|---|---|
| **Import wizard** (automatic) | Preferred — runs as part of every import, no action needed. |
| **Metadata Sync tool** (below) | Post-import fallback — use when covers weren't set during import (e.g. the import was stopped early), or to re-run cover assignment after making manual changes. |

Any cover-assignment issues are written to the migration log and shown in the warnings section on the import results page.

---

## File migration

The importer is **metadata-first**. It does not move, copy, or rename image files.

Your Coppermine `albums/` directory structure is preserved exactly. Lumora references images in the same folder layout Coppermine used.

### Recommended workflow

1. **Run the importer** (Admin → Import → Coppermine Importer).
2. **Copy or symlink** your Coppermine `albums/` directory into Lumora's `albums/` directory so folder names and filenames are identical.
3. **Verify** using Admin → Tools → File Integrity Check to confirm all image files and thumbnails are found.

### Folder name mapping

| Coppermine album            | Lumora album folder |
|-----------------------------|---------------------|
| keyword = `xena/season1`   | `xena/season1`      |
| keyword = `` (empty)       | `00001` (zero-padded album ID) |
| keyword = `photos`         | `photos`            |

No files need to be renamed or restructured.

### Example

**Coppermine directory (before migration):**

```
albums/
├── 00001/
│   ├── thumb_ep101.jpg
│   └── ep101.jpg
└── xena/season1/
    ├── thumb_scene01.jpg
    └── scene01.jpg
```

**Lumora directory (after copying):**

```
albums/
├── 00001/
│   ├── thumb_ep101.jpg   ← referenced by Lumora as thumbnail
│   └── ep101.jpg         ← referenced by Lumora as original
└── xena/season1/
    ├── thumb_scene01.jpg
    └── scene01.jpg
```

No renaming required.

---

## Re-import protection

The importer records the date, record counts, and plugin version after each successful import. If you navigate to the importer after a previous run, you will see a warning and must explicitly confirm before proceeding.

Re-running the importer **will create duplicate content** unless you manually clear the existing Lumora categories, albums, and images first.

---

## Import status display

After a successful import, the migration status is visible in Admin → Import (the Lumora migration hub). It shows:

```
Source:      coppermine
Imported at: 2026-06-15 14:30:00
Categories:  14
Albums:      103
Images:      8,432
```

---

## Metadata Sync tool

The plugin ships a second admin page — the **Metadata Sync tool** — which syncs category and album cover-thumbnail selections from Coppermine into an *already-imported* Lumora gallery.

Access it at: `Admin → Import → Coppermine Importer → Metadata Sync` (linked from the importer wizard's credentials page and results page).

Use the Metadata Sync tool when:
- The main import was stopped before covers were assigned.
- You want to re-run cover assignment after making manual changes.
- You added new images to Coppermine and re-imported only the images.

### What it syncs

| Data                             | Synced | Notes                                    |
|-----------------------------------|--------|------------------------------------------|
| Category cover-thumbnail         | ✅     | From Coppermine's category cover field   |
| Album cover-thumbnail            | ✅     | From Coppermine's album cover field      |
| Categories, albums, image records | ❌     | Use the main importer for those          |

Albums and categories are matched to their Lumora counterparts by their folder path / name, not by any temporary ID mapping — so this tool works correctly even when run long after the original import session ended.

### Status values in the preview table

| Status          | Meaning                                                              |
|-----------------|------------------------------------------------------------------------|
| Ready           | Will be set on Apply                                                 |
| Has cover       | Already set in Lumora; only changes if Overwrite is checked          |
| Unmatched       | No Lumora counterpart found by folder / name-path                    |
| Image not found | Matched, but the cover image is not in Lumora's `images` table       |
| Ambiguous       | Category name-path matched more than one Lumora category             |

### Safety

- All writes for one sync run are wrapped in a single transaction — an error partway through rolls back the whole run, never leaving it half-applied.
- Only records with no cover already set are touched by default. Check **Overwrite** to replace existing cover selections instead.
- A required **backup confirmation** checkbox must be ticked before Apply — take a database backup first if you haven't already.
- A timestamped log of each run is written to `plugins/coppermine-importer/logs/` for troubleshooting; restrict web access to that directory or delete old logs periodically.
- Safe to re-run any number of times.

---

**Building or modifying importer plugins?** See [`docs/ARCHITECTURE.md`](../../docs/ARCHITECTURE.md#importer-plugins) for the plugin format, the shared migration-tracking tables, and this plugin's own versioning convention.
