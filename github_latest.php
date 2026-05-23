<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
// Always revalidate on the client so each page load reflects current repos.
header('Cache-Control: no-cache, no-store, must-revalidate');
setCorsHeaders();
enforceRateLimit('github_latest', 30, 60);

$cacheFile = __DIR__ . '/github_cache.json';
// Short TTL so newly created public repos show up almost immediately on
// page load. Kept at 60s (not 0) because unauthenticated GitHub API allows
// only 60 requests/hour per IP — this caps us at one upstream call per
// minute regardless of traffic, and stale cache is served if that fails.
$cacheTTL  = 60;

// Serve cache if fresh
if (file_exists($cacheFile)) {
    $cache = readJsonFile($cacheFile);
    if (!empty($cache['fetched_at']) && time() - $cache['fetched_at'] < $cacheTTL) {
        echo json_encode($cache);
        exit;
    }
}

$ch = curl_init('https://api.github.com/users/m190Owner/repos?sort=created&direction=desc&per_page=100&type=public');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => ['User-Agent: logansandivar-site'],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$raw || $code !== 200) {
    // Return stale cache if available
    echo file_exists($cacheFile) ? file_get_contents($cacheFile) : json_encode(['ok' => false]);
    exit;
}

$repos = json_decode($raw, true);
if (!is_array($repos) || empty($repos)) {
    echo json_encode(['ok' => false]);
    exit;
}

// Keep only public, non-fork repos (newest first — API already sorts by created desc).
$public = [];
foreach ($repos as $r) {
    if (empty($r['fork']) && empty($r['private'])) {
        $public[] = [
            'name'        => $r['name'],
            'full_name'   => $r['full_name'],
            'description' => $r['description'] ?? '',
            'url'         => $r['html_url'],
            'stars'       => $r['stargazers_count'] ?? 0,
            'language'    => $r['language'] ?? '',
        ];
    }
}

if (!$public) {
    echo json_encode(['ok' => false]);
    exit;
}

$latest = $public[0];
$data = [
    'ok'          => true,
    // Top-level latest repo (used by the announcement banner).
    'name'        => $latest['name'],
    'full_name'   => $latest['full_name'],
    'description' => $latest['description'],
    'url'         => $latest['url'],
    'stars'       => $latest['stars'],
    // Full public-repo list (used by the projects grid).
    'repos'       => $public,
    'fetched_at'  => time(),
];

writeJsonFile($cacheFile, $data);
echo json_encode($data);
