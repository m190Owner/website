<?php
// Homepage "MMO" — now a server-authoritative PvP arena. Every visitor is an
// emoji avatar; click another player to damage them. Power grows the longer you
// survive (resets on death); survive 60s straight and you nuke everyone and win
// the round. Polling based, like cursors.php, but all combat/round logic is
// resolved server-side under an exclusive lock so clients can't cheat.
//
// POST (no action)     -> heartbeat: presence + emote/chat; returns full state
// POST ?action=attack  -> {target}: damage a player you're fighting
// Response: { now, players:{id:{x,y,n,a,hp,dead,power,aliveMs,respawnMs,e,et,m,mt}},
//             round:{id,phase,winner,interMs}, count }

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();
enforceRateLimit('world', 400, 60); // fast polling + attacks

$file = __DIR__ . '/world_data.json';

const WORLD_TTL_MS   = 6000;
const MAX_HP         = 100;
const WIN_MS         = 60000;   // survive this long alive -> nuke + win
const RESPAWN_MS     = 5000;
const INTERMISSION_MS = 10000;
const BASE_DMG       = 6;       // damage per hit at power 1
const POWER_STEP_MS  = 10000;   // +1 power per this many ms alive
const POWER_MAX      = 8;
const ATTACK_CD_MS   = 300;     // min ms between a player's attacks

function nowMs(): float { return round(microtime(true) * 1000); }

function wclean(string $s, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F<>&"\']/u', '', $s);
    return trim(mb_substr($s, 0, $max, 'UTF-8'));
}

function powerOf(array $p, float $now): int {
    if (!empty($p['dead'])) return 1;
    return (int) min(POWER_MAX, 1 + floor(($now - ($p['sp'] ?? $now)) / POWER_STEP_MS));
}

