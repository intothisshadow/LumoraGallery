<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Login
 *
 * Rate limiting: failed login attempts are tracked per IP in
 * cache/.login_ratelimit.json.  After 5 failures within 15 minutes a
 * server-side delay is enforced and an error is shown.  Every individual
 * failure adds a 1-second delay to slow automated guessing.  The record for
 * the source IP is cleared on any successful authentication.
 *
 * The post-login `redirect` destination is validated by
 * lumora_safe_redirect_target() (functions.php) to reject protocol-relative
 * targets like "//evil.com", which also start with '/' but browsers treat
 * as an off-site redirect. See TODO-security.md #3.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

// Already logged in → go to dashboard.
if (lumora_is_logged_in()) {
    lumora_redirect(lumora_base_url() . 'admin/dashboard.php');
}

// ── Rate limiting ─────────────────────────────────────────────────────────────
// Track failed attempts per IP in cache/.login_ratelimit.json.
// Window: 15 minutes.  Limit: 5 failures before lockout.
// Every single failure also adds a 1-second server delay to slow brute force.
//
// The entire read-prune-decide[-write] cycle for a request happens inside a
// single exclusive flock() hold (see $rl_with_lock below) rather than
// separate unlocked reads and LOCK_EX-only writes. Previously, two
// concurrent requests from the same IP could each read a stale (pre-write)
// failure count and both be let through before either observed the other's
// write, letting more than $rl_max failures slip past the lockout. See
// TODO-security.md #6.

$rl_ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$rl_file   = LUMORA_ROOT . 'cache' . DIRECTORY_SEPARATOR . '.login_ratelimit.json';
$rl_window = 900;   // 15-minute sliding window
$rl_max    = 5;     // failures before lockout
$rl_now    = time();

/**
 * Open the rate-limit store, acquire an exclusive lock for the lifetime of
 * $callback, prune stale entries, and hand the pruned map to $callback along
 * with a $write closure that persists a replacement map before the lock is
 * released. Degrades to an empty, unwritable in-memory map (no lockout, no
 * persistence) if the cache directory or file cannot be opened/locked, so a
 * filesystem hiccup fails open on rate limiting rather than blocking login
 * entirely.
 *
 * @template T
 * @param callable(array<string, list<int>>, callable(array<string, list<int>>): void): T $callback
 * @return T
 */
$rl_with_lock = static function (callable $callback) use ($rl_file, $rl_window, $rl_now): mixed {
    $noop_write = static function (array $ignored): void {};

    $dir = dirname($rl_file);
    if (!is_dir($dir)) {
        return $callback([], $noop_write);
    }

    $fh = @fopen($rl_file, 'c+');
    if ($fh === false) {
        return $callback([], $noop_write);
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return $callback([], $noop_write);
    }

    try {
        $raw     = stream_get_contents($fh);
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        $data    = is_array($decoded) ? $decoded : [];

        // Prune timestamps outside the window.
        foreach ($data as $ip => &$times) {
            $times = array_values(array_filter(
                is_array($times) ? $times : [],
                static fn(mixed $t): bool => is_int($t) && ($rl_now - $t) < $rl_window
            ));
            if (empty($times)) {
                unset($data[$ip]);
            }
        }
        unset($times);

        $write = static function (array $newData) use ($fh): void {
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($newData, JSON_UNESCAPED_SLASHES));
            fflush($fh);
        };

        return $callback($data, $write);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
};

// Read-only snapshot for the initial GET-time lockout check and disabled
// form state. The POST path below re-checks (and mutates) the lock state
// under its own fresh lock acquisition rather than trusting this snapshot,
// so a request that arrives concurrently with another IP's write always
// sees the up-to-date count at the moment it actually matters.
$rl_data     = $rl_with_lock(static fn(array $data): array => $data);
$rl_failures = count($rl_data[$rl_ip] ?? []);
$rl_locked   = ($rl_failures >= $rl_max);

