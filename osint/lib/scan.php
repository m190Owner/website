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
const OSINT_MAX_PHONES    = 3;
const OSINT_MAX_DOMAINS   = 3;
const OSINT_BATCH         = 30;    // sites checked in parallel per chunk request
const OSINT_UA            = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const OSINT_XPOSED        = 'https://api.xposedornot.com/v1/breach-analytics?email=';
const OSINT_GRAVATAR      = 'https://www.gravatar.com/';
const OSINT_BREACH_CAP    = 60;    // most-recent breaches kept per email

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
        // Migrate older DBs: avatar (profile picture / logo) + detail (breach date, etc.).
        foreach (['avatar', 'detail'] as $col) {
            try { $db->exec("ALTER TABLE osint_findings ADD COLUMN $col TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
        }
        // Per-finding triage the user sets: new | attention | false | done.
        try { $db->exec("ALTER TABLE osint_findings ADD COLUMN status TEXT NOT NULL DEFAULT 'new'"); } catch (\Throwable $e) {}
        // Whether the scan included the opt-in deep (extra-site) email checks, and the
        // separate opt-in emailing "probe" checks (password-reset based).
        try { $db->exec("ALTER TABLE osint_scans ADD COLUMN deep INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE osint_scans ADD COLUMN probe INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        // Phone numbers added to the profile (offline metadata is computed from them).
        try { $db->exec("ALTER TABLE osint_profile ADD COLUMN phones TEXT NOT NULL DEFAULT '[]'"); } catch (\Throwable $e) {}
        // Domains added to the profile (DNS / email-security / subdomain footprint).
        try { $db->exec("ALTER TABLE osint_profile ADD COLUMN domains TEXT NOT NULL DEFAULT '[]'"); } catch (\Throwable $e) {}
        // Generic per-user checklist state, backing the removal + hardening trackers.
        $db->exec("CREATE TABLE IF NOT EXISTS osint_checklist (
            user_id INTEGER NOT NULL, list TEXT NOT NULL, item TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'done', updated_at INTEGER NOT NULL,
            PRIMARY KEY (user_id, list, item))");
        // Cached domain-footprint lookups (so revisiting is instant + feeds the report).
        $db->exec("CREATE TABLE IF NOT EXISTS osint_domain_cache (
            user_id INTEGER NOT NULL, domain TEXT NOT NULL, json TEXT NOT NULL DEFAULT '{}',
            updated_at INTEGER NOT NULL, PRIMARY KEY (user_id, domain))");
        // Opt-in breach monitoring: a baseline of known breaches per email, plus any new
        // ones found since (surfaced in-app on next login). Driven by osint/cron.php.
        $db->exec("CREATE TABLE IF NOT EXISTS osint_monitor (
            user_id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0,
            last_check INTEGER, known TEXT NOT NULL DEFAULT '{}', pending TEXT NOT NULL DEFAULT '[]')");
        // Persistent "not me" — keyed by a hash of the finding title, so future scans
        // auto-mark the same account/breach false. Survives "clear results".
        $db->exec("CREATE TABLE IF NOT EXISTS osint_dismissed (
            user_id INTEGER NOT NULL, key_hash TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL, PRIMARY KEY (user_id, key_hash))");
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
    $db = scan_db(); if (!$db) return ['usernames' => [], 'emails' => [], 'phones' => [], 'domains' => []];
    $st = $db->prepare("SELECT usernames, emails, phones, domains FROM osint_profile WHERE user_id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    return [
        'usernames' => $r ? (array) json_decode($r['usernames'], true) : [],
        'emails'    => $r ? (array) json_decode($r['emails'], true) : [],
        'phones'    => $r ? (array) json_decode($r['phones'] ?? '[]', true) : [],
        'domains'   => $r ? (array) json_decode($r['domains'] ?? '[]', true) : [],
    ];
}

/** Normalize a domain: strip scheme/path/www, lowercase, validate. Null if not a domain. */
function scan_domain_normalize(string $raw): ?string {
    $d = strtolower(trim($raw));
    $d = preg_replace('#^[a-z]+://#', '', $d);        // strip scheme
    $d = explode('/', $d)[0];                          // strip path
    $d = explode('?', $d)[0];
    $d = explode('@', $d);                             // strip any user@ / email local part
    $d = end($d);
    $d = preg_replace('/:\d+$/', '', $d);              // strip port
    $d = preg_replace('/^www\./', '', $d);
    $d = rtrim($d, '.');
    if ($d === '' || strlen($d) > 253 || strpos($d, '.') === false) return null;
    if (!filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return null;
    if (!preg_match('/[a-z]{2,}$/', $d)) return null;  // require an alphabetic TLD
    return $d;
}

/** Normalize + persist the profile. Returns the cleaned [usernames, emails, phones, domains]. */
function scan_profile_set(int $uid, array $usernames, array $emails, array $phones = [], array $domains = []): array {
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
    $ph = [];
    foreach ($phones as $x) {
        $meta = scan_phone_meta((string) $x);
        if ($meta && !in_array($meta['e164'], $ph, true)) $ph[] = $meta['e164'];   // store normalized E.164
        if (count($ph) >= OSINT_MAX_PHONES) break;
    }
    $dm = [];
    foreach ($domains as $x) {
        $d = scan_domain_normalize((string) $x);
        if ($d !== null && !in_array($d, $dm, true)) $dm[] = $d;
        if (count($dm) >= OSINT_MAX_DOMAINS) break;
    }
    $db = scan_db();
    if ($db) {
        $db->prepare("INSERT INTO osint_profile (user_id,usernames,emails,phones,domains,updated_at) VALUES (?,?,?,?,?,?)
                      ON CONFLICT(user_id) DO UPDATE SET usernames=excluded.usernames, emails=excluded.emails, phones=excluded.phones, domains=excluded.domains, updated_at=excluded.updated_at")
           ->execute([$uid, json_encode($u), json_encode($e), json_encode($ph), json_encode($dm), time()]);
    }
    return ['usernames' => $u, 'emails' => $e, 'phones' => $ph, 'domains' => $dm];
}

/** Bundled offline phone data: calling codes + NANP area codes + Canadian area codes. */
function scan_phone_data(): array {
    static $d = null;
    if ($d === null) {
        $d = json_decode((string) @file_get_contents(__DIR__ . '/../assets/phone-cc.json'), true);
        if (!is_array($d)) $d = ['codes' => [], 'nanp' => [], 'ca' => []];
    }
    return $d;
}

/** Parse a phone number offline → [e164, cc, country, nat, valid], or null if implausible. */
function scan_phone_meta(string $raw): ?array {
    $plus   = strpos(trim($raw), '+') === 0;
    $digits = preg_replace('/\D+/', '', $raw);
    if (!$plus && strpos($digits, '00') === 0) $digits = substr($digits, 2);   // 00 intl prefix
    if (strlen($digits) < 7 || strlen($digits) > 15) return null;
    $d = scan_phone_data();
    if (strlen($digits) === 10 && $digits[0] !== '0' && !$plus) $digits = '1' . $digits;   // bare US 10-digit

    if ($digits[0] === '1' && strlen($digits) === 11) {                          // NANP
        $area = substr($digits, 1, 3);
        $c = $d['nanp'][$area] ?? (in_array($area, $d['ca'] ?? [], true) ? ['CA', 'Canada'] : ['US', 'United States']);
        return ['e164' => '+' . $digits, 'cc' => $c[0], 'country' => $c[1], 'nat' => substr($digits, 1), 'valid' => true];
    }
    for ($L = 3; $L >= 1; $L--) {                                                // international
        $code = substr($digits, 0, $L);
        if (isset($d['codes'][$code])) {
            $c = $d['codes'][$code];
            return ['e164' => '+' . $digits, 'cc' => $c[0], 'country' => $c[1], 'nat' => substr($digits, $L), 'valid' => strlen($digits) >= 8];
        }
    }
    return ['e164' => '+' . $digits, 'cc' => '', 'country' => 'Unknown country code', 'nat' => $digits, 'valid' => false];
}

/** ISO2 → flag emoji (regional indicator symbols). */
function scan_flag(string $cc): string {
    $cc = strtoupper($cc);
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
}

// ---- scan lifecycle ----
// ---- deep (opt-in) email checks: extra sites' account APIs. These particular ones do
//      NOT send an email to the address; the opt-in gate keeps them off by default anyway
//      (slower + probes third parties), and lets emailing modules be added under it later.
function osint_deep_modules(): array {
    static $m = null;
    if ($m !== null) return $m;
    $ua = 'User-Agent: ' . OSINT_UA;
    $m = [
        'spotify' => [
            'name' => 'Spotify', 'url' => 'https://www.spotify.com/',
            'build' => fn($e) => ['url' => 'https://spclient.wg.spotify.com/signup/public/v1/account?validate=1&email=' . rawurlencode($e), 'headers' => [$ua], 'follow' => true],
            'parse' => function ($code, $body) { $j = json_decode($body, true); $s = is_array($j) ? ($j['status'] ?? null) : null; if ($s === 20) return true; if ($s === 1) return false; return null; },
        ],
        'twitter' => [
            'name' => 'X (Twitter)', 'url' => 'https://twitter.com/',
            'build' => fn($e) => ['url' => 'https://api.twitter.com/i/users/email_available.json?email=' . rawurlencode($e), 'headers' => [$ua], 'follow' => true],
            'parse' => function ($code, $body) { $j = json_decode($body, true); return (is_array($j) && isset($j['taken'])) ? (bool) $j['taken'] : null; },
        ],
        'plurk' => [
            'name' => 'Plurk', 'url' => 'https://www.plurk.com/',
            'build' => fn($e) => ['url' => 'https://www.plurk.com/Users/isEmailFound?email=' . rawurlencode($e), 'headers' => [$ua], 'follow' => true],
            'parse' => function ($code, $body) { $b = trim($body); if ($b === 'True') return true; if ($b === 'False') return false; return null; },
        ],
        // --- emailing modules: a real hit SENDS a password-reset email to the address.
        //     Gated behind the separate "aggressive" opt-in. A blocked request 429s before
        //     any email fires (safe); most datacenter IPs are blocked, so expect "couldn't
        //     check" from a server. 'prep' runs once to grab a CSRF token from the page.
        'instagram' => [
            'name' => 'Instagram', 'url' => 'https://www.instagram.com/', 'emails' => true,
            'prep'  => function () use ($ua) {
                $r = scan_multi_get([0 => ['url' => 'https://www.instagram.com/accounts/login/', 'headers' => [$ua], 'follow' => true]]);
                return preg_match('/"csrf_token":"([^"]+)"/', $r[0]['body'] ?? '', $mm) ? $mm[1] : '';
            },
            'build' => function ($e, $csrf = null) use ($ua) {
                if (!$csrf) return null;
                return ['url' => 'https://www.instagram.com/api/v1/web/accounts/account_recovery_send_ajax/',
                        'headers' => [$ua, 'X-CSRFToken: ' . $csrf, 'X-Requested-With: XMLHttpRequest',
                                      'Content-Type: application/x-www-form-urlencoded', 'Referer: https://www.instagram.com/accounts/password/reset/'],
                        'post' => 'email_or_username=' . rawurlencode($e), 'follow' => true];
            },
            'parse' => function ($code, $body) {
                $j = json_decode($body, true);
                if (!is_array($j)) return null;
                if (($j['status'] ?? '') === 'ok') return true;                       // reset link sent => exists
                if (stripos(json_encode($j), 'no users found') !== false) return false;
                return null;                                                          // rate-limited / unclear
            },
        ],
    ];
    return $m;
}

/** Per-email checks in order. $deep adds the no-email modules, $probe adds emailing ones. */
function scan_email_checks(bool $deep, bool $probe): array {
    $c = ['breach', 'gravatar', 'duolingo'];
    foreach (osint_deep_modules() as $id => $m) {
        $emailing = !empty($m['emails']);
        if (($emailing && $probe) || (!$emailing && $deep)) $c[] = 'deep:' . $id;
    }
    return $c;
}

// ---- scan lifecycle ----
function scan_total(int $nUser, int $nSite, int $nEmail, int $nEmailChecks): int { return $nUser * $nSite + $nEmail * $nEmailChecks; }

/** Map a global task index to a concrete task using the scan's snapshot + per-email checks. */
function scan_task_at(int $i, array $U, array $S, array $E, array $checks): ?array {
    $acc = count($U) * count($S);
    if ($i < $acc) {
        $ns = count($S);
        return ['kind' => 'account', 'user' => $U[intdiv($i, $ns)], 'site' => $S[$i % $ns]];
    }
    $K = count($checks);
    if ($K === 0) return null;
    $j  = $i - $acc;
    $ei = intdiv($j, $K);
    if ($ei >= count($E)) return null;
    return ['kind' => $checks[$j % $K], 'email' => $E[$ei]];
}

/** Start a scan. Returns [scan, null] or [null, error]. */
function scan_start(int $uid, bool $deep = false, bool $probe = false): array {
    $p = scan_profile_get($uid);
    if (!$p['usernames'] && !$p['emails'] && !$p['phones']) return [null, 'Add at least one username, email, or phone to your profile first.'];
    $db = scan_db(); if (!$db) return [null, 'Service unavailable.'];
    $total = scan_total(count($p['usernames']), count(scan_sites()), count($p['emails']), count(scan_email_checks($deep, $probe)));
    $db->prepare("INSERT INTO osint_scans (user_id,started_at,total,usernames,emails,deep,probe) VALUES (?,?,?,?,?,?,?)")
       ->execute([$uid, time(), $total, json_encode($p['usernames']), json_encode($p['emails']), $deep ? 1 : 0, $probe ? 1 : 0]);
    $scanId = (int) $db->lastInsertId();

    // A Gmail/Googlemail address inherently has a Google account — record it up front
    // (no probing: it's true by definition of the domain). Deeper Google profile lookup
    // needs an authenticated Google session, which a keyless hosted tool won't do.
    $dismissed = scan_dismissed_set($uid);
    $g = 0;
    foreach ($p['emails'] as $em) {
        if (preg_match('/@(gmail|googlemail)\.com$/i', $em)) {
            $title  = $em . ' — Google account';
            $status = isset($dismissed[scan_dismiss_key($title)]) ? 'false' : 'new';
            $db->prepare("INSERT INTO osint_findings (scan_id,user_id,category,title,url,exposes,avatar,detail,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$scanId, $uid, 'account', $title, 'https://myaccount.google.com/',
                          'email,google', '', 'This is a Gmail address, so it has a Google account.', $status, time()]);
            $g++;
        }
    }
    // Phone numbers: offline metadata (country/region/validity) — instant, no network.
    foreach ($p['phones'] as $ph) {
        $meta = scan_phone_meta($ph);
        if (!$meta) continue;
        $flag   = $meta['cc'] !== '' ? scan_flag($meta['cc']) . ' ' : '';
        $title  = $meta['e164'] . ' — ' . $flag . $meta['country'];
        $detail = ($meta['valid'] ? 'Valid number format' : 'Unusual format') . ($meta['cc'] !== '' ? ' · region ' . $meta['cc'] : '');
        $status = isset($dismissed[scan_dismiss_key($title)]) ? 'false' : 'new';
        $db->prepare("INSERT INTO osint_findings (scan_id,user_id,category,title,url,exposes,avatar,detail,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$scanId, $uid, 'phone', $title, '', 'phone', '', $detail, $status, time()]);
        $g++;
    }
    if ($g > 0) $db->prepare("UPDATE osint_scans SET found = found + ? WHERE id = ?")->execute([$g, $scanId]);
    return [['id' => $scanId, 'total' => $total, 'cursor' => 0], null];
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
    $checks = scan_email_checks((bool) ($scan['deep'] ?? 0), (bool) ($scan['probe'] ?? 0));
    $cursor = (int) $scan['cursor'];
    $total  = (int) $scan['total'];
    $end    = min($cursor + OSINT_BATCH, $total);

    // Build this batch's HTTP tasks.
    $tasks = [];
    $meta  = [];
    $prep  = [];   // per-module prep (e.g. a CSRF token), fetched once per batch
    for ($i = $cursor; $i < $end; $i++) {
        $t = scan_task_at($i, $U, $S, $E, $checks);
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
        } elseif ($t['kind'] === 'gravatar') { // profile JSON: 200 (+ profile) if the email has a Gravatar, 404 if not
            $tasks[$i] = ['url' => 'https://gravatar.com/' . md5(strtolower(trim($t['email']))) . '.json', 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true];
        } elseif ($t['kind'] === 'duolingo') { // public users API by email, no email is sent
            $tasks[$i] = ['url' => 'https://www.duolingo.com/2017-06-30/users?email=' . rawurlencode($t['email']), 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true];
        } else { // deep:<module>
            $id  = substr($t['kind'], 5);
            $mod = osint_deep_modules()[$id] ?? null;
            if ($mod) {
                $pv = null;
                if (isset($mod['prep'])) { if (!array_key_exists($id, $prep)) $prep[$id] = ($mod['prep'])(); $pv = $prep[$id]; }
                $built = ($mod['build'])($t['email'], $pv);
                if ($built) $tasks[$i] = $built;   // null build (e.g. no CSRF) → left unbuilt = couldn't check
            }
        }
    }

    $res = scan_multi_get($tasks);

    $foundInc = 0; $unreachInc = 0; $newFindings = [];
    $dismissed = scan_dismissed_set($uid);   // persistent "not me" — pre-mark matching hits
    $ins = $db->prepare("INSERT INTO osint_findings (scan_id,user_id,category,title,url,exposes,avatar,detail,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $emit = function (string $cat, string $title, string $url, string $exposes, string $avatar, string $detail)
                     use ($ins, $scanId, $uid, &$newFindings, &$foundInc, $dismissed) {
        $status = isset($dismissed[scan_dismiss_key($title)]) ? 'false' : 'new';
        $ins->execute([$scanId, $uid, $cat, $title, $url, $exposes, $avatar, $detail, $status, time()]);
        $newFindings[] = ['category' => $cat, 'title' => $title, 'url' => $url, 'avatar' => $avatar, 'detail' => $detail, 'status' => $status];
        $foundInc++;
    };
    foreach ($meta as $i => $t) {
        $r = $res[$i] ?? null;
        if ($t['kind'] === 'account') {
            if (!$r || $r['err']) { $unreachInc++; continue; }
            if (scan_matches($t['site'], $r['code'], $r['body'])) {
                $emit('account', $t['user'] . ' on ' . $t['site']['name'], $tasks[$i]['url'], 'account',
                      scan_extract_image($r['body'], $tasks[$i]['url']), '');   // og:image avatar to eyeball it
            } elseif (scan_blocked($r['code'], (int) $t['site']['ec'])) {
                $unreachInc++;   // 403/429/5xx: blocked, so "couldn't check", not "clean"
            }
        } elseif ($t['kind'] === 'breach') {
            if (!$r || $r['err']) { $unreachInc++; continue; }
            if ($r['code'] === 404) continue;                 // genuinely clean
            if ($r['code'] !== 200) { $unreachInc++; continue; } // unknown, not clear
            foreach (scan_breach_details($r['body']) as $b) {
                $detail = trim(($b['date'] ?: '') . ($b['data'] ? ' · ' . $b['data'] : ''), ' ·');
                $emit('breach', $t['email'] . ' in the ' . $b['name'] . ' breach', 'https://xposedornot.com/', 'email,breach', $b['logo'], $detail);
            }
        } elseif ($t['kind'] === 'gravatar') {
            if (!$r || $r['err']) { $unreachInc++; continue; }
            if ($r['code'] === 404) continue;                     // no Gravatar for this email
            if ($r['code'] !== 200) { $unreachInc++; continue; }
            $av = OSINT_GRAVATAR . 'avatar/' . md5(strtolower(trim($t['email']))) . '?s=200';
            $prof = scan_gravatar_profile($r['body']);
            $detail = $prof ? implode(' · ', array_filter([$prof['name'], $prof['location']])) : '';
            $emit('account', $t['email'] . ' — Gravatar profile', $av, 'email,account', $av, $detail);
            if ($prof) {
                foreach ($prof['accounts'] as $a) {
                    $emit('account', $t['email'] . ' — ' . $a['label'] . ($a['verified'] ? ' (verified)' : '') . ' via Gravatar',
                          $a['url'], 'email,account', '', 'Linked on your public Gravatar profile.');
                }
                foreach ($prof['urls'] as $ur) {
                    $emit('account', $t['email'] . ' — ' . $ur['title'] . ' (link on Gravatar)', $ur['url'], 'email,account', '', 'Personal link on your public Gravatar profile.');
                }
            }
        } elseif ($t['kind'] === 'duolingo') {
            if (!$r || $r['err']) { $unreachInc++; continue; }
            $pic = scan_duolingo_pic($r['body']);
            if ($pic === null) continue;   // no Duolingo account for this email
            $emit('account', $t['email'] . ' — Duolingo account', 'https://www.duolingo.com/', 'email,account', $pic, 'Email is registered on Duolingo.');
        } else { // deep:<module>
            $mod = osint_deep_modules()[substr($t['kind'], 5)] ?? null;
            if (!$mod || !$r || $r['err']) { $unreachInc++; continue; }
            $exists = ($mod['parse'])($r['code'], $r['body']);
            if ($exists === null) { $unreachInc++; continue; }   // couldn't tell (rate-limited / blocked)
            if ($exists === false) continue;                     // not registered there
            $note = !empty($mod['emails'])
                  ? 'Registered on ' . $mod['name'] . ' — a password-reset email was sent to the address.'
                  : 'Email is registered on ' . $mod['name'] . '.';
            $emit('account', $t['email'] . ' — ' . $mod['name'] . ' account', $mod['url'], 'email,account', '', $note);
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

/** XposedOrNot breach-analytics → [ ['name','date','data','logo'], ... ], most recent first. */
function scan_breach_details(string $body): array {
    $p = json_decode($body, true);
    $bs = $p['ExposedBreaches']['breaches_details'] ?? null;
    if (!is_array($bs)) return [];
    $out = [];
    foreach ($bs as $b) {
        if (!is_array($b) || empty($b['breach'])) continue;
        $data = (string) ($b['xposed_data'] ?? '');
        $out[] = [
            'name' => (string) $b['breach'],
            'date' => preg_replace('/[^0-9\-]/', '', (string) ($b['xposed_date'] ?? '')),
            'data' => mb_substr(str_replace(';', ', ', $data), 0, 160),
            'logo' => filter_var((string) ($b['logo'] ?? ''), FILTER_VALIDATE_URL) ? (string) $b['logo'] : '',
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));   // newest first
    return array_slice($out, 0, OSINT_BREACH_CAP);
}

/** Pull a profile avatar (og:image / twitter:image) out of an already-fetched page. */
function scan_extract_image(string $html, string $baseUrl): string {
    $head = substr($html, 0, 24000);   // these metas live in <head>, near the top
    foreach (['og:image', 'twitter:image', 'twitter:image:src'] as $prop) {
        $q = preg_quote($prop, '#');
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $m)
         || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $q . '["\']#i', $head, $m)) {
            $u = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            if ($u === '') continue;
            if (strpos($u, '//') === 0) $u = 'https:' . $u;
            elseif ($u !== '' && $u[0] === '/') { $p = parse_url($baseUrl); $u = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . $u; }
            if (preg_match('#^https?://#i', $u)) return mb_substr($u, 0, 400);
        }
    }
    return '';
}

/** Gravatar profile JSON → [name, location, about, accounts[], urls[]], or null. */
function scan_gravatar_profile(string $body): ?array {
    $j = json_decode($body, true);
    $e = is_array($j) ? ($j['entry'][0] ?? null) : null;
    if (!is_array($e)) return null;
    $accounts = [];
    foreach (($e['accounts'] ?? []) as $a) {
        $url = (string) ($a['url'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
        $accounts[] = [
            'label'    => (string) ($a['shortname'] ?? $a['display'] ?? $a['domain'] ?? 'account'),
            'url'      => $url,
            'verified' => !empty($a['verified']),
        ];
    }
    $urls = [];
    foreach (($e['urls'] ?? []) as $ur) {
        $v = (string) ($ur['value'] ?? '');
        if (filter_var($v, FILTER_VALIDATE_URL)) $urls[] = ['title' => (string) ($ur['title'] ?? $v), 'url' => $v];
    }
    return [
        'name'     => trim((string) ($e['displayName'] ?? '')),
        'location' => trim((string) ($e['currentLocation'] ?? '')),
        'about'    => trim((string) ($e['aboutMe'] ?? '')),
        'accounts' => $accounts,
        'urls'     => $urls,
    ];
}

/** Duolingo public-users response → profile picture URL, '' if the account has none,
 *  null if there is no account for that email. */
function scan_duolingo_pic(string $body): ?string {
    $j = json_decode($body, true);
    $users = is_array($j) ? ($j['users'] ?? null) : null;
    if (!is_array($users) || !$users) return null;   // no account
    $pic = (string) ($users[0]['picture'] ?? '');
    if ($pic === '') return '';
    if (strpos($pic, '//') === 0) $pic = 'https:' . $pic;
    return preg_match('#^https?://#i', $pic) ? mb_substr($pic, 0, 400) : '';
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
    $handles = []; $buf = []; $hdrs = [];
    $localCa = 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt';
    foreach ($tasks as $key => $t) {
        $ch = curl_init();
        $buf[$key] = ''; $hdrs[$key] = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $t['url'],
            CURLOPT_HTTPHEADER     => $t['headers'] ?? [],
            CURLOPT_FOLLOWLOCATION => !empty($t['follow']),
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $t['contimeout'] ?? 5,
            CURLOPT_TIMEOUT        => $t['timeout'] ?? 8,
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
        if (isset($t['post'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $t['post']); }
        if (!empty($t['wanthdr'])) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $h) use (&$hdrs, $key) {
                $p = strpos($h, ':');
                if ($p !== false) $hdrs[$key][strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
                return strlen($h);
            });
        }
        if (is_file($localCa)) curl_setopt($ch, CURLOPT_CAINFO, $localCa);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running > 0);
    foreach ($handles as $key => $ch) {
        $errno = curl_errno($ch);
        $ok = ($errno === 0 || $errno === CURLE_WRITE_ERROR);   // 23 = we aborted at the cap, still valid
        $out[$key] = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $buf[$key], 'err' => !$ok, 'headers' => $hdrs[$key] ?? []];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// ---- domain footprint (DNS + email security + certificate transparency) ----
/** Extract record data of a given numeric type from a Google DoH JSON response. */
function scan_doh_answers(?array $r, int $type): array {
    if (!$r || $r['err']) return [];
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['Answer'])) return [];
    $out = [];
    foreach ($j['Answer'] as $a) if ((int) ($a['type'] ?? 0) === $type) $out[] = trim((string) ($a['data'] ?? ''), '"');
    return $out;
}
/** The DNSSEC 'authenticated data' flag on a DoH response. */
function scan_doh_ad(?array $r): bool {
    if (!$r || $r['err']) return false;
    $j = json_decode($r['body'], true);
    return is_array($j) && !empty($j['AD']);
}
/** Subdomains for $domain out of a crt.sh JSON body (robust to truncation). Capped. */
function scan_crt_subdomains(?array $r, string $domain): array {
    if (!$r || $r['err'] || $r['code'] !== 200) return [];
    $set = [];
    if (preg_match_all('/"(?:name_value|common_name)":"([^"]*)"/', $r['body'], $m)) {
        foreach ($m[1] as $chunk) {
            foreach (preg_split('/\\\\n|\n/', $chunk) as $name) {
                $name = ltrim(strtolower(trim($name, ". \t")), '*.');
                if ($name !== '' && ($name === $domain || substr($name, -strlen('.' . $domain)) === '.' . $domain)) $set[$name] = true;
            }
        }
    }
    unset($set[$domain]);
    $list = array_keys($set);
    sort($list);
    return array_slice($list, 0, 100);
}

/** Live DNS / email-security / subdomain footprint for a domain (all keyless). */
function scan_domain_lookup(string $domainRaw): array {
    $domain = scan_domain_normalize($domainRaw);
    if ($domain === null) return ['error' => 'Not a valid domain.'];
    $doh = fn($name, $type) => ['url' => 'https://dns.google/resolve?name=' . rawurlencode($name) . '&type=' . $type,
                                'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true];
    $tasks = [
        'A' => $doh($domain, 'A'), 'AAAA' => $doh($domain, 'AAAA'), 'MX' => $doh($domain, 'MX'),
        'NS' => $doh($domain, 'NS'), 'TXT' => $doh($domain, 'TXT'), 'DMARC' => $doh('_dmarc.' . $domain, 'TXT'),
        'DS' => $doh($domain, 'DS'),
        'CRT' => ['url' => 'https://crt.sh/?q=' . rawurlencode('%.' . $domain) . '&output=json',
                  'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'timeout' => 22, 'contimeout' => 8],
        'HOME' => ['url' => 'https://' . $domain . '/', 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'wanthdr' => true, 'timeout' => 12],
        'WB' => ['url' => 'https://web.archive.org/cdx/search/cdx?url=' . rawurlencode($domain) . '&matchType=domain&output=json&collapse=urlkey&fl=original,timestamp&limit=500',
                 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'timeout' => 16],
    ];
    $res = scan_multi_get($tasks, 4194304);   // 4 MB — crt.sh can be large

    $hh = $res['HOME']['headers'] ?? [];
    $security = [
        'reachable' => isset($res['HOME']) && !$res['HOME']['err'] && (int) $res['HOME']['code'] > 0,
        'code'   => (int) ($res['HOME']['code'] ?? 0),
        'hsts'   => isset($hh['strict-transport-security']),
        'csp'    => isset($hh['content-security-policy']),
        'xfo'    => isset($hh['x-frame-options']),
        'xcto'   => isset($hh['x-content-type-options']),
        'refpol' => isset($hh['referrer-policy']),
        'perms'  => isset($hh['permissions-policy']),
        'server' => mb_substr((string) ($hh['server'] ?? ''), 0, 60),
    ];

    $A = scan_doh_answers($res['A'] ?? null, 1);
    $AAAA = scan_doh_answers($res['AAAA'] ?? null, 28);
    $MX = scan_doh_answers($res['MX'] ?? null, 15);
    $NS = scan_doh_answers($res['NS'] ?? null, 2);
    $TXT = array_map(fn($t) => str_replace('"', '', $t), scan_doh_answers($res['TXT'] ?? null, 16));
    $DMARCtxt = array_map(fn($t) => str_replace('"', '', $t), scan_doh_answers($res['DMARC'] ?? null, 16));
    $DS = scan_doh_answers($res['DS'] ?? null, 43);

    $spf = null;
    foreach ($TXT as $t) if (stripos($t, 'v=spf1') === 0) { $spf = $t; break; }
    $dmarc = null;
    foreach ($DMARCtxt as $t) if (stripos($t, 'v=DMARC1') === 0) { $dmarc = $t; break; }
    $dmarcPolicy = ($dmarc && preg_match('/\bp=([a-z]+)/i', $dmarc, $mm)) ? strtolower($mm[1]) : null;

    return [
        'domain' => $domain, 'ts' => time(),
        'a' => $A, 'aaaa' => $AAAA, 'mx' => $MX, 'ns' => $NS, 'txt' => $TXT,
        'spf' => $spf, 'dmarc' => $dmarc, 'dmarc_policy' => $dmarcPolicy,
        'dnssec' => scan_doh_ad($res['A'] ?? null) || !empty($DS),
        'subdomains' => scan_crt_subdomains($res['CRT'] ?? null, $domain),
        'crt_ok' => isset($res['CRT']) && !$res['CRT']['err'] && (int) $res['CRT']['code'] === 200,
        'resolves' => !empty($A) || !empty($AAAA), 'has_mail' => !empty($MX),
        'security' => $security,
        'wayback'  => scan_wayback($res['WB'] ?? null),
    ];
}

/** Wayback Machine CDX response → [ok, count, first, last, urls[]] for a domain. */
function scan_wayback(?array $r): array {
    $empty = ['ok' => false, 'count' => 0, 'first' => '', 'last' => '', 'urls' => []];
    if (!$r || $r['err'] || (int) $r['code'] !== 200) return $empty;
    $j = json_decode($r['body'], true);
    if (!is_array($j) || count($j) < 2) return ['ok' => true, 'count' => 0, 'first' => '', 'last' => '', 'urls' => []];
    array_shift($j);   // header row ["original","timestamp"]
    $urls = []; $ts = [];
    foreach ($j as $row) {
        if (!is_array($row)) continue;
        $u = (string) ($row[0] ?? '');
        $t = (string) ($row[1] ?? '');
        if ($u !== '') $urls[] = $u;
        if (preg_match('/^\d{8}/', $t)) $ts[] = $t;
    }
    sort($ts);
    $fmt = fn($t) => $t ? substr($t, 0, 4) . '-' . substr($t, 4, 2) . '-' . substr($t, 6, 2) : '';
    $urls = array_values(array_unique($urls));
    return [
        'ok'    => true,
        'count' => count($urls),
        'first' => $fmt($ts[0] ?? ''),
        'last'  => $fmt($ts ? $ts[count($ts) - 1] : ''),
        'urls'  => array_slice($urls, 0, 15),
    ];
}

function scan_domain_cache_get(int $uid, string $domain): ?array {
    $db = scan_db(); if (!$db) return null;
    try {
        $st = $db->prepare("SELECT json, updated_at FROM osint_domain_cache WHERE user_id = ? AND domain = ?");
        $st->execute([$uid, $domain]);
        $r = $st->fetch();
        if (!$r) return null;
        $d = json_decode($r['json'], true);
        if (is_array($d)) { $d['ts'] = (int) $r['updated_at']; return $d; }
        return null;
    } catch (\Throwable $e) { return null; }
}
function scan_domain_cache_set(int $uid, string $domain, array $data): void {
    $db = scan_db(); if (!$db) return;
    try {
        $db->prepare("INSERT INTO osint_domain_cache (user_id,domain,json,updated_at) VALUES (?,?,?,?)
                      ON CONFLICT(user_id,domain) DO UPDATE SET json=excluded.json, updated_at=excluded.updated_at")
           ->execute([$uid, $domain, json_encode($data), time()]);
    } catch (\Throwable $e) {}
}

// ---- network / IP self-footprint ----
/** The caller's real public IP, honouring a Cloudflare / proxy front. */
function os_client_ip(): string {
    $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $remote;
    foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $hop) {
        $hop = trim($hop);
        if (filter_var($hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $hop;
    }
    return $remote;
}

/** Geolocation + network + threat-feed reputation for an IP (keyless: ipwho.is + DShield). */
function scan_ip_footprint(string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return ['ip' => $ip, 'error' => 'Not a valid IP.'];
    $out = ['ip' => $ip, 'private' => !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)];
    if ($out['private']) return $out;

    $res = scan_multi_get([
        'geo' => ['url' => 'https://ipwho.is/' . rawurlencode($ip), 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true],
        'ds'  => ['url' => 'https://isc.sans.edu/api/ip/' . rawurlencode($ip) . '?json', 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'timeout' => 10],
    ]);
    $g = json_decode($res['geo']['body'] ?? '', true);
    if (is_array($g) && !empty($g['success'])) {
        $conn = $g['connection'] ?? [];
        $out += [
            'type'    => (string) ($g['type'] ?? ''),
            'city'    => (string) ($g['city'] ?? ''),
            'region'  => (string) ($g['region'] ?? ''),
            'country' => (string) ($g['country'] ?? ''),
            'cc'      => (string) ($g['country_code'] ?? ''),
            'flag'    => (string) ($g['flag']['emoji'] ?? ''),
            'tz'      => (string) ($g['timezone']['id'] ?? ''),
            'asn'     => (string) ($conn['asn'] ?? ''),
            'isp'     => (string) ($conn['isp'] ?? ($conn['org'] ?? '')),
            'org'     => (string) ($conn['org'] ?? ''),
        ];
    }
    $d = json_decode($res['ds']['body'] ?? '', true);
    $dip = is_array($d) ? ($d['ip'] ?? null) : null;
    if (is_array($dip)) {
        $tf = $dip['threatfeeds'] ?? null;
        $out += [
            'ds_ok'      => true,
            'ds_count'   => (int) ($dip['count'] ?? 0),
            'ds_attacks' => (int) ($dip['attacks'] ?? 0),
            'ds_maxdate' => (string) ($dip['maxdate'] ?? ''),
            'ds_feeds'   => is_array($tf) ? array_keys($tf) : [],
        ];
    }
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
    $st = $db->prepare("SELECT id,category,title,url,exposes,avatar,detail,status FROM osint_findings WHERE scan_id = ? AND user_id = ? ORDER BY category, id");
    $st->execute([$scanId, $uid]);
    return $st->fetchAll();
}

/** Recent scans (newest first) for the history/trend view. */
function scan_history(int $uid, int $limit = 12): array {
    $db = scan_db(); if (!$db) return [];
    $st = $db->prepare("SELECT id,started_at,found,unreachable,total,status FROM osint_scans WHERE user_id = ? ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $uid, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Dismiss-keys of the findings from the scan immediately before $beforeId (for the
 *  "new since last scan" diff). Empty when there is no earlier scan. */
function scan_prev_titles(int $uid, int $beforeId): array {
    $db = scan_db(); if (!$db) return [];
    try {
        $st = $db->prepare("SELECT id FROM osint_scans WHERE user_id = ? AND id < ? ORDER BY id DESC LIMIT 1");
        $st->execute([$uid, $beforeId]);
        $prev = $st->fetchColumn();
        if (!$prev) return [];
        $st = $db->prepare("SELECT title FROM osint_findings WHERE scan_id = ? AND user_id = ?");
        $st->execute([(int) $prev, $uid]);
        $set = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) $set[scan_dismiss_key((string) $t)] = true;
        return $set;
    } catch (\Throwable $e) { return []; }
}

const OSINT_STATUSES = ['new', 'attention', 'false', 'done'];

/** Set the triage status on one of the user's own findings, and remember/forget a
 *  persistent "not me" so future scans pre-dismiss the same finding. */
function scan_set_finding_status(int $uid, int $fid, string $status): bool {
    if (!in_array($status, OSINT_STATUSES, true)) return false;
    $db = scan_db(); if (!$db) return false;
    try {
        $st = $db->prepare("SELECT title FROM osint_findings WHERE id = ? AND user_id = ?");
        $st->execute([$fid, $uid]);
        $title = $st->fetchColumn();
        if ($title === false) return false;   // not the caller's finding
        $db->prepare("UPDATE osint_findings SET status = ? WHERE id = ? AND user_id = ?")->execute([$status, $fid, $uid]);
        $key = scan_dismiss_key((string) $title);
        if ($status === 'false') {
            $db->prepare("INSERT OR IGNORE INTO osint_dismissed (user_id,key_hash,title,created_at) VALUES (?,?,?,?)")
               ->execute([$uid, $key, mb_substr((string) $title, 0, 200), time()]);
        } else {
            $db->prepare("DELETE FROM osint_dismissed WHERE user_id = ? AND key_hash = ?")->execute([$uid, $key]);
        }
        return true;
    } catch (\Throwable $e) { return false; }
}

/** Stable key for the persistent "not me" set (same across scans for the same hit). */
function scan_dismiss_key(string $title): string { return sha1(mb_strtolower(trim($title))); }

/** The user's dismissed keys as [key_hash => true]. Cached per request. */
function scan_dismissed_set(int $uid): array {
    static $c = [];
    if (array_key_exists($uid, $c)) return $c[$uid];
    $db = scan_db(); if (!$db) return $c[$uid] = [];
    try {
        $st = $db->prepare("SELECT key_hash FROM osint_dismissed WHERE user_id = ?");
        $st->execute([$uid]);
        $set = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) $set[$k] = true;
        return $c[$uid] = $set;
    } catch (\Throwable $e) { return $c[$uid] = []; }
}

/** Delete all of a user's scans + findings (the "clear results" action). */
function scan_clear(int $uid): void {
    $db = scan_db(); if (!$db) return;
    try {
        $db->prepare("DELETE FROM osint_findings WHERE user_id = ?")->execute([$uid]);
        $db->prepare("DELETE FROM osint_scans WHERE user_id = ?")->execute([$uid]);
    } catch (\Throwable $e) {}
}

// ---- checklists (removal + hardening progress) ----
const OSINT_CHECK_STATUSES = ['todo', 'started', 'done'];

/** The user's saved state for a checklist as [item => status]. */
function scan_checklist_get(int $uid, string $list): array {
    $db = scan_db(); if (!$db) return [];
    try {
        $st = $db->prepare("SELECT item, status FROM osint_checklist WHERE user_id = ? AND list = ?");
        $st->execute([$uid, $list]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[$r['item']] = $r['status'];
        return $out;
    } catch (\Throwable $e) { return []; }
}

/** Set (or clear, when 'todo') one checklist item's status for the user. */
function scan_checklist_set(int $uid, string $list, string $item, string $status): bool {
    if (!in_array($status, OSINT_CHECK_STATUSES, true)) return false;
    $db = scan_db(); if (!$db) return false;
    $list = mb_substr($list, 0, 32); $item = mb_substr($item, 0, 80);
    try {
        if ($status === 'todo') {
            $db->prepare("DELETE FROM osint_checklist WHERE user_id = ? AND list = ? AND item = ?")->execute([$uid, $list, $item]);
        } else {
            $db->prepare("INSERT INTO osint_checklist (user_id,list,item,status,updated_at) VALUES (?,?,?,?,?)
                          ON CONFLICT(user_id,list,item) DO UPDATE SET status=excluded.status, updated_at=excluded.updated_at")
               ->execute([$uid, $list, $item, $status, time()]);
        }
        return true;
    } catch (\Throwable $e) { return false; }
}

// ---- breach monitoring (opt-in; driven by osint/cron.php) ----
/** Monitoring state for a user: [enabled, last_check, pending[]]. */
function scan_monitor_get(int $uid): array {
    $db = scan_db(); if (!$db) return ['enabled' => false, 'last_check' => 0, 'pending' => []];
    try {
        $st = $db->prepare("SELECT enabled, last_check, pending FROM osint_monitor WHERE user_id = ?");
        $st->execute([$uid]);
        $r = $st->fetch();
        return [
            'enabled'    => $r ? (bool) $r['enabled'] : false,
            'last_check' => $r ? (int) $r['last_check'] : 0,
            'pending'    => $r ? (array) json_decode($r['pending'] ?: '[]', true) : [],
        ];
    } catch (\Throwable $e) { return ['enabled' => false, 'last_check' => 0, 'pending' => []]; }
}

function scan_monitor_set_enabled(int $uid, bool $on): void {
    $db = scan_db(); if (!$db) return;
    try {
        $db->prepare("INSERT INTO osint_monitor (user_id,enabled,known,pending) VALUES (?,?,'{}','[]')
                      ON CONFLICT(user_id) DO UPDATE SET enabled = excluded.enabled")->execute([$uid, $on ? 1 : 0]);
    } catch (\Throwable $e) {}
}

function scan_monitor_clear_pending(int $uid): void {
    $db = scan_db(); if (!$db) return;
    try { $db->prepare("UPDATE osint_monitor SET pending = '[]' WHERE user_id = ?")->execute([$uid]); } catch (\Throwable $e) {}
}

/** User ids with monitoring enabled (for the cron sweep). */
function scan_monitor_enabled_users(): array {
    $db = scan_db(); if (!$db) return [];
    try { return array_map('intval', $db->query("SELECT user_id FROM osint_monitor WHERE enabled = 1")->fetchAll(PDO::FETCH_COLUMN)); }
    catch (\Throwable $e) { return []; }
}

/** Re-check one user's emails against XposedOrNot, recording NEW breaches vs the stored
 *  baseline. First sight of an email is baselined silently. Returns the count of new hits. */
function scan_monitor_run(int $uid): int {
    $db = scan_db(); if (!$db) return 0;
    $p = scan_profile_get($uid);
    if (!$p['emails']) {
        try { $db->prepare("UPDATE osint_monitor SET last_check = ? WHERE user_id = ?")->execute([time(), $uid]); } catch (\Throwable $e) {}
        return 0;
    }
    $st = $db->prepare("SELECT known, pending FROM osint_monitor WHERE user_id = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    $known = $row ? (array) json_decode($row['known'] ?: '{}', true) : [];
    $pending = $row ? (array) json_decode($row['pending'] ?: '[]', true) : [];

    $tasks = [];
    foreach ($p['emails'] as $i => $em) $tasks[$i] = ['url' => OSINT_XPOSED . rawurlencode($em), 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true];
    $res = scan_multi_get($tasks);

    $newCount = 0;
    foreach ($p['emails'] as $i => $em) {
        $r = $res[$i] ?? null;
        if (!$r || $r['err'] || (int) $r['code'] !== 200) continue;   // 404 = clean, or error → don't disturb baseline
        $names = [];
        foreach (scan_breach_details($r['body']) as $b) $names[] = $b['name'];
        $prev = $known[$em] ?? null;
        if ($prev === null) { $known[$em] = array_values(array_unique($names)); continue; }   // baseline silently
        foreach (array_values(array_diff($names, $prev)) as $bn) { $pending[] = ['email' => $em, 'breach' => $bn, 'at' => time()]; $newCount++; }
        $known[$em] = array_values(array_unique(array_merge($prev, $names)));
    }
    $pending = array_slice($pending, -50);   // bound
    try {
        $db->prepare("INSERT INTO osint_monitor (user_id,enabled,last_check,known,pending) VALUES (?,1,?,?,?)
                      ON CONFLICT(user_id) DO UPDATE SET last_check = excluded.last_check, known = excluded.known, pending = excluded.pending")
           ->execute([$uid, time(), json_encode($known), json_encode($pending)]);
    } catch (\Throwable $e) {}
    return $newCount;
}

/** The shared secret for the monitoring cron endpoint. Created (gitignored) on first use. */
function scan_cron_token(): string {
    $path = OSINT_DATA_DIR . '/cron.key';
    if (is_file($path)) { $t = trim((string) @file_get_contents($path)); if ($t !== '') return $t; }
    $t = bin2hex(random_bytes(16));
    @file_put_contents($path, $t);
    return $t;
}

/** Headline exposure index (0 = nothing found, 100 = heavy) from a scan's findings. */
function scan_exposure(array $findings): array {
    $accounts = 0; $identity = 0; $breaches = 0; $pwExposed = false; $years = []; $classes = [];
    foreach ($findings as $f) {
        if (($f['status'] ?? 'new') === 'false') continue;   // "not me" doesn't count
        $cat = $f['category'] ?? '';
        if ($cat === 'breach') {
            $breaches++;
            $detail = (string) ($f['detail'] ?? '');
            if (stripos($detail, 'password') !== false) $pwExposed = true;
            if (preg_match('/\b(19|20)\d\d\b/', $detail, $m)) $years[] = (int) $m[0];
            // Aggregate the exposed data classes ("Passwords, Physical addresses, ...").
            $rest = preg_replace('/^\s*(19|20)\d\d\s*·?\s*/', '', $detail);
            foreach (explode(',', $rest) as $c) {
                $c = trim($c);
                if ($c !== '' && !preg_match('/^(19|20)\d\d$/', $c)) {
                    $k = mb_strtolower($c);
                    if (!isset($classes[$k])) $classes[$k] = $c;
                }
            }
        } elseif ($cat === 'account') {
            if (strpos((string) ($f['exposes'] ?? ''), 'email') !== false) $identity++; else $accounts++;
        }
    }
    $score = (int) min(100, min(30, $accounts * 4) + min(15, $identity * 5) + min(35, $breaches * 3) + ($pwExposed ? 20 : 0));
    return [
        'score'       => $score,
        'level'       => $score >= 61 ? 'high' : ($score >= 26 ? 'mid' : 'low'),
        'accounts'    => $accounts, 'identity' => $identity, 'breaches' => $breaches, 'pw' => $pwExposed,
        'span'        => $years ? (min($years) === max($years) ? (string) min($years) : min($years) . '–' . max($years)) : '',
        'dataclasses' => array_values($classes),
    ];
}
