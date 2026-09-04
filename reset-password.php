<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Emergency Admin Password Reset
 *
 * Open this file directly in a browser — no login required — when an admin
 * password is lost and the normal "Forgot password?" flow can't be reached
 * (e.g. outbound mail isn't configured on this host).
 *
 * Trust model: identical to install/index.php. Reaching this file at all
 * already means filesystem access next to config.php (FTP/file manager) —
 * that IS the authentication here; there is no separate identity check.
 *
 * Recovery targets are every account in a group holding both
 * 'user_management' and 'site_configuration' (UserService::getRecoveryAccounts()),
 * not literally `role = 'admin'`, since groups are dynamic and an admin
 * account may have been moved to a custom group with equivalent permissions.
 *
 * Rate limiting is shared with admin/login.php via RateLimitService, since
 * this is an equally sensitive unauthenticated admin-auth surface.
 *
 * Deletes itself after a successful reset so it doesn't remain a standing,
 * unauthenticated way to overwrite an admin password. If self-deletion
 * fails, a warning nags the admin panel until it's removed — see
 * admin/delete_reset_script.php.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.17.0
 */
define('LUMORA_ENTRY', true);
require_once __DIR__ . '/include/bootstrap.php';

$ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$accounts = UserService::getRecoveryAccounts();
$errors   = [];
$success  = false;
$username = '';

if ($accounts === []) {
    $errors[] = 'No account with both User Management and Configuration access exists. '
              . 'Run install/index.php to get started, or log in as a different admin '
              . 'and grant those permissions to a group.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lumora_csrf_validate();

    if (RateLimitService::isLocked($ip)) {
        $errors[] = 'Too many attempts from this address. Please try again later.';
    } else {
        $selected_id           = lumora_int($_POST['user_id'] ?? 0);
        $new_password          = (string) ($_POST['new_password'] ?? '');
        $new_password_confirm  = (string) ($_POST['new_password_confirm'] ?? '');

        $target = null;
        foreach ($accounts as $account) {
            if ($account['id'] === $selected_id) {
                $target = $account;
                break;
            }
        }

        if ($target === null) {
            RateLimitService::recordFailure($ip);
            $errors[] = 'Choose one of the listed accounts.';
        } elseif ($new_password !== $new_password_confirm) {
            RateLimitService::recordFailure($ip);
            $errors[] = 'New password confirmation does not match.';
        } else {
            $result = UserService::resetPassword($target['id'], $new_password);
            if ($result !== true) {
                RateLimitService::recordFailure($ip);
                $errors[] = $result;
            } else {
                RateLimitService::clearFailures($ip);
                $username = $target['username'];
                $success  = true;
            }
        }
    }
}

$self_deleted = null;
if ($success) {
    $self_deleted = @unlink(__FILE__);
}

// ── Build page HTML ───────────────────────────────────────────────────────────
$gal_name   = h(lumora_config('gallery_name', 'Lumora Gallery'));
$csrf_h     = h(lumora_csrf_token());
$login_h    = h(lumora_base_url() . 'admin/login.php');
$username_h = h($username);

$err_html = '';
foreach ($errors as $e) {
    $err_html .= '<div class="alert alert-danger py-2">' . h($e) . '</div>';
}

if ($success) {
    $self_delete_html = $self_deleted
        ? '<div class="alert alert-success py-2"><strong>reset-password.php has been automatically deleted</strong> from your server.</div>'
        : '<div class="alert alert-danger py-2"><strong>Could not auto-delete reset-password.php</strong> — please delete it from your server manually right now.</div>';

    $body_html = <<<HTML
<div class="alert alert-success py-2">Password for <strong>{$username_h}</strong> has been reset.</div>
{$self_delete_html}
<a href="{$login_h}" class="btn btn-primary w-100 mt-1">Log in with the new password &#8594;</a>
HTML;
} elseif ($accounts === []) {
    $body_html = $err_html;
} else {
    $options_html = '';
    foreach ($accounts as $account) {
        $options_html .= '<option value="' . h((string) $account['id']) . '">' . h($account['username']) . '</option>';
    }

    $body_html = <<<HTML
{$err_html}
<form method="post" action="" autocomplete="off">
  <input type="hidden" name="csrf_token" value="{$csrf_h}">
  <div class="mb-3">
    <label class="form-label fw-semibold" for="lum-rp-user">Account</label>
    <select id="lum-rp-user" name="user_id" class="form-select" required autofocus>
      {$options_html}
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold" for="lum-rp-new">New Password</label>
    <input type="password" id="lum-rp-new" name="new_password" class="form-control"
           autocomplete="new-password" required minlength="8">
    <div class="form-text">Minimum 8 characters.</div>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold" for="lum-rp-confirm">Confirm New Password</label>
    <input type="password" id="lum-rp-confirm" name="new_password_confirm" class="form-control"
           autocomplete="new-password" required minlength="8">
  </div>
  <button type="submit" class="btn btn-primary w-100">Reset Password</button>
</form>
HTML;
}

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Emergency Password Reset — {$gal_name}</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background:#f0f2f5; }
    .login-card { max-width: 460px; margin: 5rem auto; }
    .login-header { background:#1a1a2e; color:#fff; padding:1.5rem; border-radius:.5rem .5rem 0 0; }
    .login-header h1 { font-size:1.3rem; margin:0; }
  </style>
</head>
<body>
<div class="login-card card shadow-sm">
  <div class="login-header">
    <h1>⚡ Lumora Gallery</h1>
    <small class="opacity-75">{$gal_name}</small>
  </div>
  <div class="card-body p-4">
    <div class="alert alert-danger py-2">
      <strong>Security notice:</strong> this page resets an admin password
      with no login required — the same trust model as
      <code>install/index.php</code>. Reaching this file at all already
      means you have full server (FTP/file manager) access.
      <strong>Delete this file as soon as you're done.</strong>
    </div>
    <h2 class="h5 mb-3">Emergency Password Reset</h2>
    {$body_html}
  </div>
  <div class="card-footer text-center text-muted small py-2">
    <a href="{$login_h}">← Back to Login</a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
