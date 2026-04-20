<?php
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'upload_image') {
    if (!isLoggedIn()) { echo json_encode(['ok' => false, 'error' => 'Not logged in.']); exit; }
    enforceRateLimit('forum_img_upload', 10, 60);
    if (!isset($_FILES['image'])) { echo json_encode(['ok' => false, 'error' => 'No file.']); exit; }
    echo json_encode(uploadPostImage($_FILES['image']));
    exit;
}

if ($action === 'reaction') {
    if (!verifyCsrf()) { echo json_encode(['ok' => false, 'error' => 'Invalid token.']); exit; }
    enforceRateLimit('forum_reaction', 30, 60);
    echo json_encode(toggleReaction($_POST['thread_id'] ?? '', $_POST['post_id'] ?? '', $_POST['emoji'] ?? ''));
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
