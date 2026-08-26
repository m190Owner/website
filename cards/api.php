<?php
// Cards Against Lag — JSON action router. Guest play; a player's secret token (in their
// localStorage) authorises their moves. `state` is the poll (GET); the rest are POST.
require __DIR__ . '/lib/game.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = (string) ($_REQUEST['action'] ?? '');
$limits = ['state' => [600, 60], 'submit' => [40, 60], 'pick' => [40, 60], 'join' => [20, 60], 'create' => [12, 60]];
[$max, $win] = $limits[$action] ?? [60, 60];
if (rateLimited('cah_' . preg_replace('/[^a-z]/', '', $action), $max, $win)) {
    http_response_code(429); echo json_encode(['ok' => false, 'error' => 'Slow down a sec.']); exit;
}

$P = $_POST;
switch ($action) {
    case 'create': echo json_encode(cah_create((string) ($P['name'] ?? ''), (string) ($P['deck'] ?? 'clean'), (int) ($P['target'] ?? 7))); break;
    case 'join':   echo json_encode(cah_join((string) ($P['code'] ?? ''), (string) ($P['name'] ?? ''))); break;
    case 'start':  echo json_encode(cah_start((string) ($P['code'] ?? ''), (string) ($P['token'] ?? ''))); break;
    case 'submit': echo json_encode(cah_submit((string) ($P['code'] ?? ''), (string) ($P['token'] ?? ''), (array) json_decode((string) ($P['cards'] ?? '[]'), true))); break;
    case 'pick':   echo json_encode(cah_pick((string) ($P['code'] ?? ''), (string) ($P['token'] ?? ''), (int) ($P['winner'] ?? 0))); break;
    case 'leave':  echo json_encode(cah_leave((string) ($P['code'] ?? ''), (string) ($P['token'] ?? ''))); break;
    case 'again':  echo json_encode(cah_again((string) ($P['code'] ?? ''), (string) ($P['token'] ?? ''))); break;
    case 'addbot': echo json_encode(cah_addbot((string) ($P['code'] ?? ''), (string) ($P['token'] ?? ''))); break;
    case 'removebot': echo json_encode(cah_removebot((string) ($P['code'] ?? ''), (string) ($P['token'] ?? ''), (int) ($P['bot'] ?? 0))); break;
    case 'state':  echo json_encode(cah_state((string) ($_REQUEST['code'] ?? ''), (string) ($_REQUEST['token'] ?? ''))); break;
    default:       echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
