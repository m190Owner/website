<?php
// WebRTC signaling broker for RoF Survivors co-op. Brokers ONLY the one-time
// handshake (SDP offer/answer + ICE candidates) so browsers can connect
// peer-to-peer; no game data flows through here. Star topology: the room
// creator is the host, joiners handshake with the host.
//
// Storage: one JSON file per room under game/data/rooms/, gitignored, expiring.
// Actions (action=...):
//   create  POST peer            -> { ok, code }            (host registers a new room)
//   join    POST room,peer       -> { ok, host, peers }     (joiner enters a room)
//   signal  POST room,from,to,msg-> { ok }                  (queue a message for `to`)
//   poll    GET  room,peer       -> { ok, messages, peers } (fetch+clear my messages)
//   peers   GET  room,peer       -> { ok, host, peers }     (refresh + list peers)

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

define('ROOM_DIR', __DIR__ . '/data/rooms');
define('ROOM_TTL', 180);            // seconds a room/peer can sit idle before expiring
if (!is_dir(ROOM_DIR)) mkdir(ROOM_DIR, 0755, true);

enforceRateLimit('mp_signal', 240, 60); // polling is frequent during the brief handshake

function jout($d) { echo json_encode($d); exit; }
function jerr($code, $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

function roomPath($code) {
    if (!preg_match('/^[A-Z0-9]{4}$/', $code)) return null;
    return ROOM_DIR . '/' . $code . '.json';
}

// Atomic read-modify-write on a room file (flock held across the whole op).
function withRoom($path, $create, callable $fn) {
    $fp = fopen($path, $create ? 'c+' : 'r+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $room = $raw ? json_decode($raw, true) : null;
    $result = $fn($room);                 // $fn returns [newRoom|null, response]
    [$newRoom, $response] = $result;
    if ($newRoom !== null) {
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($newRoom));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $response;
}

// Drop peers we haven't heard from within the TTL.
function pruneRoom($room) {
    $now = time();
    foreach ($room['peers'] as $id => $seen) {
        if ($now - $seen > ROOM_TTL) unset($room['peers'][$id]);
    }
    return $room;
}

// Occasional sweep of dead room files.
if (rand(1, 40) === 1) {
    foreach (glob(ROOM_DIR . '/*.json') as $f) {
        if (time() - filemtime($f) > ROOM_TTL) @unlink($f);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$peer = preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['peer'] ?? '');

if ($action === 'create') {
    if ($peer === '') jerr(400, 'peer required');
    // Find a free 4-char code.
    $code = null;
    for ($i = 0; $i < 12; $i++) {
        $try = '';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars
        for ($j = 0; $j < 4; $j++) $try .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if (!file_exists(roomPath($try))) { $code = $try; break; }
    }
    if (!$code) jerr(503, 'no room codes available');
    writeJsonFile(roomPath($code), [
        'host' => $peer,
        'peers' => [$peer => time()],
        'mailbox' => [],
        'created' => time(),
    ]);
    jout(['ok' => true, 'code' => $code]);
}

$code = strtoupper($_REQUEST['room'] ?? '');
$path = roomPath($code);
if (!$path) jerr(400, 'invalid room code');

if ($action === 'join') {
    if ($peer === '') jerr(400, 'peer required');
    if (!file_exists($path)) jerr(404, 'room not found');
    $res = withRoom($path, false, function ($room) use ($peer) {
        if (!$room) return [null, ['ok' => false, 'error' => 'room gone']];
        $room = pruneRoom($room);
        $room['peers'][$peer] = time();
        return [$room, ['ok' => true, 'host' => $room['host'], 'peers' => array_keys($room['peers'])]];
    });
    jout($res ?: ['ok' => false, 'error' => 'join failed']);
}

if ($action === 'signal') {
    $from = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['from'] ?? '');
    $to = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['to'] ?? '');
    $msg = $_POST['msg'] ?? '';
    if ($from === '' || $to === '' || $msg === '') jerr(400, 'from/to/msg required');
    if (strlen($msg) > 20000) jerr(413, 'message too large');
    if (!file_exists($path)) jerr(404, 'room not found');
    $res = withRoom($path, false, function ($room) use ($from, $to, $msg) {
        if (!$room) return [null, ['ok' => false]];
        $room['peers'][$from] = time();
        $room['mailbox'][] = ['to' => $to, 'from' => $from, 'msg' => $msg, 'ts' => time()];
        // Cap mailbox so a stuck client can't grow it unbounded.
        if (count($room['mailbox']) > 200) $room['mailbox'] = array_slice($room['mailbox'], -200);
        return [$room, ['ok' => true]];
    });
    jout($res ?: ['ok' => false]);
}

if ($action === 'poll' || $action === 'peers') {
    if ($peer === '') jerr(400, 'peer required');
    if (!file_exists($path)) jerr(404, 'room not found');
    $res = withRoom($path, false, function ($room) use ($peer, $action) {
        if (!$room) return [null, ['ok' => false, 'error' => 'room gone']];
        $room = pruneRoom($room);
        $room['peers'][$peer] = time();
        $mine = [];
        if ($action === 'poll') {
            $keep = [];
            foreach ($room['mailbox'] as $m) {
                if ($m['to'] === $peer) $mine[] = $m; else $keep[] = $m;
            }
            $room['mailbox'] = $keep;
        }
        return [$room, ['ok' => true, 'host' => $room['host'], 'peers' => array_keys($room['peers']), 'messages' => $mine]];
    });
    jout($res ?: ['ok' => false, 'error' => 'poll failed']);
}

jerr(400, 'unknown action');
