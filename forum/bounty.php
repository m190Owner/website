<?php
require_once __DIR__ . '/includes/bootstrap.php';

$bountyId = $_GET['id'] ?? '';
$action = $_GET['action'] ?? '';
$error = '';
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn() && verifyCsrf()) {
    $postAction = $_POST['action'] ?? '';
    enforceRateLimit('forum_bounty', 15, 60);

    if ($postAction === 'create') {
        $result = createBounty(
            $_POST['title'] ?? '',
            $_POST['description'] ?? '',
            (int)($_POST['reward'] ?? 0),
            $_POST['category'] ?? '',
            $_POST['deadline'] ?? ''
        );
        if ($result['ok']) {
            header('Location: /forum/bounty.php?id=' . $result['id']);
            exit;
        }
        $error = $result['error'];
        $action = 'new';
    }

    if ($postAction === 'submit') {
        $result = submitBountySolution($_POST['bounty_id'] ?? '', $_POST['content'] ?? '');
        if ($result['ok']) $success = 'Solution submitted.';
        else $error = $result['error'];
        $bountyId = $_POST['bounty_id'] ?? '';
    }

    if ($postAction === 'award') {
        $result = awardBounty($_POST['bounty_id'] ?? '', $_POST['submission_id'] ?? '');
        if ($result['ok']) $success = 'Bounty awarded!';
        else $error = $result['error'];
        $bountyId = $_POST['bounty_id'] ?? '';
    }

    if ($postAction === 'cancel') {
        $result = cancelBounty($_POST['bounty_id'] ?? '');
        if ($result['ok']) { header('Location: /forum/bounty.php'); exit; }
        $error = $result['error'];
        $bountyId = $_POST['bounty_id'] ?? '';
    }
}

$navActive = 'bounty';
$pageTitle = 'Bounty Board';
require_once __DIR__ . '/includes/header.php';

