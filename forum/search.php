<?php
require_once __DIR__ . '/includes/bootstrap.php';

$query = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$results = $query ? searchForum($query, $page) : ['results' => [], 'total' => 0, 'pages' => 1];

$navActive = 'search';
$pageTitle = 'Search';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700; margin-bottom:16px;">Search</h1>

    <form method="GET" class="search-bar">
        <input class="form-input" type="text" name="q" value="<?= e($query) ?>" placeholder="Search threads and posts..." autofocus>
        <button class="btn btn-primary">Search</button>
    </form>

    <?php if ($query): ?>
        <p class="text-muted text-sm mb-4"><?= $results['total'] ?> result<?= $results['total'] !== 1 ? 's' : '' ?> for "<?= e($query) ?>"</p>

        <div class="card">
            <div class="card-body">
                <?php if (empty($results['results'])): ?>
                    <div class="empty-state"><p>No results found.</p></div>
                <?php else: ?>
                    <?php foreach ($results['results'] as $r):
                        $excerpt = htmlspecialchars($r['excerpt'], ENT_QUOTES, 'UTF-8');
                        $excerpt = preg_replace('/(' . preg_quote(e($query), '/') . ')/i', '<mark>$1</mark>', $excerpt);
                    ?>
                    <div class="search-result">
                        <a href="/forum/thread.php?id=<?= e($r['thread_id']) ?><?= isset($r['post_id']) ? '#post-' . e($r['post_id']) : '' ?>">
                            <?= e($r['thread_title']) ?>
                        </a>
                        <div class="search-excerpt"><?= $excerpt ?></div>
                        <div class="thread-meta" style="margin-top:4px;">
                            by <a href="/forum/profile.php?user=<?= e($r['author']) ?>"><?= e($r['author']) ?></a>
                            in <a href="/forum/category.php?id=<?= e($r['category']) ?>"><?= e(getCategoryById($r['category'])['name'] ?? $r['category']) ?></a>
                            &middot; <?= timeAgo($r['created']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($results['pages'] > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $results['pages']; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="/forum/search.php?q=<?= urlencode($query) ?>&page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
