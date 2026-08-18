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
const OSINT_CRAWLER_UA    = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';   // gets og: tags from anti-bot sites (IG/Telegram)
const OSINT_XPOSED        = 'https://api.xposedornot.com/v1/breach-analytics?email=';
const OSINT_GRAVATAR      = 'https://www.gravatar.com/';
const OSINT_BREACH_CAP    = 60;    // most-recent breaches kept per email
const OSINT_MONITOR_INTERVAL = 43200;   // 12h — min gap between automatic monitor re-checks

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
        // The threat model the user is defending against — re-prioritizes everything (the "lens").
        try { $db->exec("ALTER TABLE osint_profile ADD COLUMN threat TEXT NOT NULL DEFAULT 'general'"); } catch (\Throwable $e) {}
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
        // Certificate-transparency monitoring: a second opt-in that watches the user's
        // domains for newly-issued certs (early warning for phishing infra / takeover).
        try { $db->exec("ALTER TABLE osint_monitor ADD COLUMN ct_enabled INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE osint_monitor ADD COLUMN ct_pending TEXT NOT NULL DEFAULT '[]'"); } catch (\Throwable $e) {}
        // Per-domain CT baseline (the highest Cert Spotter issuance id seen so far).
        $db->exec("CREATE TABLE IF NOT EXISTS osint_ct_state (
            user_id INTEGER NOT NULL, domain TEXT NOT NULL, last_id TEXT NOT NULL DEFAULT '',
            updated_at INTEGER NOT NULL, PRIMARY KEY (user_id, domain))");
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
    $c = ['breach', 'gravatar', 'duolingo', 'ghemail', 'leakcheck'];
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
        } elseif ($t['kind'] === 'ghemail') { // GitHub user whose PUBLIC email matches (no email sent)
            $tasks[$i] = ['url' => 'https://api.github.com/search/users?q=' . rawurlencode($t['email']) . '+in:email', 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/vnd.github+json'], 'follow' => true];
        } elseif ($t['kind'] === 'leakcheck') { // second breach corpus (names/dates/fields), no email sent
            $tasks[$i] = ['url' => 'https://leakcheck.io/api/public?check=' . rawurlencode($t['email']), 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true];
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
                $m2 = scan_extract_meta($r['body'], $t['site']['name']);   // display name + bio, to eyeball if it's really you
                $emit('account', $t['user'] . ' on ' . $t['site']['name'], $tasks[$i]['url'], 'account',
                      scan_extract_image($r['body'], $tasks[$i]['url']),
                      trim($m2['title'] . ($m2['desc'] ? ' · ' . $m2['desc'] : ''), ' ·'));
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
            $pj = json_decode($r['body'], true);   // pastes come in the same response we already fetched
            $pc = is_array($pj) ? (int) ($pj['PastesSummary']['cnt'] ?? 0) : 0;
            if ($pc > 0) $emit('breach', $t['email'] . ' — in ' . $pc . ' public paste(s)', 'https://xposedornot.com/', 'email,breach', '', 'This address appeared in ' . $pc . ' public paste(s) (Pastebin and similar).');
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
        } elseif ($t['kind'] === 'ghemail') {
            if (!$r || $r['err'] || $r['code'] !== 200) { $unreachInc++; continue; }
            $j = json_decode($r['body'], true);
            $items = is_array($j) ? ($j['items'] ?? []) : [];
            if (!$items) continue;   // no GitHub account with this email public
            foreach (array_slice($items, 0, 2) as $it) {
                $login = (string) ($it['login'] ?? '');
                if ($login === '') continue;
                $emit('account', $t['email'] . ' — GitHub @' . $login, (string) ($it['html_url'] ?? 'https://github.com/' . $login),
                      'email,account', (string) ($it['avatar_url'] ?? ''), 'A GitHub user has this email public on their commits or profile.');
            }
        } elseif ($t['kind'] === 'leakcheck') {
            if (!$r || $r['err'] || $r['code'] !== 200) { $unreachInc++; continue; }
            $j = json_decode($r['body'], true);
            if (!is_array($j) || empty($j['success']) || (int) ($j['found'] ?? 0) === 0) continue;   // clean
            $src = [];
            foreach (($j['sources'] ?? []) as $s) { $n = (string) ($s['name'] ?? ''); if ($n !== '') $src[] = $n . (!empty($s['date']) ? ' (' . substr((string) $s['date'], 0, 4) . ')' : ''); }
            $fields = array_slice(array_map(fn($x) => ucfirst(str_replace('_', ' ', (string) $x)), (array) ($j['fields'] ?? [])), 0, 12);
            $detail = trim(($src ? implode(', ', array_slice($src, 0, 12)) : ((int) $j['found'] . ' records')) . ($fields ? ' · exposes ' . implode(', ', $fields) : ''), ' ·');
            $emit('breach', $t['email'] . ' — ' . (int) $j['found'] . ' record(s) found (LeakCheck)', 'https://leakcheck.io/', 'email,breach', '', mb_substr($detail, 0, 250));
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

/** Display name (og:title) + bio (og:description) from a profile page, for triage. */
function scan_extract_meta(string $html, string $siteName): array {
    $head = substr($html, 0, 24000);
    $get = function ($props) use ($head) {
        foreach ((array) $props as $p) {
            $q = preg_quote($p, '#');
            if (preg_match('#<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $m)
             || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $q . '["\']#i', $head, $m)) return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }
        return '';
    };
    $title = $get(['og:title', 'twitter:title']);
    $desc  = $get(['og:description', 'twitter:description', 'description']);
    $title = preg_replace('/\s*[-|•·—:]\s*' . preg_quote($siteName, '/') . '\b.*$/iu', '', $title);   // drop trailing site name
    if (mb_strtolower(trim($title)) === mb_strtolower($siteName)) $title = '';
    return ['title' => mb_substr($title, 0, 80), 'desc' => mb_substr($desc, 0, 160)];
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
        'CAA' => $doh($domain, 'CAA'), 'MTASTS' => $doh('_mta-sts.' . $domain, 'TXT'), 'BIMI' => $doh('default._bimi.' . $domain, 'TXT'),
        'RDAP' => ['url' => 'https://rdap.org/domain/' . rawurlencode($domain), 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/rdap+json'], 'follow' => true, 'timeout' => 12],
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

    // Registration (RDAP), live TLS cert, and the deeper email-security records.
    $caa = [];
    foreach (scan_doh_answers($res['CAA'] ?? null, 257) as $c) if (preg_match('/\bissue(?:wild)?\s+"?([^"\s]+)"?/i', $c, $mm)) $caa[] = trim($mm[1]);
    $caa = array_values(array_unique($caa));
    $mtaSts = false;
    foreach (scan_doh_answers($res['MTASTS'] ?? null, 16) as $t) if (stripos(str_replace('"', '', $t), 'v=STSv1') !== false) $mtaSts = true;
    $bimi = false;
    foreach (scan_doh_answers($res['BIMI'] ?? null, 16) as $t) if (stripos(str_replace('"', '', $t), 'v=BIMI1') !== false) $bimi = true;
    $rdap = scan_rdap($res['RDAP'] ?? null);
    $tls = scan_tls_cert($domain);
    $nowT = time();
    $domExp = ($rdap['ok'] && $rdap['expires']) ? (int) floor((strtotime($rdap['expires'] . ' 00:00:00 UTC') - $nowT) / 86400) : null;
    $certExp = ($tls && $tls['valid_to']) ? (int) floor(($tls['valid_to'] - $nowT) / 86400) : null;

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
        'rdap' => $rdap, 'tls' => $tls,
        'caa' => $caa, 'mta_sts' => $mtaSts, 'bimi' => $bimi,
        'domain_expiry_days' => $domExp, 'cert_expiry_days' => $certExp,
    ];
}

/** Pull the 'fn' (display name) out of an RDAP vcardArray. */
function scan_vcard_fn($vcardArray): string {
    if (!is_array($vcardArray) || count($vcardArray) < 2 || !is_array($vcardArray[1])) return '';
    foreach ($vcardArray[1] as $field) if (is_array($field) && ($field[0] ?? '') === 'fn') return (string) ($field[3] ?? '');
    return '';
}

/** RDAP (registration data) response → registrar, dates, status, nameservers, DNSSEC. */
function scan_rdap(?array $r): array {
    $empty = ['ok' => false, 'registrar' => '', 'created' => '', 'expires' => '', 'updated' => '', 'statuses' => [], 'nameservers' => [], 'dnssec' => null];
    if (!$r || $r['err'] || (int) $r['code'] !== 200) return $empty;
    $j = json_decode($r['body'], true);
    if (!is_array($j)) return $empty;
    $created = $expires = $updated = '';
    foreach (($j['events'] ?? []) as $e) {
        $a = strtolower((string) ($e['eventAction'] ?? '')); $d = substr((string) ($e['eventDate'] ?? ''), 0, 10);
        if ($a === 'registration') $created = $d;
        elseif ($a === 'expiration') $expires = $d;
        elseif ($a === 'last changed') $updated = $d;
    }
    $registrar = '';
    foreach (($j['entities'] ?? []) as $ent) {
        if (in_array('registrar', (array) ($ent['roles'] ?? []), true)) { $registrar = scan_vcard_fn($ent['vcardArray'] ?? null) ?: (string) ($ent['handle'] ?? ''); break; }
    }
    $ns = [];
    foreach (($j['nameservers'] ?? []) as $n) { $h = strtolower((string) ($n['ldhName'] ?? '')); if ($h) $ns[] = $h; }
    $statuses = array_values(array_filter(array_map('strval', (array) ($j['status'] ?? []))));
    return [
        'ok' => true, 'registrar' => mb_substr($registrar, 0, 80),
        'created' => $created, 'expires' => $expires, 'updated' => $updated,
        'statuses' => array_slice($statuses, 0, 8), 'nameservers' => array_slice($ns, 0, 8),
        'dnssec' => isset($j['secureDNS']['delegationSigned']) ? (bool) $j['secureDNS']['delegationSigned'] : null,
    ];
}

/** Inspect the live TLS certificate for a domain (issuer, validity, SANs). Null if unreachable. */
function scan_tls_cert(string $domain): ?array {
    if (!function_exists('stream_socket_client') || !function_exists('openssl_x509_parse')) return null;
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $domain]]);
    $errno = 0; $errstr = '';
    $c = @stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$c) return null;
    $params = stream_context_get_params($c);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    @fclose($c);
    if (!$cert) return null;
    $info = openssl_x509_parse($cert);
    if (!is_array($info)) return null;
    $sans = [];
    foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $s) {
        $s = trim($s);
        if (stripos($s, 'DNS:') === 0) $sans[] = substr($s, 4);
    }
    return [
        'issuer'     => (string) ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? ''),
        'subject'    => (string) ($info['subject']['CN'] ?? ''),
        'valid_from' => (int) ($info['validFrom_time_t'] ?? 0),
        'valid_to'   => (int) ($info['validTo_time_t'] ?? 0),
        'sans'       => array_slice(array_values(array_unique($sans)), 0, 50),
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

/** Near-expiry (or expired) domain registrations + TLS certs from the user's cached
 *  domain footprints. [ ['domain','kind','days'], ... ]. */
function scan_expiry_warnings(int $uid): array {
    $p = scan_profile_get($uid);
    $out = [];
    foreach ($p['domains'] as $dom) {
        $c = scan_domain_cache_get($uid, $dom);
        if (!$c) continue;
        $de = $c['domain_expiry_days'] ?? null;
        if ($de !== null && $de < 30) $out[] = ['domain' => $dom, 'kind' => 'registration', 'days' => (int) $de];
        $ce = $c['cert_expiry_days'] ?? null;
        if ($ce !== null && $ce < 21) $out[] = ['domain' => $dom, 'kind' => 'TLS certificate', 'days' => (int) $ce];
    }
    return $out;
}

// ---- look-alike / typosquat domains (dnstwist-style; keyless DoH resolution) ----
/** QWERTY neighbour map — for typo (fat-finger) variant generation. */
function scan_twist_kb(): array {
    return ['q'=>'wa','w'=>'qeas','e'=>'wrsd','r'=>'etdf','t'=>'ryfg','y'=>'tugh','u'=>'yihj','i'=>'uojk','o'=>'ipkl','p'=>'ol',
            'a'=>'qwsz','s'=>'wedxza','d'=>'erfcxs','f'=>'rtgvcd','g'=>'tyhbvf','h'=>'yujnbg','j'=>'uikmnh','k'=>'iolmj','l'=>'opk',
            'z'=>'asx','x'=>'zsdc','c'=>'xdfv','v'=>'cfgb','b'=>'vghn','n'=>'bhjm','m'=>'njk'];
}

/** Generate look-alike variants of a domain → [domain => algorithm], deduped, capped,
 *  and prioritised so the highest-signal families survive the cap. Mutates only the
 *  first label; swaps the whole TLD for the tld-swap family. */
