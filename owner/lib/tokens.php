<?php
// Honeytoken (canary) engine. Mint tripwire artifacts — a booby-trapped link, a
// tracking pixel, a QR, or a fake-credentials file — plant them, and get a
// geolocated alert the instant one is touched. Detection layer only: every hit
// rides the existing security audit log + #security webhook (throttled per-IP).
//
// Storage is a standalone SQLite in owner/data/ (gitignored + .htaccess-denied),
// same discipline as the audit log. All functions are best-effort / never throw —
// a tripwire firing must never 500 the public-facing handler that serves the decoy.
require_once __DIR__ . '/audit.php';   // audit_log(), audit_client_ip(), owner_config()

define('TOKENS_DB_PATH',     OWNER_DATA_DIR . '/tokens.sqlite');
define('TOKEN_HITS_RETAIN',  180 * 86400);   // drop raw hit rows older than ~6 months

// The tripwire shapes. All ultimately fire via /c/{id} or /t/{id}.gif; `type` only
// drives how the owner console renders the artifact to plant.
const TOKEN_TYPES = [
    'url'   => ['label' => 'Booby-trapped link', 'icon' => "\u{1F517}"],
    'pixel' => ['label' => 'Tracking pixel',     'icon' => "\u{1F5BC}"],
    'qr'    => ['label' => 'QR canary',          'icon' => "\u{25A6}"],
    'creds' => ['label' => 'Fake credentials',   'icon' => "\u{1F511}"],
];

