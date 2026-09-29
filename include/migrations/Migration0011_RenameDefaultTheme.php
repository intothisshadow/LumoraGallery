<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Migration 0011
 *
 * Switches the active theme from the pre-rename slug `default` to
 * `lumora-classic` (DB version 17), but only when the new folder is
 * actually installed. Idempotent; a no-op for any other active theme.
 *
 * @package    LumoraGallery
 * @subpackage Database
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.20.0
 * @see        AbstractMigration Base class every migration extends.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class Migration0011_RenameDefaultTheme extends AbstractMigration
{
    public function up(): void
    {
        $prefix = LumoraDB::prefix();
        $target = ThemeService::BUNDLED_THEME;

        if (!is_file(LUMORA_THEMES_PATH . $target . DIRECTORY_SEPARATOR . 'template.html')) {
            return;
        }

        LumoraDB::query(
            "UPDATE `{$prefix}config` SET `value` = ? WHERE `name` = 'theme' AND `value` = ?",
            [$target, ThemeService::LEGACY_BUNDLED_THEME]
        );
    }

    public function down(): void
    {
        // Not reversible: the previous slug's folder may no longer exist.
    }
}