function scan_twist_generate(string $domain, int $cap = 120): array {
    $parts = explode('.', $domain);
    $sld = array_shift($parts);
    $tld = implode('.', $parts);
    if ($sld === '' || $tld === '' || strlen($sld) < 2) return [];
    $buckets = [];
    $seen = [$domain => true];
    $add = function (string $d, string $type) use (&$buckets, &$seen) {
        $d = strtolower($d);
        if (isset($seen[$d]) || strlen($d) > 253) return;
        if (!filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) return;
        $seen[$d] = true;
        $buckets[$type][] = $d;
    };
    $chars = str_split($sld);
    $n = count($chars);
    $kb = scan_twist_kb();
    $homo = ['a'=>['4'],'b'=>['d'],'c'=>['e'],'d'=>['cl','b'],'e'=>['3'],'g'=>['9','q'],'h'=>['n'],'i'=>['1','l','j'],'j'=>['i'],
             'l'=>['1','i'],'m'=>['rn','nn'],'n'=>['m','r'],'o'=>['0'],'q'=>['g'],'r'=>['n'],'s'=>['5','z'],'t'=>['7'],
             'u'=>['v'],'v'=>['u'],'w'=>['vv'],'y'=>['j'],'z'=>['s','2'],'0'=>['o'],'1'=>['l','i'],'5'=>['s']];
    $vowels = ['a','e','i','o','u'];
    for ($i = 0; $i < $n; $i++) {
        $pre = substr($sld, 0, $i); $post = substr($sld, $i + 1); $ch = $chars[$i];
        $add($pre . $post . '.' . $tld, 'omission');                       // drop a char
        $add($pre . $ch . $ch . $post . '.' . $tld, 'repetition');         // double a char
        if ($i < $n - 1) { $sw = $chars; [$sw[$i], $sw[$i+1]] = [$sw[$i+1], $sw[$i]]; $add(implode('', $sw) . '.' . $tld, 'transposition'); }
        foreach (str_split($kb[$ch] ?? '') as $a) {
            $add($pre . $a . $post . '.' . $tld, 'replacement');           // fat-finger swap
            $add($pre . $ch . $a . $post . '.' . $tld, 'insertion');       // fat-finger insert
        }
        foreach (($homo[$ch] ?? []) as $gl) $add($pre . $gl . $post . '.' . $tld, 'homoglyph');
        if (in_array($ch, $vowels, true)) foreach ($vowels as $v) if ($v !== $ch) $add($pre . $v . $post . '.' . $tld, 'vowel-swap');
        if ($i > 0) $add($pre . '-' . $ch . $post . '.' . $tld, 'hyphenation');
        $o = ord($ch);
        for ($b = 0; $b < 7; $b++) { $x = chr($o ^ (1 << $b)); if (ctype_alnum($x)) $add($pre . strtolower($x) . $post . '.' . $tld, 'bitsquat'); }
    }
    foreach (['com','net','org','co','io','info','app','xyz','online','site','shop','biz','us','dev','me','cc','live','pro','store','link'] as $a)
        if ($a !== $tld) $add($sld . '.' . $a, 'tld-swap');
    foreach (['login','secure','account','verify','support','mail','my','app'] as $w) {
        $add($sld . '-' . $w . '.' . $tld, 'addition');
        $add($w . '-' . $sld . '.' . $tld, 'addition');
    }
    // Flatten in priority order (drop lowest-signal families first when over the cap).
    $order = ['homoglyph','omission','transposition','replacement','tld-swap','repetition','insertion','vowel-swap','hyphenation','addition','bitsquat'];
    $out = [];
    foreach ($order as $type) foreach (($buckets[$type] ?? []) as $d) { $out[$d] = $type; if (count($out) >= $cap) return $out; }
    return $out;
}

/** Generate + resolve look-alike domains, returning only the ones that are actually
 *  registered (resolve to an IP), enriched with MX presence and same-host detection.
 *  All keyless: Google DoH. Heavier than a normal lookup, so it's its own endpoint. */