// Detail view
if ($bountyId):
    $bounty = getBountyById($bountyId);
    if (!$bounty) {
        echo '<div class="forum-wrap"><div class="alert alert-error">Bounty not found.</div></div>';
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
    $cat = getBountyCategoryById($bounty['category']);
?>
<div class="forum-wrap" style="max-width:750px;">
    <div class="breadcrumbs">
        <a href="/forum/">m190</a><span class="sep">/</span>
        <a href="/forum/bounty.php">Bounty Board</a><span class="sep">/</span>
        <span><?= e(mb_strimwidth($bounty['title'], 0, 40, '...')) ?></span>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="card mb-4">
        <div style="padding:24px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <span class="bounty-status bounty-<?= e($bounty['status']) ?>"><?= strtoupper(e($bounty['status'])) ?></span>
                        <?php if ($cat): ?><span class="bounty-cat" style="color:<?= $cat['color'] ?>;background:<?= $cat['color'] ?>15;border-color:<?= $cat['color'] ?>30"><?= $cat['icon'] ?> <?= e($cat['name']) ?></span><?php endif; ?>
                    </div>
                    <h1 style="font-size:1.15rem;color:#e5e5e5;font-weight:700;margin-bottom:6px;"><?= e($bounty['title']) ?></h1>
                    <div class="thread-meta">
                        Posted by <a href="/forum/profile.php?user=<?= e($bounty['author']) ?>"><?= e($bounty['author']) ?></a>
                        &middot; <?= timeAgo($bounty['created']) ?>
                        <?php if ($bounty['deadline']): ?>&middot; Deadline: <span style="color:<?= strtotime($bounty['deadline']) < time() ? '#ff6b6b' : '#ffb86b' ?>"><?= date('M j, Y', strtotime($bounty['deadline'])) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="bounty-reward-big">
                    <span class="bounty-reward-num"><?= $bounty['reward'] ?></span>
                    <span class="bounty-reward-label">REP BOUNTY</span>
                </div>
            </div>
            <div class="post-content" style="margin-top:16px;"><?= formatContent($bounty['description']) ?></div>

            <?php if (isLoggedIn() && $bounty['author'] === currentUser() && $bounty['status'] === 'open'): ?>
            <div style="margin-top:16px;">
                <form method="POST" class="inline-form" onsubmit="return confirm('Cancel this bounty?')">
                    <?= csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="bounty_id" value="<?= e($bounty['id']) ?>">
                    <button class="btn btn-danger btn-sm">Cancel Bounty</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Submissions -->
    <div class="card mb-4">
        <div class="card-header">
            <h2>Submissions (<?= count($bounty['submissions']) ?>)</h2>
            <?php if ($bounty['winner']): ?><span style="color:#6bffb8;font-size:0.75rem;">Awarded to <?= e($bounty['winner']) ?></span><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($bounty['submissions'])): ?>
                <div class="empty-state"><p>No submissions yet. Be the first to claim this bounty.</p></div>
            <?php else: ?>
                <?php foreach ($bounty['submissions'] as $sub): ?>
                <div class="post" id="sub-<?= e($sub['id']) ?>">
                    <div class="post-sidebar">
                        <?= avatarHtml($sub['author'], 40) ?>
                        <a href="/forum/profile.php?user=<?= e($sub['author']) ?>" class="post-author-link"><?= e($sub['author']) ?></a>
                    </div>
                    <div class="post-body">
                        <div class="post-content"><?= formatContent($sub['content']) ?></div>
                        <div class="post-footer">
                            <span><?= timeAgo($sub['created']) ?></span>
                            <?php if ($bounty['winner'] === $sub['author']): ?>
                                <span style="color:#6bffb8;font-weight:700;font-size:0.75rem;">&#9733; WINNER &#9733;</span>
                            <?php elseif ($bounty['status'] === 'open' && isLoggedIn() && $bounty['author'] === currentUser()): ?>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Award <?= $bounty['reward'] ?> rep to <?= e($sub['author']) ?>?')">
                                    <?= csrfField() ?><input type="hidden" name="action" value="award"><input type="hidden" name="bounty_id" value="<?= e($bounty['id']) ?>"><input type="hidden" name="submission_id" value="<?= e($sub['id']) ?>">
                                    <button class="btn btn-primary btn-sm" style="padding:3px 10px;font-size:0.68rem;">Award Bounty</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Submit solution -->
    <?php if (isLoggedIn() && $bounty['status'] === 'open' && $bounty['author'] !== currentUser()):
        $alreadySubmitted = false;
        foreach ($bounty['submissions'] as $s) { if ($s['author'] === currentUser()) $alreadySubmitted = true; }
        if (!$alreadySubmitted):
    ?>
    <div class="card">
        <div class="card-header"><h2>Submit Solution</h2></div>
        <div style="padding:20px;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="bounty_id" value="<?= e($bounty['id']) ?>">
                <div class="form-group">
                    <textarea class="form-textarea" name="content" required minlength="5" maxlength="5000" placeholder="Describe your solution..." style="min-height:140px;"></textarea>
                    <span class="form-hint">**bold** *italic* `code` ```code block```</span>
                </div>
                <button type="submit" class="btn btn-primary">Submit Solution</button>
            </form>
        </div>
    </div>
    <?php endif; endif; ?>
</div>

