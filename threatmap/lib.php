<?php
// Threat-map data layer. Pulls REAL, public, key-free threat feeds server-side,
// geolocates them to country centroids, and MERGES into a rolling cache so the
// globe stays populated even though any single feed pull is small. Everything is
// best-effort: a dead feed just means we serve the last good cache.
//
// Sources (all public, no API key):
//   - abuse.ch Feodo Tracker  — live botnet C2 servers (country + malware family)
//   - SANS ISC DShield        — top attacking IPs (country via per-IP enrichment)
require_once __DIR__ . '/../config.php';   // readJsonFile / writeJsonFile

define('TM_DATA_DIR',     __DIR__ . '/data');
define('TM_CACHE',        TM_DATA_DIR . '/threats.json');
define('TM_REFRESH_SEC',  900);            // refetch at most every 15 min
define('TM_WINDOW',       7 * 86400);      // keep threats seen within 7 days
define('TM_MAX',          400);            // hard cap on rendered nodes
define('TM_DSHIELD_BATCH', 8);             // new DShield IPs geolocated per refresh
define('TM_FEEDS', [
    'feodo'   => 'https://feodotracker.abuse.ch/downloads/ipblocklist.json',
    'dshield' => 'https://isc.sans.edu/api/topips/records/60?json',
]);

/** ISO2 -> [lat, lon, name]. Bundled asset (no runtime dependency). */
function tm_centroids(): array {
    static $c = null;
    if ($c === null) {
        $c = json_decode((string) @file_get_contents(__DIR__ . '/assets/centroids.json'), true);
        if (!is_array($c)) $c = [];
    }
    return $c;
}

/** Best-effort JSON GET. Verifies TLS; uses Git's CA bundle locally if present. */
function tm_fetch_json(string $url, int $timeout = 12) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'logansandivar-threatmap/1.0 (+https://logansandivar.com)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $localCa = 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt';
    if (is_file($localCa)) curl_setopt($ch, CURLOPT_CAINFO, $localCa);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/** Place a country code on the globe, with a little jitter so same-country nodes spread. */
function tm_place(string $cc, array &$rec): bool {
    $cc = strtoupper($cc);
    $c = tm_centroids()[$cc] ?? null;
    if (!$c) return false;
    $rec['cc']   = $cc;
    $rec['land'] = $c[2] ?? $cc;
    $rec['lat']  = round($c[0] + (random_int(-18, 18) / 10), 2);
    $rec['lon']  = round($c[1] + (random_int(-18, 18) / 10), 2);
    return true;
}

/** abuse.ch Feodo Tracker -> normalized threat records (has country + malware). */
function tm_from_feodo($json): array {
    if (!is_array($json)) return [];
    $out = [];
    foreach ($json as $r) {
        $ip = (string) ($r['ip_address'] ?? '');
        $cc = (string) ($r['country'] ?? '');
        if ($ip === '' || $cc === '') continue;
        $rec = [
            'ip'     => $ip,
            'kind'   => (string) ($r['malware'] ?? 'C2'),
            'cat'    => 'c2',
            'port'   => (int) ($r['port'] ?? 0),
            'asname' => mb_substr((string) ($r['as_name'] ?? ''), 0, 40),
            'src'    => 'Feodo Tracker',
            'status' => (string) ($r['status'] ?? ''),
        ];
        if (tm_place($cc, $rec)) $out[$ip] = $rec;
    }
    return $out;
}

/** SANS DShield top attackers -> records, enriching country per-IP (bounded batch). */
function tm_from_dshield($json, array $known): array {
    if (!is_array($json)) return [];
    $out = [];
    $looked = 0;
    foreach ($json as $r) {
        $ip = (string) ($r['source'] ?? '');
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) continue;
        if (isset($known[$ip])) continue;                       // already geolocated before
        if ($looked >= TM_DSHIELD_BATCH) break;                 // bound the per-refresh work
        $looked++;
        $d = tm_fetch_json('https://isc.sans.edu/api/ip/' . rawurlencode($ip) . '?json', 5);
        $cc = strtoupper((string) ($d['ip']['ascountry'] ?? ''));
        if ($cc === '' || strlen($cc) !== 2) continue;
        $rec = [
            'ip'      => $ip,
            'kind'    => 'Scanner / attacker',
            'cat'     => 'attacker',
            'port'    => 0,
            'asname'  => mb_substr((string) ($d['ip']['asname'] ?? ''), 0, 40),
            'src'     => 'DShield',
            'reports' => (int) ($r['reports'] ?? 0),
        ];
        if (tm_place($cc, $rec)) $out[$ip] = $rec;
    }
    return $out;
}

/** Throttled fetch + merge into the rolling cache. Returns the cache array. */
function tm_refresh(bool $force = false): array {
    $cache = readJsonFile(TM_CACHE, ['updated' => 0, 'threats' => []]);
    if (!is_array($cache)) $cache = ['updated' => 0, 'threats' => []];
    if (!isset($cache['threats']) || !is_array($cache['threats'])) $cache['threats'] = [];

    $now = time();
    if (!$force && ($now - (int) ($cache['updated'] ?? 0)) < TM_REFRESH_SEC) return $cache;

    $known = $cache['threats'];
    $fresh = tm_from_feodo(tm_fetch_json(TM_FEEDS['feodo']));
    $fresh += tm_from_dshield(tm_fetch_json(TM_FEEDS['dshield']), $known);

    // Merge: refresh 'seen' for anything present now; keep older ones until they age out.
    foreach ($fresh as $ip => $rec) {
        $rec['seen'] = $now;
        $cache['threats'][$ip] = $rec;
    }
    // Age out + cap.
    foreach ($cache['threats'] as $ip => $rec) {
        if (($now - (int) ($rec['seen'] ?? 0)) > TM_WINDOW) unset($cache['threats'][$ip]);
    }
    if (count($cache['threats']) > TM_MAX) {
        uasort($cache['threats'], fn($a, $b) => ($b['seen'] ?? 0) <=> ($a['seen'] ?? 0));
        $cache['threats'] = array_slice($cache['threats'], 0, TM_MAX, true);
    }
    $cache['updated'] = $now;
    writeJsonFile(TM_CACHE, $cache);
    return $cache;
}

/** Public view: the threat list + metadata for the browser. */
function tm_view(): array {
    $cache = tm_refresh();
    $threats = array_values($cache['threats'] ?? []);
    // Newest first; the client caps how many it animates at once.
    usort($threats, fn($a, $b) => ($b['seen'] ?? 0) <=> ($a['seen'] ?? 0));
    $cats = [];
    foreach ($threats as $t) $cats[$t['cat'] ?? 'other'] = ($cats[$t['cat'] ?? 'other'] ?? 0) + 1;
    return [
        'updated' => (int) ($cache['updated'] ?? 0),
        'count'   => count($threats),
        'cats'    => $cats,
        'threats' => $threats,
    ];
}
