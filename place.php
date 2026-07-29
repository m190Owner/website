<?php
// Shared pixel canvas ("Canvas") — an r/place-style board everyone paints on
// together, plus live presence (other visitors shown as moving cursors). Polling
// based, like cursors.php/chat.php, so it runs on plain shared hosting.
//
// GET  (no action)          -> full state: {w,h,palette,grid(base64),now,epoch,cooldown,isOwner}
// GET  ?action=poll&since=  -> {now,epoch,pixels:[{i,c}],users:{id:{x,y,n,c}},count}
//         (also heartbeats the caller's own cursor if id/px/py/pn/pc are sent)
// POST ?action=place        -> place one pixel {x,y,c}; enforces a per-IP cooldown
// POST ?action=clear        -> owner only: wipe the board (bumps epoch)

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();

define('PLACE_DIR', __DIR__ . '/place_data');
if (!is_dir(PLACE_DIR)) mkdir(PLACE_DIR, 0755, true);
define('GRID_FILE',     PLACE_DIR . '/grid.bin');
define('CHANGES_FILE',  PLACE_DIR . '/changes.json');
define('PRESENCE_FILE', PLACE_DIR . '/presence.json');
define('CD_FILE',       PLACE_DIR . '/cooldowns.json');
define('META_FILE',     PLACE_DIR . '/meta.json');

const PW = 200, PH = 200;
const COOLDOWN_MS = 4000;      // per-IP wait between pixels
const CHANGES_RETAIN_MS = 120000;
const PRESENCE_TTL = 5;        // seconds a cursor stays "online" without a heartbeat

// Classic 16-colour palette (index 0 = white = background).
const PALETTE = [
  '#ffffff','#e4e4e4','#888888','#222222','#ffa7d1','#e50000','#e59500','#a06a42',
  '#e5d900','#94e044','#02be01','#00d3dd','#0083c7','#0000ea','#cf6ee4','#820080',
];

function nowMs(): float { return round(microtime(true) * 1000); }

function gridEnsure(): void {
    if (!file_exists(GRID_FILE)) file_put_contents(GRID_FILE, str_repeat("\x00", PW * PH), LOCK_EX);
}

function metaGet(): array {
    $m = readJsonFile(META_FILE, ['epoch' => 1]);
    return is_array($m) ? $m : ['epoch' => 1];
}

function appendChange(int $i, int $c): void {
    $ch = readJsonFile(CHANGES_FILE, []);
    if (!is_array($ch)) $ch = [];
    $cut = nowMs() - CHANGES_RETAIN_MS;
    $ch = array_values(array_filter($ch, fn($e) => ($e['t'] ?? 0) > $cut));
    $ch[] = ['i' => $i, 'c' => $c, 't' => nowMs()];
    if (count($ch) > 6000) $ch = array_slice($ch, -6000);
    writeJsonFile(CHANGES_FILE, $ch);
}

function prunePresence(array $p): array {
    $now = time();
    foreach ($p as $k => $v) if ($now - ($v['t'] ?? 0) > PRESENCE_TTL) unset($p[$k]);
    return $p;
}

function cleanName(string $raw): string {
    $n = trim(preg_replace('/[^a-zA-Z0-9_ ]/', '', $raw));
    $n = substr($n, 0, 16);
    if ($n === '' || containsProfanity($n)) $n = 'anon';
    return $n;
}

$action  = $_REQUEST['action'] ?? '';
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
gridEnsure();

$isOwner = OWNER_IP !== '' && ($_SERVER['REMOTE_ADDR'] ?? '') === OWNER_IP;

