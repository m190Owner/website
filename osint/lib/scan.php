<?php
// Footprint scan engine — a faithful PHP port of m190finder's core, built for a
// shared-PHP host: no 20-minute request. The browser drives the scan in small
// parallel batches (osint/scan.php), each a short request, so ~700 sites spread
// across many chunks with a live progress bar.
//
// Sources, all free + key-free, exactly like the original:
//   - accounts: your usernames vs the WhatsMyName dataset (GET a public profile
//     URL; a hit needs BOTH the status code AND the marker string to agree).
//   - breach:   your emails vs XposedOrNot (404 = clean, 200 = parse breaches).
//   - gravatar: your emails vs Gravatar (a public email->profile mapping).
// POST/login-flow and captcha-gated sites are skipped (we never probe signup or
// solve captchas), and NSFW sites are excluded. Scoped only to the signed-in
// user's own profile identifiers.
require_once __DIR__ . '/osint_auth.php';

const OSINT_MAX_USERNAMES = 3;
const OSINT_MAX_EMAILS    = 5;
const OSINT_BATCH         = 30;    // sites checked in parallel per chunk request
const OSINT_UA            = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const OSINT_XPOSED        = 'https://api.xposedornot.com/v1/check-email/';
const OSINT_GRAVATAR      = 'https://www.gravatar.com/';

