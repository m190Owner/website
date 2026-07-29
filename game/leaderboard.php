<?php
// Global leaderboard for RoF Survivors (/game/).
// Mirrors the lab leaderboard pattern: shared helpers from config.php,
// file-based JSON storage, rate limiting, and the shared handle sanitizer.
//
// GET  ?action=start           -> { ok:true, token }             (issue a signed run token)
// GET                          -> { ok:true, leaderboard:[...] }  (top 50, by time desc)
// POST name,time,kills,level,token,mode,players -> { ok:true, your_rank, leaderboard }
//
// ANTI-CHEAT: a run must present a server-signed HMAC token issued at run start.
// The token binds the run to a real start time, so the submitted survival time
// can't exceed the wall-clock elapsed since the token was issued, tokens can't be
// forged (HMAC), and each token is single-use (replay guard). This makes forging a
// top time require actually letting that much real time pass.

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
setCorsHeaders();

define('GAME_DATA_DIR', __DIR__ . '/data');

if (!is_dir(GAME_DATA_DIR)) {
    mkdir(GAME_DATA_DIR, 0755, true);
}

// Solo, co-op, and the date-keyed daily challenge keep separate boards.
$reqMode = $_REQUEST['mode'] ?? 'solo';
$mode = in_array($reqMode, ['coop', 'daily'], true) ? $reqMode : 'solo';
$LB_FILE = GAME_DATA_DIR . ($mode === 'coop' ? '/coop_leaderboard.json' : '/leaderboard.json');

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

// ---- Signed run tokens (HMAC) ----
define('HMAC_SECRET_FILE', GAME_DATA_DIR . '/hmac_secret.php');
define('USED_TOKENS_FILE', GAME_DATA_DIR . '/used_tokens.json');
define('TOKEN_TTL', 6 * 3600); // a run token is valid for up to 6 hours

// Persistent server secret. Stored as a .php file so a direct web request executes
// it (returning nothing) instead of leaking the raw value. Auto-created once.
function lbSecret(): string {
    if (!file_exists(HMAC_SECRET_FILE)) {
        $s = bin2hex(random_bytes(32));
        @file_put_contents(HMAC_SECRET_FILE, "<?php return '" . $s . "';\n", LOCK_EX);
    }
    $s = @include HMAC_SECRET_FILE;
    return is_string($s) && $s !== '' ? $s : 'insecure-fallback-secret';
}

function lbIssueToken(): string {
    $nonce = bin2hex(random_bytes(8));
    $ts = time();
    $sig = hash_hmac('sha256', $nonce . '.' . $ts, lbSecret());
    return $nonce . '.' . $ts . '.' . $sig;
}

// Returns null if the token is valid for a run of `$claimedTime` seconds, else an
// error string. Consumes the token (single use) on success.
function lbCheckToken(string $token, int $claimedTime): ?string {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return 'missing run token — start a fresh run';
    [$nonce, $ts, $sig] = $parts;
    if (!ctype_xdigit($nonce) || !ctype_digit($ts)) return 'invalid run token';
    $expected = hash_hmac('sha256', $nonce . '.' . $ts, lbSecret());
    if (!hash_equals($expected, $sig)) return 'invalid run token';

    $ts = (int) $ts;
    $now = time();
    if ($ts > $now + 5 || $now - $ts > TOKEN_TTL) return 'run token expired — start a fresh run';
    // Survival time cannot exceed the real wall-clock elapsed since run start.
    if ($claimedTime > ($now - $ts) + 15) return 'submitted time exceeds real elapsed';

    // Single-use replay guard.
    $used = readJsonFile(USED_TOKENS_FILE, []);
    if (!is_array($used)) $used = [];
    $used = array_filter($used, fn($t) => $now - (int) $t < TOKEN_TTL);
    if (isset($used[$nonce])) return 'run token already used';
    $used[$nonce] = $now;
    writeJsonFile(USED_TOKENS_FILE, $used);
    return null;
}

// Issue a token: GET/POST ?action=start
if (($_REQUEST['action'] ?? '') === 'start') {
    echo json_encode(['ok' => true, 'token' => lbIssueToken()]);
    exit;
}

// ---- Daily Challenge: one shared seed per UTC day, a board that resets daily,
// and per-player streaks. Stored date-keyed so old days age out. ----
define('DAILY_FILE',        GAME_DATA_DIR . '/daily.json');
define('DAILY_STREAK_FILE', GAME_DATA_DIR . '/daily_streaks.json');
define('DAILY_KEEP_DAYS',   30);

