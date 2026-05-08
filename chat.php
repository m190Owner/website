<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();

/**
 * Chat-specific spam and inline-XSS heuristics.
 * Kept here (not in config.php) because these patterns are only
 * meaningful for free-form chat messages; lab handles are already
 * constrained by the alphanumeric+underscore regex.
 */
function chatExtraBlocks(string $text): bool {
    $lower = strtolower($text);
    $patterns = [
        'buy now', 'click here', 'free money', 'make money fast', 'onlyfans',
        '<script', 'javascript:', 'data:text',
    ];
    foreach ($patterns as $p) {
        if (strpos($lower, $p) !== false) return true;
    }
    return false;
}

$chatFile = __DIR__ . '/chat_messages.json';

if (!file_exists($chatFile)) {
    writeJsonFile($chatFile, []);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ==============================================
// ADMIN: DELETE MESSAGE
// ==============================================
if ($method === 'POST' && $action === 'delete') {
    $token     = $_GET['token'] ?? '';
    $adminToken = getenv('CHAT_ADMIN_TOKEN');

    if (!$adminToken || !hash_equals($adminToken, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $id = preg_replace('/[^a-f0-9]/', '', $_GET['id'] ?? '');
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
        exit;
    }

    $messages = readJsonFile($chatFile, []);
    $messages = array_values(array_filter($messages, fn($m) => $m['id'] !== $id));
    writeJsonFile($chatFile, $messages);
    echo json_encode(['success' => true]);
    exit;
}

// ==============================================
// POST: SEND MESSAGE
// ==============================================
if ($method === 'POST') {
    enforceRateLimit('chat_post', 5, 60);

    $input = json_decode(file_get_contents('php://input'), true);
    $msg = isset($input['message']) ? trim($input['message']) : '';

    if ($msg === '' || mb_strlen($msg) > 100) {
        echo json_encode(['error' => 'Message must be 1-100 characters']);
        exit;
    }

    if (containsProfanity($msg) || chatExtraBlocks($msg)) {
        echo json_encode(['error' => 'Message blocked']);
        exit;
    }

    $msg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');

    $messages = readJsonFile($chatFile, []);
    $messages[] = [
        'text' => $msg,
        'time' => time(),
        'id'   => bin2hex(random_bytes(4))
    ];

    if (count($messages) > 50) {
        $messages = array_slice($messages, -50);
    }

    writeJsonFile($chatFile, $messages);
    echo json_encode(['success' => true]);
    exit;
}

// ==============================================
// GET: FETCH MESSAGES
// ==============================================
enforceRateLimit('chat_get', 30, 60);

$messages = readJsonFile($chatFile, []);
$cutoff   = time() - 86400;
$messages = array_values(array_filter($messages, fn($m) => $m['time'] > $cutoff));
echo json_encode($messages);
