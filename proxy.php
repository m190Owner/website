<?php
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET');
enforceRateLimit('proxy', 15, 60);

$url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($url)) {
    http_response_code(400);
    echo 'No URL provided';
    exit;
}

// Only allow http/https
if (!preg_match('/^https?:\/\//i', $url)) {
    http_response_code(400);
    echo 'Invalid URL';
    exit;
}

// Block SSRF: resolve hostname and reject internal/private IPs
$parsed = parse_url($url);
$host = $parsed['host'] ?? '';

if ($host === '') {
    http_response_code(400);
    echo 'Invalid URL';
    exit;
}

// Block obvious internal hostnames
$blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', 'metadata.google.internal'];
if (in_array(strtolower($host), $blockedHosts)) {
    http_response_code(403);
    echo 'Blocked host';
    exit;
}

// Resolve DNS and block private/reserved IP ranges
$ip = gethostbyname($host);
if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    echo 'Could not resolve host';
    exit;
}

if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    http_response_code(403);
    echo 'Blocked: private/reserved IP range';
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo 'Failed to fetch URL';
    exit;
}

header('Content-Type: ' . ($contentType ?: 'text/html'));
echo $response;
