<?php
// m190 pwn lab — backend
require_once __DIR__ . '/../config.php';
setCorsHeaders();
header('Content-Type: application/json');

// ----- Configuration -----
// Replace these three with the hashes printed by `make hashes` in Task 9.
// SHA-256 of the literal flag string (no trailing newline).
define('LAB_HASH_EASY',   '8d7a620e44f793f1f598b1a359d5aff50fe987a7e0ba55ce31c29607a5904261');
define('LAB_HASH_MEDIUM', 'ad93fbf2fd395dcd49782469e90fcc472e383efddc9267012ddeaf5b463b562e');
define('LAB_HASH_HARD',   'bf0ecfd5fc3a970ee55d748ba6f470bfa332122a7e0490127c430a80bad49a90');

define('LAB_DATA_DIR',     __DIR__ . '/data');
define('LAB_SESSIONS_FILE',LAB_DATA_DIR . '/sessions.json');
define('LAB_LEADERBOARD_FILE', LAB_DATA_DIR . '/leaderboard.json');
define('LAB_BINARIES_DIR', __DIR__ . '/binaries');
define('LAB_WRITEUPS_DIR', __DIR__ . '/writeups');
define('LAB_TIERS', ['easy', 'medium', 'hard']);
define('LAB_PREREQ', ['easy' => null, 'medium' => 'easy', 'hard' => 'medium']);

