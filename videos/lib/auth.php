<?php
// Sessions, CSRF, and account helpers for /videos/.

function videos_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('vidsess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

// ---- CSRF ----
function csrf_token(): string {
    videos_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden form field for classic POST forms. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verify a submitted CSRF token; abort otherwise. $viaJson picks the error shape. */
function csrf_require(bool $viaJson = false): void {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if (function_exists('audit_log')) {
            audit_log('csrf_fail', 'warn', [
                'actor_uid' => $_SESSION['uid'] ?? null,
                'detail'    => 'CSRF token mismatch on ' . ($_SERVER['REQUEST_URI'] ?? ''),
            ]);
        }
        if ($viaJson) json_out(['ok' => false, 'error' => 'bad csrf token'], 403);
        http_response_code(403);
        exit('Bad CSRF token.');
    }
}

// ---- Current user ----
function current_user(): ?array {
    static $cached = false;
    static $user = null;
    if ($cached) return $user;
    $cached = true;

    videos_session_start();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return $user = null;

    $st = videos_db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch();

    // A banned or deleted account is treated as logged out.
    if (!$u || (int) $u['is_banned'] === 1) {
        $_SESSION = [];
        return $user = null;
    }
    return $user = $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        $to = $_SERVER['REQUEST_URI'] ?? '/videos/';
        redirect('/videos/login.php?next=' . urlencode($to));
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        exit('Forbidden.');
    }
    return $u;
}

// ---- Login / logout / register ----
function login_user(int $uid): void {
    videos_session_start();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function logout_user(): void {
    videos_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Create an account. Returns [userId, null] on success or [null, errorMessage].
 * Username rules reuse the site-wide sanitizeHandle() (3-16 [A-Za-z0-9_], no
 * profanity). Password must be >= 8 chars.
 */
function register_user(string $rawUser, string $password): array {
    $username = sanitizeHandle($rawUser);
    if ($username === null) {
        return [null, 'Username must be 3-16 letters, numbers, or _ (no profanity).'];
    }
    if (strlen($password) < 8) {
        return [null, 'Password must be at least 8 characters.'];
    }
    if (strlen($password) > 200) {
        return [null, 'Password is too long.'];
    }

    $db = videos_db();
    $st = $db->prepare("SELECT 1 FROM users WHERE username = ?");
    $st->execute([$username]);
    if ($st->fetch()) {
        return [null, 'That username is taken.'];
    }

    $isAdmin = (strcasecmp($username, VIDEO_ADMIN_USERNAME) === 0) ? 1 : 0;
    $ins = $db->prepare(
        "INSERT INTO users (username, password_hash, is_admin, created_at)
         VALUES (?, ?, ?, ?)"
    );
    $ins->execute([$username, password_hash($password, PASSWORD_DEFAULT), $isAdmin, time()]);
    return [(int) $db->lastInsertId(), null];
}

/** Returns [userId, null] or [null, error]. */
function authenticate(string $rawUser, string $password): array {
    $db = videos_db();
    $st = $db->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([trim($rawUser)]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return [null, 'Wrong username or password.'];
    }
    if ((int) $u['is_banned'] === 1) {
        return [null, 'This account has been banned.'];
    }
    return [(int) $u['id'], null];
}

/**
 * Change a user's own password after verifying the current one. Returns null on
 * success, or an error message. Used by the self-service change-password page so
 * users who logged in with an admin-issued temp password can set their own.
 */
function change_password(int $userId, string $current, string $new, string $confirm): ?string {
    if ($new !== $confirm)   return 'The new passwords do not match.';
    if (strlen($new) < 8)    return 'New password must be at least 8 characters.';
    if (strlen($new) > 200)  return 'New password is too long.';

    $db = videos_db();
    $st = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $st->execute([$userId]);
    $hash = $st->fetchColumn();
    if ($hash === false)                     return 'Account not found.';
    if (!password_verify($current, $hash))   return 'Your current password is incorrect.';
    if (password_verify($new, $hash))        return 'New password must be different from your current one.';

    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
       ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    return null;
}

// ---- Password reset (manual, admin-approved — the site has no email) ----

/** A readable temporary password (no ambiguous characters), >= 8 chars. */
function gen_temp_password(): string {
    $alpha = 'abcdefghijkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) $p .= $alpha[random_int(0, strlen($alpha) - 1)];
    return $p;
}

/** Queue a reset request for the admin. Silent about whether the account exists. */
function request_password_reset(string $rawUser): void {
    $db = videos_db();
    $st = $db->prepare("SELECT id, username FROM users WHERE username = ?");
    $st->execute([trim($rawUser)]);
    $u = $st->fetch();
    if (!$u) return;
    $chk = $db->prepare("SELECT 1 FROM password_resets WHERE user_id = ? AND status = 'pending'");
    $chk->execute([$u['id']]);
    if ($chk->fetch()) return;                       // one pending request per user
    $db->prepare("INSERT INTO password_resets (user_id, username, status, created_at) VALUES (?, ?, 'pending', ?)")
       ->execute([$u['id'], $u['username'], time()]);
}

/** Admin action: set a new temporary password, clear pending requests. Returns
 *  ['username','password'] to show the admin once, or null if the user is gone. */
function admin_reset_password(int $userId): ?array {
    $db = videos_db();
    $st = $db->prepare("SELECT username FROM users WHERE id = ?");
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) return null;
    $temp = gen_temp_password();
    $db->prepare("UPDATE users SET password_hash = ?, is_banned = is_banned WHERE id = ?")
       ->execute([password_hash($temp, PASSWORD_DEFAULT), $userId]);
    $db->prepare("UPDATE password_resets SET status = 'handled', resolved_at = ? WHERE user_id = ? AND status = 'pending'")
       ->execute([time(), $userId]);
    return ['username' => $row['username'], 'password' => $temp];
}

/** Resolve a username to an id for a direct (no-request) reset. */
function user_id_by_name(string $rawUser): ?int {
    $st = videos_db()->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([trim($rawUser)]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

function pending_resets(): array {
    return videos_db()->query(
        "SELECT id, user_id, username, created_at FROM password_resets WHERE status = 'pending' ORDER BY created_at ASC LIMIT 200"
    )->fetchAll();
}
