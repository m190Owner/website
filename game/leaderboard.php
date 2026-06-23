<?php
// Global leaderboard for RoF Survivors (/game/).
// Mirrors the lab leaderboard pattern: shared helpers from config.php,
// file-based JSON storage, rate limiting, and the shared handle sanitizer.
//
// GET  -> { ok:true, leaderboard:[ {name,time,kills,level,at}, ... ] }  (top 50, by time desc)
// POST -> name,time,kills,level  ->  { ok:true, your_rank:N, leaderboard:[...] }
//
// NOTE: scores are submitted by the browser, so a determined user could forge a
// run — unavoidable for a client-side game. We rate-limit and range-cap values
// to stop casual tampering; that is the intended bar for a fun feature.

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
setCorsHeaders();

define('GAME_DATA_DIR', __DIR__ . '/data');
define('GAME_LB_FILE', GAME_DATA_DIR . '/leaderboard.json');

if (!is_dir(GAME_DATA_DIR)) {
    mkdir(GAME_DATA_DIR, 0755, true);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// Sort by survival time desc, tiebreak by kills desc.
function sortBoard(array $b): array {
    usort($b, function ($a, $c) {
        if ($a['time'] !== $c['time']) return $c['time'] <=> $a['time'];
        if ($a['kills'] !== $c['kills']) return $c['kills'] <=> $a['kills'];
        return ($a['at'] ?? 0) <=> ($c['at'] ?? 0); // earlier run wins ties
    });
    return $b;
}

function jerr(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($method === 'POST') {
    enforceRateLimit('game_lb_submit', 10, 60);

    $name = sanitizeHandle($_POST['name'] ?? '');
    if ($name === null) {
        jerr(400, 'invalid name (3-16 chars, letters/numbers/_, no profanity)');
    }

    $time  = (int)($_POST['time'] ?? -1);
    $kills = (int)($_POST['kills'] ?? -1);
    $level = (int)($_POST['level'] ?? -1);

    // Range / sanity caps — reject impossible runs.
    if ($time < 0 || $time > 86400 ||      // <= 24h survived
        $kills < 0 || $kills > 1000000 ||
        $level < 1 || $level > 10000) {
        jerr(400, 'invalid score');
    }

    $board = readJsonFile(GAME_LB_FILE, []);
    $entry = ['name' => $name, 'time' => $time, 'kills' => $kills, 'level' => $level, 'at' => time()];
    $board[] = $entry;
    $board = sortBoard($board);
    $board = array_slice($board, 0, 200); // keep storage bounded
    writeJsonFile(GAME_LB_FILE, $board);

    // Rank of the run we just inserted (first row matching this exact entry).
    $rank = null;
    foreach ($board as $i => $e) {
        if ($e['name'] === $name && $e['time'] === $time &&
            $e['kills'] === $kills && ($e['at'] ?? 0) === $entry['at']) {
            $rank = $i + 1;
            break;
        }
    }

    echo json_encode([
        'ok'          => true,
        'your_rank'   => $rank,
        'leaderboard' => array_slice($board, 0, 50),
    ]);
    exit;
}

// GET — current board.
$board = sortBoard(readJsonFile(GAME_LB_FILE, []));
echo json_encode([
    'ok'          => true,
    'leaderboard' => array_slice($board, 0, 50),
]);
