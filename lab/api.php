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

    // Other actions added in subsequent tasks.
    default:
        jerr(400, 'unknown action');
}
