<?php
// Shared helpers for the /unmask/ "what your browser leaks" experience:
// SQLite store for the uniqueness stat, client-IP resolution, and keyless IP->geo
// via ipwho.is (same source the OSINT tool uses). No secrets, no API keys.
require_once __DIR__ . '/../config.php';   // rateLimited(), setCorsHeaders()

const UNMASK_DB  = __DIR__ . '/data/unmask.sqlite';
const UNMASK_TTL = 2592000;   // 30 days — stored prints auto-expire

function unmask_db(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    try {
        if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0755, true);
        $pdo = new PDO('sqlite:' . UNMASK_DB);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS prints (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            fp         TEXT NOT NULL,
            token      TEXT NOT NULL,
            city       TEXT, region TEXT, country TEXT, isp TEXT, gpu TEXT,
            created_at INTEGER NOT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fp ON prints(fp)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_created ON prints(created_at)");
    } catch (\Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** Best-effort real client IP (honours a CDN/proxy hop, falls back to REMOTE_ADDR). */
function unmask_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function unmask_is_public_ip(string $ip): bool {
    return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** IP -> geo via ipwho.is (keyless). Normalized array or null. Adds a soft "hosting/VPN"
 *  guess from the network name (ipwho.is free doesn't reliably flag it). */
function unmask_geo(string $ip): ?array {
    if (!unmask_is_public_ip($ip)) return null;
    $ch = curl_init('https://ipwho.is/' . rawurlencode($ip));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'm190-unmask/1.0',
    ]);
    // Windows PHP ships no CA bundle; use Git's when present (harmless on the Linux host).
    $localCa = 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt';
    if (is_file($localCa)) curl_setopt($ch, CURLOPT_CAINFO, $localCa);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string) $body, true);
    if (!is_array($j) || empty($j['success'])) return null;
    $conn = is_array($j['connection'] ?? null) ? $j['connection'] : [];
    $isp  = (string) ($conn['isp'] ?? $conn['org'] ?? '');
    $org  = (string) ($conn['org'] ?? $conn['isp'] ?? '');
    $hostingRe = '/hosting|cloud|\bvpn\b|\bvps\b|datacenter|data center|ovh|hetzner|digitalocean|amazon|\baws\b|google|azure|linode|vultr|leaseweb|m247|mullvad|proton|nord|express/i';
    return [
        'ip'           => $ip,
        'city'         => (string) ($j['city'] ?? ''),
        'region'       => (string) ($j['region'] ?? ''),
        'country'      => (string) ($j['country'] ?? ''),
        'country_code' => (string) ($j['country_code'] ?? ''),
        'lat'          => isset($j['latitude'])  ? (float) $j['latitude']  : null,
        'lon'          => isset($j['longitude']) ? (float) $j['longitude'] : null,
        'isp'          => $isp,
        'asn'          => (string) ($conn['asn'] ?? ''),
        'org'          => $org,
        'flag'         => (string) ($j['flag']['emoji'] ?? ''),
        'hosting'      => (bool) preg_match($hostingRe, $org . ' ' . $isp),
    ];
}
