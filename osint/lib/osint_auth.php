<?php
// Isolated auth for the invite-only /osint/ tool. Deliberately INDEPENDENT of the
// videos accounts AND the owner console: its own session (osintsess), its own user
// store (osint/data/osint.sqlite), its own CSRF. There is NO public signup — an
// account can only be created by redeeming an invite code, and invites can only be
// minted from the owner console (owner/osint.php). Admin (invite/user management)
// is gated by the owner 2FA session; end users get the osint session.
require_once __DIR__ . '/../../owner/lib/audit.php';   // audit_log(), owner_*(), config helpers

define('OSINT_DATA_DIR', __DIR__ . '/../data');
define('OSINT_DB_PATH',  OSINT_DATA_DIR . '/osint.sqlite');

function osint_db(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    try {
        if (!is_dir(OSINT_DATA_DIR)) @mkdir(OSINT_DATA_DIR, 0755, true);
        $pdo = new PDO('sqlite:' . OSINT_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            username    TEXT UNIQUE NOT NULL,
            pass_hash   TEXT NOT NULL,
            created_at  INTEGER NOT NULL,
            invite_code TEXT,
            disabled    INTEGER NOT NULL DEFAULT 0,
            last_login  INTEGER
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS invites (
            code       TEXT PRIMARY KEY,
            note       TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            expires_at INTEGER,
            used_by    TEXT,
            used_at    INTEGER,
            revoked    INTEGER NOT NULL DEFAULT 0
        )");
    } catch (\Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** Best-effort HTTPS detection honouring a TLS-terminating proxy. */
function osint_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function osint_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('osintsess');                     // distinct from vidsess / ownersess
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/osint/',                    // scoped to the tool area only
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => osint_https(),
    ]);
    session_start();
}

/** HTML escape, local to the osint area. */
function ose(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- current user ----
function osint_current_user(): ?array {
    static $cached = false, $user = null;
    if ($cached) return $user;
    $cached = true;
    osint_session_start();
    $uid = $_SESSION['osint_uid'] ?? null;
    if (!$uid) return $user = null;
    $db = osint_db(); if (!$db) return $user = null;
    $st = $db->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || (int) $u['disabled'] === 1) { $_SESSION = []; return $user = null; }
    return $user = $u;
}

function osint_require(): void {
    if (!osint_current_user()) {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $q = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $uri !== '' && $uri[0] === '/' && strpos($uri, '//') !== 0)
             ? '?next=' . rawurlencode($uri) : '';
        header('Location: /osint/login.php' . $q);
        exit;
    }
}

/** Local-path-only redirect target (no open redirect). */
function osint_safe_next(?string $n): string {
    $n = (string) $n;
    if ($n !== '' && $n[0] === '/' && strpos($n, '//') !== 0 && strpos($n, '/\\') !== 0 && strpos($n, '/osint/') === 0) return $n;
    return '/osint/';
}

function osint_login(int $uid): void {
    osint_session_start();
    session_regenerate_id(true);
    $_SESSION['osint_uid']  = $uid;
    $_SESSION['osint_csrf'] = bin2hex(random_bytes(32));
    $db = osint_db();
    if ($db) { try { $db->prepare("UPDATE users SET last_login = ? WHERE id = ?")->execute([time(), $uid]); } catch (\Throwable $e) {} }
}

function osint_logout(): void {
    osint_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---- CSRF (osint area) ----
function osint_csrf_token(): string {
    osint_session_start();
    if (empty($_SESSION['osint_csrf'])) $_SESSION['osint_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['osint_csrf'];
}
function osint_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . ose(osint_csrf_token()) . '">'; }
function osint_csrf_require(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(osint_csrf_token(), $sent)) { http_response_code(403); exit('Bad CSRF token.'); }
}

// ---- auth actions ----
function osint_authenticate(string $rawUser, string $password): array {
    $db = osint_db(); if (!$db) return [null, 'Service unavailable.'];
    $st = $db->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([trim($rawUser)]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['pass_hash'])) return [null, 'Wrong username or password.'];
    if ((int) $u['disabled'] === 1) return [null, 'This account has been disabled.'];
    return [(int) $u['id'], null];
}

