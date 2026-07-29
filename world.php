<?php
// Live presence for the homepage "MMO" overlay. Every visitor is an emoji avatar
// with a name, transient emotes, and transient chat bubbles. Polling based, like
// cursors.php — each client POSTs its state and gets everyone else back.
//
// POST body (JSON) or GET query:
//   id, x, y (0-1 viewport fractions), n (name), a (emoji avatar),
//   e/et (emote + client timestamp), m/mt (chat message + client timestamp)
// -> { users: { id: {x,y,n,a,e,et,m,mt} }, count }

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();
enforceRateLimit('world', 240, 60); // updates a couple times a second

const WORLD_TTL = 6; // seconds a visitor stays "present" without an update
$file = __DIR__ . '/world_data.json';

// Keep short display strings safe: drop control + HTML-significant chars, cap length.
function wclean(string $s, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F<>&"\']/u', '', $s);
    return trim(mb_substr($s, 0, $max, 'UTF-8'));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
$in = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;

$id = preg_replace('/[^a-zA-Z0-9]/', '', $in['id'] ?? '');

// Atomic read-modify-write under an exclusive lock, so two visitors heartbeating
// at the same time never clobber each other's presence (no flicker).
$fp = fopen($file, 'c+');
$data = [];
if ($fp) {
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) $data = [];

    if ($id !== '') {
        $e = $data[$id] ?? [];
        $e['x'] = max(0, min(1, (float)($in['x'] ?? 0.5)));
        $e['y'] = max(0, min(1, (float)($in['y'] ?? 0.5)));

        $name = wclean($in['n'] ?? '', 16);
        $e['n'] = ($name === '' || containsProfanity($name)) ? 'guest' : $name;

        $av = wclean($in['a'] ?? '', 2);
        $e['a'] = $av === '' ? '🙂' : $av;

        // Transient emote (fires once; clients compare et to what they last saw).
        $et = (float)($in['et'] ?? 0);
        if ($et > 0) { $e['e'] = wclean($in['e'] ?? '', 2); $e['et'] = $et; }

        // Transient chat bubble.
        $mt = (float)($in['mt'] ?? 0);
        if ($mt > 0) {
            $msg = wclean($in['m'] ?? '', 120);
            if ($msg !== '' && !containsProfanity($msg)) { $e['m'] = $msg; $e['mt'] = $mt; }
        }

        $e['t'] = time();
        $data[$id] = $e;
    }

    // Prune the departed.
    foreach ($data as $k => $v) if (time() - ($v['t'] ?? 0) > WORLD_TTL) unset($data[$k]);

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Everyone except the caller.
$out = [];
foreach ($data as $k => $v) { if ($k === $id) continue; $out[$k] = $v; }
echo json_encode(['users' => (object)$out, 'count' => count($data)]);
