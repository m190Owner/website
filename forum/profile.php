<?php
require_once __DIR__ . '/includes/bootstrap.php';

$username = $_GET['user'] ?? '';
$profile = getUserProfile($username);

if (!$profile) {
    http_response_code(404);
    $pageTitle = 'Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="forum-wrap"><div class="alert alert-error">User not found.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Handle bio update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn() && verifyCsrf()) {
    if (currentUser() === $profile['username'] && isset($_POST['bio'])) {
        $users = readJsonFile(USERS_FILE, []);
        $key = strtolower($profile['username']);
        $users[$key]['bio'] = mb_substr(trim($_POST['bio']), 0, 200);
        writeJsonFile(USERS_FILE, $users);
        $profile = getUserProfile($username);
    }
}

// Get recent posts by user
$recentPosts = [];
foreach (getAllThreadFiles() as $file) {
    $thread = readJsonFile($file);
    foreach ($thread['posts'] ?? [] as $post) {
        if ($post['author'] === $profile['username']) {
            $recentPosts[] = [
                'thread_id' => $thread['id'],
                'thread_title' => $thread['title'],
                'content' => mb_strimwidth($post['content'], 0, 120, '...'),
                'created' => $post['created']
            ];
        }
    }
}
usort($recentPosts, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));
$recentPosts = array_slice($recentPosts, 0, 10);

$navActive = 'profile';
$pageTitle = $profile['username'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap" style="max-width:700px;">
    <div class="breadcrumbs">
        <a href="/forum/">Forum</a>
        <span class="sep">/</span>
        <span><?= e($profile['username']) ?></span>
    </div>

    <div class="card mb-4">
        <div class="profile-header">
            <?= avatarHtml($profile['username'], 64) ?>
            <div class="profile-info">
                <h1><?= e($profile['username']) ?><?= roleBadge($profile['role']) ?></h1>
                <?php if ($profile['banned'] ?? false): ?>
                    <span class="badge" style="background:rgba(255,107,107,0.12); color:#ff6b6b;">BANNED</span>
                <?php endif; ?>
                <?php if ($profile['bio'] ?? ''): ?>
                    <p style="color:#8a96b8; font-size:0.82rem; margin-top:4px;"><?= e($profile['bio']) ?></p>
                <?php endif; ?>
                <div class="profile-stats">
                    <div><span class="profile-stat-val"><?= $profile['post_count'] ?></span> <span class="text-muted text-sm">posts</span></div>
                    <div><span class="profile-stat-val"><?= $profile['thread_count'] ?></span> <span class="text-muted text-sm">threads</span></div>
                    <div><span class="text-muted text-sm">joined</span> <span style="color:#8a96b8; font-size:0.78rem;"><?= date('M j, Y', strtotime($profile['created'])) ?></span></div>
                </div>
            </div>
        </div>

        <?php if (isLoggedIn() && currentUser() === $profile['username']): ?>
        <div style="padding:0 24px 20px;">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label" for="bio">Bio</label>
                    <input class="form-input" type="text" id="bio" name="bio" maxlength="200"
                           value="<?= e($profile['bio'] ?? '') ?>" placeholder="Tell us about yourself...">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">Update Bio</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($recentPosts)): ?>
    <div class="card">
        <div class="card-header">
            <h2>Recent Posts</h2>
        </div>
        <div class="card-body">
            <?php foreach ($recentPosts as $rp): ?>
            <div class="thread-row">
                <div class="thread-info">
                    <a href="/forum/thread.php?id=<?= e($rp['thread_id']) ?>" class="thread-title"><?= e($rp['thread_title']) ?></a>
                    <div class="thread-meta" style="margin-top:4px;"><?= e($rp['content']) ?></div>
                    <div class="thread-meta" style="margin-top:2px;"><?= timeAgo($rp['created']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
