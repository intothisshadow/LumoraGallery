<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Admin Login
 *
 * Rate limiting: failed login attempts are tracked per IP by
 * RateLimitService, shared with reset-password.php (the emergency, no-login
 * password reset in the gallery root — both are equally sensitive
 * unauthenticated admin-auth surfaces). After 5 failures within 15 minutes a
 * server-side delay is enforced and an error is shown. Every individual
 * failure adds a 1-second delay to slow automated guessing. The record for
 * the source IP is cleared on any successful authentication.
 *
 * The post-login `redirect` destination is validated by
 * lumora_safe_redirect_target() (functions.php) to reject protocol-relative
 * targets like "//evil.com", which also start with '/' but browsers treat
 * as an off-site redirect.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.0.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

// Already logged in → go to dashboard.
if (lumora_is_logged_in()) {
    lumora_redirect(lumora_base_url() . 'admin/dashboard.php');
}

// ── Rate limiting ─────────────────────────────────────────────────────────────
$rl_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// Read-only snapshot for the initial GET-time lockout check and disabled
// form state. The POST path below re-checks the lock state via a fresh
// RateLimitService::isLocked() call rather than trusting this snapshot, so a
// request that arrives concurrently with another IP's write always sees the
// up-to-date count at the moment it actually matters.
$rl_locked = RateLimitService::isLocked($rl_ip);

// ── Request handling ──────────────────────────────────────────────────────────
$error    = '';
$redirect = filter_var($_GET['redirect'] ?? '', FILTER_SANITIZE_URL);
$csrf     = lumora_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    // Re-check the lockout state immediately before deciding whether to
    // process credentials, rather than trusting the snapshot read above —
    // this is the actual TOCTOU-closing step (RateLimitService::isLocked()
    // itself does the read under a fresh exclusive lock).
    $rl_locked = RateLimitService::isLocked($rl_ip);

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
            RateLimitService::clearFailures($rl_ip);
            $dest = lumora_safe_redirect_target($redirect, lumora_base_url() . 'admin/dashboard.php');
            lumora_redirect($dest);
        } else {
            // Failure — record attempt and add a per-failure delay.
            $rl_failure_count = RateLimitService::recordFailure($rl_ip);
            usleep(1_000_000); // 1-second delay on every failure
            // If this failure just tripped the limit, upgrade the message.
            if ($rl_failure_count >= RateLimitService::MAX_FAILURES) {
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
