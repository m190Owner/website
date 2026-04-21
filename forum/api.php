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

if ($action === 'vote') {
    if (!verifyCsrf()) { echo json_encode(['ok' => false, 'error' => 'Invalid token.']); exit; }
    enforceRateLimit('forum_vote', 30, 60);
    echo json_encode(votePost($_POST['thread_id'] ?? '', $_POST['post_id'] ?? '', $_POST['direction'] ?? ''));
    exit;
}

if ($action === 'shoutbox_send') {
    if (!verifyCsrf()) { echo json_encode(['ok' => false, 'error' => 'Invalid token.']); exit; }
    enforceRateLimit('forum_shoutbox', 15, 60);
    echo json_encode(addShoutboxMessage($_POST['content'] ?? ''));
    exit;
}

if ($action === 'shoutbox_fetch') {
    echo json_encode(['ok' => true, 'messages' => getShoutboxMessages()]);
    exit;
}

if ($action === 'notifications_fetch') {
    echo json_encode(['ok' => true, 'notifications' => getNotifications(), 'unread' => getUnreadNotificationCount()]);
    exit;
}

if ($action === 'notifications_read') {
    markNotificationsRead();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'pubkey_register') {
    if (!isLoggedIn()) { echo json_encode(['ok' => false, 'error' => 'Not logged in.']); exit; }
    if (!verifyCsrf()) { echo json_encode(['ok' => false, 'error' => 'Invalid token.']); exit; }
    $pk = $_POST['public_key'] ?? '';
    if (strlen($pk) < 10 || strlen($pk) > 2000) { echo json_encode(['ok' => false, 'error' => 'Invalid key.']); exit; }
    json_decode($pk);
    if (json_last_error() !== JSON_ERROR_NONE) { echo json_encode(['ok' => false, 'error' => 'Not valid JWK.']); exit; }
    echo json_encode(['ok' => registerPublicKey($pk)]);
    exit;
}

if ($action === 'pubkey_get') {
    if (!isLoggedIn()) { echo json_encode(['ok' => false, 'error' => 'Not logged in.']); exit; }
    $u = $_POST['username'] ?? $_GET['username'] ?? '';
    $pk = getUserPublicKey($u);
    if (!$pk) { echo json_encode(['ok' => false, 'error' => 'User has no registered key.']); exit; }
    echo json_encode(['ok' => true, 'public_key' => $pk]);
    exit;
}

if ($action === 'deaddrop_send') {
    if (!isLoggedIn()) { echo json_encode(['ok' => false, 'error' => 'Not logged in.']); exit; }
    if (!verifyCsrf()) { echo json_encode(['ok' => false, 'error' => 'Invalid token.']); exit; }
    enforceRateLimit('forum_deaddrop', 10, 60);
    $isPublic = !empty($_POST['is_public']);
    echo json_encode(createDeadDrop(
        $_POST['recipient'] ?? '',
        $_POST['encrypted_content'] ?? '',
        $_POST['nonce'] ?? '',
        $isPublic,
        $_POST['expires_hours'] ?? null
    ));
    exit;
}

if ($action === 'deaddrop_burn') {
    if (!isLoggedIn()) { echo json_encode(['ok' => false, 'error' => 'Not logged in.']); exit; }
    if (!verifyCsrf()) { echo json_encode(['ok' => false, 'error' => 'Invalid token.']); exit; }
    echo json_encode(['ok' => burnDeadDrop($_POST['drop_id'] ?? '')]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
