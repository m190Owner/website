<?php
require_once __DIR__ . '/includes/bootstrap.php';
$navActive = 'forum';
$pageTitle = 'Forum';
require_once __DIR__ . '/includes/header.php';

$categories = getCategories();
$recentThreads = getRecentThreads(5);
$stats = getForumStats();
$onlineUsers = getOnlineUsers();
$catIcons = ['general' => '💬', 'projects' => '🔧', 'gaming' => '🎮', 'off-topic' => '🌀'];
?>

<div class="forum-wrap">
    <!-- Stats -->
    <div class="card mb-4">
        <div class="stats-bar">
            <div class="stat-item"><span class="stat-num"><?= $stats['members'] ?></span><span class="stat-label">Members</span></div>
            <div class="stat-item"><span class="stat-num"><?= $stats['threads'] ?></span><span class="stat-label">Threads</span></div>
            <div class="stat-item"><span class="stat-num"><?= $stats['posts'] ?></span><span class="stat-label">Posts</span></div>
            <div class="stat-item"><span class="stat-num"><?= $stats['online'] ?></span><span class="stat-label">Online</span></div>
            <?php if ($stats['newest_member']): ?>
            <div class="stat-item">
                <span class="stat-num" style="font-size:0.82rem;"><a href="/forum/profile.php?user=<?= e($stats['newest_member']) ?>" style="color:#7aa2ff;text-decoration:none;"><?= e($stats['newest_member']) ?></a></span>
                <span class="stat-label">Newest Member</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Search -->
    <form method="GET" action="/forum/search.php" class="search-bar mb-4">
        <input class="form-input" type="text" name="q" placeholder="Search threads and posts...">
        <button class="btn btn-secondary">Search</button>
    </form>

    <div class="flex-between mb-4">
        <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700;">Categories</h1>
        <?php if (isLoggedIn()): ?>
            <a href="/forum/new.php" class="btn btn-primary btn-sm">+ New Thread</a>
        <?php endif; ?>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <?php if (empty($categories)): ?>
                <div class="empty-state"><p>No categories yet.</p></div>
            <?php else: ?>
                <?php foreach ($categories as $cat):
                    $threadCount = getThreadCountForCategory($cat['id']);
                    $postCount = getPostCountForCategory($cat['id']);
                    $lastPost = getLastPostForCategory($cat['id']);
                    $icon = $catIcons[$cat['id']] ?? '📁';
                ?>
                <div class="cat-row">
                    <div class="cat-icon"><?= $icon ?></div>
                    <div class="cat-info">
                        <a href="/forum/category.php?id=<?= e($cat['id']) ?>" class="cat-name"><?= e($cat['name']) ?></a>
                        <div class="cat-desc"><?= e($cat['description']) ?></div>
                    </div>
                    <div class="cat-stats">
                        <div><span class="cat-stat-num"><?= $threadCount ?></span><span class="cat-stat-label">Threads</span></div>
                        <div><span class="cat-stat-num"><?= $postCount ?></span><span class="cat-stat-label">Posts</span></div>
                    </div>
                    <div class="cat-last-post">
                        <?php if ($lastPost): ?>
                            <a href="/forum/thread.php?id=<?= e($lastPost['thread_id']) ?>"><?= e(mb_strimwidth($lastPost['thread_title'], 0, 28, '...')) ?></a><br>
                            by <a href="/forum/profile.php?user=<?= e($lastPost['author']) ?>"><?= e($lastPost['author']) ?></a><br>
                            <?= timeAgo($lastPost['time']) ?>
                        <?php else: ?>
                            <span class="text-muted">No posts yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($recentThreads)): ?>
    <div class="card mb-4">
        <div class="card-header"><h2>Recent Activity</h2></div>
        <div class="card-body">
            <?php foreach ($recentThreads as $thread): ?>
            <div class="thread-row">
                <?= avatarHtml($thread['author'], 32) ?>
                <div class="thread-info">
                    <div class="thread-title-row">
                        <?php if ($thread['pinned'] ?? false): ?><span class="pin-tag">PIN</span><?php endif; ?>
                        <?php if ($thread['locked'] ?? false): ?><span class="lock-tag">LOCKED</span><?php endif; ?>
                        <?php foreach ($thread['tags'] ?? [] as $tagId): ?><?= tagHtml($tagId) ?><?php endforeach; ?>
                        <a href="/forum/thread.php?id=<?= e($thread['id']) ?>" class="thread-title"><?= e($thread['title']) ?></a>
                    </div>
                    <div class="thread-meta">
                        by <a href="/forum/profile.php?user=<?= e($thread['author']) ?>"><?= e($thread['author']) ?></a>
                        in <a href="/forum/category.php?id=<?= e($thread['category']) ?>"><?= e(getCategoryById($thread['category'])['name'] ?? $thread['category']) ?></a>
                        &middot; <?= timeAgo($thread['created']) ?>
                    </div>
                </div>
                <div class="thread-stats">
                    <div><span class="cat-stat-num"><?= $thread['reply_count'] ?></span><span class="cat-stat-label">Replies</span></div>
                </div>
                <div class="thread-last">
                    <a href="/forum/profile.php?user=<?= e($thread['last_post_author']) ?>"><?= e($thread['last_post_author']) ?></a><br>
                    <?= timeAgo($thread['last_post_time']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Online users -->
    <?php if (!empty($onlineUsers)): ?>
    <div class="card">
        <div class="card-header"><h2>Online Now (<?= count($onlineUsers) ?>)</h2></div>
        <div style="padding:12px 20px; display:flex; gap:10px; flex-wrap:wrap;">
            <?php foreach ($onlineUsers as $ou): ?>
                <a href="/forum/profile.php?user=<?= e($ou) ?>" style="display:flex; align-items:center; gap:4px; color:#e5e5e5; text-decoration:none; font-size:0.8rem;">
                    <span class="online-dot on"></span><?= e($ou) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!isLoggedIn()): ?>
    <div style="text-align:center; margin-top:30px;">
        <p style="color:#5a6480; font-size:0.85rem; margin-bottom:12px;">This forum is invite-only. Have an invite code?</p>
        <a href="/forum/register.php" class="btn btn-primary">Register</a>
        <a href="/forum/login.php" class="btn btn-secondary" style="margin-left:8px;">Login</a>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
