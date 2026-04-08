<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$file = __DIR__ . '/room_players.json';
if (!file_exists($file)) file_put_contents($file, '{}');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $input['id'] ?? '');
    $x = floatval($input['x'] ?? 0);
    $y = floatval($input['y'] ?? 0);
    $z = floatval($input['z'] ?? 0);
    $ry = floatval($input['ry'] ?? 0);
    $color = preg_replace('/[^a-fA-F0-9#]/', '', $input['color'] ?? '#7aa2ff');
    $name = substr(preg_replace('/[^a-zA-Z0-9_ ]/', '', $input['name'] ?? 'Visitor'), 0, 16);

    if ($id) {
        $data = json_decode(file_get_contents($file), true) ?: [];
        $data[$id] = [
            'x' => $x, 'y' => $y, 'z' => $z, 'ry' => $ry,
            'color' => $color, 'name' => $name, 'time' => time()
        ];
        // Remove stale players (no update in 5 seconds)
        foreach ($data as $k => $v) {
            if (time() - $v['time'] > 5) unset($data[$k]);
        }
        file_put_contents($file, json_encode($data));
    }
    echo json_encode(['ok' => true]);
} else {
    $data = json_decode(file_get_contents($file), true) ?: [];
    foreach ($data as $k => $v) {
        if (time() - $v['time'] > 5) unset($data[$k]);
    }
    echo json_encode($data);
}