function tokens_db(): ?PDO {
    static $pdo = false;                        // false = untried, null = failed
    if ($pdo !== false) return $pdo;
    try {
        if (!is_dir(OWNER_DATA_DIR)) @mkdir(OWNER_DATA_DIR, 0755, true);
        $pdo = new PDO('sqlite:' . TOKENS_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
            id             TEXT PRIMARY KEY,
            type           TEXT NOT NULL,
            memo           TEXT NOT NULL DEFAULT '',
            created_at     INTEGER NOT NULL,
            trigger_count  INTEGER NOT NULL DEFAULT 0,
            last_triggered INTEGER,
            active         INTEGER NOT NULL DEFAULT 1
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS token_hits (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            token_id TEXT NOT NULL,
            ts       INTEGER NOT NULL,
            ip       TEXT NOT NULL DEFAULT '',
            country  TEXT NOT NULL DEFAULT '',
            ua       TEXT NOT NULL DEFAULT '',
            referer  TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hits_token ON token_hits(token_id, ts DESC)");
    } catch (\Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** URL-safe id used in /c/{id} and /t/{id}.gif. */
function token_slug(int $len = 10): string {
    $alpha = 'abcdefghijkmnpqrstuvwxyz23456789';   // no ambiguous chars
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
    return $s;
}

/** Canonical site origin from the current request (localhost in dev, the domain in prod). */
function token_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
          || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'logansandivar.com');
    // Only allow a sane host header through (defends the artifact URLs from header spoofing).
    if (!preg_match('/^[A-Za-z0-9.\-:]{1,255}$/', $host)) $host = 'logansandivar.com';
    return ($https ? 'https://' : 'http://') . $host;
}
function token_url(string $id): string       { return token_origin() . '/c/' . $id; }
function token_pixel_url(string $id): string { return token_origin() . '/t/' . $id . '.gif'; }

/** The 1x1 transparent GIF served by the pixel handler. */
function token_pixel_bytes(): string {
    return (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

/** Client metadata captured on a trip. IP/UA are retained on purpose — this is an
 *  inward-facing forensic tool, owner-2FA-gated, and the data is the whole point. */
function token_client_meta(): array {
    return [
        'ip'      => audit_client_ip(),
        'country' => preg_replace('/[^A-Za-z]/', '', mb_substr((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''), 0, 2)),
        'ua'      => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'referer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
    ];
}

/** Mint a token. Returns the new row, or null on invalid type / storage failure. */
function token_mint(string $type, string $memo): ?array {
    if (!isset(TOKEN_TYPES[$type])) return null;
    $memo = trim(mb_substr($memo, 0, 200));
    try {
        $db = tokens_db();
        if (!$db) return null;
        do {
            $id  = token_slug(10);
            $chk = $db->prepare("SELECT 1 FROM tokens WHERE id = ?");
            $chk->execute([$id]);
        } while ($chk->fetch());
        $now = time();
        $db->prepare("INSERT INTO tokens (id,type,memo,created_at,trigger_count,active) VALUES (?,?,?,?,0,1)")
           ->execute([$id, $type, $memo, $now]);
        return ['id' => $id, 'type' => $type, 'memo' => $memo, 'created_at' => $now,
                'trigger_count' => 0, 'last_triggered' => null, 'active' => 1];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Record a trip. Called by the PUBLIC /c/ and /t/ handlers. Always best-effort.
 * Returns true if this was a known, active token (the handler ignores the return
 * and serves its decoy either way — an unknown id must be indistinguishable).
 */
function token_record_hit(string $id, array $meta): bool {
    try {
        $db = tokens_db();
        if (!$db) return false;
        $st = $db->prepare("SELECT id, memo, active FROM tokens WHERE id = ?");
        $st->execute([$id]);
        $tok = $st->fetch();
        if (!$tok || (int) $tok['active'] !== 1) return false;

        $now = time();
        $db->prepare("INSERT INTO token_hits (token_id,ts,ip,country,ua,referer) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $now, $meta['ip'] ?? '', $meta['country'] ?? '', $meta['ua'] ?? '', $meta['referer'] ?? '']);
        $db->prepare("UPDATE tokens SET trigger_count = trigger_count + 1, last_triggered = ? WHERE id = ?")
           ->execute([$now, $id]);
        if (random_int(1, 50) === 1) token_prune($db);

        // Alert. Event name 'honeytoken' is throttled per-IP by the audit layer, so a
        // scanner hammering the token pings #security once, not a thousand times.
        $where  = $tok['memo'] !== '' ? $tok['memo'] : ('token ' . $id);
        $detail = "\u{1F3A3} Honeytoken tripped — " . $where
                . ' · ' . (($meta['country'] ?? '') ?: '??')
                . ' · ' . (mb_substr((string) ($meta['ua'] ?? ''), 0, 120) ?: 'no-UA');
        if (!empty($meta['referer'])) $detail .= ' · ref ' . $meta['referer'];
        audit_log('honeytoken', 'crit', [
            'actor'  => 'tripwire',
            'target' => mb_substr($where, 0, 120),
            'ip'     => (string) ($meta['ip'] ?? ''),
            'ua'     => (string) ($meta['ua'] ?? ''),
            'detail' => $detail,
            'push'   => true,
        ]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function token_prune(PDO $db): void {
    try {
        $db->prepare("DELETE FROM token_hits WHERE ts < ?")->execute([time() - TOKEN_HITS_RETAIN]);
    } catch (\Throwable $e) {}
}

// ---- Read / manage side (owner console) ----
function token_list(): array {
    $db = tokens_db();
    if (!$db) return [];
    try {
        return $db->query("SELECT * FROM tokens ORDER BY created_at DESC LIMIT 500")->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

function token_get(string $id): ?array {
    $db = tokens_db();
    if (!$db) return null;
    $st = $db->prepare("SELECT * FROM tokens WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function token_hits(string $id, int $limit = 25): array {
    $db = tokens_db();
    if (!$db) return [];
    $st = $db->prepare("SELECT ts,ip,country,ua,referer FROM token_hits WHERE token_id = ? ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $id);
    $st->bindValue(2, max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function token_set_active(string $id, bool $active): void {
    $db = tokens_db();
    if (!$db) return;
    try { $db->prepare("UPDATE tokens SET active = ? WHERE id = ?")->execute([$active ? 1 : 0, $id]); }
    catch (\Throwable $e) {}
}

function token_delete(string $id): void {
    $db = tokens_db();
    if (!$db) return;
    try {
        $db->prepare("DELETE FROM token_hits WHERE token_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM tokens WHERE id = ?")->execute([$id]);
    } catch (\Throwable $e) {}
}

/** A believable decoy .env whose callback lines ARE the tripwire (stable per id). */
function token_fake_creds(string $id): string {
    $rand = function (int $n): string {
        return substr(strtr(base64_encode(random_bytes($n * 2)), '+/=', 'xyz'), 0, $n);
    };
    $u = token_url($id);
    $p = token_pixel_url($id);
    return "# Production environment — DO NOT COMMIT\n"
         . "# generated " . date('c') . "\n\n"
         . "APP_ENV=production\n"
         . "AWS_ACCESS_KEY_ID=AKIA" . strtoupper($rand(16)) . "\n"
         . "AWS_SECRET_ACCESS_KEY=" . $rand(40) . "\n"
         . "DB_HOST=10.0.1.50\n"
         . "DB_USER=svc_reporting\n"
         . "DB_PASSWORD=" . $rand(24) . "\n"
         . "INTERNAL_API_BASE=" . $u . "\n"
         . "SENTRY_DSN=" . $p . "\n";
}