<?php
// List view
elseif ($action === 'new' && isLoggedIn()):
?>
<div class="forum-wrap" style="max-width:700px;">
    <div class="breadcrumbs">
        <a href="/forum/">m190</a><span class="sep">/</span>
        <a href="/forum/bounty.php">Bounty Board</a><span class="sep">/</span>
        <span>New Bounty</span>
    </div>

    <div class="card">
        <div class="card-header"><h2>Post a Bounty</h2></div>
        <div style="padding:20px;">
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-row">
                    <div class="form-group" style="flex:2;min-width:200px;">
                        <label class="form-label">Title</label>
                        <input class="form-input" type="text" name="title" required minlength="5" maxlength="120" value="<?= e($_POST['title'] ?? '') ?>" placeholder="What needs to be done?">
                    </div>
                    <div class="form-group" style="flex:1;min-width:100px;">
                        <label class="form-label">Reward (Rep)</label>
                        <input class="form-input" type="number" name="reward" required min="1" max="500" value="<?= e($_POST['reward'] ?? '10') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category" required>
                            <?php foreach (getBountyCategories() as $bc): ?>
                            <option value="<?= e($bc['id']) ?>" <?= ($_POST['category'] ?? '') === $bc['id'] ? 'selected' : '' ?>><?= $bc['icon'] ?> <?= e($bc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">Deadline (optional)</label>
                        <input class="form-input" type="date" name="deadline" value="<?= e($_POST['deadline'] ?? '') ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" name="description" required minlength="10" maxlength="5000" style="min-height:180px;" placeholder="Describe the challenge in detail. What are the requirements? How will you judge solutions?"><?= e($_POST['description'] ?? '') ?></textarea>
                    <span class="form-hint">**bold** *italic* `code` ```code block```</span>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="btn btn-primary">Post Bounty</button>
                    <a href="/forum/bounty.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else:
    $filter = $_GET['filter'] ?? 'open';
    $bounties = getBounties($filter);
?>
<div class="forum-wrap">
    <div class="breadcrumbs">
        <a href="/forum/">m190</a><span class="sep">/</span><span>Bounty Board</span>
    </div>

    <div class="flex-between mb-4">
        <div>
            <h1 style="font-size:1.1rem;color:#e5e5e5;font-weight:700;">Bounty Board</h1>
            <p style="font-size:0.75rem;color:#5a6480;margin-top:2px;">Post challenges. Claim rewards.</p>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
            <?php if (isLoggedIn()): ?>
                <a href="/forum/bounty.php?action=new" class="btn btn-primary btn-sm">+ Post Bounty</a>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:flex;gap:4px;margin-bottom:16px;">
        <a href="/forum/bounty.php?filter=open" class="btn btn-sm <?= $filter === 'open' ? 'btn-primary' : 'btn-secondary' ?>">Open</a>
        <a href="/forum/bounty.php?filter=completed" class="btn btn-sm <?= $filter === 'completed' ? 'btn-primary' : 'btn-secondary' ?>">Completed</a>
        <a href="/forum/bounty.php?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if (empty($bounties)): ?>
                <div class="empty-state">
                    <p>No bounties yet.</p>
                    <?php if (isLoggedIn()): ?><a href="/forum/bounty.php?action=new" class="btn btn-primary btn-sm">Post the first bounty</a><?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($bounties as $b):
                    $bCat = getBountyCategoryById($b['category']);
                ?>
                <a href="/forum/bounty.php?id=<?= e($b['id']) ?>" class="bounty-row">
                    <div class="bounty-reward-badge">
                        <span class="bounty-reward-val"><?= $b['reward'] ?></span>
                        <span class="bounty-reward-unit">REP</span>
                    </div>
                    <div class="bounty-info">
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span class="bounty-status bounty-<?= e($b['status']) ?>"><?= strtoupper(e($b['status'])) ?></span>
                            <?php if ($bCat): ?><span class="bounty-cat" style="color:<?= $bCat['color'] ?>;background:<?= $bCat['color'] ?>15;border-color:<?= $bCat['color'] ?>30"><?= $bCat['icon'] ?> <?= e($bCat['name']) ?></span><?php endif; ?>
                            <span class="bounty-title"><?= e($b['title']) ?></span>
                        </div>
                        <div class="thread-meta" style="margin-top:4px;">
                            by <span style="color:#8a96b8;"><?= e($b['author']) ?></span>
                            &middot; <?= timeAgo($b['created']) ?>
                            &middot; <?= count($b['submissions']) ?> submission<?= count($b['submissions']) !== 1 ? 's' : '' ?>
                            <?php if ($b['deadline']): ?>&middot; due <?= date('M j', strtotime($b['deadline'])) ?><?php endif; ?>
                            <?php if ($b['winner']): ?>&middot; <span style="color:#6bffb8;">won by <?= e($b['winner']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
