<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();
enforceRateLimit('github_latest', 30, 60);

$cacheFile = __DIR__ . '/github_cache.json';
$cacheTTL  = 3600; // 1 hour

// Serve cache if fresh
if (file_exists($cacheFile)) {
    $cache = readJsonFile($cacheFile);
    if (!empty($cache['fetched_at']) && time() - $cache['fetched_at'] < $cacheTTL) {
        echo json_encode($cache);
        exit;
    }
}

$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: logansandivar-site\r\n",
    'timeout' => 5,
]]);

$raw = @file_get_contents('https://api.github.com/users/m190Owner/repos?sort=created&direction=desc&per_page=10&type=public', false, $ctx);

if (!$raw) {
    // Return stale cache if available, else error
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

$repos = json_decode($raw, true);
if (!is_array($repos) || empty($repos)) {
    echo json_encode(['ok' => false]);
    exit;
}

// Pick the newest non-fork public repo
$repo = null;
foreach ($repos as $r) {
    if (!$r['fork'] && !$r['private']) {
        $repo = $r;
        break;
    }
}

if (!$repo) {
    echo json_encode(['ok' => false]);
    exit;
}

$data = [
    'ok'          => true,
    'name'        => $repo['name'],
    'full_name'   => $repo['full_name'],
    'description' => $repo['description'] ?? '',
    'url'         => $repo['html_url'],
    'stars'       => $repo['stargazers_count'],
    'fetched_at'  => time(),
];

writeJsonFile($cacheFile, $data);
echo json_encode($data);
