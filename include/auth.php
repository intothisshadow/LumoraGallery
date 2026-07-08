<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Authentication
 *
 * Multi-user authentication with roles: admin, moderator, contributor.
 * All three roles may log in to the admin panel; page-level access within
 * the panel is enforced per-permission via lumora_require_permission() /
 * lumora_require_any_permission(), using the role→permission mapping in
 * UserService::ROLE_PERMISSIONS. lumora_require_admin() remains available
 * for pages that are strictly admin-only (site configuration, user
 * management, updates, installation, import).
 * Sessions are started by bootstrap.php before these functions are called.
 *
 * Persistent "Remember Me" uses a split-token scheme:
 *   - Cookie value:  selector (32 hex chars) + ':' + validator (64 hex chars)
 *   - DB stores:     selector plain, SHA-256(validator) as hashed_validator
 * Timing-safe comparison via hash_equals() prevents validator enumeration.
 * Validator mismatch on a known selector is treated as a theft attempt and
 * ALL tokens for the affected user are revoked immediately.
 * Tokens are rotated on every successful auto-login.
 * DB operations on {PREFIX}remember_tokens are wrapped in catch(\Throwable)
 * so that pre-v3 installations (table absent) are unaffected.
 *
 * Password Reset (DB version 7):
 *   - Same split-token scheme as remember-me.
 *   - Tokens expire after 1 hour and are single-use.
 *   - The reset URL is always written to lumora_recovery.txt in LUMORA_ROOT
 *     so admins without email can retrieve it via FTP / file manager.
 *   - If the admin account has an email address set, a best-effort send via
 *     mail() is attempted in addition to the recovery file.
 *   - DB operations on {PREFIX}password_reset_tokens are wrapped in
 *     catch(\Throwable) so that pre-v7 installations (table absent) are
 *     unaffected — lumora_create_reset_token() is the only function that
 *     intentionally re-throws on insert failure.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

define('LUMORA_SESSION_KEY',    'lumora_auth');
define('LUMORA_SESSION_TTL',    7200);           // seconds – idle/age timeout (2 h)
define('LUMORA_REMEMBER_COOKIE', 'lumora_remember');
define('LUMORA_REMEMBER_DAYS',   30);            // persistent-cookie lifetime

// ── Login / Logout ────────────────────────────────────────────────────────────

/**
 * Attempt to authenticate a user.
 *
 * When $remember is true a persistent remember-me token is created and a
 * 30-day cookie is set. Requires the {PREFIX}remember_tokens table (DB v3+);
 * on older installs the cookie/DB write fails silently and the user still
 * gets a normal session-only login.
 *
 * Returns the user row on success, null on failure.
 */
