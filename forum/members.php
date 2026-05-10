<?php
require_once __DIR__ . '/includes/bootstrap.php';

$users = readJsonFile(USERS_FILE, []);
$memberList = [];
foreach ($users as $key => $user) {
    $pc = getUserPostCount($user['username']);
    $memberList[] = [
        'username' => $user['username'],
        'role' => $user['role'],
        'created' => $user['created'],
        'banned' => $user['banned'] ?? false,
        'post_count' => $pc,
        'rank' => getUserRank($pc),
        'online' => isUserOnline($user['username'])
    ];
}

$sort = $_GET['sort'] ?? 'joined';
if ($sort === 'posts') usort($memberList, fn($a, $b) => $b['post_count'] - $a['post_count']);
elseif ($sort === 'name') usort($memberList, fn($a, $b) => strcasecmp($a['username'], $b['username']));
else usort($memberList, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));

$navActive = 'members';
$pageTitle = 'Members';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <div class="flex-between mb-4">
        <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700;">Members (<?= count($memberList) ?>)</h1>
        <div style="display:flex; gap:6px;">
            <a href="?sort=joined" class="btn btn-sm <?= $sort === 'joined' ? 'btn-primary' : 'btn-secondary' ?>">Newest</a>
            <a href="?sort=posts" class="btn btn-sm <?= $sort === 'posts' ? 'btn-primary' : 'btn-secondary' ?>">Most Posts</a>
            <a href="?sort=name" class="btn btn-sm <?= $sort === 'name' ? 'btn-primary' : 'btn-secondary' ?>">A-Z</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?php foreach ($memberList as $m): ?>
            <div class="member-card">
                <?= avatarHtml($m['username'], 40) ?>
                <div class="member-info">
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span class="online-dot <?= $m['online'] ? 'on' : 'off' ?>"></span>
                        <a href="/forum/profile.php?user=<?= e($m['username']) ?>"><?= e($m['username']) ?></a>
                        <?= roleBadge($m['role']) ?>
                        <?php if ($m['banned']): ?><span class="badge" style="background:rgba(255,107,107,0.12);color:#ff6b6b;">BANNED</span><?php endif; ?>
                    </div>
                    <div class="member-detail">
                        <span style="color:<?= getRankColor($m['rank']) ?>"><?= $m['rank'] ?></span>
                        &middot; <?= $m['post_count'] ?> posts
                        &middot; Joined <?= date('M j, Y', strtotime($m['created'])) ?>
                    </div>
                </div>
                <?php if (isLoggedIn() && currentUser() !== $m['username']): ?>
                    <a href="/forum/messages.php?to=<?= e($m['username']) ?>" class="btn btn-secondary btn-sm">Message</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