/** Redeem an invite code + create the account. Returns [uid, null] or [null, error]. */
function osint_register_with_invite(string $code, string $rawUser, string $password): array {
    $db = osint_db(); if (!$db) return [null, 'Service unavailable.'];
    $code = trim($code);
    $inv = null;
    if ($code !== '') {
        $st = $db->prepare("SELECT * FROM invites WHERE code = ?");
        $st->execute([$code]);
        $inv = $st->fetch();
    }
    if (!$inv || (int) $inv['revoked'] === 1) return [null, 'That invite code is not valid.'];
    if ($inv['used_by'] !== null)             return [null, 'That invite has already been used.'];
    if ($inv['expires_at'] !== null && time() > (int) $inv['expires_at']) return [null, 'That invite has expired.'];

    $username = sanitizeHandle($rawUser);   // 3-16 [A-Za-z0-9_], no profanity (config.php)
    if ($username === null)        return [null, 'Username must be 3-16 letters, numbers, or _ (no profanity).'];
    if (strlen($password) < 8)     return [null, 'Password must be at least 8 characters.'];
    if (strlen($password) > 200)   return [null, 'Password is too long.'];

    $chk = $db->prepare("SELECT 1 FROM users WHERE username = ?");
    $chk->execute([$username]);
    if ($chk->fetch()) return [null, 'That username is taken.'];

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO users (username, pass_hash, created_at, invite_code) VALUES (?,?,?,?)")
           ->execute([$username, password_hash($password, PASSWORD_DEFAULT), time(), $code]);
        $uid = (int) $db->lastInsertId();
        // Consume the invite atomically (only if still unused — guards a double-submit race).
        $upd = $db->prepare("UPDATE invites SET used_by = ?, used_at = ? WHERE code = ? AND used_by IS NULL");
        $upd->execute([$username, time(), $code]);
        if ($upd->rowCount() !== 1) { $db->rollBack(); return [null, 'That invite has already been used.']; }
        $db->commit();
        return [$uid, null];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return [null, 'Could not create the account.'];
    }
}

// ---- invite + user administration (owner-gated callers only) ----
function osint_invite_create(string $note = '', int $ttlDays = 14): ?array {
    $db = osint_db(); if (!$db) return null;
    $code = bin2hex(random_bytes(12));
    $exp  = $ttlDays > 0 ? time() + $ttlDays * 86400 : null;
    try {
        $db->prepare("INSERT INTO invites (code, note, created_at, expires_at) VALUES (?,?,?,?)")
           ->execute([$code, mb_substr(trim($note), 0, 120), time(), $exp]);
        return ['code' => $code, 'note' => $note, 'expires_at' => $exp];
    } catch (\Throwable $e) { return null; }
}
function osint_invite_revoke(string $code): void {
    $db = osint_db(); if (!$db) return;
    try { $db->prepare("UPDATE invites SET revoked = 1 WHERE code = ? AND used_by IS NULL")->execute([$code]); } catch (\Throwable $e) {}
}
function osint_invites_list(): array {
    $db = osint_db(); if (!$db) return [];
    try { return $db->query("SELECT * FROM invites ORDER BY created_at DESC LIMIT 200")->fetchAll(); } catch (\Throwable $e) { return []; }
}
function osint_users_list(): array {
    $db = osint_db(); if (!$db) return [];
    try { return $db->query("SELECT id,username,created_at,disabled,last_login,invite_code FROM users ORDER BY created_at DESC LIMIT 200")->fetchAll(); } catch (\Throwable $e) { return []; }
}
function osint_user_set_disabled(int $id, bool $disabled): void {
    $db = osint_db(); if (!$db) return;
    try { $db->prepare("UPDATE users SET disabled = ? WHERE id = ?")->execute([$disabled ? 1 : 0, $id]); } catch (\Throwable $e) {}
}
