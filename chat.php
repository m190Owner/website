<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();

$chatFile = __DIR__ . '/chat_messages.json';

if (!file_exists($chatFile)) {
    writeJsonFile($chatFile, []);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    enforceRateLimit('chat_post', 5, 60); // 5 messages per minute

    $input = json_decode(file_get_contents('php://input'), true);
    $msg = isset($input['message']) ? trim($input['message']) : '';

    if ($msg === '' || mb_strlen($msg) > 100) {
        echo json_encode(['error' => 'Message must be 1-100 characters']);
        exit;
    }

    $msg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');

    $messages = readJsonFile($chatFile, []);
    $messages[] = [
        'text' => $msg,
        'time' => time(),
        'id' => bin2hex(random_bytes(4))
    ];

    // Keep last 50
    if (count($messages) > 50) {
        $messages = array_slice($messages, -50);
    }

    writeJsonFile($chatFile, $messages);
    echo json_encode(['success' => true]);
} else {
    enforceRateLimit('chat_get', 30, 60);

    $messages = readJsonFile($chatFile, []);
    // Only return messages from last 24 hours
    $cutoff = time() - 86400;
    $messages = array_values(array_filter($messages, function($m) use ($cutoff) {
        return $m['time'] > $cutoff;
    }));
    echo json_encode($messages);
}
