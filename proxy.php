<?php
// Fetch proxy for browser.html. Hardened against SSRF:
//  - resolves the host ourselves and rejects ANY private/reserved A/AAAA record
//  - PINS curl to the exact validated IP (CURLOPT_RESOLVE) so a DNS-rebinding
//    race can't swap in an internal IP between validation and the request
//  - does NOT let curl auto-follow redirects; each hop is re-validated + re-pinned
//  - handles IPv6, IPv4-mapped IPv6, and IP-literal hosts
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET');
enforceRateLimit('proxy', 15, 60);

// True if an IP is one we must never let the server reach (private/reserved,
// loopback, link-local incl. cloud metadata 169.254.169.254, ULA, etc.).
function proxyBlockedIp(string $ip): bool {
    // Unwrap IPv4-mapped IPv6 (e.g. ::ffff:127.0.0.1) and re-check as IPv4.
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = substr($ip, 7);
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
    // Rejects 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, 0/8, ::1, fc00::/7, fe80::/10, ...
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return true;
    return false;
}

// All addresses a host resolves to (IPv4 + IPv6). An IP literal returns itself.
function proxyResolve(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) $ips = array_merge($ips, $v4);
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    return array_values(array_unique($ips));
}

// Validate + fetch one URL, following redirects manually (re-validating each hop).
// Returns [body, contentType, httpCode] on success or [null, errorMessage, code].
function proxyFetch(string $url, int $redirectsLeft): array {
    if (!preg_match('#^https?://#i', $url)) return [null, 'Invalid URL scheme', 400];
    $p = parse_url($url);
    if ($p === false || empty($p['host'])) return [null, 'Invalid URL', 400];

    $scheme = strtolower($p['scheme']);
    $host = trim($p['host'], '[]');
    $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);

    $ips = proxyResolve($host);
    if (!$ips) return [null, 'Could not resolve host', 400];
    // EVERY resolved address must be public — defends against records that mix a
    // decoy public IP with an internal one.
    foreach ($ips as $ip) {
        if (proxyBlockedIp($ip)) return [null, 'Blocked: private/reserved target', 403];
    }
    $pinIp = $ips[0];

    if (!function_exists('curl_init')) return [null, 'Proxy unavailable', 500];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, // handled manually below, with re-validation
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
        // Pin the connection to the IP we just validated (no re-resolution by curl).
        CURLOPT_RESOLVE        => ["$host:$port:$pinIp"],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL); // where a 3xx would send us
    curl_close($ch);

    if ($body === false) return [null, 'Failed to fetch URL', 502];

    // Re-validate the redirect target by recursing (new host → fresh checks + pin).
    if ($code >= 300 && $code < 400 && $redirectUrl && $redirectsLeft > 0) {
        return proxyFetch($redirectUrl, $redirectsLeft - 1);
    }
    return [$body, $ctype, $code];
}

$url = $_GET['url'] ?? '';
if ($url === '') {
    http_response_code(400);
    echo 'No URL provided';
    exit;
}

[$body, $second, $code] = proxyFetch($url, 3);
if ($body === null) {
    http_response_code($code ?: 400);
    echo $second; // error message
    exit;
}

header('Content-Type: ' . ($second ?: 'text/html'));
echo $body;
