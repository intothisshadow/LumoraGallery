<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Server Environment Service
 *
 * Detects the current web server (LiteSpeed/OpenLiteSpeed vs. Apache, nginx,
 * Caddy, or anything else) and, where possible from PHP alone, a handful of
 * related capabilities (HTTP/2, HTTP/3, Brotli, an active LiteSpeed Cache).
 * Used by the admin Installation page's System Information panel and by
 * CacheHeaderService to decide whether LiteSpeed-specific behaviour (LSCache
 * purge headers) applies to the current request.
 *
 * Detection relies entirely on PHP superglobals — no LiteSpeed-specific PHP
 * extension or API is required, so every check degrades to a safe "false"/
 * "Unknown" on Apache, nginx, Caddy, or any other server (LG-033).
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class ServerEnvironmentService
{
    /**
     * Detect the current web server and a handful of related capabilities.
     *
     * `brotli` is a heuristic, not a guarantee: PHP has no portable way to
     * ask the web server which compression modules are active, so it is
     * reported as available only when the server identifies as LiteSpeed
     * (which has shipped Brotli support natively since LSWS 5.4) and the
     * requesting client's `Accept-Encoding` header advertises `br` support.
     *
     * `lscache_active` reflects the `X-LSCACHE` marker LiteSpeed's cache
     * module adds to requests it is actively handling — it is only ever
     * present on LiteSpeed/OpenLiteSpeed, so its presence also implies
     * `is_litespeed`.
     *
     * @return array{
     *   raw:            string,
     *   name:           string,
     *   is_litespeed:   bool,
     *   http2:          bool,
     *   http3:          bool,
     *   brotli:         bool,
     *   lscache_active: bool,
     * }
     */
    public static function detect(): array
    {
        $raw = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';

        $lscache_marker = isset($_SERVER['X-LSCACHE']) || isset($_SERVER['HTTP_X_LSCACHE']);
        $is_litespeed   = str_contains($raw, 'LiteSpeed') || $lscache_marker;

        $name = match (true) {
            $is_litespeed && str_contains($raw, 'OpenLiteSpeed') => 'OpenLiteSpeed',
            $is_litespeed                                        => 'LiteSpeed',
            str_contains($raw, 'nginx')                          => 'nginx',
            str_contains($raw, 'Apache')                         => 'Apache',
            str_contains($raw, 'Caddy')                          => 'Caddy',
            $raw !== ''                                          => $raw,
            default                                              => 'Unknown',
        };

        $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : '';
        $http2    = str_contains($protocol, 'HTTP/2');
        $http3    = str_contains($protocol, 'HTTP/3');

        $accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        $brotli          = $is_litespeed && str_contains($accept_encoding, 'br');

        return [
            'raw'            => $raw !== '' ? $raw : 'Unknown',
            'name'           => $name,
            'is_litespeed'   => $is_litespeed,
            'http2'          => $http2,
            'http3'          => $http3,
            'brotli'         => $brotli,
            'lscache_active' => $lscache_marker,
        ];
    }
}
