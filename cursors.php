<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$file = __DIR__ . '/cursor_data.json';
if (!file_exists($file)) file_put_contents($file, '{}');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $input['id'] ?? '');
    $x = floatval($input['x'] ?? 0);
    $y = floatval($input['y'] ?? 0);
    $color = preg_replace('/[^a-zA-Z0-9#(),. ]/', '', $input['color'] ?? '#ff6666');

    if ($id) {
        $data = json_decode(file_get_contents($file), true) ?: [];
        $data[$id] = ['x' => $x, 'y' => $y, 'color' => $color, 'time' => time()];
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