// ---- Place a pixel ----
if ($action === 'place' && $method === 'POST') {
    enforceRateLimit('place_pixel', 90, 60); // coarse spam guard on top of the cooldown
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $x = (int)($in['x'] ?? -1); $y = (int)($in['y'] ?? -1); $c = (int)($in['c'] ?? -1);
    if ($x < 0 || $x >= PW || $y < 0 || $y >= PH || $c < 0 || $c >= count(PALETTE)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad pixel']); exit;
    }

    $ip  = $_SERVER['REMOTE_ADDR'] ?? '?';
    $now = nowMs();
    $cds = readJsonFile(CD_FILE, []);
    if (!is_array($cds)) $cds = [];
    // prune stale cooldown records
    foreach ($cds as $k => $t) if ($now - $t > COOLDOWN_MS * 4) unset($cds[$k]);
    $wait = COOLDOWN_MS - ($now - ($cds[$ip] ?? 0));
    if (!$isOwner && $wait > 0) { echo json_encode(['ok' => false, 'wait' => (int)ceil($wait)]); exit; }

    $fp = fopen(GRID_FILE, 'r+b');
    if ($fp) { flock($fp, LOCK_EX); fseek($fp, $y * PW + $x); fwrite($fp, chr($c)); flock($fp, LOCK_UN); fclose($fp); }
    $cds[$ip] = $now; writeJsonFile(CD_FILE, $cds);
    appendChange($y * PW + $x, $c);
    if (random_int(1, 12) === 1) activity_log('🎨', 'the canvas is being painted', 'pixel', 45);
    echo json_encode(['ok' => true, 'cooldown' => COOLDOWN_MS]);
    exit;
}

// ---- Owner: clear the board ----
if ($action === 'clear' && $method === 'POST') {
    if (!$isOwner) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'not owner']); exit; }
    file_put_contents(GRID_FILE, str_repeat("\x00", PW * PH), LOCK_EX);
    writeJsonFile(CHANGES_FILE, []);
    $m = metaGet(); $m['epoch'] = (int)($m['epoch'] ?? 1) + 1; writeJsonFile(META_FILE, $m);
    echo json_encode(['ok' => true, 'epoch' => $m['epoch']]);
    exit;
}

// ---- Poll: presence heartbeat + pixel deltas + online users ----
if ($action === 'poll') {
    $since = (float)($_REQUEST['since'] ?? 0);
    $self  = preg_replace('/[^a-zA-Z0-9]/', '', $_REQUEST['id'] ?? '');

    $pres = readJsonFile(PRESENCE_FILE, []);
    if (!is_array($pres)) $pres = [];
    if ($self !== '') {
        $pres[$self] = [
            'x' => (float)($_REQUEST['px'] ?? 0),
            'y' => (float)($_REQUEST['py'] ?? 0),
            'n' => cleanName($_REQUEST['pn'] ?? 'anon'),
            'c' => max(0, min(count(PALETTE) - 1, (int)($_REQUEST['pc'] ?? 0))),
            't' => time(),
        ];
    }
    $pres = prunePresence($pres);
    writeJsonFile(PRESENCE_FILE, $pres);

    $users = [];
    foreach ($pres as $k => $v) {
        if ($k === $self) continue; // client already knows itself
        $users[$k] = ['x' => $v['x'], 'y' => $v['y'], 'n' => $v['n'], 'c' => $v['c']];
    }

    $ch = readJsonFile(CHANGES_FILE, []);
    $pixels = [];
    if (is_array($ch)) foreach ($ch as $e) if (($e['t'] ?? 0) > $since) $pixels[] = ['i' => $e['i'], 'c' => $e['c']];

    echo json_encode([
        'now'    => nowMs(),
        'epoch'  => (int)(metaGet()['epoch'] ?? 1),
        'pixels' => $pixels,
        'users'  => (object)$users,
        'count'  => count($pres),
    ]);
    exit;
}

// ---- Default: full board state (initial load) ----
echo json_encode([
    'w'        => PW,
    'h'        => PH,
    'palette'  => PALETTE,
    'grid'     => base64_encode(file_get_contents(GRID_FILE)),
    'now'      => nowMs(),
    'epoch'    => (int)(metaGet()['epoch'] ?? 1),
    'cooldown' => COOLDOWN_MS,
    'isOwner'  => $isOwner,
]);