function scan_domain_twist(string $domainRaw): array {
    $domain = scan_domain_normalize($domainRaw);
    if ($domain === null) return ['error' => 'Not a valid domain.'];
    $variants = scan_twist_generate($domain);
    $doh = fn($name, $type) => ['url' => 'https://dns.google/resolve?name=' . rawurlencode($name) . '&type=' . $type,
                                'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true, 'timeout' => 6];
    if (!$variants) return ['ok' => true, 'domain' => $domain, 'generated' => 0, 'registered' => 0, 'hits' => [], 'ts' => time()];
    $legitA = scan_doh_answers(scan_multi_get(['a' => $doh($domain, 'A')])['a'] ?? null, 1);

    $aRec = [];
    foreach (array_chunk(array_keys($variants), 40) as $chunk) {
        $tasks = [];
        foreach ($chunk as $d) $tasks[$d] = $doh($d, 'A');
        $res = scan_multi_get($tasks);
        foreach ($chunk as $d) { $a = scan_doh_answers($res[$d] ?? null, 1); if ($a) $aRec[$d] = $a; }
    }
    $mx = [];
    foreach (array_chunk(array_keys($aRec), 40) as $chunk) {
        if (!$chunk) break;
        $tasks = [];
        foreach ($chunk as $d) $tasks[$d] = $doh($d, 'MX');
        $res = scan_multi_get($tasks);
        foreach ($chunk as $d) if (scan_doh_answers($res[$d] ?? null, 15)) $mx[$d] = true;
    }
    $hits = [];
    foreach ($aRec as $d => $a) {
        $hits[] = ['domain' => $d, 'type' => $variants[$d], 'a' => array_slice($a, 0, 3),
                   'mx' => isset($mx[$d]), 'same_ip' => (bool) array_intersect($a, $legitA)];
    }
    // Most dangerous first: receives mail (phishing-capable) + hosted somewhere other than you.
    $rank = fn($h) => ($h['mx'] ? 0 : 2) + ($h['same_ip'] ? 1 : 0);
    usort($hits, fn($x, $y) => [$rank($x), $x['domain']] <=> [$rank($y), $y['domain']]);
    return ['ok' => true, 'domain' => $domain, 'generated' => count($variants), 'registered' => count($hits), 'hits' => $hits, 'ts' => time()];
}

// ---- active subdomain enumeration (theHarvester / Amass-style; keyless) ----
/** Common subdomain labels to brute-force via DNS (infra + dev + mail + admin surface). */
function scan_subdomain_wordlist(): array {
    return ['www','mail','smtp','imap','pop','webmail','ns1','ns2','ns','dns','api','api2','dev','staging','stage','test','uat','qa',
            'admin','portal','vpn','remote','gateway','gw','cpanel','whm','autodiscover','autoconfig','mx','mx1','mx2','blog',
            'shop','store','app','apps','m','mobile','cdn','static','assets','img','images','media','files','ftp','sftp','git',
            'gitlab','jenkins','ci','jira','confluence','wiki','docs','support','help','status','monitor','grafana','kibana',
            'dashboard','internal','intranet','corp','ad','dc','ldap','proxy','db','sql','secure','login','sso','auth','id',
            'account','my','beta','demo','sandbox','preprod','prod','backup','old','new','email','cloud','host','server','vps',
            'connect','edge','v1','v2','origin','direct','go','get','share','download','upload','data','stats','analytics'];
}

/** Enumerate a domain's subdomains from certificate transparency + live cert SANs +
 *  a DNS brute of common labels, resolving each to separate the LIVE attack surface
 *  from historical-only names. Detects wildcard DNS so brute hits stay honest. Keyless. */
function scan_subdomain_enum(string $domainRaw): array {
    $domain = scan_domain_normalize($domainRaw);
    if ($domain === null) return ['error' => 'Not a valid domain.'];
    $doh = fn($name, $type) => ['url' => 'https://dns.google/resolve?name=' . rawurlencode($name) . '&type=' . $type,
                                'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true, 'timeout' => 6];
    $suffix = '.' . $domain;

    // Passive: certificate transparency (crt.sh) + the live leaf certificate's SANs.
    $crtRes = scan_multi_get(['c' => ['url' => 'https://crt.sh/?q=' . rawurlencode('%.' . $domain) . '&output=json',
                                      'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'timeout' => 12, 'contimeout' => 5]], 4194304);
    $src = [];   // name => [source => true]
    foreach (scan_crt_subdomains($crtRes['c'] ?? null, $domain) as $n) $src[$n]['ct'] = true;
    $tls = scan_tls_cert($domain);
    if ($tls) foreach ($tls['sans'] as $s) {
        $s = ltrim(strtolower(trim($s)), '*.');
        if ($s !== $domain && substr($s, -strlen($suffix)) === $suffix && !preg_match('/[^a-z0-9.\-]/', $s)) $src[$s]['san'] = true;
    }
    // Active: brute-force common labels.
    foreach (scan_subdomain_wordlist() as $w) $src[$w . $suffix]['brute'] = true;

    // Wildcard DNS detection — a random label that resolves means brute results are unreliable.
    $wildIps = scan_doh_answers(scan_multi_get(['w' => $doh('zz' . bin2hex(random_bytes(5)) . 'zz' . $suffix, 'A')])['w'] ?? null, 1);
    $wildcard = (bool) $wildIps;

    // Resolve every candidate (capped for a bounded request).
    $names = array_slice(array_keys($src), 0, 240);
    $pubIp = function ($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        foreach (['192.0.2.', '198.51.100.', '203.0.113.'] as $doc) if (strpos($ip, $doc) === 0) return false;   // RFC 5737 black-holes
        return true;
    };
    $live = [];
    foreach (array_chunk($names, 40) as $chunk) {
        $tasks = [];
        foreach ($chunk as $n) $tasks[$n] = $doh($n, 'A');
        $res = scan_multi_get($tasks);
        // Only publicly-routable answers count as live — drop TEST-NET/private black-holes
        // (e.g. GitHub points non-existent names at 192.0.2.x).
        foreach ($chunk as $n) { $a = array_values(array_filter(scan_doh_answers($res[$n] ?? null, 1), $pubIp)); if ($a) $live[$n] = array_slice($a, 0, 2); }
    }

    $rows = [];
    foreach ($names as $n) {
        $s = $src[$n];
        $passive = isset($s['ct']) || isset($s['san']);
        $resolves = isset($live[$n]);
        // A brute-only name that merely matches the wildcard catch-all isn't a real host.
        if ($resolves && !$passive && $wildcard && !array_diff($live[$n], $wildIps)) $resolves = false;
        if (!$resolves && !$passive) continue;   // drop brute misses; keep passive-known (historical)
        $tags = [];
        if (isset($s['ct']))    $tags[] = 'ct';
        if (isset($s['san']))   $tags[] = 'cert';
        if (isset($s['brute'])) $tags[] = 'brute';
        $rows[] = ['name' => $n, 'resolves' => $resolves, 'a' => $resolves ? $live[$n] : [], 'src' => $tags];
    }
    usort($rows, fn($x, $y) => [$x['resolves'] ? 0 : 1, $x['name']] <=> [$y['resolves'] ? 0 : 1, $y['name']]);
    return [
        'ok' => true, 'domain' => $domain, 'total' => count($rows),
        'live' => count(array_filter($rows, fn($r) => $r['resolves'])),
        'wildcard' => $wildcard,
        'crt_ok' => isset($crtRes['c']) && !$crtRes['c']['err'] && (int) $crtRes['c']['code'] === 200,
        'rows' => $rows, 'ts' => time(),
    ];
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

// ---- exposed services / attack surface (Shodan InternetDB; keyless) ----
/** Open ports, known CVEs, service CPEs, hostnames, and tags for a public IP, from
 *  Shodan's free InternetDB. null = unreachable; found=false = IP has no exposed data
 *  (nothing internet-facing seen — the good state). */
function scan_internetdb(string $ip): ?array {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    $r = scan_multi_get(['x' => ['url' => 'https://internetdb.shodan.io/' . rawurlencode($ip), 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/json'], 'follow' => true, 'timeout' => 10]])['x'] ?? null;
    if (!$r || $r['err']) return null;
    if ((int) $r['code'] === 404) return ['ip' => $ip, 'found' => false, 'ports' => [], 'vulns' => [], 'cpes' => [], 'hostnames' => [], 'tags' => []];
    if ((int) $r['code'] !== 200) return null;
    $j = json_decode($r['body'], true);
    if (!is_array($j)) return null;
    $vulns = array_values(array_filter((array) ($j['vulns'] ?? []), fn($v) => is_string($v) && preg_match('/^CVE-/i', $v)));
    return [
        'ip' => $ip, 'found' => true,
        'ports'     => array_slice(array_values(array_unique(array_map('intval', (array) ($j['ports'] ?? [])))), 0, 40),
        'vulns'     => array_slice($vulns, 0, 30),
        'cpes'      => array_slice(array_map('strval', (array) ($j['cpes'] ?? [])), 0, 20),
        'hostnames' => array_slice(array_map('strval', (array) ($j['hostnames'] ?? [])), 0, 10),
        'tags'      => array_slice(array_map('strval', (array) ($j['tags'] ?? [])), 0, 15),
    ];
}

/** Well-known port → service label, for readable output. */
function scan_port_label(int $p): string {
    static $m = [21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3',
        111 => 'RPC', 135 => 'MSRPC', 139 => 'NetBIOS', 143 => 'IMAP', 161 => 'SNMP', 389 => 'LDAP', 443 => 'HTTPS',
        445 => 'SMB', 465 => 'SMTPS', 587 => 'SMTP', 993 => 'IMAPS', 995 => 'POP3S', 1433 => 'MSSQL', 1521 => 'Oracle',
        2049 => 'NFS', 2082 => 'cPanel', 2083 => 'cPanel', 3306 => 'MySQL', 3389 => 'RDP', 5432 => 'PostgreSQL',
        5900 => 'VNC', 5985 => 'WinRM', 6379 => 'Redis', 8080 => 'HTTP-alt', 8443 => 'HTTPS-alt', 9200 => 'Elasticsearch',
        11211 => 'Memcached', 27017 => 'MongoDB'];
    return $m[$p] ?? '';
}

/** Map a domain's whole internet-facing attack surface: resolve the apex + any cached
 *  live subdomains to IPs, then pull open ports / CVEs for each host from InternetDB.
 *  Own-domain scoped (validated by the caller). */
function scan_attack_surface(int $uid, string $domainRaw): array {
    $domain = scan_domain_normalize($domainRaw);
    if ($domain === null) return ['error' => 'Not a valid domain.'];
    $doh = fn($n, $t) => ['url' => 'https://dns.google/resolve?name=' . rawurlencode($n) . '&type=' . $t, 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true, 'timeout' => 8];
    $res = scan_multi_get(['a' => $doh($domain, 'A'), 'aaaa' => $doh($domain, 'AAAA')]);
    $ipmap = [];   // ip => [hostname => true]
    foreach (scan_doh_answers($res['a'] ?? null, 1) as $ip) $ipmap[$ip][$domain] = true;
    foreach (scan_doh_answers($res['aaaa'] ?? null, 28) as $ip) $ipmap[$ip][$domain] = true;
    // Fold in the live subdomains discovered by a prior enumeration, if cached.
    $subs = scan_domain_cache_get($uid, 'subs:' . $domain);
    if ($subs && !empty($subs['rows'])) {
        foreach ($subs['rows'] as $row) if (!empty($row['resolves'])) foreach ((array) ($row['a'] ?? []) as $ip) $ipmap[$ip][$row['name']] = true;
    }
    $ips = array_slice(array_keys($ipmap), 0, 15);
    $hosts = []; $totVulns = 0; $portSet = [];
    foreach ($ips as $ip) {
        $idb = scan_internetdb($ip);
        $names = array_slice(array_keys($ipmap[$ip]), 0, 4);
        if ($idb === null) { $hosts[] = ['ip' => $ip, 'names' => $names, 'unreachable' => true, 'ports' => [], 'vulns' => [], 'tags' => []]; continue; }
        $totVulns += count($idb['vulns']);
        foreach ($idb['ports'] as $p) $portSet[$p] = true;
        $hosts[] = ['ip' => $ip, 'names' => $names, 'found' => $idb['found'], 'ports' => $idb['ports'], 'vulns' => $idb['vulns'], 'tags' => $idb['tags'], 'cpes' => $idb['cpes']];
    }
    usort($hosts, fn($a, $b) => [count($b['vulns']), count($b['ports'])] <=> [count($a['vulns']), count($a['ports'])]);
    return ['ok' => true, 'domain' => $domain, 'hosts' => $hosts, 'ip_count' => count($ips),
            'total_vulns' => $totVulns, 'total_ports' => count($portSet), 'ts' => time()];
}

// ---- investigation lookups (arbitrary URL / IP / domain / cert — public infra data) ----
/** Resolve a (possibly relative) Location against a base URL. */
function scan_abs_url(string $loc, string $base): string {
    $loc = trim($loc);
    if ($loc === '') return '';
    if (preg_match('#^https?://#i', $loc)) return $loc;
    $p = parse_url($base);
    if (!$p || empty($p['host'])) return '';
    $scheme = $p['scheme'] ?? 'http';
    $origin = $scheme . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($loc, '//') === 0) return $scheme . ':' . $loc;
    if ($loc[0] === '/') return $origin . $loc;
    $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    return $origin . ($dir ?: '/') . $loc;
}

/** Phishing/obfuscation red flags for a URL + its redirect chain. [ [level,text], ... ]. */
function scan_url_flags(string $url, array $chain): array {
    $p = parse_url($url); $host = strtolower((string) ($p['host'] ?? '')); $flags = [];
    if (($p['scheme'] ?? '') !== 'https') $flags[] = ['bad', 'Not served over HTTPS'];
    if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP)) $flags[] = ['bad', 'Host is a raw IP address, not a domain'];
    if (strpos($host, 'xn--') !== false) $flags[] = ['warn', 'Punycode/IDN host — may visually mimic a real brand'];
    if (!empty($p['user'])) $flags[] = ['bad', 'Credentials embedded in the URL (user@…)'];
    $subs = substr_count($host, '.');
    if ($subs >= 4) $flags[] = ['warn', 'Many subdomains (' . $subs . ') — a common cloaking trick'];
    $tld = strtolower((string) substr((string) strrchr($host, '.'), 1));
    if (in_array($tld, ['zip', 'mov', 'tk', 'ml', 'ga', 'cf', 'gq', 'top', 'xyz', 'click', 'link', 'work', 'loan', 'rest'], true)) $flags[] = ['warn', 'Higher-abuse TLD .' . $tld];
    if (count($chain) > 2) $flags[] = ['warn', (count($chain) - 1) . ' redirects — a shortener or cloaked link'];
    if (mb_strlen($url) > 120) $flags[] = ['warn', 'Unusually long URL'];
    return $flags;
}

/** Follow a URL's redirect chain (hop by hop) and flag it. */
function scan_url_trace(string $input, int $maxHops = 10): array {
    $input = trim($input);
    if (!preg_match('#^https?://#i', $input)) $input = 'http://' . $input;
    if (!filter_var($input, FILTER_VALIDATE_URL)) return ['error' => 'Not a valid URL.'];
    $chain = []; $url = $input; $hops = 0;
    while ($hops++ < $maxHops) {
        $r = scan_multi_get(['h' => ['url' => $url, 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => false, 'wanthdr' => true, 'timeout' => 10]])['h'] ?? null;
        if (!$r || $r['err']) { $chain[] = ['url' => $url, 'code' => 0]; break; }
        $code = (int) $r['code'];
        $chain[] = ['url' => $url, 'code' => $code];
        $loc = $r['headers']['location'] ?? '';
        if ($code >= 300 && $code < 400 && $loc !== '') { $next = scan_abs_url($loc, $url); if ($next === '' || $next === $url) break; $url = $next; }
        else break;
    }
    $final = $chain ? end($chain)['url'] : $input;
    return ['ok' => true, 'chain' => $chain, 'final' => $final, 'flags' => scan_url_flags($final, $chain)];
}

/** All common DNS records for a domain via DoH. */
function scan_dns_all(string $domain): array {
    $domain = scan_domain_normalize($domain);
    if ($domain === null) return ['error' => 'Not a valid domain.'];
    $types = ['A' => 1, 'AAAA' => 28, 'MX' => 15, 'NS' => 2, 'TXT' => 16, 'CAA' => 257, 'SOA' => 6, 'SRV' => 33];
    $tasks = [];
    foreach ($types as $t => $n) $tasks[$t] = ['url' => 'https://dns.google/resolve?name=' . rawurlencode($domain) . '&type=' . $t, 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true];
    $res = scan_multi_get($tasks);
    $out = ['ok' => true, 'domain' => $domain, 'records' => []];
    foreach ($types as $t => $n) {
        $vals = scan_doh_answers($res[$t] ?? null, $n);
        if ($vals) $out['records'][$t] = array_slice(array_map(fn($v) => str_replace('"', '', $v), $vals), 0, 20);
    }
    return $out;
}

/** Reverse-DNS (PTR) for an IPv4 address via DoH. */
function scan_ptr(string $ip): string {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return '';
    $rev = implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
    $r = scan_multi_get(['p' => ['url' => 'https://dns.google/resolve?name=' . $rev . '&type=PTR', 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true]])['p'] ?? null;
    $a = scan_doh_answers($r, 12);
    return $a ? rtrim($a[0], '.') : '';
}

/** Parse a pasted PEM certificate. */
function scan_cert_pem(string $pem): array {
    $pem = trim($pem);
    if (strpos($pem, 'BEGIN CERTIFICATE') === false) return ['error' => 'Paste a PEM certificate (-----BEGIN CERTIFICATE-----).'];
    if (!function_exists('openssl_x509_parse')) return ['error' => 'Certificate parsing is unavailable.'];
    $info = @openssl_x509_parse($pem);
    if (!is_array($info)) return ['error' => 'Could not parse that certificate.'];
    $sans = [];
    foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $s) { $s = trim($s); if (stripos($s, 'DNS:') === 0) $sans[] = substr($s, 4); }
    return [
        'ok' => true,
        'subject' => (string) ($info['subject']['CN'] ?? ''),
        'issuer'  => (string) ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? ''),
        'valid_from' => (int) ($info['validFrom_time_t'] ?? 0),
        'valid_to'   => (int) ($info['validTo_time_t'] ?? 0),
        'serial' => (string) ($info['serialNumberHex'] ?? $info['serialNumber'] ?? ''),
        'sigalg' => (string) ($info['signatureTypeSN'] ?? ''),
        'sans'   => array_slice(array_values(array_unique($sans)), 0, 50),
    ];
}

// ---- social profile aggregation (public profiles for a username; keyless) ----
function scan_social_card(string $platform, string $url): array {
    return ['platform' => $platform, 'url' => $url, 'exists' => null, 'name' => '', 'bio' => '', 'location' => '', 'avatar' => '', 'joined' => '', 'stats' => '', 'linked' => []];
}
function scan_social_github(?array $r, string $u): array {
    $c = scan_social_card('GitHub', 'https://github.com/' . $u);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    if ((int) $r['code'] !== 200) return $c;
    $j = json_decode($r['body'], true); if (!is_array($j) || empty($j['login'])) return $c;
    $c['exists'] = true;
    $c['name'] = (string) ($j['name'] ?? ''); $c['bio'] = (string) ($j['bio'] ?? '');
    $c['location'] = (string) ($j['location'] ?? ''); $c['avatar'] = (string) ($j['avatar_url'] ?? '');
    $c['url'] = (string) ($j['html_url'] ?? $c['url']); $c['joined'] = isset($j['created_at']) ? substr($j['created_at'], 0, 10) : '';
    $bits = [];
    if (isset($j['public_repos'])) $bits[] = (int) $j['public_repos'] . ' repos';
    if (isset($j['followers'])) $bits[] = (int) $j['followers'] . ' followers';
    if (!empty($j['company'])) $bits[] = trim($j['company']);
    $c['stats'] = implode(' · ', $bits);
    if (!empty($j['twitter_username'])) $c['linked'][] = ['service' => 'twitter', 'name' => $j['twitter_username'], 'url' => 'https://twitter.com/' . $j['twitter_username']];
    if (!empty($j['blog'])) $c['linked'][] = ['service' => 'website', 'name' => $j['blog'], 'url' => preg_match('#^https?://#', $j['blog']) ? $j['blog'] : 'https://' . $j['blog']];
    return $c;
}
function scan_social_hn(?array $r, string $u): array {
    $c = scan_social_card('Hacker News', 'https://news.ycombinator.com/user?id=' . $u);
    if (!$r || $r['err']) return $c;
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['id'])) { $c['exists'] = ($r['code'] === 200) ? false : null; return $c; }
    $c['exists'] = true;
    $c['bio'] = trim(html_entity_decode(strip_tags((string) ($j['about'] ?? '')), ENT_QUOTES | ENT_HTML5));
    $c['joined'] = isset($j['created']) ? date('Y-m-d', (int) $j['created']) : '';
    $c['stats'] = isset($j['karma']) ? (int) $j['karma'] . ' karma' : '';
    return $c;
}
function scan_social_keybase(?array $r, string $u): array {
    $c = scan_social_card('Keybase', 'https://keybase.io/' . $u);
    if (!$r || $r['err'] || (int) $r['code'] !== 200) return $c;
    $j = json_decode($r['body'], true);
    $them = is_array($j) ? ($j['them'] ?? null) : null;
    if (!is_array($them) || empty($them['basics']['username'])) { $c['exists'] = false; return $c; }
    $c['exists'] = true;
    $c['name'] = (string) ($them['profile']['full_name'] ?? '');
    $c['bio'] = (string) ($them['profile']['bio'] ?? '');
    $c['location'] = (string) ($them['profile']['location'] ?? '');
    $c['avatar'] = (string) ($them['pictures']['primary']['url'] ?? '');
    $c['joined'] = isset($them['basics']['ctime']) ? date('Y-m-d', (int) $them['basics']['ctime']) : '';
    foreach (($them['proofs_summary']['all'] ?? []) as $p) {
        $svc = (string) ($p['proof_type'] ?? ''); $tag = (string) ($p['nametag'] ?? '');
        if ($svc === '' || $tag === '') continue;
        $c['linked'][] = ['service' => $svc, 'name' => $tag, 'url' => (string) ($p['service_url'] ?? $p['proof_url'] ?? '')];
    }
    $c['linked'] = array_slice($c['linked'], 0, 12);
    return $c;
}
function scan_social_chess(?array $r, string $u): array {
    $c = scan_social_card('Chess.com', 'https://www.chess.com/member/' . $u);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    $j = json_decode($r['body'], true); if (!is_array($j) || empty($j['username'])) return $c;
    $c['exists'] = true;
    $c['name'] = (string) ($j['name'] ?? ''); $c['location'] = (string) ($j['location'] ?? '');
    $c['avatar'] = (string) ($j['avatar'] ?? ''); $c['url'] = (string) ($j['url'] ?? $c['url']);
    $c['joined'] = isset($j['joined']) ? date('Y-m-d', (int) $j['joined']) : '';
    $c['stats'] = isset($j['followers']) ? (int) $j['followers'] . ' followers' : '';
    return $c;
}
function scan_social_lichess(?array $r, string $u): array {
    $c = scan_social_card('Lichess', 'https://lichess.org/@/' . $u);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    $j = json_decode($r['body'], true); if (!is_array($j) || empty($j['username'])) return $c;
    $c['exists'] = true;
    $c['name'] = (string) ($j['profile']['realName'] ?? '');
    $c['bio'] = (string) ($j['profile']['bio'] ?? '');
    $c['location'] = (string) ($j['profile']['location'] ?? '');
    $c['url'] = (string) ($j['url'] ?? $c['url']);
    $c['joined'] = isset($j['createdAt']) ? date('Y-m-d', (int) ($j['createdAt'] / 1000)) : '';
    if (!empty($j['disabled'])) $c['stats'] = 'account closed';
    return $c;
}
function scan_social_reddit(?array $r, string $u): array {
    $c = scan_social_card('Reddit', 'https://www.reddit.com/user/' . $u);
    if (!$r || $r['err'] || (int) $r['code'] !== 200) return $c;   // Reddit often 403s from servers
    $j = json_decode($r['body'], true);
    $d = is_array($j) ? ($j['data'] ?? null) : null;
    if (!is_array($d) || empty($d['name'])) { $c['exists'] = false; return $c; }
    $c['exists'] = true;
    $c['avatar'] = preg_replace('/\?.*$/', '', (string) ($d['icon_img'] ?? ''));
    $c['joined'] = isset($d['created_utc']) ? date('Y-m-d', (int) $d['created_utc']) : '';
    $c['stats'] = isset($d['total_karma']) ? (int) $d['total_karma'] . ' karma' : '';
    return $c;
}

/** Extract an og:/meta tag's content from an HTML string. */
function scan_og_tag(string $html, string $prop): string {
    $q = preg_quote($prop, '#');
    if (preg_match('#<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m)
     || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $q . '["\']#i', $html, $m)) return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    return '';
}
function scan_social_instagram(?array $r, string $u): array {
    $c = scan_social_card('Instagram', 'https://www.instagram.com/' . $u . '/');
    if (!$r || $r['err']) return $c;
    $h = $r['body'];
    $desc = scan_og_tag($h, 'og:description'); $title = scan_og_tag($h, 'og:title'); $img = scan_og_tag($h, 'og:image');
    if ($desc === '' && (stripos($h, 'Page Not Found') !== false || (int) $r['code'] === 404)) { $c['exists'] = false; return $c; }
    if ($desc === '' && $title === '') return $c;   // blocked / no preview → couldn't check
    $c['exists'] = true;
    if (preg_match('/from (.+?) \(@/u', $desc, $m)) $c['name'] = trim($m[1]);
    elseif (preg_match('/^(.+?) \(@/u', $title, $m)) $c['name'] = trim($m[1]);
    $stats = [];
    if (preg_match('/([\d.,KMB]+)\s+Followers/i', $desc, $m)) $stats[] = $m[1] . ' followers';
    if (preg_match('/([\d.,KMB]+)\s+Posts/i', $desc, $m)) $stats[] = $m[1] . ' posts';
    $c['stats'] = implode(' · ', $stats);
    if (preg_match('#^https?://#', $img)) $c['avatar'] = $img;
    return $c;
}
function scan_social_bluesky(?array $r, string $u): array {
    $c = scan_social_card('Bluesky', 'https://bsky.app/profile/' . $u . '.bsky.social');
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 400 || (int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['handle'])) { $c['exists'] = ((int) $r['code'] === 200) ? false : null; return $c; }
    $c['exists'] = true;
    $c['name'] = (string) ($j['displayName'] ?? ''); $c['bio'] = (string) ($j['description'] ?? '');
    $c['avatar'] = (string) ($j['avatar'] ?? ''); $c['url'] = 'https://bsky.app/profile/' . $j['handle'];
    $c['joined'] = isset($j['createdAt']) ? substr($j['createdAt'], 0, 10) : '';
    $bits = [];
    if (isset($j['followersCount'])) $bits[] = (int) $j['followersCount'] . ' followers';
    if (isset($j['postsCount'])) $bits[] = (int) $j['postsCount'] . ' posts';
    $c['stats'] = implode(' · ', $bits);
    return $c;
}
function scan_social_telegram(?array $r, string $u): array {
    $c = scan_social_card('Telegram', 'https://t.me/' . $u);
    if (!$r || $r['err']) return $c;
    $h = $r['body'];
    $title = scan_og_tag($h, 'og:title'); $desc = scan_og_tag($h, 'og:description'); $img = scan_og_tag($h, 'og:image');
    // Only public channels/groups/bots have a real preview page. A plain username (or none)
    // returns the "Telegram: Contact @x" fallback — not a public profile.
    if ($title === '' || preg_match('/^Telegram:\s*Contact/i', $title)) { $c['exists'] = false; return $c; }
    $c['exists'] = true;
    $c['name'] = $title;
    $c['bio'] = (stripos($desc, 'If you have Telegram') === false && stripos($desc, 'you can view and join') === false && stripos($desc, 'you can contact') === false) ? $desc : '';
    if (preg_match('#^https?://#', $img)) $c['avatar'] = $img;
    return $c;
}

function scan_social_dockerhub(?array $r, string $u): array {
    $c = scan_social_card('Docker Hub', 'https://hub.docker.com/u/' . $u);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['username'])) return $c;
    $c['exists'] = true;
    $c['name'] = (string) ($j['full_name'] ?? ''); $c['location'] = (string) ($j['location'] ?? '');
    $c['avatar'] = (string) ($j['gravatar_url'] ?? ''); $c['joined'] = isset($j['date_joined']) ? substr($j['date_joined'], 0, 10) : '';
    if (!empty($j['company'])) $c['stats'] = (string) $j['company'];
    return $c;
}
function scan_social_steam(?array $r, string $u): array {
    $c = scan_social_card('Steam', 'https://steamcommunity.com/id/' . $u);
    if (!$r || $r['err'] || (int) $r['code'] !== 200) return $c;
    $x = $r['body'];
    if (stripos($x, 'could not be found') !== false || stripos($x, '<steamID>') === false) { $c['exists'] = false; return $c; }
    $g = function ($t) use ($x) {
        if (preg_match('#<' . $t . '><!\[CDATA\[(.*?)\]\]></' . $t . '>#s', $x, $m)) return trim($m[1]);
        if (preg_match('#<' . $t . '>(.*?)</' . $t . '>#s', $x, $m)) return trim($m[1]);
        return '';
    };
    $c['exists'] = true;
    $c['name'] = $g('steamID'); $c['location'] = $g('location');
    $c['bio'] = trim(html_entity_decode(strip_tags($g('summary')), ENT_QUOTES | ENT_HTML5));
    $c['avatar'] = $g('avatarFull') ?: $g('avatarMedium');
    $c['joined'] = preg_replace('/^Since\s*/i', '', $g('memberSince'));
    $c['stats'] = trim(strip_tags($g('stateMessage')));
    return $c;
}
/** Generic og-based profile card (YouTube/SoundCloud/DeviantArt/Twitch). */
function scan_social_og_card(?array $r, string $platform, string $url): array {
    $c = scan_social_card($platform, $url);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404 || (int) $r['code'] === 410) { $c['exists'] = false; return $c; }
    $h = $r['body'];
    $title = scan_og_tag($h, 'og:title'); $desc = scan_og_tag($h, 'og:description'); $img = scan_og_tag($h, 'og:image');
    if ($title === '' && $desc === '') { $c['exists'] = ((int) $r['code'] === 200) ? null : false; return $c; }
    $c['exists'] = true;
    $name = preg_replace('/\s*[-|•·]\s*' . preg_quote($platform, '/') . '\b.*$/i', '', $title);
    $name = preg_replace('/\s+on ' . preg_quote($platform, '/') . '$/i', '', $name);
    $c['name'] = trim($name) ?: $title;
    $c['bio'] = $desc;
    if (preg_match('#^https?://#', $img)) $c['avatar'] = $img;
    return $c;
}