// Respawns, the 60s-survival nuke/win, and round rollover — all authoritative.
function resolve(array &$d, float $now): void {
    if (!isset($d['r']) || !is_array($d['r'])) {
        $d['r'] = ['id' => 1, 'phase' => 'active', 'win' => null, 'wn' => '', 'ends' => 0];
    }
    $r = &$d['r'];
    $players = &$d['p'];

    if ($r['phase'] === 'active') {
        // Respawn anyone whose death timer elapsed.
        foreach ($players as &$p) {
            if (!empty($p['dead']) && $now >= ($p['du'] ?? 0)) {
                $p['dead'] = 0; $p['hp'] = MAX_HP; $p['sp'] = $now; $p['la'] = 0;
            }
        }
        unset($p);

        // Win: the longest-surviving alive player past WIN_MS nukes everyone else.
        $winner = null; $best = null;
        foreach ($players as $id => $p) {
            if (empty($p['dead']) && ($now - ($p['sp'] ?? $now)) >= WIN_MS) {
                $sp = $p['sp'] ?? $now;
                if ($best === null || $sp < $best || ($sp === $best && $id < $winner)) { $best = $sp; $winner = $id; }
            }
        }
        if ($winner !== null) {
            foreach ($players as $id => &$p) {
                if ($id !== $winner) { $p['dead'] = 1; $p['hp'] = 0; $p['du'] = $now + INTERMISSION_MS; }
            }
            unset($p);
            $r['phase'] = 'inter';
            $r['win']   = $winner;
            $r['wn']    = $players[$winner]['n'] ?? 'someone';
            $r['ends']  = $now + INTERMISSION_MS;
        }
    } elseif ($r['phase'] === 'inter' && $now >= ($r['ends'] ?? 0)) {
        // New round: everyone present respawns fresh.
        $r['id']    = (int)($r['id'] ?? 1) + 1;
        $r['phase'] = 'active';
        $r['win']   = null; $r['wn'] = '';
        foreach ($players as &$p) { $p['dead'] = 0; $p['hp'] = MAX_HP; $p['sp'] = $now; $p['la'] = 0; }
        unset($p);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
$in = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
$action = $_REQUEST['action'] ?? '';
$id = preg_replace('/[^a-zA-Z0-9]/', '', $in['id'] ?? '');
$now = nowMs();

$fp = fopen($file, 'c+');
$d = ['p' => [], 'r' => null];
if ($fp) {
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $dec = json_decode($raw ?: '', true);
    if (is_array($dec) && isset($dec['p'])) $d = $dec;
    if (!isset($d['p']) || !is_array($d['p'])) $d['p'] = [];

    // Upsert the caller's presence (and init combat state if new).
    if ($id !== '') {
        $p = $d['p'][$id] ?? null;
        if ($p === null) {
            $p = ['hp' => MAX_HP, 'dead' => 0, 'sp' => $now, 'du' => 0, 'la' => 0];
        }
        $p['x'] = max(0, min(1, (float)($in['x'] ?? 0.5)));
        $p['y'] = max(0, min(1, (float)($in['y'] ?? 0.5)));
        $name = wclean($in['n'] ?? '', 16);
        $p['n'] = ($name === '' || containsProfanity($name)) ? 'guest' : $name;
        $av = wclean($in['a'] ?? '', 2);
        $p['a'] = $av === '' ? '🙂' : $av;
        $et = (float)($in['et'] ?? 0);
        if ($et > 0) { $p['e'] = wclean($in['e'] ?? '', 2); $p['et'] = $et; }
        $mt = (float)($in['mt'] ?? 0);
        if ($mt > 0) { $msg = wclean($in['m'] ?? '', 120); if ($msg !== '' && !containsProfanity($msg)) { $p['m'] = $msg; $p['mt'] = $mt; } }
        $p['t'] = $now;
        $d['p'][$id] = $p;
    }

    resolve($d, $now);

    // Attack resolution.
    if ($action === 'attack' && $id !== '') {
        $tid = preg_replace('/[^a-zA-Z0-9]/', '', $in['target'] ?? '');
        $atk = $d['p'][$id] ?? null;
        $tgt = $d['p'][$tid] ?? null;
        if ($d['r']['phase'] === 'active' && $tid !== $id && $atk && $tgt
            && empty($atk['dead']) && empty($tgt['dead'])
            && $now - ($atk['la'] ?? 0) >= ATTACK_CD_MS) {
            $dmg = BASE_DMG * powerOf($atk, $now);
            $tgt['hp'] = ($tgt['hp'] ?? MAX_HP) - $dmg;
            $atk['la'] = $now;
            if ($tgt['hp'] <= 0) { $tgt['hp'] = 0; $tgt['dead'] = 1; $tgt['du'] = $now + RESPAWN_MS; }
            $d['p'][$id] = $atk; $d['p'][$tid] = $tgt;
        }
    }

    // Prune the departed.
    foreach ($d['p'] as $k => $v) if ($now - ($v['t'] ?? 0) > WORLD_TTL_MS) unset($d['p'][$k]);

    rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($d)); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
}

// ---- Build the public response ----
$players = [];
foreach ($d['p'] as $k => $p) {
    $players[$k] = [
        'x' => $p['x'] ?? 0.5, 'y' => $p['y'] ?? 0.5, 'n' => $p['n'] ?? 'guest', 'a' => $p['a'] ?? '🙂',
        'hp' => (int) round($p['hp'] ?? MAX_HP), 'dead' => !empty($p['dead']) ? 1 : 0,
        'power' => powerOf($p, $now),
        'aliveMs' => !empty($p['dead']) ? 0 : (int)($now - ($p['sp'] ?? $now)),
        'respawnMs' => !empty($p['dead']) ? (int) max(0, ($p['du'] ?? 0) - $now) : 0,
    ];
    if (isset($p['e']))  { $players[$k]['e'] = $p['e']; $players[$k]['et'] = $p['et']; }
    if (isset($p['m']))  { $players[$k]['m'] = $p['m']; $players[$k]['mt'] = $p['mt']; }
}
$r = $d['r'] ?? ['id' => 1, 'phase' => 'active', 'wn' => '', 'ends' => 0];
echo json_encode([
    'now'     => $now,
    'players' => (object)$players,
    'round'   => ['id' => $r['id'] ?? 1, 'phase' => $r['phase'] ?? 'active', 'winner' => $r['wn'] ?? '',
                  'interMs' => ($r['phase'] ?? '') === 'inter' ? (int) max(0, ($r['ends'] ?? 0) - $now) : 0],
    'winMs'   => WIN_MS,
    'maxHp'   => MAX_HP,
    'count'   => count($players),
]);
