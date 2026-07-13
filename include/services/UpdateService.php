<?php

declare(strict_types=1);
/**
 * Lumora Gallery — Update Service
 *
 * Checks for new Lumora releases via the configured release provider
 * (see AbstractUpdateProvider::createFromConfig() — GitHubUpdateProvider,
 * backed by the GitHub Releases API, by default). Results are cached in the
 * config table for 24 hours so the provider is never hit on every page load.
 *
 * This class previously queried a fixed JSON endpoint hosted on the Lumora
 * website (coding.unloved-heart.net/lumora/update.json). That dependency has
 * been removed entirely — release discovery now goes through the same
 * provider abstraction UpdaterService already used for download URLs and
 * SHA-256 checksums, so there is exactly one source of truth for "what is
 * the latest release" rather than two separate mechanisms that could
 * disagree. The repository queried is configurable via the
 * `update_github_repo` config key (see GitHubUpdateProvider), so forks can
 * point this at their own release source without code changes.
 *
 * Privacy: only a plain GET request is sent to the provider's public API —
 * no gallery content, user data, image data, or analytics information is
 * ever transmitted.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class UpdateService
{
    /** Cache TTL in seconds (24 hours). */
    private const CACHE_TTL = 86400;

    /** Config key — raw JSON payload from the last successful fetch. */
    private const CACHE_JSON = 'update_check_cache';

    /** Config key — Unix timestamp of the last fetch attempt. */
    private const CACHE_AT = 'update_check_at';

    // ── Cache helpers ─────────────────────────────────────────────────────────

    /**
     * Return true when no valid cache entry exists or the cache is older
     * than CACHE_TTL seconds.
     */
    public static function isCacheExpired(): bool
    {
        $at = (int) LumoraConfig::get(self::CACHE_AT, '0');
        return $at === 0 || (time() - $at) >= self::CACHE_TTL;
    }

    /**
     * Return the decoded remote payload from the cache, or null when absent
     * or unparseable.
     *
     * @return array<string, mixed>|null
     */
    public static function getCachedPayload(): ?array
    {
        $json = LumoraConfig::get(self::CACHE_JSON, '');
        if ($json === '') return null;
        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the update status built from the cache only — no network call.
     *
     * Callers that must never block on I/O (admin nav, dashboard) should use
     * this method.  Returns status 'unknown' when no cache entry exists yet.
     *
     * @return array{
     *   status: 'update_available'|'up_to_date'|'error'|'unknown',
     *   installed: string,
     *   latest: string|null,
     *   release_date: string|null,
     *   download_url: string|null,
     *   changelog_url: string|null,
     *   minimum_php: string|null,
     *   checked_at: int|null,
     *   error: string|null
     * }
     */
    public static function getCachedStatus(): array
    {
        return self::buildStatus(data: self::getCachedPayload(), error: null);
    }

    /**
     * Return the full update status, refreshing from the configured release
     * provider when the cache is expired or $force is true.
     *
     * On network failure the most-recent stale cache is used as a fallback so
     * admins always see the last known state rather than a blank error page.
     *
     * @param bool $force  Bypass the TTL and always query the provider.
     *
     * @return array{
     *   status: 'update_available'|'up_to_date'|'error'|'unknown',
     *   installed: string,
     *   latest: string|null,
     *   release_date: string|null,
     *   download_url: string|null,
     *   changelog_url: string|null,
     *   minimum_php: string|null,
     *   checked_at: int|null,
     *   error: string|null
     * }
     */
    public static function check(bool $force = false): array
    {
        if (!$force && !self::isCacheExpired()) {
            return self::buildStatus(data: self::getCachedPayload(), error: null);
        }

        $data  = self::fetch();
        $error = null;

        if ($data === null) {
            // Network failure — fall back to stale cache if one is available.
            $error = 'Could not reach the release provider.';
            $data  = self::getCachedPayload();
        }

        return self::buildStatus(data: $data, error: $error);
    }

    /**
     * Return true when the cached status shows an update is available.
     * Never makes a network call.
     */
    public static function hasCachedUpdate(): bool
    {
        return self::getCachedStatus()['status'] === 'update_available';
    }

    /**
     * Return a human-readable label for the configured release provider and
     * source, e.g. "GitHub Releases (intothisshadow/Lumora)". Used in the
     * admin "About Updates" panel in place of the old fixed endpoint URL.
     */
    public static function getProviderLabel(): string
    {
        $provider = AbstractUpdateProvider::createFromConfig();
        return $provider->getName() . ' (' . $provider->getSourceLabel() . ')';
    }

    // ── Network ───────────────────────────────────────────────────────────────

    /**
     * Query the configured release provider, persist the payload to the
     * config cache, and return the decoded array.
     *
     * Returns null on any network or parse failure; the cache is only updated
     * on success so a stale entry survives transient network issues.
     *
     * Transmits only a plain GET request — no gallery data is sent.
     *
     * @return array<string, mixed>|null
     */
    public static function fetch(): ?array
    {
        $provider = AbstractUpdateProvider::createFromConfig();

        try {
            $data = $provider->fetchMetadata();
        } catch (\Throwable) {
            return null;
        }

        if ($data === null) return null;

        // Persist to cache (non-fatal if the DB write fails).
        try {
            LumoraConfig::set(self::CACHE_JSON, json_encode($data, JSON_THROW_ON_ERROR));
            LumoraConfig::set(self::CACHE_AT,   (string) time());
        } catch (\Throwable) {
            // Proceed — return the payload even if caching fails.
        }

        return $data;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Build the canonical status array from a decoded remote payload and an
     * optional error string.  Every key is always present so callers never
     * need to guard individual fields with isset().
     *
     * @param array<string, mixed>|null $data
     * @return array{
     *   status: 'update_available'|'up_to_date'|'error'|'unknown',
     *   installed: string,
     *   latest: string|null,
     *   release_date: string|null,
     *   download_url: string|null,
     *   changelog_url: string|null,
     *   minimum_php: string|null,
     *   checked_at: int|null,
     *   error: string|null
     * }
     */
    private static function buildStatus(?array $data, ?string $error): array
    {
        $installed      = LUMORA_VERSION;
        $checked_at_raw = (int) LumoraConfig::get(self::CACHE_AT, '0');
        $checked_at     = $checked_at_raw > 0 ? $checked_at_raw : null;

        if ($data === null) {
            return [
                'status'        => $error !== null ? 'error' : 'unknown',
                'installed'     => $installed,
                'latest'        => null,
                'release_date'  => null,
                'download_url'  => null,
                'changelog_url' => null,
                'minimum_php'   => null,
                'checked_at'    => $checked_at,
                'error'         => $error ?? 'No update information available yet.',
            ];
        }

        $latest        = isset($data['latest_version']) ? trim((string) $data['latest_version']) : null;
        $release_date  = isset($data['release_date'])   ? trim((string) $data['release_date'])   : null;
        $download_url  = isset($data['download_url'])   ? trim((string) $data['download_url'])   : null;
        $changelog_url = isset($data['changelog_url'])  ? trim((string) $data['changelog_url'])  : null;
        $minimum_php   = isset($data['minimum_php'])    ? trim((string) $data['minimum_php'])    : null;

        if ($latest === null || $latest === '') {
            return [
                'status'        => 'error',
                'installed'     => $installed,
                'latest'        => null,
                'release_date'  => $release_date,
                'download_url'  => $download_url,
                'changelog_url' => $changelog_url,
                'minimum_php'   => $minimum_php,
                'checked_at'    => $checked_at,
                'error'         => 'Release provider returned an unrecognised response.',
            ];
        }

        $status = version_compare($installed, $latest, '<')
            ? 'update_available'
            : 'up_to_date';

        return [
            'status'        => $status,
            'installed'     => $installed,
            'latest'        => $latest,
            'release_date'  => $release_date,
            'download_url'  => $download_url,
            'changelog_url' => $changelog_url,
            'minimum_php'   => $minimum_php,
            'checked_at'    => $checked_at,
            'error'         => $error,
        ];
    }
}
