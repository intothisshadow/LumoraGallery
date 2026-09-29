<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Alert Service
 *
 * Builds the condensed alerts shown at the top of the admin sidebar
 * (maintenance mode, database update required, core/plugin/theme updates).
 * Cache-only: no network call happens while a page renders. The list is
 * passed through the `admin_sidebar_alerts` filter so plugins can add their
 * own; entries use the same shape as Lumora Press's filter of that name.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.21.0
 * @see        lum_admin_page() Renders the returned alerts.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class AdminAlertService
{
    /**
     * Alerts for the current user, highest priority first. `label` is
     * trusted HTML; everything else is plain text.
     *
     * @param array<string, mixed>|null $user Current user, passed to the filter (defaults to lumora_current_user()).
     * @return list<array{variant: string, url: string, title: string, label: string}>
     */
    public static function collect(?array $user = null): array
    {
        $admin  = lumora_base_url() . 'admin/';
        $alerts = [];

        if (lumora_has_permission('site_configuration')) {
            if (UpdaterService::isUpdateRunning()) {
                $alerts[] = [
                    'variant' => 'warning',
                    'url'     => $admin . 'update.php',
                    'title'   => 'An update is running and visitors currently see the maintenance page.',
                    'label'   => 'Update in progress',
                ];
            } elseif (self::isOffline()) {
                $alerts[] = UpdaterService::maintenanceStartedByUpdater()
                    ? [
                        'variant' => 'error',
                        'url'     => $admin . 'update.php',
                        'title'   => 'An update left the gallery offline. Visitors see the maintenance page until it is turned off on Admin → Updates.',
                        'label'   => 'Update left gallery <strong>offline</strong>',
                    ]
                    : [
                        'variant' => 'warning',
                        'url'     => $admin . 'config.php#gallery_offline',
                        'title'   => 'Visitors currently see the maintenance page instead of the gallery. Turn it off with Gallery Offline Mode in Configuration.',
                        'label'   => 'Gallery is <strong>offline</strong>',
                    ];
            }
        }

        if (lumora_has_permission('view_updates')) {
            if (SchemaService::hasPendingMigrations()) {
                $alerts[] = [
                    'variant' => 'warning',
                    'url'     => $admin . 'update.php',
                    'title'   => 'Lumora has database changes that need to be applied. Open Admin → Updates to run them.',
                    'label'   => '<strong>Database update</strong> required',
                ];
            }

            $status = UpdateService::getCachedStatus();
            if ($status['status'] === 'update_available' && $status['latest'] !== null) {
                $alerts[] = [
                    'variant' => 'info',
                    'url'     => $admin . 'update.php',
                    'title'   => 'Lumora ' . $status['latest'] . ' is available. You are running ' . LUMORA_VERSION . '.',
                    'label'   => 'Lumora <strong>' . h($status['latest']) . '</strong> is available',
                ];
            }
        }

        if (lumora_has_permission('site_configuration')) {
            $plugins = count(PluginUpdateService::availableUpdates());
            $themes  = count(ThemeUpdateService::availableUpdates());
            if ($plugins + $themes > 0) {
                $parts = [];
                if ($plugins > 0) $parts[] = '<strong>' . $plugins . '</strong> plugin update' . ($plugins === 1 ? '' : 's');
                if ($themes > 0)  $parts[] = '<strong>' . $themes . '</strong> theme update' . ($themes === 1 ? '' : 's');

                $alerts[] = [
                    'variant' => 'info',
                    'url'     => $admin . ($plugins > 0 ? 'plugins.php' : 'appearance.php'),
                    'title'   => 'Updates are available for bundled plugins or themes.',
                    'label'   => implode(', ', $parts),
                ];
            }
        }

        /** @var list<array{variant: string, url: string, title: string, label: string}> */
        return array_values(array_filter(
            (array) HookService::applyFilters('admin_sidebar_alerts', $alerts, $user ?? lumora_current_user()),
            static fn(mixed $a): bool => is_array($a) && isset($a['variant'], $a['url'], $a['title'], $a['label'])
        ));
    }

    /** Render the alerts as the sidebar's single-line links ('' when there are none). */
    public static function renderHtml(array $alerts): string
    {
        $html = '';
        foreach ($alerts as $a) {
            $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $a['variant'])) ?: 'info';
            $html .= '<a class="lum-admin-sidebar-alert lum-admin-sidebar-alert--' . $variant . '"'
                . ' href="' . h((string) $a['url']) . '" title="' . h((string) $a['title']) . '">'
                . $a['label'] . '</a>';
        }
        return $html;
    }

    private static function isOffline(): bool
    {
        return (string) LumoraConfig::get('gallery_offline', '0') === '1';
    }
}
