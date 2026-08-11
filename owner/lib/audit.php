<?php
// Security audit log. A standalone SQLite in the owner's domain (owner/data/),
// WRITTEN by the videos auth call sites via audit_log(), READ by the /owner/
// console. audit_log() never throws — a logging failure must not break a login.
require_once __DIR__ . '/owner_auth.php';     // owner_config() (webhook, cainfo)

define('OWNER_DATA_DIR',   __DIR__ . '/../data');
define('AUDIT_DB_PATH',    OWNER_DATA_DIR . '/audit.sqlite');
define('AUDIT_RETAIN_DAYS', 90);
define('AUDIT_MAX_ROWS',    20000);

// Severities pushed to Discord by default.
const AUDIT_PUSH_SEVERITIES = ['warn', 'crit'];
// Noisy events coalesced to one Discord alert per IP per window (seconds), so a
// brute-force burst pings once, not a hundred times.
const AUDIT_THROTTLE = [
    'login_fail'       => 900,
    'owner_login_fail' => 900,
    'twofa_fail'       => 900,
    'csrf_fail'        => 1800,
];

function owner_audit_db(): ?PDO {
    static $pdo = false;                       // false = not yet tried, null = failed
    if ($pdo !== false) return $pdo;
    try {
        if (!is_dir(OWNER_DATA_DIR)) @mkdir(OWNER_DATA_DIR, 0755, true);
        $pdo = new PDO('sqlite:' . AUDIT_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS events (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            ts        INTEGER NOT NULL,
            event     TEXT NOT NULL,
            severity  TEXT NOT NULL,
            actor     TEXT NOT NULL DEFAULT '',
            actor_uid INTEGER,
            ip        TEXT NOT NULL DEFAULT '',
            ua        TEXT NOT NULL DEFAULT '',
            target    TEXT NOT NULL DEFAULT '',
            detail    TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_ts    ON events(ts DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_event ON events(event)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS alert_throttle (
            k TEXT PRIMARY KEY, last_ts INTEGER NOT NULL
        )");
    } catch (\Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** Best-effort real client IP; trusts forwarding headers (host/CDN sits in front). */
function audit_client_ip(): string {
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Record a security event. $opts keys: actor, actor_uid, target, detail, ip, ua,
 * push (bool, overrides the severity default). Writes a row and, for warn/crit,
 * posts to the #security webhook (throttled for noisy events). Best-effort.
 */
function audit_log(string $event, string $severity, array $opts = []): void {
    try {
        $db = owner_audit_db();
        if (!$db) return;
        $ip = (string) ($opts['ip'] ?? audit_client_ip());
        $ua = mb_substr((string) ($opts['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
        $db->prepare(
            "INSERT INTO events (ts,event,severity,actor,actor_uid,ip,ua,target,detail)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            time(), $event, $severity,
            mb_substr((string) ($opts['actor'] ?? ''), 0, 80),
            isset($opts['actor_uid']) ? (int) $opts['actor_uid'] : null,
            $ip, $ua,
            mb_substr((string) ($opts['target'] ?? ''), 0, 120),
            mb_substr((string) ($opts['detail'] ?? ''), 0, 500),
        ]);

        if (random_int(1, 50) === 1) audit_prune($db);   // occasional bounded prune

        $push = $opts['push'] ?? in_array($severity, AUDIT_PUSH_SEVERITIES, true);
        if ($push && audit_should_alert($db, $event, $ip)) {
            audit_discord($event, $severity, $ip, $opts);
        }
    } catch (\Throwable $e) {
        // Swallow — logging must never break the caller.
    }
}

/** Throttle gate: false if we alerted for this (event,ip) within the window. */
function audit_should_alert(PDO $db, string $event, string $ip): bool {
    $window = AUDIT_THROTTLE[$event] ?? 0;
    if ($window <= 0) return true;
    $now = time();
    $k = $event . '|' . $ip;
    try {
        $st = $db->prepare("SELECT last_ts FROM alert_throttle WHERE k = ?");
        $st->execute([$k]);
        $last = $st->fetchColumn();
        if ($last !== false && $now - (int) $last < $window) return false;
        $db->prepare("INSERT INTO alert_throttle (k,last_ts) VALUES (?,?)
                      ON CONFLICT(k) DO UPDATE SET last_ts = excluded.last_ts")
           ->execute([$k, $now]);
    } catch (\Throwable $e) {
        return true;
    }
    return true;
}

function audit_prune(PDO $db): void {
    try {
        $db->prepare("DELETE FROM events WHERE ts < ?")->execute([time() - AUDIT_RETAIN_DAYS * 86400]);
        $db->exec("DELETE FROM events WHERE id NOT IN (SELECT id FROM events ORDER BY id DESC LIMIT " . AUDIT_MAX_ROWS . ")");
        $db->exec("DELETE FROM alert_throttle WHERE last_ts < " . (time() - 86400));
    } catch (\Throwable $e) {}
}

/** Post a security alert to the #security webhook. Domain-guarded (SSRF-safe). */
function audit_discord(string $event, string $severity, string $ip, array $opts): void {
    $c = owner_config();
    $webhook = (string) ($c['security_webhook'] ?? '');
    if ($webhook === '') return;
    if (!preg_match('#^https://(canary\.|ptb\.)?discord(app)?\.com/api/webhooks/#', $webhook)) return;

    $colors = ['crit' => 0xE5555F, 'warn' => 0xFAA61A, 'info' => 0x7AA2FF];
    $lines = [];
    if (!empty($opts['actor']))  $lines[] = '**Actor:** ' . $opts['actor'];
    if (!empty($opts['target'])) $lines[] = '**Target:** ' . $opts['target'];
    $lines[] = '**IP:** ' . ($ip !== '' ? $ip : 'unknown');
    if (!empty($opts['detail'])) $lines[] = (string) $opts['detail'];

    $body = json_encode(['username' => 'security', 'embeds' => [[
        'title'       => mb_substr(strtoupper($severity) . ' · ' . $event, 0, 250),
        'description' => mb_substr(implode("\n", $lines), 0, 1500),
        'color'       => $colors[$severity] ?? 0x888888,
        'timestamp'   => date('c'),
    ]]]);

    if (function_exists('curl_init')) {
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (!empty($c['cainfo']) && is_file($c['cainfo'])) curl_setopt($ch, CURLOPT_CAINFO, $c['cainfo']);
        curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST',
            'header' => "Content-Type: application/json\r\n", 'content' => $body,
            'timeout' => 6, 'ignore_errors' => true]]);
        @file_get_contents($webhook, false, $ctx);
    }
}

// ---- Read side (owner viewer) ----
function audit_filter_sql(array $filters, array &$args): string {
    $where = [];
    if (!empty($filters['event']))    { $where[] = 'event = ?';    $args[] = $filters['event']; }
    if (!empty($filters['severity'])) { $where[] = 'severity = ?'; $args[] = $filters['severity']; }
    if (!empty($filters['actor']))    { $where[] = 'actor LIKE ?'; $args[] = '%' . $filters['actor'] . '%'; }
    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}

function audit_recent(array $filters = [], int $limit = 100, int $offset = 0): array {
    $db = owner_audit_db();
    if (!$db) return [];
    $args = [];
    $sql = 'SELECT * FROM events' . audit_filter_sql($filters, $args)
         . ' ORDER BY id DESC LIMIT ' . (int) $limit . ' OFFSET ' . max(0, (int) $offset);
    $st = $db->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function audit_count(array $filters = []): int {
    $db = owner_audit_db();
    if (!$db) return 0;
    $args = [];
    $st = $db->prepare('SELECT COUNT(*) FROM events' . audit_filter_sql($filters, $args));
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function audit_event_types(): array {
    $db = owner_audit_db();
    if (!$db) return [];
    try {
        return array_column($db->query("SELECT DISTINCT event FROM events ORDER BY event")->fetchAll(), 'event');
    } catch (\Throwable $e) {
        return [];
    }
}