function scan_db(): ?PDO {
    static $ready = false;
    $db = osint_db();
    if ($db && !$ready) {
        $ready = true;
        $db->exec("CREATE TABLE IF NOT EXISTS osint_profile (
            user_id INTEGER PRIMARY KEY, usernames TEXT NOT NULL DEFAULT '[]',
            emails TEXT NOT NULL DEFAULT '[]', updated_at INTEGER)");
        $db->exec("CREATE TABLE IF NOT EXISTS osint_scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            started_at INTEGER NOT NULL, finished_at INTEGER, status TEXT NOT NULL DEFAULT 'running',
            cursor INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0,
            found INTEGER NOT NULL DEFAULT 0, unreachable INTEGER NOT NULL DEFAULT 0,
            usernames TEXT NOT NULL DEFAULT '[]', emails TEXT NOT NULL DEFAULT '[]')");
        $db->exec("CREATE TABLE IF NOT EXISTS osint_findings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, scan_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
            category TEXT NOT NULL, title TEXT NOT NULL, url TEXT NOT NULL,
            exposes TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_osf_scan ON osint_findings(scan_id)");
    }
    return $db;
}

/** The checkable WhatsMyName sites (post/captcha/NSFW removed). Cached. */
function scan_sites(): array {
    static $sites = null;
    if ($sites !== null) return $sites;
    $sites = [];
    $raw = json_decode((string) @file_get_contents(__DIR__ . '/../assets/wmn-data.json'), true);
    foreach (($raw['sites'] ?? []) as $s) {
        if (!empty($s['post_body'])) continue;                                   // no signup/login probes
        if (in_array('captcha', (array) ($s['protection'] ?? []), true)) continue; // never solve captchas
        if (($s['cat'] ?? 'misc') === 'xx NSFW xx') continue;
        if (empty($s['uri_check'])) continue;
        $sites[] = [
            'name' => (string) $s['name'],
            'uri'  => (string) $s['uri_check'],
            'ec'   => (int) ($s['e_code'] ?? 200),
            'es'   => (string) ($s['e_string'] ?? ''),
            'cat'  => (string) ($s['cat'] ?? 'misc'),
            'hdr'  => (array) ($s['headers'] ?? []),
            'strip'=> (string) ($s['strip_bad_char'] ?? ''),
        ];
    }
    return $sites;
}

// ---- profile ----
function scan_profile_get(int $uid): array {
    $db = scan_db(); if (!$db) return ['usernames' => [], 'emails' => []];
    $st = $db->prepare("SELECT usernames, emails FROM osint_profile WHERE user_id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    return [
        'usernames' => $r ? (array) json_decode($r['usernames'], true) : [],
        'emails'    => $r ? (array) json_decode($r['emails'], true) : [],
    ];
}

/** Normalize + persist the profile. Returns the cleaned [usernames, emails]. */
function scan_profile_set(int $uid, array $usernames, array $emails): array {
    $u = [];
    foreach ($usernames as $x) {
        $x = trim((string) $x);
        if ($x !== '' && preg_match('/^[A-Za-z0-9._\-]{1,40}$/', $x) && !in_array($x, $u, true)) $u[] = $x;
        if (count($u) >= OSINT_MAX_USERNAMES) break;
    }
    $e = [];
    foreach ($emails as $x) {
        $x = strtolower(trim((string) $x));
        if ($x !== '' && filter_var($x, FILTER_VALIDATE_EMAIL) && !in_array($x, $e, true)) $e[] = $x;
        if (count($e) >= OSINT_MAX_EMAILS) break;
    }
    $db = scan_db();
    if ($db) {
        $db->prepare("INSERT INTO osint_profile (user_id,usernames,emails,updated_at) VALUES (?,?,?,?)
                      ON CONFLICT(user_id) DO UPDATE SET usernames=excluded.usernames, emails=excluded.emails, updated_at=excluded.updated_at")
           ->execute([$uid, json_encode($u), json_encode($e), time()]);
    }
    return ['usernames' => $u, 'emails' => $e];
}

// ---- scan lifecycle ----
function scan_total(int $nUser, int $nSite, int $nEmail): int { return $nUser * $nSite + $nEmail * 2; }

/** Map a global task index to a concrete task using the scan's snapshot. */
function scan_task_at(int $i, array $U, array $S, array $E): ?array {
    $acc = count($U) * count($S);
    if ($i < $acc) {
        $ns = count($S);
        return ['kind' => 'account', 'user' => $U[intdiv($i, $ns)], 'site' => $S[$i % $ns]];
    }
    $j = $i - $acc;
    if ($j < count($E)) return ['kind' => 'breach', 'email' => $E[$j]];
    $j -= count($E);
    if ($j < count($E)) return ['kind' => 'gravatar', 'email' => $E[$j]];
    return null;
}

/** Start a scan. Returns [scan, null] or [null, error]. */
function scan_start(int $uid): array {
    $p = scan_profile_get($uid);
    if (!$p['usernames'] && !$p['emails']) return [null, 'Add at least one username or email to your profile first.'];
    $db = scan_db(); if (!$db) return [null, 'Service unavailable.'];
    $total = scan_total(count($p['usernames']), count(scan_sites()), count($p['emails']));
    $db->prepare("INSERT INTO osint_scans (user_id,started_at,total,usernames,emails) VALUES (?,?,?,?,?)")
       ->execute([$uid, time(), $total, json_encode($p['usernames']), json_encode($p['emails'])]);
    return [['id' => (int) $db->lastInsertId(), 'total' => $total, 'cursor' => 0], null];
}

/** Process the next batch of a running scan. Returns a progress array. */
function scan_chunk(int $uid, int $scanId): array {
    $db = scan_db(); if (!$db) return ['ok' => false, 'error' => 'db'];
    $st = $db->prepare("SELECT * FROM osint_scans WHERE id = ? AND user_id = ?");
    $st->execute([$scanId, $uid]);
    $scan = $st->fetch();
    if (!$scan) return ['ok' => false, 'error' => 'no such scan'];
    if ($scan['status'] === 'done') return scan_progress($scan, []);

    $U = (array) json_decode($scan['usernames'], true);
    $E = (array) json_decode($scan['emails'], true);
    $S = scan_sites();
    $cursor = (int) $scan['cursor'];
    $total  = (int) $scan['total'];
    $end    = min($cursor + OSINT_BATCH, $total);

    // Build this batch's HTTP tasks.
    $tasks = [];
    $meta  = [];
    for ($i = $cursor; $i < $end; $i++) {
        $t = scan_task_at($i, $U, $S, $E);
        if (!$t) continue;
        $meta[$i] = $t;
        if ($t['kind'] === 'account') {
            $user = $t['user'];
            foreach (str_split($t['site']['strip'] ?: '') as $bad) if ($bad !== '') $user = str_replace($bad, '', $user);
            $url = str_replace('{account}', rawurlencode_username($user), $t['site']['uri']);
            $hdr = ['User-Agent: ' . OSINT_UA];
            foreach ($t['site']['hdr'] as $k => $v) $hdr[] = $k . ': ' . $v;
            $tasks[$i] = ['url' => $url, 'headers' => $hdr, 'follow' => false];
        } elseif ($t['kind'] === 'breach') {
            $tasks[$i] = ['url' => OSINT_XPOSED . rawurlencode($t['email']), 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true];
        } else { // gravatar
            $tasks[$i] = ['url' => OSINT_GRAVATAR . md5(strtolower(trim($t['email']))) . '.json', 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true];
        }
    }

    $res = scan_multi_get($tasks);

    $foundInc = 0; $unreachInc = 0; $newFindings = [];
    $ins = $db->prepare("INSERT INTO osint_findings (scan_id,user_id,category,title,url,exposes,created_at) VALUES (?,?,?,?,?,?,?)");
    foreach ($meta as $i => $t) {
        $r = $res[$i] ?? null;
        if ($t['kind'] === 'account') {
            if (!$r || $r['err']) { $unreachInc++; continue; }
            if (scan_matches($t['site'], $r['code'], $r['body'])) {
                $title = $t['user'] . ' on ' . $t['site']['name'];
                $ins->execute([$scanId, $uid, 'account', $title, $tasks[$i]['url'], 'account', time()]);
                $newFindings[] = ['category' => 'account', 'title' => $title, 'url' => $tasks[$i]['url']];
                $foundInc++;
            } elseif (scan_blocked($r['code'], (int) $t['site']['ec'])) {
                $unreachInc++;   // 403/429/5xx: we were blocked, so this is "couldn't check", not "clean"
            }
        } elseif ($t['kind'] === 'breach') {
            if (!$r || $r['err']) { $unreachInc++; continue; }
            if ($r['code'] === 404) continue;                 // genuinely clean
            if ($r['code'] !== 200) { $unreachInc++; continue; } // unknown, not clear
            foreach (scan_breach_names($r['body']) as $name) {
                $title = $t['email'] . ' in the ' . $name . ' breach';
                $ins->execute([$scanId, $uid, 'breach', $title, 'https://xposedornot.com/', 'email,breach', time()]);
                $newFindings[] = ['category' => 'breach', 'title' => $title, 'url' => 'https://xposedornot.com/'];
                $foundInc++;
            }
        } else { // gravatar
            if (!$r || $r['err'] || $r['code'] !== 200) continue;
            $title = $t['email'] . ' has a public Gravatar profile';
            $url = OSINT_GRAVATAR . md5(strtolower(trim($t['email'])));
            $ins->execute([$scanId, $uid, 'account', $title, $url, 'email,account', time()]);
            $newFindings[] = ['category' => 'account', 'title' => $title, 'url' => $url];
            $foundInc++;
        }
    }

    $newCursor = $end;
    $done = $newCursor >= $total;
    $db->prepare("UPDATE osint_scans SET cursor=?, found=found+?, unreachable=unreachable+?, status=?, finished_at=? WHERE id=?")
       ->execute([$newCursor, $foundInc, $unreachInc, $done ? 'done' : 'running', $done ? time() : null, $scanId]);

    $scan['cursor'] = $newCursor; $scan['found'] += $foundInc; $scan['unreachable'] += $unreachInc; $scan['status'] = $done ? 'done' : 'running';
    return scan_progress($scan, $newFindings);
}

function scan_progress(array $scan, array $newFindings): array {
    return [
        'ok'          => true,
        'scan_id'     => (int) $scan['id'],
        'done'        => (int) $scan['cursor'],
        'total'       => (int) $scan['total'],
        'found'       => (int) $scan['found'],
        'unreachable' => (int) $scan['unreachable'],
        'status'      => $scan['status'],
        'new'         => $newFindings,
    ];
}

function scan_matches(array $site, int $code, string $body): bool {
    if ($code !== (int) $site['ec']) return false;
    if (($site['es'] ?? '') === '') return true;
    return strpos($body, $site['es']) !== false;
}

/** A non-matching response we should treat as "couldn't check" rather than "clean". */
function scan_blocked(int $code, int $expected): bool {
    if ($code === $expected) return false;
    return $code === 0 || $code === 401 || $code === 403 || $code === 429 || $code >= 500;
}

/** XposedOrNot breach-name extraction (tolerant of schema drift). */
function scan_breach_names(string $body): array {
    $p = json_decode($body, true);
    if (!is_array($p)) return [];
    $out = [];
    foreach ((array) ($p['breaches'] ?? []) as $item) {
        if (is_string($item)) $out[] = $item;
        elseif (is_array($item)) foreach ($item as $x) if (is_string($x)) $out[] = $x;
    }
    return array_values(array_filter(array_unique($out)));
}

/** Username goes into a path/query segment — encode but keep it readable. */
function rawurlencode_username(string $u): string { return str_replace('%40', '@', rawurlencode($u)); }

/** Parallel GET a batch of tasks. Returns [key => ['code','body','err']]. Caps body size. */
function scan_multi_get(array $tasks, int $cap = 262144): array {
    $out = [];
    if (!$tasks || !function_exists('curl_multi_init')) {
        foreach ($tasks as $k => $t) $out[$k] = ['code' => 0, 'body' => '', 'err' => true];
        return $out;
    }
    $mh = curl_multi_init();
    $handles = []; $buf = [];
    $localCa = 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt';
    foreach ($tasks as $key => $t) {
        $ch = curl_init();
        $buf[$key] = '';
        curl_setopt_array($ch, [
            CURLOPT_URL            => $t['url'],
            CURLOPT_HTTPHEADER     => $t['headers'] ?? [],
            CURLOPT_FOLLOWLOCATION => !empty($t['follow']),
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION  => function ($c, $data) use (&$buf, $key, $cap) {
                if (strlen($buf[$key]) >= $cap) return 0;      // enough for matching; abort the rest
                $buf[$key] .= $data;
                return strlen($data);
            },
        ]);
        if (is_file($localCa)) curl_setopt($ch, CURLOPT_CAINFO, $localCa);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running > 0);
    foreach ($handles as $key => $ch) {
        $errno = curl_errno($ch);
        $ok = ($errno === 0 || $errno === CURLE_WRITE_ERROR);   // 23 = we aborted at the cap, still valid
        $out[$key] = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $buf[$key], 'err' => !$ok];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// ---- read side ----
function scan_get(int $uid, int $scanId): ?array {
    $db = scan_db(); if (!$db) return null;
    $st = $db->prepare("SELECT * FROM osint_scans WHERE id = ? AND user_id = ?");
    $st->execute([$scanId, $uid]);
    return $st->fetch() ?: null;
}
function scan_latest(int $uid): ?array {
    $db = scan_db(); if (!$db) return null;
    $st = $db->prepare("SELECT * FROM osint_scans WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}
function scan_findings(int $uid, int $scanId): array {
    $db = scan_db(); if (!$db) return [];
    $st = $db->prepare("SELECT category,title,url,exposes FROM osint_findings WHERE scan_id = ? AND user_id = ? ORDER BY category, id");
    $st->execute([$scanId, $uid]);
    return $st->fetchAll();
}
