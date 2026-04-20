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

$avatarError = '';
$avatarSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn() && verifyCsrf() && currentUser() === $profile['username']) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_bio') {
        $users = readJsonFile(USERS_FILE, []);
        $key = strtolower($profile['username']);
        $users[$key]['bio'] = mb_substr(trim($_POST['bio'] ?? ''), 0, 200);
        writeJsonFile(USERS_FILE, $users);
        $profile = getUserProfile($username);
    }

    if ($action === 'update_signature') {
        setUserSignature($_POST['signature'] ?? '');
    }

    if ($action === 'upload_avatar' && isset($_FILES['avatar'])) {
        enforceRateLimit('forum_avatar', 5, 60);
        $result = uploadAvatar($_FILES['avatar']);
        if ($result['ok']) $avatarSuccess = 'Profile picture updated.';
        else $avatarError = $result['error'];
    }

    if ($action === 'remove_avatar') {
        $key = strtolower(currentUser());
        foreach (['jpg','jpeg','png','gif','webp'] as $ext) {
            $old = AVATARS_DIR . '/' . $key . '.' . $ext;
            if (file_exists($old)) unlink($old);
        }
        $avatarSuccess = 'Profile picture removed.';
    }
}

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

$rank = getUserRank($profile['post_count']);
$online = isUserOnline($profile['username']);
$reputation = getUserReputation($profile['username']);
$achievements = getUserAchievements($profile['username']);
$allAchievements = getAchievementDefs();
$customTitle = getUserTitle($profile['username']);
$signature = getUserSignature($profile['username']);

$navActive = 'profile';
$pageTitle = $profile['username'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap" style="max-width:700px;">
    <div class="breadcrumbs">
        <a href="/forum/">m190</a><span class="sep">/</span>
        <a href="/forum/members.php">Members</a><span class="sep">/</span>
        <span><?= e($profile['username']) ?></span>
    </div>

    <div class="card mb-4">
        <div class="profile-header">
            <?= avatarHtml($profile['username'], 64) ?>
            <div class="profile-info">
                <h1>
                    <span class="online-dot <?= $online ? 'on' : 'off' ?>"></span>
                    <?= e($profile['username']) ?><?= roleBadge($profile['role']) ?>
                </h1>
                <?php if ($profile['banned'] ?? false): ?>
                    <span class="badge" style="background:rgba(255,107,107,0.12); color:#ff6b6b;">BANNED</span>
                <?php endif; ?>
                <div style="margin-top:3px; display:flex; align-items:center; gap:8px;">
                    <span class="post-rank" style="color:<?= getRankColor($rank) ?>;font-size:0.72rem;"><?= $rank ?></span>
                    <?php if ($customTitle): ?><span style="color:#7aa2ff; font-size:0.72rem; font-style:italic;">&middot; <?= e($customTitle) ?></span><?php endif; ?>
                </div>
                <?php if ($profile['bio'] ?? ''): ?>
                    <p style="color:#8a96b8; font-size:0.82rem; margin-top:4px;"><?= e($profile['bio']) ?></p>
                <?php endif; ?>
                <div class="profile-stats">
                    <div><span class="profile-stat-val"><?= $profile['post_count'] ?></span> <span class="text-muted text-sm">posts</span></div>
                    <div><span class="profile-stat-val"><?= $profile['thread_count'] ?></span> <span class="text-muted text-sm">threads</span></div>
                    <div>
                        <span class="profile-stat-val" style="color:<?= $reputation >= 0 ? '#6bffb8' : '#ff6b6b' ?>"><?= $reputation > 0 ? '+' : '' ?><?= $reputation ?></span>
                        <span class="text-muted text-sm">reputation</span>
                    </div>
                    <div><span class="text-muted text-sm">joined</span> <span style="color:#8a96b8; font-size:0.78rem;"><?= date('M j, Y', strtotime($profile['created'])) ?></span></div>
                </div>
            </div>
            <?php if (isLoggedIn() && currentUser() !== $profile['username']): ?>
                <a href="/forum/messages.php?to=<?= e($profile['username']) ?>" class="btn btn-secondary btn-sm" style="align-self:flex-start;">Message</a>
            <?php endif; ?>
        </div>

        <?php if (isLoggedIn() && currentUser() === $profile['username']): ?>
        <div style="padding:0 24px 20px;">
            <?php if ($avatarError): ?><div class="alert alert-error"><?= e($avatarError) ?></div><?php endif; ?>
            <?php if ($avatarSuccess): ?><div class="alert alert-success"><?= e($avatarSuccess) ?></div><?php endif; ?>

            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <form method="POST" enctype="multipart/form-data" style="flex:1; min-width:200px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload_avatar">
                    <div class="form-group" style="margin-bottom:8px;">
                        <label class="form-label" for="avatar">Profile Picture</label>
                        <input class="form-input" type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required style="padding:7px;">
                        <p class="form-hint">JPG, PNG, GIF, or WebP. Max 2MB.</p>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <button type="submit" class="btn btn-secondary btn-sm">Upload</button>
                        <?php if (getAvatarPath($profile['username'])): ?>
                            <button type="submit" form="remove-avatar-form" class="btn btn-danger btn-sm">Remove</button>
                        <?php endif; ?>
                    </div>
                </form>
                <form method="POST" id="remove-avatar-form" style="display:none;"><?= csrfField() ?><input type="hidden" name="action" value="remove_avatar"></form>
                <form method="POST" style="flex:2; min-width:250px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_bio">
                    <div class="form-group" style="margin-bottom:8px;">
                        <label class="form-label" for="bio">Bio</label>
                        <input class="form-input" type="text" id="bio" name="bio" maxlength="200" value="<?= e($profile['bio'] ?? '') ?>" placeholder="Tell us about yourself...">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Update Bio</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Achievements -->
    <div class="card mb-4">
        <div class="card-header"><h2>Achievements (<?= count($achievements) ?>/<?= count($allAchievements) ?>)</h2></div>
        <div style="padding:16px 20px;">
            <div class="achievements-grid">
                <?php foreach ($allAchievements as $achId => $ach):
                    $earned = in_array($achId, $achievements);
                ?>
                <div class="achievement <?= $earned ? '' : 'achievement-locked' ?>" title="<?= e($ach['desc']) ?>">
                    <span class="ach-icon"><?= $ach['icon'] ?></span>
                    <span class="ach-name"><?= e($ach['name']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (isLoggedIn() && currentUser() === $profile['username']): ?>
    <!-- Signature -->
    <div class="card mb-4">
        <div class="card-header"><h2>Signature</h2></div>
        <div style="padding:16px 20px;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_signature">
                <div class="form-group" style="margin-bottom:8px;">
                    <textarea class="form-textarea" name="signature" maxlength="300" rows="2" placeholder="Your signature appears below your posts..."><?= e($signature) ?></textarea>
                    <p class="form-hint">Max 300 characters. Appears below all your posts.</p>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">Update Signature</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentPosts)): ?>
    <div class="card">
        <div class="card-header"><h2>Recent Posts</h2></div>
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
