<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Install Ping Service
 *
 * Opt-in, off-by-default anonymous install counter. When enabled, sends a
 * minimal ping (install UUID, Lumora version, PHP version — nothing else)
 * to a Lumora-hosted endpoint separate from UpdateService's release-check
 * source, so enabling/disabling one never affects the other. The UUID has
 * no relation to any other stored value, so a ping can't be correlated
 * back to a specific site.
 *
 * maybeSendPing() runs on every admin page load but is a cheap no-op
 * unless the feature is enabled and the roughly-monthly interval has
 * elapsed. Every failure mode is swallowed silently — this must never
 * produce a user-facing error or block any admin action.
 *
 * @package    LumoraGallery
 * @subpackage Installer
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.11.0
 * @see        UpdateService Its release-check source is deliberately separate from this ping.
 * @see        AbstractUpdateProvider The GitHub Releases API source UpdateService uses instead.
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class InstallPingService
{
    /**
     * Dedicated anonymous-install-count endpoint. Deliberately distinct from
     * UpdateService's release-check source (the GitHub Releases API) — the
     * two features must stay fully independent.
     */
    private const ENDPOINT = 'https://coding.unloved-heart.net/lumoragallery/install-tracking-server/ping.php';

    /** Minimum interval between pings once enabled (~30 days). */
    private const PING_INTERVAL = 2592000;

    /** HTTP request timeout in seconds. */
    private const FETCH_TIMEOUT = 5;

    /** Config key — opt-in toggle. Stored as '1'/'0'; default off. */
    private const CFG_ENABLED = 'install_ping_enabled';

    /** Config key — randomly generated install identifier. */
    private const CFG_UUID = 'install_uuid';

    /** Config key — Unix timestamp of the last ping attempt (sent or failed). */
    private const CFG_LAST_SENT_AT = 'install_ping_last_sent_at';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return true when the opt-in install ping is enabled in config.
     */
    public static function isEnabled(): bool
    {
        return LumoraConfig::get(self::CFG_ENABLED, '0') === '1';
    }

    /**
     * Return the persisted install UUID, generating and persisting a new
     * one on first call if none exists yet.
     *
     * The UUID is generated once, the very first time it's needed (either
     * at install time in practice, since this is typically first called
     * when the feature is enabled, or on first ping attempt), and then
     * reused for the lifetime of the installation.
     */
    public static function getOrCreateUuid(): string
    {
        $uuid = trim((string) LumoraConfig::get(self::CFG_UUID, ''));
        if ($uuid !== '') {
            return $uuid;
        }

        $uuid = self::generateUuidV4();
        try {
            LumoraConfig::set(self::CFG_UUID, $uuid);
        } catch (\Throwable) {
            // Non-fatal — if the write failed to persist, the next call
            // will simply generate (and attempt to persist) a new one.
        }

        return $uuid;
    }

    /**
     * Called on every admin page load. A cheap no-op in the overwhelming
     * majority of calls: returns immediately when the feature is disabled,
     * and only performs the actual network request when the ping interval
     * has elapsed since the last attempt.
     */
    public static function maybeSendPing(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $last = (int) LumoraConfig::get(self::CFG_LAST_SENT_AT, '0');
        if ($last !== 0 && (time() - $last) < self::PING_INTERVAL) {
            return;
        }

        self::sendPing();
    }

    /**
     * Perform the actual network request and record the attempt timestamp
     * regardless of outcome, so a persistently unreachable endpoint is
     * retried on the next monthly interval rather than on every subsequent
     * page load.
     *
     * Public (rather than folded into maybeSendPing()) so admin/config.php
     * can trigger an immediate first ping the moment the feature is switched
     * on, instead of waiting for the next admin page load to notice the
     * interval has elapsed.
     *
     * Reuses the same stream-context / error-suppression pattern
     * AbstractUpdateProvider::httpGet() uses, so both endpoints behave
     * identically under network failure and remain mockable via the same
     * MockHttpTransport test helper.
     */
    public static function sendPing(): void
    {
        $payload = json_encode([
            'install_uuid' => self::getOrCreateUuid(),
            'version'      => LUMORA_VERSION,
            'php_version'  => PHP_VERSION,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => "Content-Type: application/json\r\n",
                'content'         => $payload,
                'timeout'         => self::FETCH_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'Lumora Gallery/' . LUMORA_VERSION
                    . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Temporarily install a no-op error handler so a failed connection
        // does not write an E_WARNING to the PHP error log — this is a
        // best-effort, fire-and-forget ping with no retry logic beyond the
        // next scheduled interval, so a network failure is an entirely
        // expected, non-exceptional outcome.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            file_get_contents(self::ENDPOINT, false, $ctx);
        } finally {
            restore_error_handler();
        }

        // Record the attempt regardless of success/failure.
        try {
            LumoraConfig::set(self::CFG_LAST_SENT_AT, (string) time());
        } catch (\Throwable) {
            // Non-fatal.
        }
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Generate a random RFC 4122 version-4 UUID.
     */
    private static function generateUuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10

        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