function scan_social_vimeo(?array $r, string $u): array {
    $c = scan_social_card('Vimeo', 'https://vimeo.com/' . $u);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    $j = json_decode($r['body'], true);
    $v = (is_array($j) && isset($j['display_name'])) ? $j : (is_array($j) ? ($j[0] ?? null) : null);
    if (!is_array($v) || empty($v['display_name'])) { $c['exists'] = ((int) $r['code'] === 200) ? false : null; return $c; }
    $c['exists'] = true;
    $c['name'] = (string) $v['display_name'];
    $c['bio'] = trim((string) ($v['bio'] ?? ''));
    $c['location'] = (string) ($v['location'] ?? '');
    $c['avatar'] = (string) ($v['portrait_huge'] ?? $v['portrait_large'] ?? '');
    $c['url'] = (string) ($v['profile_url'] ?? $c['url']);
    $c['joined'] = isset($v['created_on']) ? substr((string) $v['created_on'], 0, 10) : '';
    if (isset($v['total_videos_uploaded'])) $c['stats'] = (int) $v['total_videos_uploaded'] . ' videos';
    return $c;
}
function scan_social_tiktok(?array $r, string $u): array {
    $c = scan_social_card('TikTok', 'https://www.tiktok.com/@' . $u);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    $desc = scan_og_tag($r['body'], 'og:description'); $title = scan_og_tag($r['body'], 'og:title'); $img = scan_og_tag($r['body'], 'og:image');
    if (stripos($desc, 'Followers') === false) { $c['exists'] = false; return $c; }   // generic "Visit TikTok…" fallback = no such profile
    $c['exists'] = true;
    $name = preg_replace('/\s*\(@.*$/', '', $title);
    $name = preg_replace('/\s*\|\s*TikTok.*$/i', '', $name);
    $name = preg_replace('/\s+on TikTok$/i', '', $name);
    $c['name'] = trim($name);
    $stats = [];
    if (preg_match('/([\d.,KMB]+)\s+Followers/i', $desc, $m)) $stats[] = $m[1] . ' followers';
    if (preg_match('/([\d.,KMB]+)\s+Likes/i', $desc, $m)) $stats[] = $m[1] . ' likes';
    $c['stats'] = implode(' · ', $stats);
    if (preg_match('#^https?://#', $img)) $c['avatar'] = $img;
    return $c;
}
function scan_social_gravatar_un(?array $r, string $u): array {
    $c = scan_social_card('Gravatar', 'https://gravatar.com/' . $u);
    if (!$r || $r['err']) return $c;
    if ((int) $r['code'] === 404) { $c['exists'] = false; return $c; }
    if ((int) $r['code'] !== 200) return $c;
    $prof = scan_gravatar_profile($r['body']);
    if (!$prof) { $c['exists'] = false; return $c; }
    $c['exists'] = true;
    $c['name'] = $prof['name']; $c['bio'] = $prof['about']; $c['location'] = $prof['location'];
    $j = json_decode($r['body'], true);
    $hash = (string) ($j['entry'][0]['hash'] ?? '');
    if ($hash !== '') $c['avatar'] = 'https://gravatar.com/avatar/' . $hash . '?s=200';
    foreach ($prof['accounts'] as $a) $c['linked'][] = ['service' => $a['label'], 'name' => $a['label'], 'url' => $a['url']];
    $c['linked'] = array_slice($c['linked'], 0, 8);
    return $c;
}

