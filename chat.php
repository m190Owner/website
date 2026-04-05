<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$chatFile = __DIR__ . '/chat_messages.json';

if (!file_exists($chatFile)) {
    file_put_contents($chatFile, '[]');
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $msg = isset($input['message']) ? trim($input['message']) : '';

    if ($msg === '' || mb_strlen($msg) > 100) {
        echo json_encode(['error' => 'Message must be 1-100 characters']);
        exit;
    }

    $msg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');

    $messages = json_decode(file_get_contents($chatFile), true) ?: [];
    $messages[] = [
        'text' => $msg,
        'time' => time(),
        'id' => bin2hex(random_bytes(4))
    ];

    // Keep last 50
    if (count($messages) > 50) {
        $messages = array_slice($messages, -50);
    }

    file_put_contents($chatFile, json_encode($messages));
    echo json_encode(['success' => true]);
} else {
    $messages = json_decode(file_get_contents($chatFile), true) ?: [];
    // Only return messages from last 24 hours
    $cutoff = time() - 86400;
    $messages = array_values(array_filter($messages, function($m) use ($cutoff) {
        return $m['time'] > $cutoff;
    }));
    echo json_encode($messages);
}
