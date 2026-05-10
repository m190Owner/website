<?php
require_once __DIR__ . '/includes/bootstrap.php';

$board = getLeaderboard();
$sortBy = $_GET['sort'] ?? 'reputation';

usort($board, function($a, $b) use ($sortBy) {
    if ($sortBy === 'posts') return $b['post_count'] - $a['post_count'];
    if ($sortBy === 'achievements') return $b['achievements'] - $a['achievements'];
    return $b['reputation'] - $a['reputation'];
});

$board = array_slice($board, 0, 25);

$navActive = 'leaderboard';
$pageTitle = 'Leaderboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap" style="max-width:700px;">
    <div class="breadcrumbs">
        <a href="/forum/">m190</a><span class="sep">/</span><span>Leaderboard</span>
    </div>

    <div class="flex-between mb-4">
        <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700;">Leaderboard</h1>
        <div style="display:flex; gap:4px;">
            <a href="/forum/leaderboard.php?sort=reputation" class="btn btn-sm <?= $sortBy === 'reputation' ? 'btn-primary' : 'btn-secondary' ?>">Reputation</a>
            <a href="/forum/leaderboard.php?sort=posts" class="btn btn-sm <?= $sortBy === 'posts' ? 'btn-primary' : 'btn-secondary' ?>">Posts</a>
            <a href="/forum/leaderboard.php?sort=achievements" class="btn btn-sm <?= $sortBy === 'achievements' ? 'btn-primary' : 'btn-secondary' ?>">Achievements</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if (empty($board)): ?>
                <div class="empty-state"><p>No members yet.</p></div>
            <?php else: ?>
                <?php foreach ($board as $i => $entry):
                    $rankClass = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                ?>
                <div class="lb-row">
                    <div class="lb-rank <?= $rankClass ?>">#<?= $i + 1 ?></div>
                    <div class="lb-user">
                        <?= avatarHtml($entry['username'], 32) ?>
                        <div>
                            <a href="/forum/profile.php?user=<?= e($entry['username']) ?>"><?= e($entry['username']) ?></a>
                            <?= roleBadge($entry['role']) ?>
                        </div>
                    </div>
                    <div class="lb-stat">
                        <span class="lb-stat-val" style="color:<?= $entry['reputation'] >= 0 ? '#6bffb8' : '#ff6b6b' ?>"><?= $entry['reputation'] > 0 ? '+' : '' ?><?= $entry['reputation'] ?></span>
                        <span class="lb-stat-label">Rep</span>
                    </div>
                    <div class="lb-stat">
                        <span class="lb-stat-val"><?= $entry['post_count'] ?></span>
                        <span class="lb-stat-label">Posts</span>
                    </div>
                    <div class="lb-stat">
                        <span class="lb-stat-val" style="color:#ffb86b;"><?= $entry['achievements'] ?></span>
                        <span class="lb-stat-label">Achievements</span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
