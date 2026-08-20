<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Hook Service
 *
 * Minimal action/filter registry (LG-045) so feature plugins can extend core
 * behaviour — logging a pageview, adding an admin nav item, adding a
 * dashboard widget — without any core file needing to know a specific
 * plugin exists. A plugin's bootstrap.php (loaded by PluginService when the
 * plugin is enabled) registers callbacks here; core code calls doAction()/
 * applyFilters() at fixed extension points and never references a plugin
 * class directly.
 *
 * Actions (doAction): fire-and-forget notifications — zero or more
 * registered callbacks are invoked with the given arguments; their return
 * values are discarded.
 *
 * Filters (applyFilters): every registered callback receives the current
 * value (plus any extra args) and must return the (possibly modified)
 * value, which is passed to the next callback in the chain.
 *
 * Both run in ascending priority order (lower runs first), matching the
 * WordPress action/filter convention this design is deliberately modelled
 * on, since it is a well-understood, minimal shape for this kind of
 * registry.
 *
 * @package    LumoraGallery
 * @subpackage Plugins
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.16.0
 * @see        PluginService Discovers and loads the plugins that call addAction()/addFilter().
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class HookService
{
    /** @var array<string, list<array{priority: int, callback: callable}>> */
    private static array $actions = [];

    /** @var array<string, list<array{priority: int, callback: callable}>> */
    private static array $filters = [];

    /**
     * Register a callback to run when $hook fires via doAction().
     */
    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        self::$actions[$hook][] = ['priority' => $priority, 'callback' => $callback];
    }

    /**
     * Invoke every callback registered for $hook, in priority order.
     * Return values are discarded — use a filter instead if a value needs
     * to flow back to the caller.
     */
    public static function doAction(string $hook, mixed ...$args): void
    {
        if (empty(self::$actions[$hook])) return;

        foreach (self::sorted(self::$actions[$hook]) as $entry) {
            ($entry['callback'])(...$args);
        }
    }

    /**
     * Register a callback to run when $hook is applied via applyFilters().
     */
    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        self::$filters[$hook][] = ['priority' => $priority, 'callback' => $callback];
    }

    /**
     * Pass $value through every callback registered for $hook, in priority
     * order, and return the final (possibly modified) value. With no
     * callbacks registered, $value is returned unchanged.
     */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (empty(self::$filters[$hook])) return $value;

        foreach (self::sorted(self::$filters[$hook]) as $entry) {
            $value = ($entry['callback'])($value, ...$args);
        }
        return $value;
    }

    /**
     * Stable-sort registered entries by ascending priority.
     *
     * @param list<array{priority: int, callback: callable}> $entries
     * @return list<array{priority: int, callback: callable}>
     */
    private static function sorted(array $entries): array
    {
        usort($entries, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        return $entries;
    }
}
