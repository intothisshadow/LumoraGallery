<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Forgot Password
 *
 * Generates a single-use, time-limited (1 hour) password-reset URL and, if
 * the admin account has an email address set, sends it via a best-effort
 * PHP mail() call. There is no mail-free fallback here any more — a host
 * with no outbound mail configured has no way to retrieve the link from
 * this page. See reset-password.php in the gallery root for that case: a
 * standalone, uploadable, self-deleting emergency password reset with the
 * same trust model as install/index.php (filesystem/FTP access). See
 * LG-051 — this page previously wrote the link to lumora_recovery.txt, a
 * predictable, unauthenticated, web-reachable path.
 *
 * Requires the {PREFIX}password_reset_tokens table (DB version 7).
 * If the table is absent a clear error is shown instead of crashing.
 *
 * Recovery target: the account looked up is the oldest user belonging to
 * any group that holds both 'user_management' and 'site_configuration'
 * (see UserService::getRecoveryAccounts()) — not literally `role = 'admin'`.
 * Groups are dynamic as of Migration0007, so an administrator's account may
 * have been moved to a custom group with equivalent permissions; matching
 * by permission rather than by the fixed 'admin' slug keeps recovery
 * working in that case. See TODO-security.md #5.
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

$sent           = false;
$mail_attempted = false;
$error          = '';
$csrf           = lumora_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    // Find the account to recover: the oldest user in any group holding both
    // 'user_management' and 'site_configuration' — i.e. an account that can
    // actually reach Users/Groups and Configuration once logged back in,
    // regardless of whether it uses the built-in 'admin' slug or a custom
    // group with equivalent permissions.
    $user = UserService::getRecoveryAccounts()[0] ?? null;

    if ($user) {
        try {
            $token_data = lumora_create_reset_token((int) $user['id']);

            $reset_url = lumora_base_url()
                . 'admin/reset_password.php?token='
                . urlencode($token_data['selector'] . ':' . $token_data['validator']);

            // ── Best-effort email ─────────────────────────────────────────────
            $mail_attempted = !empty($user['email']) && function_exists('mail');
            if ($mail_attempted) {
                $gallery_name = lumora_config('gallery_name', 'Lumora Gallery');
                $host         = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $subject      = 'Password Reset — ' . $gallery_name;
                $body         =
                    "Hello,\n\n"
                    . "A password reset was requested for the Lumora Gallery admin account.\n\n"
                    . "Click the link below to set a new password:\n\n"
                    . $reset_url . "\n\n"
                    . "This link expires in 1 hour.\n\n"
                    . "If you did not request this reset, you can safely ignore this email.\n\n"
                    . "— " . $gallery_name;
                $headers = 'From: noreply@' . $host;

                // Suppress PHP mail() warnings via a temporary error handler so
                // they go only to error_log and never leak to the browser.
                $mail_warnings = [];
                set_error_handler(static function (int $no, string $str) use (&$mail_warnings): bool {
                    $mail_warnings[] = '[' . $no . '] ' . $str;
                    return true;
                });
                mail($user['email'], $subject, $body, $headers);
                restore_error_handler();
                if ($mail_warnings !== []) {
                    error_log('Lumora forgot_password: mail() warnings: ' . implode('; ', $mail_warnings));
                }
            }

            $sent = true;

        } catch (\Throwable $e) {
            error_log('Lumora forgot_password: ' . $e->getMessage());
            $error = 'Could not generate a reset token. '
                   . 'The password_reset_tokens table may be missing — '
                   . 'please run the DB v7 migration SQL from the CHANGELOG.';
        }
    } else {
        // No admin user found — show the same success UI to avoid disclosing
        // account existence.
        $sent = true;
    }
}

$base_url = h(lumora_base_url());
$csrf_h   = h($csrf);
$gal_name = h(lumora_config('gallery_name', 'Lumora Gallery'));
$login_h  = h(lumora_base_url() . 'admin/login.php');

if ($error !== '') {
    $body_html = '<div class="alert alert-danger py-2">' . h($error) . '</div>';
} elseif ($sent && $mail_attempted) {
    $body_html = <<<HTML
<div class="alert alert-success py-2">
  <strong>Reset link sent.</strong><br>
  Check the email address on file for the admin account. The link is valid
  for <strong>1 hour</strong>.
</div>
<a href="{$login_h}" class="btn btn-outline-secondary w-100 mt-1">← Back to Login</a>
HTML;
} elseif ($sent) {
    $body_html = <<<HTML
<div class="alert alert-success py-2">
  <strong>Request received.</strong><br>
  If a matching admin account exists, a reset link has been sent to its
  email address (valid for <strong>1 hour</strong>). If no mail address is
  configured on that account, use <code>reset-password.php</code> in your
  gallery root directory instead (upload it via FTP if it isn't there
  already) — an unauthenticated emergency reset with the same trust model
  as the installer.
</div>
<a href="{$login_h}" class="btn btn-outline-secondary w-100 mt-1">← Back to Login</a>
HTML;
} else {
    $body_html = <<<HTML
<p class="text-muted small mb-3">
  A reset link will be emailed to the admin account's address, if one is
  set. If outbound mail isn't configured on this host, use
  <code>reset-password.php</code> in your gallery root directory instead —
  an unauthenticated emergency reset with the same trust model as the
  installer.
</p>
<form method="post" action="">
  <input type="hidden" name="csrf_token" value="{$csrf_h}">
  <button type="submit" class="btn btn-primary w-100">Send Password Reset Link</button>
</form>
HTML;
}

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password — {$gal_name}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="{$base_url}admin/admin.css">
  <style>
    body { background:#f0f2f5; }
    .login-card { max-width: 420px; margin: 5rem auto; }
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
    <h2 class="h5 mb-3">Forgot Password</h2>
    {$body_html}
  </div>
  <div class="card-footer text-center text-muted small py-2">
    <a href="{$login_h}">← Back to Login</a>
    &nbsp;·&nbsp;
    <a href="{$base_url}">View Gallery</a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
