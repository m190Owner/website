<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();
enforceRateLimit('cursors', 120, 60); // high limit since cursors update frequently

$file = __DIR__ . '/cursor_data.json';
if (!file_exists($file)) writeJsonFile($file, (object)[]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $input['id'] ?? '');
    $x = floatval($input['x'] ?? 0);
    $y = floatval($input['y'] ?? 0);
    $color = preg_replace('/[^a-zA-Z0-9#(),. ]/', '', $input['color'] ?? '#ff6666');

    if ($id) {
        $data = readJsonFile($file, []);
        $data[$id] = ['x' => $x, 'y' => $y, 'color' => $color, 'time' => time()];
        foreach ($data as $k => $v) {
            if (time() - $v['time'] > 5) unset($data[$k]);
        }
        writeJsonFile($file, $data);
    }
    echo json_encode(['ok' => true]);
} else {
    $data = readJsonFile($file, []);
    foreach ($data as $k => $v) {
        if (time() - $v['time'] > 5) unset($data[$k]);
    }
    echo json_encode($data);
}
