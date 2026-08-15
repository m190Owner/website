<?php
// Standalone auth for the private /owner/ console. Deliberately INDEPENDENT of the
// videos account system (the very thing the audit log watches): its own session,
// its own password, its own gitignored config. The TOTP 2FA feature plugs in here.
require_once __DIR__ . '/../../config.php';   // enforceRateLimit + site security helpers

/** Load the gitignored owner config (password hash, #security webhook, cainfo). */
function owner_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = __DIR__ . '/../config.php';
    $cfg = is_file($path) ? require $path : [];
    if (!is_array($cfg)) $cfg = [];
    return $cfg;
}

/** Configured only once a password hash exists. */
function owner_is_configured(): bool {
    return !empty(owner_config()['pass_hash']);
}

/** Best-effort HTTPS detection that honours a TLS-terminating proxy. */
function owner_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function owner_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ownersess');                    // distinct from the videos 'vidsess'
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',                        // site-wide so it can also gate /jellyfin/ (owner 2FA)
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => owner_https(),
    ]);
    session_start();
}

function owner_is_authed(): bool {
    owner_session_start();
    return !empty($_SESSION['owner_ok']);
}

// ---- 2FA pending state: password OK, awaiting the code. A partial session that
//      does NOT satisfy owner_is_authed(), so it can't reach /owner/. ----
function owner_pending_begin(): void {
    owner_session_start();
    $_SESSION['owner_pending'] = time();
}
function owner_pending_active(int $ttl = 300): bool {
    owner_session_start();
    return !empty($_SESSION['owner_pending']) && (time() - (int) $_SESSION['owner_pending']) < $ttl;
}

/** Verify a submitted password against the configured hash (constant-time). */
function owner_check_password(string $pw): bool {
    $hash = (string) (owner_config()['pass_hash'] ?? '');
    return $hash !== '' && password_verify($pw, $hash);
}

/** Mark the session authenticated. (The TOTP step will gate this in feature #2.) */
function owner_login_ok(): void {
    owner_session_start();
    session_regenerate_id(true);
    unset($_SESSION['owner_pending']);
    $_SESSION['owner_ok']    = true;
    $_SESSION['owner_since'] = time();
    $_SESSION['owner_csrf']  = bin2hex(random_bytes(32));
}

function owner_logout(): void {
    owner_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        // Expire the cookie at the current site-wide path AND the legacy /owner/ path,
        // so a stale narrow cookie from before the path change can't linger and shadow it.
        foreach (['/', '/owner/'] as $path) {
            setcookie(session_name(), '', time() - 42000, $path, $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
    }
    session_destroy();
}

/** Gate a page: send to the login if not authenticated, remembering where to return. */
function owner_require(): void {
    if (!owner_is_authed()) {
        $q = '';
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        // Only bounce back to a real page (GET) and only to a local same-site path.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && owner_safe_next($uri) !== '/owner/') {
            $q = '?next=' . rawurlencode($uri);
        }
        header('Location: /owner/login.php' . $q);
        exit;
    }
}

/** Validate a post-login redirect target: a local same-site path only (no open redirect). */
function owner_safe_next(?string $n): string {
    $n = (string) $n;
    if ($n !== '' && $n[0] === '/' && !str_starts_with($n, '//') && !str_starts_with($n, '/\\')) {
        return $n;
    }
    return '/owner/';
}

// ---- CSRF (owner area) ----
function owner_csrf_token(): string {
    owner_session_start();
    if (empty($_SESSION['owner_csrf'])) $_SESSION['owner_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['owner_csrf'];
}
function owner_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . oe(owner_csrf_token()) . '">';
}
function owner_csrf_require(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(owner_csrf_token(), $sent)) {
        http_response_code(403);
        exit('Bad CSRF token.');
    }
}

/** HTML escape, local to the owner console (independent of the videos util). */
function oe(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
