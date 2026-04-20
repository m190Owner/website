<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!isLoggedIn()) { header('Location: /forum/login.php'); exit; }

$activeUser = $_GET['to'] ?? $_POST['to'] ?? '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    enforceRateLimit('forum_msg', 20, 60);
    $result = sendMessage($_POST['to'] ?? '', $_POST['content'] ?? '');
    if ($result['ok']) {
        header('Location: /forum/messages.php?to=' . urlencode($_POST['to']));
        exit;
    }
    $error = $result['error'];
}

$conversations = getConversations();
$messages = [];
$chatPartner = null;

if ($activeUser) {
    $users = readJsonFile(USERS_FILE, []);
    $key = strtolower($activeUser);
    if (isset($users[$key])) {
        $chatPartner = $users[$key]['username'];
        $messages = getMessages($chatPartner);
    }
}

$navActive = 'messages';
$pageTitle = 'Messages';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700; margin-bottom:16px;">Messages</h1>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div style="display:flex; gap:16px; min-height:500px;">
        <!-- Conversation list -->
        <div class="card" style="width:280px; flex-shrink:0;">
            <div class="card-header"><h2>Conversations</h2></div>
            <div class="card-body">
                <?php if (empty($conversations) && !$chatPartner): ?>
                    <div class="empty-state" style="padding:20px;"><p>No messages yet.</p></div>
                <?php endif; ?>
                <?php foreach ($conversations as $c): ?>
                <a href="/forum/messages.php?to=<?= e($c['user']) ?>"
                   class="msg-list-item <?= $c['unread'] > 0 ? 'unread' : '' ?> <?= $chatPartner === $c['user'] ? 'active' : '' ?>"
                   style="<?= $chatPartner === $c['user'] ? 'background:rgba(122,162,255,0.06);' : '' ?>">
                    <?= avatarHtml($c['user'], 32) ?>
                    <div class="msg-preview">
                        <div class="msg-preview-user"><?= e($c['user']) ?></div>
                        <div class="msg-preview-text"><?= e(mb_strimwidth($c['last_message']['content'], 0, 40, '...')) ?></div>
                    </div>
                    <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
                        <span class="msg-time"><?= timeAgo($c['last_message']['created']) ?></span>
                        <?php if ($c['unread'] > 0): ?><span class="msg-unread-dot"></span><?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Chat area -->
        <div class="card" style="flex:1; display:flex; flex-direction:column;">
            <?php if ($chatPartner): ?>
                <div class="card-header">
                    <h2 style="display:flex; align-items:center; gap:8px;">
                        <span class="online-dot <?= isUserOnline($chatPartner) ? 'on' : 'off' ?>"></span>
                        <a href="/forum/profile.php?user=<?= e($chatPartner) ?>" style="color:#e5e5e5; text-decoration:none;"><?= e($chatPartner) ?></a>
                    </h2>
                </div>
                <div style="flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:4px;" id="msg-scroll">
                    <?php if (empty($messages)): ?>
                        <div class="empty-state"><p>Start a conversation with <?= e($chatPartner) ?>.</p></div>
                    <?php endif; ?>
                    <?php foreach ($messages as $msg):
                        $isMine = $msg['from'] === currentUser();
                    ?>
                    <div class="msg-bubble-wrap <?= $isMine ? 'sent' : 'received' ?>">
                        <div class="msg-bubble <?= $isMine ? 'sent' : 'received' ?>"><?= formatContent($msg['content']) ?></div>
                        <div class="msg-bubble-time"><?= timeAgo($msg['created']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="padding:12px; border-top:1px solid rgba(122,162,255,0.06);">
                    <form method="POST" style="display:flex; gap:8px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="to" value="<?= e($chatPartner) ?>">
                        <input class="form-input" type="text" name="content" placeholder="Type a message..." required maxlength="5000" autocomplete="off" autofocus>
                        <button class="btn btn-primary">Send</button>
                    </form>
                </div>
            <?php else: ?>
                <div style="flex:1; display:flex; align-items:center; justify-content:center;">
                    <div class="empty-state"><p>Select a conversation or message someone from the <a href="/forum/members.php" style="color:#7aa2ff;">members</a> page.</p></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
var el = document.getElementById('msg-scroll');
if (el) el.scrollTop = el.scrollHeight;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