function handleDaily(string $method): void {
    $today = gmdate('Y-m-d');

    if ($method === 'POST') {
        enforceRateLimit('game_daily_submit', 10, 60);

        $name = sanitizeHandle($_POST['name'] ?? '');
        if ($name === null) jerr(400, 'invalid name (3-16 chars, letters/numbers/_, no profanity)');

        $time  = (int)($_POST['time'] ?? -1);
        $kills = (int)($_POST['kills'] ?? -1);
        $level = (int)($_POST['level'] ?? -1);
        if ($time < 0 || $time > 86400 || $kills < 0 || $kills > 1000000 || $level < 1 || $level > 10000) {
            jerr(400, 'invalid score');
        }

        // Same signed-token anti-cheat as the main board.
        $tokenErr = lbCheckToken($_POST['token'] ?? '', $time);
        if ($tokenErr !== null) jerr(403, $tokenErr);

        // The run must be for today's seed — no back- or forward-dating.
        if (($_POST['date'] ?? '') !== $today) {
            jerr(400, "that run isn't today's challenge — refresh and play today's seed");
        }

        $all   = readJsonFile(DAILY_FILE, []);
        if (!is_array($all)) $all = [];
        $board = $all[$today] ?? [];

        // Keep each player's best (longest) time for the day.
        $entry = ['name' => $name, 'time' => $time, 'kills' => $kills, 'level' => $level, 'at' => time()];
        $prev = null;
        foreach ($board as $i => $e) { if ($e['name'] === $name) { $prev = $i; break; } }
        if ($prev === null)                    $board[] = $entry;
        elseif ($board[$prev]['time'] < $time) $board[$prev] = $entry;

        $board = sortBoard($board);
        $all[$today] = $board;
        // Age out old days.
        if (count($all) > DAILY_KEEP_DAYS) { krsort($all); $all = array_slice($all, 0, DAILY_KEEP_DAYS, true); }
        writeJsonFile(DAILY_FILE, $all);
        if ($time >= 30) activity_log('🎮', $name . ' survived ' . gmdate('i:s', $time) . ' in today\'s Daily Challenge');

        // Streak: consecutive days (ending today) this name has played.
        $streaks = readJsonFile(DAILY_STREAK_FILE, []);
        if (!is_array($streaks)) $streaks = [];
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $cur = $streaks[$name] ?? null;
        if     ($cur && ($cur['last'] ?? '') === $today)     $streak = (int)$cur['streak'];       // already counted
        elseif ($cur && ($cur['last'] ?? '') === $yesterday) $streak = (int)$cur['streak'] + 1;
        else                                                 $streak = 1;
        $streaks[$name] = ['streak' => $streak, 'last' => $today];
        writeJsonFile(DAILY_STREAK_FILE, $streaks);

        $rank = null;
        foreach ($board as $i => $e) { if ($e['name'] === $name) { $rank = $i + 1; break; } }

        echo json_encode([
            'ok'          => true,
            'your_rank'   => $rank,
            'streak'      => $streak,
            'date'        => $today,
            'leaderboard' => array_slice($board, 0, 50),
        ]);
        exit;
    }

    // GET — today's board.
    $all   = readJsonFile(DAILY_FILE, []);
    $board = is_array($all) && isset($all[$today]) ? sortBoard($all[$today]) : [];
    echo json_encode(['ok' => true, 'date' => $today, 'leaderboard' => array_slice($board, 0, 50)]);
    exit;
}

if ($mode === 'daily') handleDaily($method);

if ($method === 'POST') {
    enforceRateLimit('game_lb_submit', 10, 60);

    $name = sanitizeHandle($_POST['name'] ?? '');
    if ($name === null) {
        jerr(400, 'invalid name (3-16 chars, letters/numbers/_, no profanity)');
    }

    $time  = (int)($_POST['time'] ?? -1);
    $kills = (int)($_POST['kills'] ?? -1);
    $level = (int)($_POST['level'] ?? -1);
    $players = max(1, min(8, (int)($_POST['players'] ?? 1)));

    // Range / sanity caps — reject impossible runs.
    if ($time < 0 || $time > 86400 ||      // <= 24h survived
        $kills < 0 || $kills > 1000000 ||
        $level < 1 || $level > 10000) {
        jerr(400, 'invalid score');
    }

    // Require a valid, single-use, time-bound server token for this run.
    $tokenErr = lbCheckToken($_POST['token'] ?? '', $time);
    if ($tokenErr !== null) jerr(403, $tokenErr);

    $board = readJsonFile($LB_FILE, []);
    $entry = ['name' => $name, 'time' => $time, 'kills' => $kills, 'level' => $level, 'at' => time()];
    if ($mode === 'coop') $entry['players'] = $players;
    $board[] = $entry;
    $board = sortBoard($board);
    $board = array_slice($board, 0, 200); // keep storage bounded
    writeJsonFile($LB_FILE, $board);
    if ($time >= 30) activity_log('🎮', $name . ' survived ' . gmdate('i:s', $time) . ' in RoF Survivors');

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
$board = sortBoard(readJsonFile($LB_FILE, []));
echo json_encode([
    'ok'          => true,
    'leaderboard' => array_slice($board, 0, 50),
]);
