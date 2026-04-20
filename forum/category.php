<?php
require_once __DIR__ . '/includes/bootstrap.php';

$catId = $_GET['id'] ?? '';
$category = getCategoryById($catId);
if (!$category) {
    http_response_code(404);
    $pageTitle = 'Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="forum-wrap"><div class="alert alert-error">Category not found.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$result = getThreadsByCategory($catId, $page, 20);

$navActive = 'forum';
$pageTitle = $category['name'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <div class="breadcrumbs">
        <a href="/forum/">Forum</a><span class="sep">/</span><span><?= e($category['name']) ?></span>
    </div>

    <div class="flex-between mb-4">
        <div>
            <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700;"><?= e($category['name']) ?></h1>
            <p style="font-size:0.78rem; color:#5a6480; margin-top:2px;"><?= e($category['description']) ?></p>
        </div>
        <?php if (isLoggedIn()): ?>
            <a href="/forum/new.php?category=<?= e($catId) ?>" class="btn btn-primary btn-sm">+ New Thread</a>
        <?php endif; ?>
    </div>

    <?php $subCats = getSubCategories($catId); if (!empty($subCats)): ?>
    <div class="card mb-4">
        <div class="card-header"><h2>Sub-Categories</h2></div>
        <div class="card-body">
            <?php foreach ($subCats as $sc): ?>
            <div class="sub-cat-row">
                <span style="color:#5a6480;">&#8627;</span>
                <div class="cat-info">
                    <a href="/forum/category.php?id=<?= e($sc['id']) ?>" class="cat-name"><?= e($sc['name']) ?></a>
                    <div class="cat-desc"><?= e($sc['description']) ?></div>
                </div>
                <div class="cat-stats">
                    <div><span class="cat-stat-num"><?= getThreadCountForCategory($sc['id']) ?></span><span class="cat-stat-label">Threads</span></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($result['threads'])): ?>
                <div class="empty-state">
                    <p>No threads yet.</p>
                    <?php if (isLoggedIn()): ?>
                        <a href="/forum/new.php?category=<?= e($catId) ?>" class="btn btn-primary btn-sm">Start the first thread</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($result['threads'] as $thread): ?>
                <div class="thread-row">
                    <?= avatarHtml($thread['author'], 32) ?>
                    <div class="thread-info">
                        <div class="thread-title-row">
                            <?php if ($thread['sticky_global'] ?? false): ?><span class="sticky-global-tag">ANN</span><?php endif; ?>
                            <?php if ($thread['pinned'] ?? false): ?><span class="pin-tag">PIN</span><?php endif; ?>
                            <?php if ($thread['locked'] ?? false): ?><span class="lock-tag">LOCKED</span><?php endif; ?>
                            <?php if (!empty($thread['prefix'])): ?><?= prefixHtml($thread['prefix']) ?><?php endif; ?>
                            <?php foreach ($thread['tags'] ?? [] as $tagId): ?><?= tagHtml($tagId) ?><?php endforeach; ?>
                            <a href="/forum/thread.php?id=<?= e($thread['id']) ?>" class="thread-title"><?= e($thread['title']) ?></a>
                        </div>
                        <div class="thread-meta">
                            by <a href="/forum/profile.php?user=<?= e($thread['author']) ?>"><?= e($thread['author']) ?></a>
                            &middot; <?= timeAgo($thread['created']) ?>
                            <?php if (isset($thread['poll'])): ?> &middot; <span style="color:#7aa2ff;">📊 Poll</span><?php endif; ?>
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
            <?php endif; ?>
        </div>

        <?php if ($result['pages'] > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="/forum/category.php?id=<?= e($catId) ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