// ----- Helpers -----
function jerr(int $code, string $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
function jok(array $data) {
    echo json_encode(['ok' => true] + $data);
    exit;
}
function expectedHash(string $tier): ?string {
    return match ($tier) {
        'easy'   => LAB_HASH_EASY,
        'medium' => LAB_HASH_MEDIUM,
        'hard'   => LAB_HASH_HARD,
        default  => null,
    };
}
function loadSessions(): array { return readJsonFile(LAB_SESSIONS_FILE, []); }
function saveSessions(array $s): void { writeJsonFile(LAB_SESSIONS_FILE, $s); }
function loadLeaderboard(): array { return readJsonFile(LAB_LEADERBOARD_FILE, []); }
function saveLeaderboard(array $l): void { writeJsonFile(LAB_LEADERBOARD_FILE, $l); }

function getSession(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $sessions = loadSessions();
    return $sessions[$token] ?? null;
}
function putSession(string $token, array $session): void {
    $sessions = loadSessions();
    $sessions[$token] = $session;
    saveSessions($sessions);
}
function gcSessions(): void {
    // 1-in-50 chance: drop sessions older than 30 days with no solves.
    if (rand(1, 50) !== 1) return;
    $sessions = loadSessions();
    $cutoff = time() - 30 * 86400;
    $changed = false;
    foreach ($sessions as $tok => $s) {
        if (empty($s['solves']) && ($s['created_at'] ?? 0) < $cutoff) {
            unset($sessions[$tok]);
            $changed = true;
        }
    }
    if ($changed) saveSessions($sessions);
}

// ----- Action dispatcher -----
$action = $_REQUEST['action'] ?? '';
if (!is_dir(LAB_DATA_DIR)) {
    mkdir(LAB_DATA_DIR, 0755, true);
}

switch ($action) {
    case 'init':
        enforceRateLimit('lab_init', 5, 300);
        gcSessions();
        $token = bin2hex(random_bytes(32));
        $session = [
            'token' => $token,
            'ip_hash' => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'm190lab_salt'),
            'created_at' => time(),
            'solves' => [],
            'handle' => null,
        ];
        putSession($token, $session);
        jok([
            'token' => $token,
            'leaderboard' => array_slice(loadLeaderboard(), 0, 50),
        ]);
        break;

    case 'submit_flag':
        enforceRateLimit('lab_submit_' . ($_POST['token'] ?? ''), 10, 60);
        $token = $_POST['token'] ?? '';
        $tier = $_POST['tier'] ?? '';
        $flag = $_POST['flag'] ?? '';
        if (!in_array($tier, LAB_TIERS, true)) jerr(400, 'bad tier');
        $session = getSession($token);
        if (!$session) jerr(401, 'invalid token');
        // Prereq check: must have solved the previous tier (if any).
        $prereq = LAB_PREREQ[$tier];
        if ($prereq !== null && !in_array($prereq, $session['solves'], true)) {
            jerr(403, 'prerequisite tier not solved');
        }
        // Hash compare.
        $submitted_hash = hash('sha256', $flag);
        if (!hash_equals(expectedHash($tier), $submitted_hash)) {
            jok(['correct' => false]);
        }
        // Already solved? Don't double-credit.
        $already = in_array($tier, $session['solves'], true);
        if (!$already) {
            $session['solves'][] = $tier;
            $session['solved_at'][$tier] = time();
            putSession($token, $session);
        }
        // Determine next tier (linear progression).
        $idx = array_search($tier, LAB_TIERS, true);
        $next = ($idx !== false && isset(LAB_TIERS[$idx + 1])) ? LAB_TIERS[$idx + 1] : null;
        $writeup_path = LAB_WRITEUPS_DIR . '/' . $tier . '.html';
        $writeup_html = file_exists($writeup_path) ? file_get_contents($writeup_path) : '<p>(writeup missing)</p>';
        jok([
            'correct' => true,
            'tier' => $tier,
            'already_solved' => $already,
            'needs_handle' => ($session['handle'] === null && !$already),
            'next_tier_unlocked' => $next,
            'writeup_html' => $writeup_html,
        ]);
        break;

    case 'set_handle':
        enforceRateLimit('lab_handle_' . ($_POST['token'] ?? ''), 3, 60);
        $token = $_POST['token'] ?? '';
        $raw = $_POST['handle'] ?? '';
        $session = getSession($token);
        if (!$session) jerr(401, 'invalid token');
        if ($session['handle'] !== null) jerr(409, 'handle already set');
        $clean = sanitizeHandle($raw);
        if ($clean === null) jerr(400, 'invalid handle (3-16 chars, alphanumeric/_, no profanity)');
        $board = loadLeaderboard();
        foreach ($board as $entry) {
            if (strcasecmp($entry['handle'], $clean) === 0) {
                jerr(409, 'handle already taken');
            }
        }
        $session['handle'] = $clean;
        putSession($token, $session);
        // Backfill leaderboard entries for any tiers already solved.
        $now = time();
        $created = $session['created_at'];
        $cumulative = 0;
        foreach (LAB_TIERS as $tier) {
            if (!in_array($tier, $session['solves'], true)) break;
            $solved_at = $session['solved_at'][$tier] ?? $now;
            $tier_time = $solved_at - $created - $cumulative;
            $cumulative += $tier_time;
            $board[] = [
                'handle' => $clean,
                'tier' => $tier,
                'time_to_solve_seconds' => max(0, $tier_time),
                'completed_at' => $solved_at,
            ];
        }
        // Keep board sorted by completed_at ascending so newer solves append cleanly.
        usort($board, fn($a, $b) => $a['completed_at'] <=> $b['completed_at']);
        saveLeaderboard($board);
        jok(['handle' => $clean]);
        break;

    case 'fetch_binary':
        enforceRateLimit('lab_fetch_' . ($_GET['token'] ?? ''), 20, 60);
        $token = $_GET['token'] ?? '';
        $tier = $_GET['tier'] ?? '';
        if (!in_array($tier, LAB_TIERS, true)) jerr(400, 'bad tier');
        $session = getSession($token);
        if (!$session) jerr(401, 'invalid token');
        $prereq = LAB_PREREQ[$tier];
        if ($prereq !== null && !in_array($prereq, $session['solves'], true)) {
            error_log("lab: blocked fetch_binary tier=$tier token=$token (no prereq)");
            jerr(403, 'prerequisite tier not solved');
        }
        $path = LAB_BINARIES_DIR . '/crackme-' . $tier;
        if (!file_exists($path)) jerr(500, 'binary missing on server');
        // Stream raw bytes
        header_remove('Content-Type');
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Cross-Origin-Resource-Policy: same-origin');
        readfile($path);
        exit;

    case 'leaderboard':
        enforceRateLimit('lab_lb', 30, 60);
        $board = loadLeaderboard();
        // Newest 100 entries; UI does its own sorting.
        $board = array_slice($board, -100);
        jok(['leaderboard' => $board]);
        break;

    case 'writeup':
        $token = $_GET['token'] ?? '';
        $tier = $_GET['tier'] ?? '';
        if (!in_array($tier, LAB_TIERS, true)) jerr(400, 'bad tier');
        $session = getSession($token);
        if (!$session) jerr(401, 'invalid token');
        if (!in_array($tier, $session['solves'], true)) jerr(403, 'tier not solved');
        $path = LAB_WRITEUPS_DIR . '/' . $tier . '.html';
        if (!file_exists($path)) jerr(500, 'writeup missing');
        jok(['html' => file_get_contents($path)]);
        break;

    // Other actions added in subsequent tasks.
    default:
        jerr(400, 'unknown action');
}