function lumora_login(string $username, string $password, bool $remember = false): ?array
{
    $user = LumoraDB::fetchOne(
        'SELECT * FROM `{PREFIX}users` WHERE username = ?',
        [trim($username)]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    // Reject disabled accounts (is_active column added in DB version 9).
    // The isset() guard keeps this safe on pre-v9 installs where the column
    // does not yet exist and SELECT * returns no is_active key.
    if (isset($user['is_active']) && !(bool) $user['is_active']) {
        return null;
    }

    // Update last login timestamp.
    LumoraDB::query('UPDATE `{PREFIX}users` SET last_login = NOW() WHERE id = ?', [$user['id']]);

    // Store session payload.
    $_SESSION[LUMORA_SESSION_KEY] = [
        'user_id'  => (int) $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'login_at' => time(),
    ];

    // Rotate session ID to prevent fixation.
    session_regenerate_id(true);

    if ($remember) {
        lumora_create_remember_token((int) $user['id']);
    }

    return $user;
}

/**
 * Log out the current user.
 *
 * When $clear_remember is true (explicit user-initiated logout) the persistent
 * cookie is cleared and all remember-me tokens for the user are revoked.
 * Session-expiry calls within lumora_is_logged_in() pass false so the cookie
 * survives and can auto-log the user back in on the next request.
 */
function lumora_logout(bool $clear_remember = false): void
{
    if ($clear_remember) {
        $user_id = (int) (($_SESSION[LUMORA_SESSION_KEY] ?? [])['user_id'] ?? 0);
        if ($user_id > 0) {
            lumora_clear_remember_tokens($user_id);
        }
        lumora_clear_remember_cookie();
    }

    unset($_SESSION[LUMORA_SESSION_KEY]);
    session_regenerate_id(true);
}

// ── Session checks ────────────────────────────────────────────────────────────

/**
 * Return true if a user is authenticated and their session has not expired.
 */
function lumora_is_logged_in(): bool
{
    if (!isset($_SESSION[LUMORA_SESSION_KEY])) return false;
    $s = $_SESSION[LUMORA_SESSION_KEY];
    if ((time() - ($s['login_at'] ?? 0)) > LUMORA_SESSION_TTL) {
        // Session expired; keep the remember-me cookie so bootstrap can
        // transparently re-authenticate via lumora_check_remember_cookie().
        lumora_logout(false);
        return false;
    }
    return true;
}

/**
 * Return true only for users with the 'admin' role.
 */
function lumora_is_admin(): bool
{
    return lumora_is_logged_in()
        && (($_SESSION[LUMORA_SESSION_KEY]['role'] ?? '') === 'admin');
}

/**
 * Return the current user's session payload array, or null if not logged in.
 */
function lumora_current_user(): ?array
{
    return lumora_is_logged_in() ? $_SESSION[LUMORA_SESSION_KEY] : null;
}

/**
 * Enforce admin access. Redirects to the login page if the check fails.
 * Call at the top of every admin page (after bootstrap).
 */
function lumora_require_admin(): void
{
    if (!lumora_is_admin()) {
        lumora_redirect(
            lumora_base_url() . 'admin/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '')
        );
    }
}

/**
 * Enforce that a user is logged in, regardless of role.
 * Redirects to the login page if the check fails.
 * Call at the top of admin pages that any staff role (admin, moderator,
 * contributor) may access — e.g. dashboard.php, account.php.
 */
function lumora_require_login(): void
{
    if (!lumora_is_logged_in()) {
        lumora_redirect(
            lumora_base_url() . 'admin/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '')
        );
    }
}

/**
 * Enforce that the logged-in user holds the named permission.
 *
 * Redirects to the login page when not authenticated at all; shows a 403
 * page when authenticated but lacking the required permission. Call at the
 * top of admin pages that are gated by a single permission (see
 * UserService::ROLE_PERMISSIONS).
 */
function lumora_require_permission(string $permission): void
{
    lumora_require_login();
    if (!lumora_has_permission($permission)) {
        lumora_forbidden();
    }
}

/**
 * Enforce that the logged-in user holds at least one of the named
 * permissions. Useful for pages shared by roles with different, but
 * overlapping, capabilities (e.g. images.php: 'manage_images' for
 * admin/moderator, 'edit_own_images' for contributor).
 *
 * @param list<string> $permissions
 */
function lumora_require_any_permission(array $permissions): void
{
    lumora_require_login();
    foreach ($permissions as $permission) {
        if (lumora_has_permission($permission)) {
            return;
        }
    }
    lumora_forbidden();
}

/**
 * Send a 403 Forbidden response and terminate the request.
 *
 * Used when a logged-in user's role does not grant the permission required
 * by the current page or AJAX action. Distinct from lumora_require_admin()'s
 * redirect-to-login behaviour, which is only appropriate for unauthenticated
 * requests.
 */
function lumora_forbidden(): never
{
    http_response_code(403);
    $base = h(lumora_base_url() . 'admin/');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Access Denied</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container" style="max-width:480px;margin-top:5rem">
  <div class="card shadow-sm">
    <div class="card-body p-4 text-center">
      <h1 class="h4 mb-3">🚫 Access Denied</h1>
      <p class="text-muted mb-4">Your account does not have permission to view this page.</p>
      <a href="{$base}dashboard.php" class="btn btn-primary btn-sm">← Back to Dashboard</a>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    exit;
}

// ── Permission check ────────────────────────────────────────────────────────────

/**
 * Return true when the currently logged-in user has the named permission.
 * Permission constants and role→permission mapping live in UserService.
 * Returns false when no user is logged in.
 */
function lumora_has_permission(string $permission): bool
{
    return UserService::currentUserHasPermission($permission);
}

/**
 * Enforce that the logged-in user may access a specific album — either
 * because their role holds 'manage_albums' (full access to every album) or
 * because the album has been explicitly assigned to them via
 * AlbumAssignmentService (contributor role with 'manage_assigned_albums').
 *
 * Redirects to the login page when not authenticated; shows a 403 page when
 * authenticated but lacking access to this specific album. Call this in
 * addition to the page-level lumora_require_any_permission() gate wherever a
 * specific album ID is being read or written (admin/albums.php's edit/save
 * handlers, admin/batch.php, admin/ajax_batch.php) so a contributor cannot
 * bypass their assignment by guessing another album's ID in the URL.
 */
function lumora_require_album_access(int $albumId): void
{
    lumora_require_login();
    $user   = lumora_current_user();
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId > 0 && AlbumAssignmentService::userCanAccessAlbum($userId, $albumId)) {
        return;
    }
    lumora_forbidden();
}

