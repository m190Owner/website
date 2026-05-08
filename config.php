<?php
// ==============================================
// SHARED SECURITY CONFIG
// ==============================================

define('ALLOWED_ORIGIN', 'https://logansandivar.com');
define('OWNER_IP', getenv('OWNER_IP') ?: '');
define('RATE_LIMIT_DIR', __DIR__ . '/rate_limits');

if (!is_dir(RATE_LIMIT_DIR)) {
    mkdir(RATE_LIMIT_DIR, 0755, true);
}

// Set CORS headers for the allowed origin only
function setCorsHeaders() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === ALLOWED_ORIGIN || $origin === 'http://localhost' || str_starts_with($origin, 'http://localhost:')) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    }
    header('Access-Control-Allow-Methods: GET, POST');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Rate limiting: returns true if the request should be blocked
function rateLimited(string $endpoint, int $maxRequests = 30, int $windowSeconds = 60): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = md5($ip . $endpoint);
    $file = RATE_LIMIT_DIR . '/' . $key;

    $now = time();
    $requests = [];

    if (file_exists($file)) {
        $data = file_get_contents($file);
        $requests = json_decode($data, true) ?: [];
        // Remove expired entries
        $requests = array_values(array_filter($requests, fn($t) => $now - $t < $windowSeconds));
    }

    if (count($requests) >= $maxRequests) {
        return true;
    }

    $requests[] = $now;
    file_put_contents($file, json_encode($requests), LOCK_EX);
    return false;
}

// Clean up old rate limit files (call occasionally)
function cleanRateLimits() {
    $files = glob(RATE_LIMIT_DIR . '/*');
    $now = time();
    foreach ($files as $f) {
        if ($now - filemtime($f) > 120) {
            @unlink($f);
        }
    }
}

// Safe JSON file read with shared lock
function readJsonFile(string $path, $default = []) {
    if (!file_exists($path)) return $default;
    $fp = fopen($path, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $data = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $decoded = json_decode($data, true);
    return $decoded !== null ? $decoded : $default;
}

// Safe JSON file write with exclusive lock
function writeJsonFile(string $path, $data) {
    file_put_contents($path, json_encode($data), LOCK_EX);
}

// Block request if rate limited
function enforceRateLimit(string $endpoint, int $max = 30, int $window = 60) {
    if (rateLimited($endpoint, $max, $window)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }
}

// Probabilistic rate limit cleanup (1 in 50 requests)
if (rand(1, 50) === 1) {
    cleanRateLimits();
}

/**
 * Profanity / blocklist check.
 * Returns true if $text contains any blocked word (case-insensitive,
 * with l33t-ish substitutions: 0->o, 1->i, 3->e, 4->a, 5->s, 7->t).
 */
function containsProfanity(string $text): bool {
    static $blocked = null;
    if ($blocked === null) {
        // Keep this list short and obvious; extend as needed.
        $blocked = [
            'fuck','shit','cunt','nigger','faggot','retard','rape',
            'pussy','bitch','dick','cock','asshole','whore','slut',
        ];
    }
    $normalized = strtolower($text);
    $normalized = strtr($normalized, ['0'=>'o','1'=>'i','3'=>'e','4'=>'a','5'=>'s','7'=>'t','@'=>'a','$'=>'s']);
    $normalized = preg_replace('/[^a-z]/', '', $normalized);
    foreach ($blocked as $word) {
        if (strpos($normalized, $word) !== false) return true;
    }
    return false;
}

/**
 * Validate a leaderboard handle.
 * Returns the trimmed handle on success, null on failure.
 * Rules: 3-16 chars, [a-zA-Z0-9_], no profanity.
 */
function sanitizeHandle(string $raw): ?string {
    $h = trim($raw);
    if (!preg_match('/^[a-zA-Z0-9_]{3,16}$/', $h)) return null;
    if (containsProfanity($h)) return null;
    return $h;
}
