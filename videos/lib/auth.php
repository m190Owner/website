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