// ── Request handling ──────────────────────────────────────────────────────────
$error    = '';
$redirect = filter_var($_GET['redirect'] ?? '', FILTER_SANITIZE_URL);
$csrf     = lumora_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    // Re-check the lockout state under a fresh exclusive lock immediately
    // before deciding whether to process credentials, rather than trusting
    // the snapshot read above — this is the actual TOCTOU-closing step.
    $rl_locked = $rl_with_lock(
        static fn(array $data): bool => count($data[$rl_ip] ?? []) >= $rl_max
    );

    if ($rl_locked) {
        // Enforce a delay and refuse; do not process credentials.
        sleep(2);
        $error = 'Too many failed login attempts from your IP address. '
               . 'Please wait a few minutes before trying again.';
    } else {
        $user = lumora_login(
            trim($_POST['username'] ?? ''),
            $_POST['password'] ?? '',
            isset($_POST['remember_me'])
        );

        if ($user) {
            // Success — clear rate-limit record for this IP.
            // Any active staff role (admin, moderator, contributor) may log in;
            // page-level access within the panel is enforced per-permission.
            $rl_with_lock(static function (array $data, callable $write) use ($rl_ip): null {
                unset($data[$rl_ip]);
                $write($data);
                return null;
            });
            $dest = lumora_safe_redirect_target($redirect, lumora_base_url() . 'admin/dashboard.php');
            lumora_redirect($dest);
        } else {
            // Failure — record attempt (read, append, write, all under one
            // lock hold) and add a per-failure delay.
            $rl_failure_count = $rl_with_lock(
                static function (array $data, callable $write) use ($rl_ip, $rl_now): int {
                    $data[$rl_ip][] = $rl_now;
                    $write($data);
                    return count($data[$rl_ip]);
                }
            );
            usleep(1_000_000); // 1-second delay on every failure
            // If this failure just tripped the limit, upgrade the message.
            if ($rl_failure_count >= $rl_max) {
                $error = 'Too many failed login attempts. '
                       . 'Please wait a few minutes before trying again.';
            } else {
                // Generic error — do not reveal whether the username exists.
                $error = 'Invalid username or password.';
            }
        }
    }
}

// ── Page output ───────────────────────────────────────────────────────────────
$base_url   = h(lumora_base_url());
$err_html   = $error ? '<div class="alert alert-danger py-2">' . h($error) . '</div>' : '';
$csrf_h     = h($csrf);
$gal_name   = h(lumora_config('gallery_name', 'Lumora Gallery'));
$redir_h    = h($redirect);
$forgot_url = h(lumora_base_url() . 'admin/forgot_password.php');

// Disable the form inputs while the IP is locked out.
$disabled = $rl_locked ? ' disabled' : '';

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login — {$gal_name}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="{$base_url}admin/admin.css">
  <style>
    body { background:#f0f2f5; }
    .login-card { max-width: 400px; margin: 5rem auto; }
    .login-header { background:#1a1a2e; color:#fff; padding:1.5rem; border-radius:.5rem .5rem 0 0; }
    .login-header h1 { font-size:1.3rem; margin:0; }
  </style>
</head>
<body>
<div class="login-card card shadow-sm">
  <div class="login-header">
    <h1>⚡ Lumora Gallery Admin</h1>
    <small class="opacity-75">{$gal_name}</small>
  </div>
  <div class="card-body p-4">
    {$err_html}
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="{$csrf_h}">
      <input type="hidden" name="redirect"   value="{$redir_h}">
      <div class="mb-3">
        <label class="form-label fw-semibold">Username</label>
        <input type="text" name="username" class="form-control" autofocus autocomplete="username" required{$disabled}>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Password</label>
        <input type="password" name="password" class="form-control" autocomplete="current-password" required{$disabled}>
      </div>
      <div class="mb-4 d-flex align-items-center gap-2">
        <input type="checkbox" class="form-check-input mt-0" id="lum-remember" name="remember_me" value="1"{$disabled}>
        <label class="form-check-label text-muted small" for="lum-remember">Stay logged in for 30 days</label>
      </div>
      <button type="submit" class="btn btn-primary w-100"{$disabled}>Log In</button>
    </form>
    <div class="text-center mt-3">
      <a href="{$forgot_url}" class="text-muted small">Forgot password?</a>
    </div>
  </div>
  <div class="card-footer text-center text-muted small py-2">
    <a href="{$base_url}">← Back to gallery</a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