/**
 * Enforce that the logged-in user may access (view/edit/delete) a specific
 * image — either because their role holds 'manage_images' (full access to
 * every image) or because the image's uploaded_by matches their own user ID
 * and their role holds 'edit_own_images' (the contributor role).
 *
 * Redirects to the login page when not authenticated; shows a 403 page when
 * authenticated but lacking access to this specific image. Mirrors
 * lumora_require_album_access(). Call this wherever a specific image ID is
 * being read or written by a page whose top-of-file gate is the shared
 * ['manage_images', 'edit_own_images'] pair (admin/images.php's edit/save/
 * delete handlers) so a contributor cannot bypass ownership scoping by
 * guessing another user's image ID in the URL.
 *
 * AJAX handlers that process many IDs per call (ajax_image_delete.php,
 * ajax_image_move.php) perform the equivalent per-ID check inline instead of
 * calling this function, since a single unauthorised ID in a bulk request
 * should be skipped with a per-item error, not abort the whole call.
 */
function lumora_require_image_access(int $imageId): void
{
    lumora_require_login();
    if (lumora_has_permission('manage_images')) {
        return;
    }
    $user   = lumora_current_user();
    $userId = (int) ($user['user_id'] ?? 0);
    if (
        $userId > 0
        && lumora_has_permission('edit_own_images')
        && GalleryService::imageBelongsToUser($imageId, $userId)
    ) {
        return;
    }
    lumora_forbidden();
}

// ── CSRF ──────────────────────────────────────────────────────────────────────

/**
 * Return (and create if absent) the CSRF token for this session.
 */
function lumora_csrf_token(): string
{
    if (empty($_SESSION['lumora_csrf'])) {
        $_SESSION['lumora_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lumora_csrf'];
}

/**
 * Return a hidden input field containing the CSRF token.
 */
function lumora_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(lumora_csrf_token()) . '">';
}

/**
 * Validate the CSRF token from a POST request.
 * Exits with 403 if validation fails.
 */