/** Aggregate public profiles for one username across keyless-API platforms. */
function scan_social_lookup(string $username): array {
    $u = trim($username);
    if ($u === '' || !preg_match('/^[A-Za-z0-9._\-]{1,40}$/', $u)) return ['error' => 'Invalid username.'];
    $ua = ['User-Agent: ' . OSINT_UA];
    $crawl = ['User-Agent: ' . OSINT_CRAWLER_UA];
    $res = scan_multi_get([
        'github'   => ['url' => 'https://api.github.com/users/' . rawurlencode($u), 'headers' => array_merge($ua, ['Accept: application/vnd.github+json']), 'follow' => true],
        'hn'       => ['url' => 'https://hacker-news.firebaseio.com/v0/user/' . rawurlencode($u) . '.json', 'headers' => $ua, 'follow' => true],
        'keybase'  => ['url' => 'https://keybase.io/_/api/1.0/user/lookup.json?username=' . rawurlencode($u), 'headers' => $ua, 'follow' => true],
        'chess'    => ['url' => 'https://api.chess.com/pub/player/' . rawurlencode(strtolower($u)), 'headers' => $ua, 'follow' => true],
        'lichess'  => ['url' => 'https://lichess.org/api/user/' . rawurlencode($u), 'headers' => $ua, 'follow' => true],
        'reddit'   => ['url' => 'https://www.reddit.com/user/' . rawurlencode($u) . '/about.json', 'headers' => $ua, 'follow' => true],
        'instagram'=> ['url' => 'https://www.instagram.com/' . rawurlencode($u) . '/', 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'bluesky'  => ['url' => 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode($u . '.bsky.social'), 'headers' => $ua, 'follow' => true],
        'telegram' => ['url' => 'https://t.me/' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 10],
        'dockerhub'=> ['url' => 'https://hub.docker.com/v2/users/' . rawurlencode(strtolower($u)) . '/', 'headers' => $ua, 'follow' => true],
        'steam'    => ['url' => 'https://steamcommunity.com/id/' . rawurlencode($u) . '?xml=1', 'headers' => $ua, 'follow' => true, 'timeout' => 10],
        'youtube'  => ['url' => 'https://www.youtube.com/@' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'soundcloud'=>['url' => 'https://soundcloud.com/' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'deviantart'=>['url' => 'https://www.deviantart.com/' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'twitch'   => ['url' => 'https://www.twitch.tv/' . rawurlencode(strtolower($u)), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'vimeo'    => ['url' => 'https://vimeo.com/api/v2/' . rawurlencode(strtolower($u)) . '/info.json', 'headers' => $ua, 'follow' => true, 'timeout' => 10],
        'tiktok'   => ['url' => 'https://www.tiktok.com/@' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'codepen'  => ['url' => 'https://codepen.io/' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'behance'  => ['url' => 'https://www.behance.net/' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'aboutme'  => ['url' => 'https://about.me/' . rawurlencode($u), 'headers' => $crawl, 'follow' => true, 'timeout' => 12],
        'gravatar' => ['url' => 'https://gravatar.com/' . rawurlencode(strtolower($u)) . '.json', 'headers' => $ua, 'follow' => true, 'timeout' => 10],
    ], 400000);
    $cards = [
        scan_social_github($res['github'] ?? null, $u),
        scan_social_instagram($res['instagram'] ?? null, $u),
        scan_social_keybase($res['keybase'] ?? null, $u),
        scan_social_bluesky($res['bluesky'] ?? null, $u),
        scan_social_hn($res['hn'] ?? null, $u),
        scan_social_telegram($res['telegram'] ?? null, $u),
        scan_social_og_card($res['youtube'] ?? null, 'YouTube', 'https://www.youtube.com/@' . $u),
        scan_social_og_card($res['soundcloud'] ?? null, 'SoundCloud', 'https://soundcloud.com/' . $u),
        scan_social_og_card($res['twitch'] ?? null, 'Twitch', 'https://www.twitch.tv/' . $u),
        scan_social_og_card($res['deviantart'] ?? null, 'DeviantArt', 'https://www.deviantart.com/' . $u),
        scan_social_dockerhub($res['dockerhub'] ?? null, $u),
        scan_social_steam($res['steam'] ?? null, $u),
        scan_social_chess($res['chess'] ?? null, $u),
        scan_social_lichess($res['lichess'] ?? null, $u),
        scan_social_reddit($res['reddit'] ?? null, $u),
        scan_social_vimeo($res['vimeo'] ?? null, $u),
        scan_social_tiktok($res['tiktok'] ?? null, $u),
        scan_social_og_card($res['codepen'] ?? null, 'CodePen', 'https://codepen.io/' . $u),
        scan_social_og_card($res['behance'] ?? null, 'Behance', 'https://www.behance.net/' . $u),
        scan_social_og_card($res['aboutme'] ?? null, 'about.me', 'https://about.me/' . $u),
        scan_social_gravatar_un($res['gravatar'] ?? null, $u),
    ];
    return ['ok' => true, 'username' => $u, 'cards' => $cards];
}

/** Common handle variations (shared with the self-search page). */
function scan_username_variants(string $u): array {
    $u = trim($u);
    $stripped = preg_replace('/[._\-]/', '', $u);
    $set = [$u, $stripped, str_replace(['.', '_', '-'], '_', $u), str_replace(['.', '_', '-'], '.', $u), 'the' . $u, 'real' . $u, 'official' . $u, 'its' . $u];
    foreach (['1', '01', '123', '2024', '2025', '_', 'official', 'hq', 'tv', 'yt'] as $s) $set[] = $u . $s;
    return array_slice(array_values(array_unique(array_filter($set, fn($x) => $x !== ''))), 0, 16);
}

/** Does a username exist on a platform, from a raw response? */
function scan_social_exists(string $platform, array $r): bool {
    if ((int) $r['code'] !== 200) return false;
    $j = json_decode($r['body'], true);
    switch ($platform) {
        case 'GitHub':     return is_array($j) && !empty($j['login']);
        case 'Keybase':    return is_array($j) && !empty($j['them']['basics']['username']);
        case 'Chess.com':  return is_array($j) && !empty($j['username']);
        case 'Lichess':    return is_array($j) && !empty($j['username']);
        case 'HackerNews': return is_array($j) && !empty($j['id']);
        case 'Bluesky':    return is_array($j) && !empty($j['handle']);
    }
    return false;
}

/** Check ~12 handle variations against key platforms to surface possible impersonators. */
function scan_impersonation(string $username): array {
    $variants = array_slice(scan_username_variants($username), 0, 12);
    $ua = ['User-Agent: ' . OSINT_UA];
    $platforms = [
        'GitHub'     => fn($v) => 'https://api.github.com/users/' . rawurlencode($v),
        'Keybase'    => fn($v) => 'https://keybase.io/_/api/1.0/user/lookup.json?username=' . rawurlencode($v),
        'Chess.com'  => fn($v) => 'https://api.chess.com/pub/player/' . rawurlencode(strtolower($v)),
        'Lichess'    => fn($v) => 'https://lichess.org/api/user/' . rawurlencode($v),
        'HackerNews' => fn($v) => 'https://hacker-news.firebaseio.com/v0/user/' . rawurlencode($v) . '.json',
        'Bluesky'    => fn($v) => 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode($v . '.bsky.social'),
    ];
    $tasks = [];
    foreach ($variants as $vi => $v) foreach ($platforms as $pn => $b) $tasks[$vi . '|' . $pn] = ['url' => $b($v), 'headers' => $ua, 'follow' => true];
    $res = scan_multi_get($tasks, 65536);
    $rows = [];
    foreach ($variants as $vi => $v) {
        $hits = [];
        foreach ($platforms as $pn => $b) { $r = $res[$vi . '|' . $pn] ?? null; if ($r && !$r['err'] && scan_social_exists($pn, $r)) $hits[] = $pn; }
        if ($hits) $rows[] = ['variant' => $v, 'is_you' => ($v === $username), 'hits' => $hits];
    }
    return ['ok' => true, 'username' => $username, 'checked' => count($variants), 'rows' => $rows];
}

/** Resolve a Fediverse handle (user@instance) via WebFinger. */
function scan_fediverse(string $handle): array {
    $handle = trim($handle, '@ ');
    if (!preg_match('/^([A-Za-z0-9_.\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})$/', $handle, $m)) return ['error' => 'Enter a handle like user@mastodon.social'];
    $user = $m[1]; $instance = $m[2];
    $r = scan_multi_get(['w' => ['url' => 'https://' . $instance . '/.well-known/webfinger?resource=' . rawurlencode('acct:' . $handle), 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/jrd+json'], 'follow' => true, 'timeout' => 10]])['w'] ?? null;
    if (!$r || $r['err'] || (int) $r['code'] !== 200) return ['ok' => true, 'handle' => $handle, 'exists' => false, 'instance' => $instance];
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['subject'])) return ['ok' => true, 'handle' => $handle, 'exists' => false, 'instance' => $instance];
    $profile = '';
    foreach (($j['links'] ?? []) as $l) if (($l['rel'] ?? '') === 'http://webfinger.net/rel/profile-page' && !empty($l['href'])) { $profile = $l['href']; break; }
    if ($profile === '') foreach (($j['aliases'] ?? []) as $a) if (preg_match('#^https?://#', $a)) { $profile = $a; break; }
    $out = ['ok' => true, 'handle' => $handle, 'exists' => true, 'instance' => $instance, 'subject' => (string) $j['subject'], 'profile' => $profile];
    // Enrich with the Mastodon-compatible public account (display name, bio, counts, avatar).
    $a = scan_multi_get(['a' => ['url' => 'https://' . $instance . '/api/v1/accounts/lookup?acct=' . rawurlencode($user), 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'timeout' => 10]])['a'] ?? null;
    if ($a && !$a['err'] && (int) $a['code'] === 200) {
        $p = json_decode($a['body'], true);
        if (is_array($p) && !empty($p['username'])) {
            $out['name'] = (string) ($p['display_name'] ?? '');
            $out['bio'] = trim(html_entity_decode(strip_tags((string) ($p['note'] ?? '')), ENT_QUOTES | ENT_HTML5));
            $out['avatar'] = (string) ($p['avatar'] ?? '');
            $out['followers'] = isset($p['followers_count']) ? (int) $p['followers_count'] : null;
            $out['statuses'] = isset($p['statuses_count']) ? (int) $p['statuses_count'] : null;
            $out['created'] = substr((string) ($p['created_at'] ?? ''), 0, 10);
            if (!empty($p['url'])) $out['profile'] = (string) $p['url'];
        }
    }
    return $out;
}

/** Fetch a URL's Open Graph / title metadata (works for most social profile/post links). */
function scan_og_meta(string $url): array {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return ['error' => 'Not a valid URL.'];
    $r = scan_multi_get(['p' => ['url' => $url, 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'timeout' => 10]])['p'] ?? null;
    if (!$r || $r['err']) return ['error' => 'Could not fetch that URL.'];
    $head = substr($r['body'], 0, 40000);
    $og = function ($prop) use ($head) {
        $q = preg_quote($prop, '#');
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]+content=["\']([^"\']*)["\']#i', $head, $m)
         || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $q . '["\']#i', $head, $m)) return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        return '';
    };
    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $head, $m)) $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    return ['ok' => true, 'url' => $url, 'code' => (int) $r['code'],
        'title' => $og('og:title') ?: $title, 'description' => $og('og:description'), 'image' => $og('og:image'), 'site' => $og('og:site_name'), 'type' => $og('og:type')];
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

/** User ids with breach monitoring enabled (for the cron sweep). */
function scan_monitor_enabled_users(): array {
    $db = scan_db(); if (!$db) return [];
    try { return array_map('intval', $db->query("SELECT user_id FROM osint_monitor WHERE enabled = 1")->fetchAll(PDO::FETCH_COLUMN)); }
    catch (\Throwable $e) { return []; }
}

/** User ids with certificate-transparency monitoring enabled (for the cron sweep). */
function scan_ct_enabled_users(): array {
    $db = scan_db(); if (!$db) return [];
    try { return array_map('intval', $db->query("SELECT user_id FROM osint_monitor WHERE ct_enabled = 1")->fetchAll(PDO::FETCH_COLUMN)); }
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

// ---- certificate-transparency monitoring (opt-in; keyless via Cert Spotter) ----
/** CT-monitoring state: [enabled, pending[]]. */
function scan_ct_get(int $uid): array {
    $db = scan_db(); if (!$db) return ['enabled' => false, 'pending' => []];
    try {
        $st = $db->prepare("SELECT ct_enabled, ct_pending FROM osint_monitor WHERE user_id = ?");
        $st->execute([$uid]);
        $r = $st->fetch();
        return ['enabled' => $r ? (bool) $r['ct_enabled'] : false, 'pending' => $r ? (array) json_decode($r['ct_pending'] ?: '[]', true) : []];
    } catch (\Throwable $e) { return ['enabled' => false, 'pending' => []]; }
}
function scan_ct_set_enabled(int $uid, bool $on): void {
    $db = scan_db(); if (!$db) return;
    try {
        $db->prepare("INSERT INTO osint_monitor (user_id, ct_enabled, known, pending) VALUES (?,?,'{}','[]')
                      ON CONFLICT(user_id) DO UPDATE SET ct_enabled = excluded.ct_enabled")->execute([$uid, $on ? 1 : 0]);
    } catch (\Throwable $e) {}
}
function scan_ct_clear_pending(int $uid): void {
    $db = scan_db(); if (!$db) return;
    try { $db->prepare("UPDATE osint_monitor SET ct_pending = '[]' WHERE user_id = ?")->execute([$uid]); } catch (\Throwable $e) {}
}

/** Re-check each of the user's domains against Cert Spotter for NEWLY-issued certs.
 *  First sight of a domain is baselined silently (records the latest issuance id, no
 *  alert). Afterwards, any issuance after that id is new → recorded in ct_pending.
 *  Returns the count of new certificates seen. Keyless. */
function scan_ct_run(int $uid): int {
    $db = scan_db(); if (!$db) return 0;
    $p = scan_profile_get($uid);
    $st = $db->prepare("SELECT ct_pending FROM osint_monitor WHERE user_id = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    $pending = $row ? (array) json_decode($row['ct_pending'] ?: '[]', true) : [];

    $newCount = 0;
    foreach ($p['domains'] as $dom) {
        $s = $db->prepare("SELECT last_id FROM osint_ct_state WHERE user_id = ? AND domain = ?");
        $s->execute([$uid, $dom]);
        $baseRow = $s->fetch();
        $lastId = $baseRow ? (string) $baseRow['last_id'] : '';
        $url = 'https://api.certspotter.com/v1/issuances?domain=' . rawurlencode($dom)
             . '&include_subdomains=true&expand=dns_names&expand=issuer&expand=not_before'
             . ($lastId !== '' ? '&after=' . rawurlencode($lastId) : '');
        $r = scan_multi_get(['c' => ['url' => $url, 'headers' => ['User-Agent: ' . OSINT_UA], 'follow' => true, 'timeout' => 15]], 4194304)['c'] ?? null;
        if (!$r || $r['err'] || (int) $r['code'] !== 200) continue;   // don't disturb the baseline on error/limit
        $j = json_decode($r['body'], true);
        if (!is_array($j)) continue;
        $maxId = $lastId;
        foreach ($j as $iss) { $id = (string) ($iss['id'] ?? ''); if ($id !== '' && ($maxId === '' || (int) $id > (int) $maxId)) $maxId = $id; }
        if ($baseRow === false) {   // first sight — baseline silently
            $db->prepare("INSERT INTO osint_ct_state (user_id,domain,last_id,updated_at) VALUES (?,?,?,?)
                          ON CONFLICT(user_id,domain) DO UPDATE SET last_id=excluded.last_id, updated_at=excluded.updated_at")
               ->execute([$uid, $dom, $maxId, time()]);
            continue;
        }
        foreach ($j as $iss) {   // after=lastId means every result is genuinely new
            $names = array_slice($iss['dns_names'] ?? [], 0, 5);
            $iname = (string) ($iss['issuer']['name'] ?? '');
            $ca = preg_match('/O=([^,]+)/', $iname, $mm) ? trim($mm[1]) : ($iname !== '' ? mb_substr($iname, 0, 40) : 'unknown CA');
            $pending[] = ['domain' => $dom, 'name' => implode(', ', $names), 'issuer' => $ca, 'nb' => substr((string) ($iss['not_before'] ?? ''), 0, 10), 'at' => time()];
            $newCount++;
        }
        if ($maxId !== $lastId) $db->prepare("UPDATE osint_ct_state SET last_id=?, updated_at=? WHERE user_id=? AND domain=?")->execute([$maxId, time(), $uid, $dom]);
    }
    $pending = array_slice($pending, -50);
    try {
        $db->prepare("INSERT INTO osint_monitor (user_id, ct_pending, known, pending) VALUES (?,?,'{}','[]')
                      ON CONFLICT(user_id) DO UPDATE SET ct_pending = excluded.ct_pending")->execute([$uid, json_encode($pending)]);
    } catch (\Throwable $e) {}
    return $newCount;
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
            // Aggregate the exposed data classes — only from the XposedOrNot per-breach findings,
            // whose detail is "YEAR · Classes" (LeakCheck / paste summaries have a different shape).
            if (strpos((string) ($f['title'] ?? ''), ' in the ') !== false) {
                $rest = preg_replace('/^\s*(19|20)\d\d\s*·?\s*/', '', $detail);
                foreach (explode(',', $rest) as $c) {
                    $c = trim($c);
                    if ($c !== '' && !preg_match('/^(19|20)\d\d$/', $c)) {
                        $k = mb_strtolower($c);
                        if (!isset($classes[$k])) $classes[$k] = $c;
                    }
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

// ---- analysis: correlations (SpiderFoot-style) + entity graph (Maltego-style) ----
/** The email a breach/identity finding is about (token before " in the " / " — "). */
function scan_finding_email(string $title): string {
    $head = preg_split('/ in the | — /', $title, 2)[0];
    $head = trim($head);
    return filter_var($head, FILTER_VALIDATE_EMAIL) ? strtolower($head) : $head;
}

/** Cross-finding correlation rules over a scan's findings. Each rule links several
 *  findings into one insight (e.g. a reused password, a widely-reused handle). Returns
 *  [ ['severity'(high|med|low),'title','detail','items'[]], ... ], most severe first. */
function scan_correlations(array $findings): array {
    $emailBreaches = []; $emailPwYear = []; $usernamePlats = []; $recent = []; $emailAccounts = [];
    $thisYear = (int) date('Y');
    foreach ($findings as $f) {
        if (($f['status'] ?? 'new') === 'false') continue;
        $cat = (string) $f['category']; $title = (string) $f['title']; $detail = (string) ($f['detail'] ?? '');
        if ($cat === 'breach') {
            $em = scan_finding_email($title);
            $emailBreaches[$em][] = $title;
            if (stripos($detail, 'password') !== false) $emailPwYear[$em]['pw'] = true;
            if (preg_match('/\b(20\d\d)\b/', $detail, $m)) {
                $y = (int) $m[1];
                $emailPwYear[$em]['year'] = max($emailPwYear[$em]['year'] ?? 0, $y);
                if ($y >= $thisYear - 2) $recent[$title] = $y;
            }
        } elseif ($cat === 'account') {
            if (strpos((string) $f['exposes'], 'email') !== false) {
                $emailAccounts[scan_finding_email($title)][] = $title;
            } elseif (preg_match('/^(.*) on (.+)$/', $title, $m)) {
                $usernamePlats[strtolower(trim($m[1]))][trim($m[2])] = true;
            }
        }
    }
    $out = [];
    // A password was exposed for an address — highest priority (credential-reuse risk).
    foreach ($emailBreaches as $em => $bs) {
        if (!empty($emailPwYear[$em]['pw'])) {
            $out[] = ['severity' => 'high', 'title' => 'Password exposed for ' . $em,
                'detail' => 'This address is in ' . count($bs) . ' breach(es), at least one exposing passwords. Any account reusing that password is exposed — rotate it everywhere and enable 2FA.',
                'items' => array_slice($bs, 0, 6)];
        }
    }
    // Fresh breaches (last 2 years) — credentials may still be live.
    if ($recent) {
        arsort($recent);
        $out[] = ['severity' => 'high', 'title' => count($recent) . ' recent breach(es) — last 2 years',
            'detail' => 'Recent breaches are the most likely to contain still-valid credentials. Deal with these first.',
            'items' => array_slice(array_keys($recent), 0, 8)];
    }
    // The same email tying together many accounts + breaches (a strong pivot identifier).
    foreach ($emailBreaches as $em => $bs) {
        $na = count($emailAccounts[$em] ?? []);
        if (count($bs) >= 5 || ($na >= 1 && count($bs) >= 3)) {
            $out[] = ['severity' => 'med', 'title' => $em . ' is a heavily-linked identity',
                'detail' => 'In ' . count($bs) . ' breach(es)' . ($na ? ' and tied to ' . $na . ' public account(s)' : '') . '. This one address links your footprint together — use a separate address for high-value logins.',
                'items' => array_slice(array_merge($emailAccounts[$em] ?? [], $bs), 0, 8)];
        }
    }
    // A handle reused across many platforms (one profile → all the others).
    foreach ($usernamePlats as $un => $plats) {
        if (count($plats) >= 3) {
            $out[] = ['severity' => 'med', 'title' => 'Handle "' . $un . '" reused on ' . count($plats) . ' platforms',
                'detail' => 'A consistent username across sites lets anyone pivot from one profile to all the rest. Use site-specific handles where anonymity matters.',
                'items' => array_slice(array_keys($plats), 0, 10)];
        }
    }
    $rank = ['high' => 0, 'med' => 1, 'low' => 2];
    usort($out, fn($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
    return $out;
}

/** Build an entity graph (nodes + edges) from a scan's findings + the profile anchors,
 *  for the Maltego-style view. Node types: username|email|domain|phone|account|breach. */
function scan_graph_data(array $findings, array $profile): array {
    $nodes = []; $edges = []; $edgeSeen = [];
    $node = function (string $id, string $type, string $label, string $sub = '', string $url = '') use (&$nodes) {
        if (!isset($nodes[$id])) $nodes[$id] = ['id' => $id, 'type' => $type, 'label' => mb_substr($label, 0, 48), 'sub' => mb_substr($sub, 0, 60), 'url' => $url];
    };
    $edge = function (string $a, string $b, string $rel) use (&$edges, &$edgeSeen) {
        $k = $a . '>' . $b; if (isset($edgeSeen[$k]) || $a === $b) return; $edgeSeen[$k] = true; $edges[] = ['from' => $a, 'to' => $b, 'rel' => $rel];
    };
    foreach ($profile['usernames'] as $un) $node('u:' . strtolower($un), 'username', $un);
    foreach ($profile['emails'] as $em)    $node('e:' . strtolower($em), 'email', $em);
    foreach ($profile['domains'] as $dm)   $node('d:' . strtolower($dm), 'domain', $dm);
    foreach ($profile['phones'] as $ph)    $node('p:' . $ph, 'phone', $ph);

    $count = 0;
    foreach ($findings as $f) {
        if (($f['status'] ?? 'new') === 'false') continue;
        if (++$count > 160) break;
        $cat = (string) $f['category']; $title = (string) $f['title']; $url = (string) ($f['url'] ?? '');
        if ($cat === 'account') {
            if (strpos((string) $f['exposes'], 'email') !== false) {
                $em = scan_finding_email($title); $emId = 'e:' . strtolower($em); $node($emId, 'email', $em);
                $label = trim((string) preg_replace('/^.*? — /', '', $title));
                $aid = 'a:' . substr(md5($title), 0, 10); $node($aid, 'account', $label, '', $url); $edge($emId, $aid, 'account');
            } elseif (preg_match('/^(.*) on (.+)$/', $title, $m)) {
                $un = trim($m[1]); $plat = trim($m[2]); $unId = 'u:' . strtolower($un); $node($unId, 'username', $un);
                $aid = 'a:' . substr(md5($title), 0, 10); $node($aid, 'account', $plat, '', $url); $edge($unId, $aid, 'account');
            }
        } elseif ($cat === 'breach') {
            $em = scan_finding_email($title); $emId = 'e:' . strtolower($em); $node($emId, 'email', $em);
            if (preg_match('/ in the (.+) breach$/', $title, $m)) $label = $m[1];
            elseif (stripos($title, 'paste') !== false) $label = 'Public pastes';
            elseif (stripos($title, 'leakcheck') !== false) $label = 'LeakCheck records';
            else $label = 'Breach';
            $bid = 'b:' . substr(md5($title), 0, 10); $node($bid, 'breach', $label, (string) ($f['detail'] ?? ''), $url); $edge($emId, $bid, 'breach');
        }
    }
    return ['nodes' => array_values($nodes), 'edges' => $edges];
}

// ---- consolidated email intelligence (Mosint-style; keyless, one email at a time) ----
/** Common free / consumer webmail providers (an email here is personal, not corporate). */
function scan_email_free_providers(): array {
    return ['gmail.com','googlemail.com','yahoo.com','ymail.com','outlook.com','hotmail.com','live.com','msn.com',
            'icloud.com','me.com','mac.com','aol.com','proton.me','protonmail.com','pm.me','gmx.com','gmx.net',
            'mail.com','yandex.com','yandex.ru','zoho.com','tutanota.com','tuta.io','hey.com','fastmail.com'];
}

/** Everything public a single email address reveals, in one report: deliverability +
 *  disposable/role/free classification, domain spoofability (SPF/DMARC), Gravatar
 *  profile, breach corpora (XposedOrNot + LeakCheck + pastes), and where it's a known
 *  registered account (Duolingo / GitHub-by-email). All keyless; nothing is emailed. */
function scan_email_intel(string $emailRaw): array {
    $email = strtolower(trim($emailRaw));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'Not a valid email address.'];
    [$local, $domain] = explode('@', $email, 2);
    $canonical = null;
    if (preg_match('/@(gmail|googlemail)\.com$/', $email)) {   // Gmail ignores dots + everything after +
        $c = str_replace('.', '', preg_replace('/\+.*$/', '', $local)) . '@gmail.com';
        if ($c !== $email) $canonical = $c;
    }
    $ua  = ['User-Agent: ' . OSINT_UA];
    $doh = fn($n, $t) => ['url' => 'https://dns.google/resolve?name=' . rawurlencode($n) . '&type=' . $t, 'headers' => array_merge($ua, ['Accept: application/dns-json']), 'follow' => true, 'timeout' => 8];
    $res = scan_multi_get([
        'breach' => ['url' => OSINT_XPOSED . rawurlencode($email), 'headers' => $ua, 'follow' => true],
        'leak'   => ['url' => 'https://leakcheck.io/api/public?check=' . rawurlencode($email), 'headers' => $ua, 'follow' => true],
        'grav'   => ['url' => 'https://gravatar.com/' . md5($email) . '.json', 'headers' => $ua, 'follow' => true],
        'duo'    => ['url' => 'https://www.duolingo.com/2017-06-30/users?email=' . rawurlencode($email), 'headers' => $ua, 'follow' => true],
        'gh'     => ['url' => 'https://api.github.com/search/users?q=' . rawurlencode($email) . '+in:email', 'headers' => array_merge($ua, ['Accept: application/vnd.github+json']), 'follow' => true],
        'mx'     => $doh($domain, 'MX'), 'txt' => $doh($domain, 'TXT'), 'dmarc' => $doh('_dmarc.' . $domain, 'TXT'),
    ]);

    // Domain deliverability + classification.
    $mxHosts = array_values(array_filter(array_map(function ($m) { $p = preg_split('/\s+/', trim($m)); return rtrim((string) end($p), '.'); }, scan_doh_answers($res['mx'] ?? null, 15))));
    $disp = json_decode((string) @file_get_contents(__DIR__ . '/../assets/disposable.json'), true);
    $roles = ['admin','administrator','info','support','sales','contact','help','billing','noreply','no-reply','postmaster','webmaster','abuse','office','hello','team','marketing','hr','jobs','careers','service','security','root','mail'];
    // Domain spoofability.
    $spf = false;
    foreach (array_map(fn($t) => str_replace('"', '', $t), scan_doh_answers($res['txt'] ?? null, 16)) as $t) if (stripos($t, 'v=spf1') === 0) { $spf = true; break; }
    $dmarc = null;
    foreach (array_map(fn($t) => str_replace('"', '', $t), scan_doh_answers($res['dmarc'] ?? null, 16)) as $t) if (stripos($t, 'v=DMARC1') === 0 && preg_match('/\bp=([a-z]+)/i', $t, $m)) { $dmarc = strtolower($m[1]); break; }

    // Breaches — XposedOrNot per-breach + pastes, plus LeakCheck as a second corpus.
    $breaches = []; $years = []; $pw = false; $classes = [];
    if (($res['breach']['code'] ?? 0) === 200 && empty($res['breach']['err'])) {
        foreach (scan_breach_details($res['breach']['body']) as $b) {
            $breaches[] = ['name' => $b['name'], 'date' => $b['date'], 'data' => $b['data'], 'src' => 'XposedOrNot'];
            if ($b['date'] && preg_match('/(19|20)\d\d/', $b['date'], $mm)) $years[] = (int) $mm[0];
            if (stripos($b['data'], 'password') !== false) $pw = true;
            foreach (explode(',', $b['data']) as $c) { $c = trim($c); if ($c !== '') $classes[mb_strtolower($c)] = $c; }
        }
    }
    $pj = json_decode($res['breach']['body'] ?? '', true);
    $pastes = is_array($pj) ? (int) ($pj['PastesSummary']['cnt'] ?? 0) : 0;
    $leak = null;
    if (($res['leak']['code'] ?? 0) === 200 && empty($res['leak']['err'])) {
        $lj = json_decode($res['leak']['body'], true);
        if (is_array($lj) && !empty($lj['success']) && (int) ($lj['found'] ?? 0) > 0) {
            $ls = [];
            foreach (($lj['sources'] ?? []) as $s) { $n = (string) ($s['name'] ?? ''); if ($n !== '') $ls[] = $n . (!empty($s['date']) ? ' (' . substr((string) $s['date'], 0, 4) . ')' : ''); }
            $leak = ['found' => (int) $lj['found'], 'sources' => array_slice($ls, 0, 12), 'fields' => array_slice(array_map(fn($x) => ucfirst(str_replace('_', ' ', (string) $x)), (array) ($lj['fields'] ?? [])), 0, 12)];
        }
    }

    // Gravatar profile + registered-account signals.
    $grav = null;
    if (($res['grav']['code'] ?? 0) === 200 && empty($res['grav']['err'])) {
        $prof = scan_gravatar_profile($res['grav']['body']);
        if ($prof) $grav = ['name' => $prof['name'], 'location' => $prof['location'], 'about' => $prof['about'],
                            'avatar' => 'https://gravatar.com/avatar/' . md5($email) . '?s=200', 'accounts' => $prof['accounts'], 'urls' => $prof['urls']];
    }
    $accounts = [];
    if (!empty($res['duo']) && empty($res['duo']['err'])) { $pic = scan_duolingo_pic($res['duo']['body']); if ($pic !== null) $accounts[] = ['label' => 'Duolingo', 'url' => 'https://www.duolingo.com/']; }
    if (($res['gh']['code'] ?? 0) === 200) { $gj = json_decode($res['gh']['body'], true); foreach (array_slice(is_array($gj) ? ($gj['items'] ?? []) : [], 0, 3) as $it) { $lg = (string) ($it['login'] ?? ''); if ($lg !== '') $accounts[] = ['label' => 'GitHub @' . $lg, 'url' => (string) ($it['html_url'] ?? 'https://github.com/' . $lg)]; } }

    sort($years);
    return [
        'ok' => true, 'email' => $email, 'local' => $local, 'domain' => $domain, 'canonical' => $canonical,
        'deliverable' => !empty($mxHosts), 'mx_hosts' => array_slice($mxHosts, 0, 4),
        'disposable' => is_array($disp) && in_array($domain, $disp, true),
        'role' => in_array($local, $roles, true),
        'free' => in_array($domain, scan_email_free_providers(), true),
        'spf' => $spf, 'dmarc' => $dmarc,
        'gravatar' => $grav, 'accounts' => $accounts,
        'breaches' => $breaches, 'breach_count' => count($breaches),
        'span' => $years ? (reset($years) === end($years) ? (string) reset($years) : reset($years) . '–' . end($years)) : '',
        'pw_exposed' => $pw, 'dataclasses' => array_slice(array_values($classes), 0, 12),
        'pastes' => $pastes, 'leakcheck' => $leak, 'ts' => time(),
    ];
}

// ---- threat-model lens: re-prioritize the whole suite for a chosen adversary ----
/** The adversaries a user can defend against. Each names what it wants, the exposure
 *  keywords/categories it prioritizes, and which hardening themes matter most. */
function scan_threat_models(): array {
    return [
        'general' => [
            'label' => 'General privacy', 'icon' => '🛡️',
            'desc' => 'A balanced default — shrink your overall public footprint.',
            'wants' => 'Less of you exposed everywhere — fewer accounts, fewer breaches, less reusable data.',
            'keywords' => ['password'], 'cat' => ['breach' => 1, 'account' => 1, 'phone' => 1], 'pw' => 1,
            'harden' => ['auth', 'email', 'footprint'],
        ],
        'stalker' => [
            'label' => 'Stalker / abusive ex', 'icon' => '📍',
            'desc' => 'Someone who wants to physically locate, contact, or monitor you in real time.',
            'wants' => 'Your location, routine, phone number, and real-time whereabouts — from photos, check-ins, and fitness/social apps.',
            'keywords' => ['location', 'address', 'geographic', 'strava', 'instagram', 'photo', 'phone', 'place', 'runner', 'check-in', 'gps'],
            'cat' => ['phone' => 3, 'account' => 1, 'breach' => 1], 'pw' => 0,
            'harden' => ['sim', 'device', 'footprint'],
        ],
        'doxxing' => [
            'label' => 'Doxxing / harassment mob', 'icon' => '📢',
            'desc' => 'A crowd trying to tie your anonymous handles to your real name and publish it.',
            'wants' => 'The links between your pseudonyms and your legal identity, home, and employer.',
            'keywords' => ['name', 'gravatar', 'location', 'address', 'geographic', 'instagram', 'handle', 'linked', 'reddit'],
            'cat' => ['account' => 2, 'breach' => 1], 'pw' => 0,
            'harden' => ['footprint', 'browser'],
        ],
        'identity_theft' => [
            'label' => 'Identity theft / fraud', 'icon' => '💳',
            'desc' => 'A criminal assembling enough on you to open accounts or pass verification.',
            'wants' => 'Full name, date of birth, address, SSN, phone, and reusable passwords from breaches.',
            'keywords' => ['password', 'date of birth', 'dates of birth', 'social security', 'ssn', 'address', 'phone', 'bank', 'payment', 'card', 'name'],
            'cat' => ['breach' => 2, 'phone' => 2], 'pw' => 3,
            'harden' => ['financial', 'auth', 'email'],
        ],
        'employer' => [
            'label' => 'Employer / background check', 'icon' => '💼',
            'desc' => 'A recruiter, investigator, or client vetting you through public records and old posts.',
            'wants' => 'Your public accounts, old handles, opinions, photos, and anything reputationally awkward.',
            'keywords' => ['reddit', 'instagram', 'twitter', 'post', 'account', 'photo', 'strava', 'github', 'profile'],
            'cat' => ['account' => 2, 'identity' => 1], 'pw' => 0,
            'harden' => ['footprint', 'browser'],
        ],
        'nation_state' => [
            'label' => 'Targeted / advanced', 'icon' => '🎯',
            'desc' => 'A resourced, persistent adversary who will use every signal against you.',
            'wants' => 'Everything — identity, devices, network, metadata, and any reused credential to pivot from.',
            'keywords' => ['password', 'location', 'address', 'phone', 'name', 'device', 'ip', 'metadata', 'email'],
            'cat' => ['breach' => 1, 'account' => 1, 'phone' => 1], 'pw' => 1,
            'harden' => ['device', 'auth', 'browser', 'sim'],
        ],
    ];
}

function scan_threat_get(int $uid): string {
    $db = scan_db(); if (!$db) return 'general';
    try {
        $st = $db->prepare("SELECT threat FROM osint_profile WHERE user_id = ?");
        $st->execute([$uid]);
        $t = (string) $st->fetchColumn();
        return isset(scan_threat_models()[$t]) ? $t : 'general';
    } catch (\Throwable $e) { return 'general'; }
}

function scan_threat_set(int $uid, string $model): bool {
    if (!isset(scan_threat_models()[$model])) return false;
    $db = scan_db(); if (!$db) return false;
    try {
        $db->prepare("INSERT INTO osint_profile (user_id, threat, updated_at) VALUES (?,?,?)
                      ON CONFLICT(user_id) DO UPDATE SET threat = excluded.threat, updated_at = excluded.updated_at")
           ->execute([$uid, $model, time()]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** How relevant one finding is to a threat model: 0 (background) … 3 (a top concern). */
function scan_threat_score(string $model, array $f): int {
    $m = scan_threat_models()[$model] ?? scan_threat_models()['general'];
    $cat = (string) ($f['category'] ?? '');
    $isIdentity = $cat === 'account' && strpos((string) ($f['exposes'] ?? ''), 'email') !== false;
    $text = strtolower((string) ($f['title'] ?? '') . ' ' . (string) ($f['detail'] ?? ''));
    $score = (int) ($m['cat'][$isIdentity ? 'identity' : $cat] ?? 0);
    foreach ($m['keywords'] as $kw) if (strpos($text, $kw) !== false) { $score += 2; break; }
    if ($cat === 'breach' && strpos($text, 'password') !== false) $score += (int) ($m['pw'] ?? 0);
    return max(0, min(3, $score));
}

/** For the chosen model + a scan's findings: the model meta, the findings ranked by
 *  relevance to this adversary, and a count of high-priority hits. */
function scan_threat_brief(string $model, array $findings): array {
    $m = scan_threat_models()[$model] ?? scan_threat_models()['general'];
    $scored = [];
    foreach ($findings as $f) {
        if (($f['status'] ?? 'new') === 'false') continue;
        $s = scan_threat_score($model, $f);
        if ($s > 0) $scored[] = ['score' => $s, 'title' => (string) $f['title'], 'category' => (string) $f['category'], 'detail' => (string) ($f['detail'] ?? ''), 'url' => (string) ($f['url'] ?? '')];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return [
        'model' => $model, 'meta' => $m,
        'top' => array_slice($scored, 0, 10),
        'high' => count(array_filter($scored, fn($x) => $x['score'] >= 3)),
        'total' => count($scored),
    ];
}

// ---- attacker view: the dossier + spear-phish + KBA exposure an adversary could build ----
/** Build a realistic (but clearly-labelled SIMULATION) spear-phish pretext from the
 *  user's real exposed data — the whole point is to show why it would land. */
function scan_phish_pretext(string $name, string $svc, string $email, bool $pw): array {
    $greet = $name !== '' ? $name : 'there';
    $svc = trim($svc) !== '' ? trim($svc) : 'your account';
    $dom = preg_replace('/[^a-z0-9]/', '', strtolower($svc)) ?: 'account';
    return [
        'from'    => '"' . $svc . ' Security" <no-reply@' . $dom . '-secure.com>',
        'to'      => $email,
        'subject' => '[' . $svc . '] Unusual sign-in detected — confirm it was you',
        'body'    => "Hi " . $greet . ",\n\nWe detected a sign-in to your " . $svc . " account from a new device. "
                   . "If this was you, you can ignore this message. If not, your account may be at risk — "
                   . "recent breach activity has been associated with this address.\n\n"
                   . "Please confirm your identity within 24 hours to avoid a temporary lock:\n\n"
                   . "    https://" . $dom . "-secure.com/verify?u=" . rawurlencode($email) . "\n\n"
                   . "Thank you,\nThe " . $svc . " Account Security Team",
        'why'     => array_values(array_filter([
            $name !== '' ? 'Greets you by your real name ("' . $name . '") — taken from public data, not guessed.' : null,
            'Name-drops ' . $svc . ', a service you actually used (it shows up in your account/breach data), so the story is believable.',
            $pw ? 'Cites "recent breach activity" — and a password of yours genuinely leaked, so the threat feels real.'
                : 'Manufactures urgency with a 24-hour deadline and an account-lock threat.',
            'The look-alike sender domain ("' . $dom . '-secure.com") mimics a real security team; most people never read the full address.',
        ])),
    ];
}

/** The OSINT dossier an attacker could assemble from the user's OWN scan: identity,
 *  contact, handles, compromised credentials — plus derived attack vectors, a spear-phish
 *  built from it, and security-question (KBA) exposure. Read-only over findings. */
function scan_attacker_dossier(int $uid): array {
    $p = scan_profile_get($uid);
    $latest = scan_latest($uid);
    $findings = $latest ? scan_findings($uid, (int) $latest['id']) : [];

    $names = []; $locations = []; $bios = []; $accounts = []; $breaches = []; $pwBreaches = []; $classes = []; $recent = [];
    $thisYear = (int) date('Y');
    foreach ($findings as $f) {
        if (($f['status'] ?? 'new') === 'false') continue;
        $cat = (string) $f['category']; $title = (string) $f['title']; $detail = (string) ($f['detail'] ?? '');
        if ($cat === 'account') {
            if (strpos((string) $f['exposes'], 'email') !== false) {
                foreach (explode('·', $detail) as $seg) {
                    $seg = trim($seg);
                    if (preg_match('/^[A-Z][A-Za-z.\'\-]+(?: [A-Z][A-Za-z.\'\-]+){1,3}$/', $seg)) $names[$seg] = true;
                }
            } else {
                if (preg_match('/^(.*) on (.+)$/', $title, $m)) $accounts[] = ['handle' => trim($m[1]), 'platform' => trim($m[2]), 'url' => (string) $f['url']];
                if ($detail !== '') $bios[] = $detail;
            }
            if (preg_match('/^([A-Z][A-Za-z.\'\- ]{2,40}?)\s*\(@/', $detail, $m)) $names[trim($m[1])] = true;
            if (preg_match_all('/\b([A-Z][a-z]+(?:[ -][A-Z][a-z]+)*),\s*([A-Z]{2})\b/', $detail, $mm, PREG_SET_ORDER)) foreach ($mm as $loc) $locations[$loc[0]] = true;
        } elseif ($cat === 'breach') {
            $bn = preg_match('/ in the (.+) breach$/', $title, $m) ? $m[1] : preg_replace('/ — .*$/', '', $title);
            $breaches[] = $bn;
            if (stripos($detail, 'password') !== false) $pwBreaches[] = $bn;
            if (preg_match('/\b(20\d\d)\b/', $detail, $m) && (int) $m[1] >= $thisYear - 3) $recent[$bn] = (int) $m[1];
            foreach (explode(',', preg_replace('/^\s*(19|20)\d\d\s*·?\s*/', '', $detail)) as $c) {
                $c = trim($c);
                if ($c !== '' && !preg_match('/^(19|20)\d\d$/', $c)) $classes[mb_strtolower($c)] = $c;
            }
        }
    }
    $names = array_keys($names); $locations = array_keys($locations); $classes = array_values($classes);
    $pwBreaches = array_values(array_unique($pwBreaches)); $breaches = array_values(array_unique($breaches));
    $hasClass = fn($kw) => (bool) array_filter($classes, fn($c) => stripos($c, $kw) !== false);

    $vectors = [];
    if ($pwBreaches) $vectors[] = ['sev' => 'high', 'name' => 'Credential stuffing', 'why' => 'A password of yours leaked in ' . count($pwBreaches) . ' breach(es) (' . implode(', ', array_slice($pwBreaches, 0, 3)) . '). If you reused it, every account sharing it is one login away.'];
    if ($p['emails'] && $breaches) $vectors[] = ['sev' => 'high', 'name' => 'Targeted phishing', 'why' => 'Your email plus a real breach you\'re in lets an attacker name-drop a service you actually use — see the simulation below.'];
    if ($p['phones']) $vectors[] = ['sev' => 'med', 'name' => 'SIM-swap & smishing', 'why' => 'Your number is known; combined with the personal facts above it helps a caller pass carrier verification or send convincing texts.'];
    if ($locations || $hasClass('address') || $hasClass('geographic') || $hasClass('birth')) $vectors[] = ['sev' => 'high', 'name' => 'Account-recovery / security-question bypass', 'why' => 'Enough personal facts are public to answer the "prove it\'s you" questions that reset accounts — see below.'];
    if (count($accounts) >= 3) $vectors[] = ['sev' => 'med', 'name' => 'Cross-platform profiling', 'why' => 'The same handle on ' . count($accounts) . ' sites lets someone stitch your full activity and social graph together.'];
    $sev = ['high' => 0, 'med' => 1, 'low' => 2];
    usort($vectors, fn($a, $b) => $sev[$a['sev']] <=> $sev[$b['sev']]);

    $svc = $recent ? array_key_first($recent) : ($breaches[0] ?? ($accounts[0]['platform'] ?? 'your account'));
    $phish = scan_phish_pretext($names[0] ?? '', $svc, $p['emails'][0] ?? 'you@example.com', !empty($pwBreaches));

    $kba = [
        ['q' => 'What city do you live in / were you born in?', 'ans' => ($locations || $hasClass('geographic') || $hasClass('address')) ? 'yes' : 'maybe',
         'src' => $locations ? implode(', ', array_slice($locations, 0, 2)) : ($hasClass('address') || $hasClass('geographic') ? 'exposed in a breach' : 'inferable from your accounts')],
        ['q' => 'What is your date of birth?', 'ans' => $hasClass('birth') ? 'yes' : 'maybe',
         'src' => $hasClass('birth') ? 'exposed in a breach' : 'often inferable from public records / social'],
        ['q' => 'What is your phone number?', 'ans' => ($p['phones'] || $hasClass('phone')) ? 'yes' : 'no',
         'src' => $p['phones'] ? 'on your profile / exposed' : ($hasClass('phone') ? 'exposed in a breach' : 'not seen in your data')],
        ['q' => 'Your full legal name / relatives?', 'ans' => ($names || $hasClass('name')) ? 'yes' : 'maybe',
         'src' => $names ? implode(', ', array_slice($names, 0, 2)) : ($hasClass('name') ? 'names exposed in a breach' : 'derivable from public records')],
        ['q' => 'Mother\'s maiden name?', 'ans' => 'maybe', 'src' => 'derivable from genealogy sites + your surname'],
        ['q' => 'First school / employer?', 'ans' => ($locations || $bios) ? 'maybe' : 'maybe', 'src' => $bios ? 'hinted in your public bios' : 'narrowed by your city + age'],
    ];

    return [
        'threat'      => scan_threat_get($uid),
        'threat_meta' => scan_threat_models()[scan_threat_get($uid)] ?? scan_threat_models()['general'],
        'has_scan'    => (bool) $latest,
        'identity'    => ['names' => $names, 'locations' => $locations, 'bios' => array_slice(array_values(array_unique($bios)), 0, 4)],
        'contact'     => ['emails' => $p['emails'], 'phones' => $p['phones'], 'domains' => $p['domains']],
        'usernames'   => $p['usernames'],
        'handles'     => array_slice($accounts, 0, 20),
        'credentials' => ['pw_breaches' => $pwBreaches, 'breaches' => $breaches, 'classes' => $classes],
        'vectors'     => $vectors,
        'phish'       => $phish,
        'kba'         => $kba,
    ];
}

/** Live, lightweight mail-security check for a domain (SPF + DMARC + DNSSEC) via DoH —
 *  the checkable slice of the hardening plan, verified fresh at receipt time. */
function scan_domain_mailsec(string $domainRaw): array {
    $domain = scan_domain_normalize($domainRaw);
    if ($domain === null) return ['domain' => $domainRaw, 'ok' => false];
    $doh = fn($n, $t) => ['url' => 'https://dns.google/resolve?name=' . rawurlencode($n) . '&type=' . $t, 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true, 'timeout' => 8];
    $res = scan_multi_get(['txt' => $doh($domain, 'TXT'), 'dmarc' => $doh('_dmarc.' . $domain, 'TXT'), 'a' => $doh($domain, 'A'), 'ds' => $doh($domain, 'DS')]);
    $spf = false;
    foreach (array_map(fn($t) => str_replace('"', '', $t), scan_doh_answers($res['txt'] ?? null, 16)) as $t) if (stripos($t, 'v=spf1') === 0) { $spf = true; break; }
    $dmarc = null;
    foreach (array_map(fn($t) => str_replace('"', '', $t), scan_doh_answers($res['dmarc'] ?? null, 16)) as $t) if (stripos($t, 'v=DMARC1') === 0 && preg_match('/\bp=([a-z]+)/i', $t, $m)) { $dmarc = strtolower($m[1]); break; }
    $dnssec = scan_doh_ad($res['a'] ?? null) || !empty(scan_doh_answers($res['ds'] ?? null, 43));
    return ['domain' => $domain, 'ok' => true, 'spf' => $spf, 'dmarc' => $dmarc, 'dnssec' => $dnssec,
            'enforced' => $spf && ($dmarc === 'reject' || $dmarc === 'quarantine')];
}

/** A timestamped, integrity-stamped snapshot of the user's exposure state — for their
 *  records / disputes. Live-verifies domain mail security at stamp time. The SHA-256 of
 *  the canonical payload lets the holder prove the record wasn't altered afterwards. */
function scan_exposure_receipt(int $uid, string $subject = '', bool $verifyDomains = true): array {
    $p = scan_profile_get($uid);
    $scan = scan_latest($uid);
    $findings = $scan ? scan_findings($uid, (int) $scan['id']) : [];
    $ex = scan_exposure($findings);
    $items = [];
    foreach ($findings as $f) { if (($f['status'] ?? 'new') === 'false') continue; $items[] = ['category' => (string) $f['category'], 'title' => (string) $f['title']]; }
    $brokerData = json_decode((string) @file_get_contents(__DIR__ . '/../assets/brokers.json'), true);
    $brokerTotal = count($brokerData['brokers'] ?? []);
    $brokerDone = count(array_filter(scan_checklist_get($uid, 'brokers'), fn($s) => $s === 'done'));
    $brokerVerified = count(array_filter(scan_checklist_get($uid, 'brokerverify'), fn($s) => $s === 'done'));
    $hardenData = json_decode((string) @file_get_contents(__DIR__ . '/../assets/harden.json'), true);
    $hardenTotal = 0; foreach (($hardenData['groups'] ?? []) as $g) $hardenTotal += count($g['items'] ?? []);
    $hardenDone = count(array_filter(scan_checklist_get($uid, 'harden'), fn($s) => $s === 'done'));
    $mailsec = [];
    if ($verifyDomains) foreach ($p['domains'] as $dom) $mailsec[] = scan_domain_mailsec($dom);
    $mon = scan_monitor_get($uid); $ct = scan_ct_get($uid);
    $ts = time();
    $payload = [
        'document'      => 'm190 finder — exposure receipt',
        'subject'       => $subject,
        'generated_at'  => gmdate('Y-m-d\TH:i:s\Z', $ts),
        'exposure'      => ['score' => $ex['score'], 'level' => $ex['level'], 'accounts' => $ex['accounts'], 'email_identity' => $ex['identity'], 'breaches' => $ex['breaches'], 'passwords_exposed' => (bool) $ex['pw'], 'breach_span' => $ex['span'], 'data_classes' => $ex['dataclasses']],
        'identifiers'   => ['usernames' => count($p['usernames']), 'emails' => count($p['emails']), 'phones' => count($p['phones']), 'domains' => count($p['domains'])],
        'findings'      => $items,
        'domain_mail_security' => $mailsec,
        'progress'      => ['brokers_total' => $brokerTotal, 'brokers_opted_out' => $brokerDone, 'brokers_verified_removed' => $brokerVerified, 'hardening_total' => $hardenTotal, 'hardening_done' => $hardenDone],
        'monitoring'    => ['breach' => (bool) $mon['enabled'], 'certificate_transparency' => (bool) $ct['enabled']],
        'last_scan_at'  => $scan ? gmdate('Y-m-d\TH:i:s\Z', (int) $scan['started_at']) : null,
    ];
    $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return ['payload' => $payload, 'hash' => hash('sha256', (string) $canonical), 'ts' => $ts];
}

/** Exposure score + account/breach counts for each of the user's scans, oldest→newest,
 *  for the exposure-over-time chart. */
function scan_timeline(int $uid, int $limit = 20): array {
    $out = [];
    foreach (array_reverse(scan_history($uid, $limit)) as $h) {
        $ex = scan_exposure(scan_findings($uid, (int) $h['id']));
        $out[] = [
            'ts' => (int) $h['started_at'], 'date' => date('Y-m-d', (int) $h['started_at']),
            'score' => (int) $ex['score'], 'level' => $ex['level'],
            'accounts' => (int) $ex['accounts'] + (int) $ex['identity'], 'breaches' => (int) $ex['breaches'],
        ];
    }
    return $out;
}
