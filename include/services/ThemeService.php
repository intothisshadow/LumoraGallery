<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Theme Service
 *
 * Everything the Appearance admin page (admin/appearance.php) needs beyond
 * plain theme listing/activation, which already lived in
 * lumora_list_themes() / lumora_get_theme_meta() (include/functions.php):
 * screenshot discovery for the theme card grid, and install/update/delete
 * from an uploaded ZIP (LG-043).
 *
 * Lumora themes are identified by a template.html file (not style.css, as
 * WordPress-style projects use) — see lumora_theme_primary_stylesheet() and
 * lumora_get_theme_meta() in include/functions.php for how the CSS header
 * metadata (Theme Name / Author / Design URI) is actually read once a
 * theme is on disk. This class mirrors that same "read the header comment
 * out of the primary stylesheet" logic against ZIP entry contents, so a
 * theme's declared name can drive its install folder before anything is
 * extracted.
 *
 * @package    LumoraGallery
 * @subpackage Themes
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.15.0
 * @see        UpdaterService::removeDirectory() Reused for staging/backup cleanup below.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class ThemeService
{
    /**
     * Priority order for the primary card image when more than one
     * screenshot basename is present in a theme's folder.
     */
    private const PREVIEW_BASENAMES = ['preview', 'thumbnail', 'screenshot'];

    private const SCREENSHOT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    /** Bundled themes that ship with the app and must never be deleted. */
    private const PROTECTED_THEMES = ['default', 'classic-fansite'];

    private const MAX_ZIP_ENTRIES           = 2000;
    private const MAX_ZIP_UNCOMPRESSED_SIZE = 50 * 1024 * 1024;

    /**
     * Every installed theme, with display metadata and a resolved card
     * thumbnail, for the Appearance page's card grid.
     *
     * @return list<array{
     *   folder: string, name: string, author: string, design_uri: string,
     *   screenshot: string|null, screenshots: list<string>, is_active: bool,
     *   is_protected: bool
     * }>
     */
    public static function listThemesWithMeta(): array
    {
        $active = (string) lumora_config('theme', 'default');

        $out = [];
        foreach (lumora_list_themes() as $folder) {
            $meta        = lumora_get_theme_meta($folder);
            $screenshots = self::findScreenshots($folder);
            $out[] = [
                'folder'       => $folder,
                'name'         => $meta['name'],
                'author'       => $meta['author'],
                'design_uri'   => $meta['design_uri'],
                'screenshot'   => $screenshots[0] ?? null,
                'screenshots'  => $screenshots,
                'is_active'    => $folder === $active,
                'is_protected' => in_array($folder, self::PROTECTED_THEMES, true),
            ];
        }
        return $out;
    }

    /**
     * Find every preview image in an installed theme's folder named
     * preview.*, thumbnail.*, or screenshot.*, plus numbered variants
     * (screenshot-2.jpg, screenshot-3.webp, …). Sorted by basename
     * priority (preview > thumbnail > screenshot) then ascending number,
     * so index 0 is always the card thumbnail and the full list feeds a
     * details-view screenshot gallery.
     *
     * @return list<string> Public URLs, in priority order.
     */
    public static function findScreenshots(string $folder): array
    {
        $dir = lumora_theme_path($folder);
        if (!is_dir($dir)) return [];

        $entries = scandir($dir) ?: [];
        $extPattern = implode('|', array_map(
            static fn(string $ext): string => preg_quote($ext, '/'),
            self::SCREENSHOT_EXTENSIONS
        ));
        $pattern = '/^(' . implode('|', self::PREVIEW_BASENAMES) . ')(?:-(\d+))?\.(' . $extPattern . ')$/i';

        $matches = [];
        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry, $m) !== 1) continue;

            $priority = array_search(strtolower($m[1]), self::PREVIEW_BASENAMES, true);
            $number   = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            $sortKey  = sprintf('%d-%05d-%s', $priority, $number, strtolower($entry));

            $matches[$sortKey] = lumora_theme_url($folder) . rawurlencode($entry);
        }
        ksort($matches);

        return array_values($matches);
    }

    /**
     * Validate an uploaded ZIP and install it as a brand-new theme folder
     * inside themes/. The destination folder name is derived from the
     * archive's own declared Theme Name header where present, else its
     * wrapping folder name, else a generated fallback slug — and must not
     * already exist (re-uploading for an existing folder is updateFromZip()
     * instead, a deliberate divergence from LumoraPress's ThemeInstaller,
     * which always hard-rejects an existing destination).
     *
     * @return array{success: bool, message: string, folder: string|null}
     */
    public static function installFromZip(string $tmpPath): array
    {
        return self::processZip($tmpPath, null);
    }

    /**
     * Validate an uploaded ZIP and replace an already-installed theme's
     * files with it in place (LG-043's one deliberate divergence from
     * LumoraPress's install-only ThemeInstaller). Never reachable for
     * anything under `custom themes/` — that directory lives entirely
     * outside themes/ and $folder is always validated against
     * lumora_list_themes() first, so only folders already inside themes/
     * are ever eligible targets.
     *
     * The extracted upload is staged to a temp folder first and only
     * swapped over the destination via rename() after every validation
     * check has passed, so a bad upload can never leave a half-extracted
     * theme live.
     *
     * @return array{success: bool, message: string, folder: string|null}
     */
    public static function updateFromZip(string $tmpPath, string $folder): array
    {
        if (!in_array($folder, lumora_list_themes(), true)) {
            return ['success' => false, 'message' => 'That theme could not be found.', 'folder' => null];
        }
        return self::processZip($tmpPath, $folder);
    }

    /**
     * Shared install/update ZIP pipeline. $targetFolder === null means
     * "install as a new theme"; a non-null value means "replace this
     * already-installed theme's files in place".
     *
     * @return array{success: bool, message: string, folder: string|null}
     */
    private static function processZip(string $tmpPath, ?string $targetFolder): array
    {
        if (!is_file($tmpPath)) {
            return ['success' => false, 'message' => 'The uploaded file could not be found.', 'folder' => null];
        }
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension is required. Enable ext-zip on your server.', 'folder' => null];
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpPath, \ZipArchive::RDONLY) !== true) {
            return ['success' => false, 'message' => 'The uploaded file is not a valid ZIP archive.', 'folder' => null];
        }

        $numFiles = $zip->count();
        if ($numFiles === 0) {
            $zip->close();
            return ['success' => false, 'message' => 'The uploaded archive is empty.', 'folder' => null];
        }
        if ($numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            return ['success' => false, 'message' => 'The archive contains too many files to be a valid theme package.', 'folder' => null];
        }

        $names = [];
        $totalUncompressed = 0;
        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) continue;

            $name = (string) $stat['name'];
            if (lumora_is_unsafe_zip_entry_name($name)) {
                $zip->close();
                return ['success' => false, 'message' => 'Archive contains an unsafe path entry: ' . $name . '. Aborting for security.', 'folder' => null];
            }

            $names[]            = $name;
            $totalUncompressed += (int) $stat['size'];
        }

        if ($totalUncompressed > self::MAX_ZIP_UNCOMPRESSED_SIZE) {
            $zip->close();
            return ['success' => false, 'message' => 'The archive is too large to be processed safely.', 'folder' => null];
        }

        $prefix        = self::detectRootPrefix($names);
        $templateEntry = $prefix . 'template.html';
        if (!in_array($templateEntry, $names, true)) {
            $zip->close();
            return ['success' => false, 'message' => 'The archive does not contain a template.html file and cannot be a valid Lumora theme.', 'folder' => null];
        }

        $themeName = self::readThemeNameFromZip($zip, $prefix, $templateEntry);

        if ($targetFolder === null) {
            $folder      = self::deriveSlug($themeName, $prefix);
            $destination = LUMORA_THEMES_PATH . $folder;
            if (is_dir($destination)) {
                $zip->close();
                return [
                    'success' => false,
                    'folder'  => null,
                    'message' => "A theme folder named \"{$folder}\" already exists. "
                        . 'Remove it first, or use the Update action on that theme\'s card instead.',
                ];
            }
        } else {
            $folder      = $targetFolder;
            $destination = LUMORA_THEMES_PATH . $folder;
        }

        $staging = LUMORA_THEMES_PATH . '.staging-' . bin2hex(random_bytes(8));
        if (!mkdir($staging, 0755, true)) {
            $zip->close();
            return ['success' => false, 'message' => 'Could not create a staging directory for the theme.', 'folder' => null];
        }

        $extracted = $zip->extractTo($staging);
        $zip->close();

        if (!$extracted) {
            UpdaterService::removeDirectory($staging);
            return ['success' => false, 'message' => 'Failed to extract the theme archive.', 'folder' => null];
        }

        // Post-extraction realpath guard, mirroring
        // UpdaterService::stageExtract()'s belt-and-braces check against
        // OS-specific path normalisation and symlink edge cases the
        // string-based pre-extraction check alone cannot catch.
        $canonStaging = rtrim((string) (realpath($staging) ?: $staging), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $resolved = realpath($item->getPathname());
            if ($resolved !== false && !str_starts_with($resolved . DIRECTORY_SEPARATOR, $canonStaging)) {
                UpdaterService::removeDirectory($staging);
                return [
                    'success' => false,
                    'folder'  => null,
                    'message' => 'Archive extraction produced a path outside the staging directory. Aborting for security.',
                ];
            }
        }

        $extractedRoot = $prefix !== ''
            ? rtrim($staging . DIRECTORY_SEPARATOR . rtrim($prefix, '/'), DIRECTORY_SEPARATOR)
            : rtrim($staging, DIRECTORY_SEPARATOR);

        if (!is_dir($extractedRoot) || !file_exists($extractedRoot . DIRECTORY_SEPARATOR . 'template.html')) {
            UpdaterService::removeDirectory($staging);
            return ['success' => false, 'message' => 'The archive does not have the expected theme folder structure.', 'folder' => null];
        }

        if ($targetFolder !== null) {
            // Update-in-place: swap the old folder out and the new one in
            // via two fast local rename() calls, so the window where
            // $destination doesn't exist at all is as small as the
            // filesystem allows — this is the "atomic enough" guarantee
            // LG-043 asks for; true atomicity would need a symlink
            // indirection layer this simpler feature doesn't have.
            $displaced = $destination . '.replaced-' . bin2hex(random_bytes(4));
            if (!@rename($destination, $displaced)) {
                UpdaterService::removeDirectory($staging);
                return ['success' => false, 'message' => 'Could not remove the existing theme files before updating.', 'folder' => null];
            }
            if (!@rename($extractedRoot, $destination)) {
                @rename($displaced, $destination);
                UpdaterService::removeDirectory($staging);
                return ['success' => false, 'message' => 'Could not install the updated theme files; the previous version was restored.', 'folder' => null];
            }
            UpdaterService::removeDirectory($displaced);
        } else {
            if (!@rename($extractedRoot, $destination)) {
                UpdaterService::removeDirectory($staging);
                return ['success' => false, 'message' => 'Could not install the theme.', 'folder' => null];
            }
        }

        UpdaterService::removeDirectory($staging);

        return [
            'success' => true,
            'folder'  => $folder,
            'message' => $targetFolder !== null
                ? 'Theme "' . $folder . '" updated.'
                : 'Theme "' . $folder . '" installed.',
        ];
    }

    /**
     * Delete a non-active, non-bundled installed theme.
     *
     * @return array{success: bool, message: string}
     */
    public static function deleteTheme(string $folder): array
    {
        if (!in_array($folder, lumora_list_themes(), true)) {
            return ['success' => false, 'message' => 'That theme could not be found.'];
        }
        if ($folder === (string) lumora_config('theme', 'default')) {
            return ['success' => false, 'message' => 'The active theme cannot be deleted. Activate a different theme first.'];
        }
        if (in_array($folder, self::PROTECTED_THEMES, true)) {
            return ['success' => false, 'message' => 'The bundled "' . $folder . '" theme cannot be deleted.'];
        }

        UpdaterService::removeDirectory(LUMORA_THEMES_PATH . $folder);

        if (is_dir(LUMORA_THEMES_PATH . $folder)) {
            return ['success' => false, 'message' => 'The theme could not be fully removed. Check file permissions.'];
        }

        return ['success' => true, 'message' => 'Theme deleted.'];
    }

    /**
     * Read the "Theme Name" header out of a ZIP archive's primary
     * stylesheet before anything is extracted, mirroring
     * lumora_get_theme_meta()'s on-disk logic: find the first
     * {THEME_URL}*.css reference in template.html, then read that
     * stylesheet's own leading CSS comment block.
     */
    private static function readThemeNameFromZip(\ZipArchive $zip, string $prefix, string $templateEntry): string
    {
        $templateContent = $zip->getFromName($templateEntry);
        if ($templateContent === false) return '';
        if (!preg_match('/\{THEME_URL\}([A-Za-z0-9_\-.]+\.css)/', $templateContent, $m)) return '';

        $cssContent = $zip->getFromName($prefix . $m[1]);
        if ($cssContent === false) return '';
        if (!preg_match('#/\*(.*?)\*/#s', $cssContent, $cm)) return '';

        if (preg_match('/^[ \t*]*Theme Name\s*:\s*(.+)$/mi', $cm[1], $nm)) {
            return trim($nm[1]);
        }
        return '';
    }

    /**
     * Detect a single wrapping top-level folder (a GitHub-export-style
     * ThemeName-1.0.0/ zip) so it can be flattened on extract, mirroring
     * UpdaterService::findAppRoot()'s equivalent depth check for
     * version.php.
     *
     * @param list<string> $names
     */
    private static function detectRootPrefix(array $names): string
    {
        if (in_array('template.html', $names, true)) return '';

        foreach ($names as $name) {
            if (str_ends_with($name, '/template.html') && substr_count($name, '/') === 1) {
                return substr($name, 0, -strlen('template.html'));
            }
        }
        return '';
    }

    /**
     * Derive a destination folder name from the theme's declared name,
     * falling back to its zip wrapping-folder name, then a generated slug.
     */
    private static function deriveSlug(string $themeName, string $prefix): string
    {
        $slug = self::slugify($themeName);
        if ($slug !== '') return $slug;

        $fromPrefix = self::slugify(rtrim($prefix, '/'));
        return $fromPrefix !== '' ? $fromPrefix : 'theme-' . bin2hex(random_bytes(4));
    }

    private static function slugify(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }
}