function lumora_csrf_validate(): void
{
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(lumora_csrf_token(), $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
}

// ── Remember Me ───────────────────────────────────────────────────────────────

/**
 * Create a new remember-me token for $user_id and set the persistent cookie.
 *
 * Split-token scheme:
 *   selector  — random 16 bytes as 32-char hex; stored plain; used for DB lookup.
 *   validator — random 32 bytes as 64-char hex; stored as SHA-256 in DB; full
 *               value travels in the cookie only.
 *
 * Fails silently on DB error so pre-v3 installs (table absent) are unaffected.
 */
function lumora_create_remember_token(int $user_id): void
{
    $selector  = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires   = date('Y-m-d H:i:s', time() + (LUMORA_REMEMBER_DAYS * 86400));

    try {
        LumoraDB::insert('remember_tokens', [
            'user_id'          => $user_id,
            'selector'         => $selector,
            'hashed_validator' => hash('sha256', $validator),
            'expires_at'       => $expires,
        ]);
    } catch (\Throwable) {
        // {PREFIX}remember_tokens absent on pre-v3 installs; fail silently.
        return;
    }

    setcookie(LUMORA_REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + (LUMORA_REMEMBER_DAYS * 86400),
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Validate the remember-me cookie and, if valid, rebuild the admin session.
 *
 * Token is rotated on every successful call (old token deleted, new one issued)
 * to limit the exposure window if a token is ever compromised.
 *
 * Returns true when the session has been populated, false otherwise.
 * Silently ignores DB errors so pre-v3 installs are unaffected.
 */
function lumora_check_remember_cookie(): bool
{
    $cookie = $_COOKIE[LUMORA_REMEMBER_COOKIE] ?? '';
    if ($cookie === '') return false;

    // Cookie must be exactly "selector:validator".
    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        lumora_clear_remember_cookie();
        return false;
    }

    [$selector, $validator] = $parts;

    // Validate format before hitting the DB.
    if (
        !preg_match('/^[0-9a-f]{32}$/', $selector) ||
        !preg_match('/^[0-9a-f]{64}$/', $validator)
    ) {
        lumora_clear_remember_cookie();
        return false;
    }

    // Fetch token row by selector.
    try {
        $token = LumoraDB::fetchOne(
            'SELECT * FROM `{PREFIX}remember_tokens` WHERE selector = ?',
            [$selector]
        );
    } catch (\Throwable) {
        return false;
    }

    if (!$token) {
        lumora_clear_remember_cookie();
        return false;
    }

    // Check expiry.
    if (strtotime((string) $token['expires_at']) < time()) {
        try { LumoraDB::delete('remember_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
        lumora_clear_remember_cookie();
        return false;
    }

    // Validate the hashed validator using constant-time comparison.
    $expected_hash = hash('sha256', $validator);
    if (!hash_equals((string) $token['hashed_validator'], $expected_hash)) {
        // Selector matched but validator did not — possible token theft.
        // Revoke every token for this user immediately.
        try { LumoraDB::delete('remember_tokens', 'user_id = ?', [(int) $token['user_id']]); } catch (\Throwable) {}
        lumora_clear_remember_cookie();
        return false;
    }

    // Load the user record.
    $user = LumoraDB::fetchOne(
        'SELECT * FROM `{PREFIX}users` WHERE id = ?',
        [(int) $token['user_id']]
    );

    if (!$user || !GroupService::groupExists($user['role']) || (isset($user['is_active']) && !(bool) $user['is_active'])) {
        try { LumoraDB::delete('remember_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
        lumora_clear_remember_cookie();
        return false;
    }

    // Rotate: delete the consumed token and issue a fresh one.
    try { LumoraDB::delete('remember_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
    lumora_create_remember_token((int) $user['id']);

    // Update last-login and rebuild the session.
    LumoraDB::query('UPDATE `{PREFIX}users` SET last_login = NOW() WHERE id = ?', [$user['id']]);

    $_SESSION[LUMORA_SESSION_KEY] = [
        'user_id'  => (int) $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'login_at' => time(),
    ];

    session_regenerate_id(true);

    return true;
}

/**
 * Expire the remember-me cookie immediately in the browser.
 */
function lumora_clear_remember_cookie(): void
{
    if (isset($_COOKIE[LUMORA_REMEMBER_COOKIE])) {
        setcookie(LUMORA_REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Delete all remember-me tokens for $user_id from the database.
 * Fails silently on pre-v3 installs where the table does not yet exist.
 */
function lumora_clear_remember_tokens(int $user_id): void
{
    try {
        LumoraDB::delete('remember_tokens', 'user_id = ?', [$user_id]);
    } catch (\Throwable) {
        // {PREFIX}remember_tokens absent on pre-v3 installs; fail silently.
    }
}

// ── User management ───────────────────────────────────────────────────────────

/**
 * Create a new admin user. Used by the installer.
 * Returns the new user ID.
 */
function lumora_create_admin(string $username, string $password, string $email = ''): int
{
    return (int) LumoraDB::insert('users', [
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'email'         => $email,
        'role'          => 'admin',
    ]);
}

/**
 * Change the password for a user.
 * Returns true on success.
 */
function lumora_change_password(int $user_id, string $new_password): bool
{
    if (strlen($new_password) < 8) return false;
    return LumoraDB::update(
        'users',
        ['password_hash' => password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12])],
        'id = ?',
        [$user_id]
    ) > 0;
}

// ── Password Reset ────────────────────────────────────────────────────────────
//
// Split-token scheme (same as remember-me):
//   selector  — 32-char hex (16 random bytes); stored plain; used for DB lookup.
//   validator — 64-char hex (32 random bytes); stored as SHA-256 in DB; full
//               value travels in the reset URL only.
// Tokens expire after 1 hour and are single-use.
// The reset URL is written to lumora_recovery.txt in LUMORA_ROOT on every
// request so admins without email can retrieve it via FTP or a file manager.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a password-reset token for $user_id.
 *
 * Any existing reset tokens for the user are deleted before issuing a new one
 * (only one active token per user at a time).
 *
 * Throws on DB error — callers should wrap in try/catch and show an appropriate
 * error if the {PREFIX}password_reset_tokens table does not yet exist.
 *
 * @return array{selector: string, validator: string, expires_at: string}
 */
function lumora_create_reset_token(int $user_id): array
{
    $selector  = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires   = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    // Revoke any outstanding reset tokens for this user first.
    try {
        LumoraDB::delete('password_reset_tokens', 'user_id = ?', [$user_id]);
    } catch (\Throwable) {
        // Table may be absent on pre-v7 installs; the INSERT below will surface
        // the real error to the caller.
    }

    LumoraDB::insert('password_reset_tokens', [
        'user_id'          => $user_id,
        'selector'         => $selector,
        'hashed_validator' => hash('sha256', $validator),
        'expires_at'       => $expires,
    ]);

    return ['selector' => $selector, 'validator' => $validator, 'expires_at' => $expires];
}

/**
 * Validate a password-reset token.
 *
 * Checks format, selector existence, expiry, and hashed validator.
 * Returns the user_id on success, null on any failure.
 *
 * Does NOT consume the token — call lumora_consume_reset_token() after the
 * password has been successfully changed.
 *
 * @return int|null  User ID on success, null on failure.
 */
function lumora_verify_reset_token(string $selector, string $validator): ?int
{
    // Validate token format before hitting the DB.
    if (
        !preg_match('/^[0-9a-f]{32}$/', $selector) ||
        !preg_match('/^[0-9a-f]{64}$/', $validator)
    ) {
        return null;
    }

    try {
        $token = LumoraDB::fetchOne(
            'SELECT * FROM `{PREFIX}password_reset_tokens` WHERE selector = ?',
            [$selector]
        );
    } catch (\Throwable) {
        return null;
    }

    if (!$token) {
        return null;
    }

    // Check expiry and prune expired token.
    if (strtotime((string) $token['expires_at']) < time()) {
        try { LumoraDB::delete('password_reset_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
        return null;
    }

    // Constant-time validator check.
    if (!hash_equals((string) $token['hashed_validator'], hash('sha256', $validator))) {
        return null;
    }

    return (int) $token['user_id'];
}

/**
 * Consume (delete) a specific reset token by selector.
 * Called immediately after a successful password change.
 * Fails silently on DB errors.
 */
function lumora_consume_reset_token(string $selector): void
{
    try {
        LumoraDB::delete('password_reset_tokens', 'selector = ?', [$selector]);
    } catch (\Throwable) {}
}

/**
 * Delete all password-reset tokens for $user_id.
 * Fails silently on pre-v7 installs where the table does not yet exist.
 */
function lumora_clear_reset_tokens(int $user_id): void
{
    try {
        LumoraDB::delete('password_reset_tokens', 'user_id = ?', [$user_id]);
    } catch (\Throwable) {}
}
