<?php
require_once __DIR__ . '/includes/bootstrap.php';

$threadId = $_GET['id'] ?? '';
$thread = getThread($threadId);

if (!$thread) {
    http_response_code(404);
    $pageTitle = 'Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="forum-wrap"><div class="alert alert-error">Thread not found.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reply' && !($thread['locked'] ?? false)) {
        enforceRateLimit('forum_reply', 10, 60);
        $result = addReply($threadId, $_POST['content'] ?? '');
        if ($result['ok']) {
            header('Location: /forum/thread.php?id=' . e($threadId) . '#bottom');
            exit;
        }
        $replyError = $result['error'];
    }

    if (isAdmin()) {
        if ($action === 'pin') { togglePin($threadId); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'lock') { toggleLock($threadId); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'delete_thread') { deleteThread($threadId); header('Location: /forum/'); exit; }
        if ($action === 'delete_post') {
            deletePost($threadId, $_POST['post_id'] ?? '');
            header('Location: /forum/thread.php?id=' . e($threadId));
            exit;
        }
    }

    // Refresh thread data after actions
    $thread = getThread($threadId);
    if (!$thread) { header('Location: /forum/'); exit; }
}

$category = getCategoryById($thread['category']);
$navActive = 'forum';
$pageTitle = $thread['title'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <div class="breadcrumbs">
        <a href="/forum/">Forum</a>
        <span class="sep">/</span>
        <a href="/forum/category.php?id=<?= e($thread['category']) ?>"><?= e($category['name'] ?? $thread['category']) ?></a>
        <span class="sep">/</span>
        <span><?= e(mb_strimwidth($thread['title'], 0, 40, '...')) ?></span>
    </div>

    <div class="flex-between mb-4">
        <div>
            <div style="display:flex; align-items:center; gap:8px;">
                <?php if ($thread['pinned'] ?? false): ?><span class="pin-tag">PINNED</span><?php endif; ?>
                <?php if ($thread['locked'] ?? false): ?><span class="lock-tag">LOCKED</span><?php endif; ?>
                <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700;"><?= e($thread['title']) ?></h1>
            </div>
            <p style="font-size:0.75rem; color:#5a6480; margin-top:3px;">
                Started by <a href="/forum/profile.php?user=<?= e($thread['author']) ?>" style="color:#7aa2ff; text-decoration:none;"><?= e($thread['author']) ?></a>
                &middot; <?= timeAgo($thread['created']) ?>
            </p>
        </div>
        <?php if (isAdmin()): ?>
        <div style="display:flex; gap:6px;">
            <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="pin"><button class="btn btn-secondary btn-sm"><?= ($thread['pinned'] ?? false) ? 'Unpin' : 'Pin' ?></button></form>
            <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="lock"><button class="btn btn-secondary btn-sm"><?= ($thread['locked'] ?? false) ? 'Unlock' : 'Lock' ?></button></form>
            <form method="POST" class="inline-form" onsubmit="return confirm('Delete this thread?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_thread"><button class="btn btn-danger btn-sm">Delete</button></form>
        </div>
        <?php endif; ?>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <?php foreach ($thread['posts'] as $i => $post):
                $profile = getUserProfile($post['author']);
                $role = $profile['role'] ?? 'member';
            ?>
            <div class="post" id="post-<?= e($post['id']) ?>">
                <div class="post-sidebar">
                    <?= avatarHtml($post['author'], 48) ?>
                    <a href="/forum/profile.php?user=<?= e($post['author']) ?>" class="post-author-link"><?= e($post['author']) ?></a>
                    <div class="post-role"><?= roleBadge($role) ?></div>
                </div>
                <div class="post-body">
                    <div class="post-content"><?= formatContent($post['content']) ?></div>
                    <div class="post-footer">
                        <span><?= timeAgo($post['created']) ?></span>
                        <div class="post-actions">
                            <?php if (isAdmin() && count($thread['posts']) > 1): ?>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this post?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?= e($post['id']) ?>">
                                    <button class="post-action-btn">delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (isLoggedIn() && !($thread['locked'] ?? false)): ?>
    <div class="card" id="bottom">
        <div class="card-header">
            <h2>Reply</h2>
        </div>
        <div style="padding:20px;">
            <?php if (!empty($replyError)): ?>
                <div class="alert alert-error"><?= e($replyError) ?></div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reply">
                <div class="form-group">
                    <textarea class="form-textarea" name="content" required placeholder="Write your reply..." maxlength="10000"></textarea>
                    <p class="form-hint">Supports **bold**, *italic*, `code`, ```code blocks```, and URLs</p>
                </div>
                <button type="submit" class="btn btn-primary">Post Reply</button>
            </form>
        </div>
    </div>
    <?php elseif ($thread['locked'] ?? false): ?>
    <div class="alert" style="background:rgba(122,162,255,0.05); border:1px solid rgba(122,162,255,0.1); color:#5a6480; text-align:center;">
        This thread is locked. No new replies can be posted.
    </div>
    <?php elseif (!isLoggedIn()): ?>
    <div style="text-align:center; margin-top:20px;">
        <a href="/forum/login.php" class="btn btn-primary">Login to reply</a>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
