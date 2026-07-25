<?php
require __DIR__ . '/lib/bootstrap.php';

$id = $_GET['v'] ?? '';
$db = videos_db();

$st = $db->prepare(
    "SELECT v.*, u.username, u.id AS uploader_id
       FROM videos v JOIN users u ON u.id = v.user_id
      WHERE v.id = ? AND v.status = 'live'"
);
$st->execute([$id]);
$v = $st->fetch();

if (!$v) {
    http_response_code(404);
    render_header('Not found');
    echo '<div class="v-auth"><h1>Video not found</h1><p class="v-dim">It may have been removed. '
       . '<a href="/videos/">Back to videos</a>.</p></div>';
    render_footer();
    exit;
}

$me   = current_user();
$back = '/videos/watch.php?v=' . urlencode($id);

// Count a view once per session.
if (empty($_SESSION['viewed'][$id])) {
    $db->prepare("UPDATE videos SET views = views + 1 WHERE id = ?")->execute([$id]);
    $_SESSION['viewed'][$id] = 1;
    $v['views']++;
}

// Vote tallies + this user's vote.
$likes    = (int) $db->query("SELECT COUNT(*) FROM votes WHERE value = 1  AND video_id = " . $db->quote($id))->fetchColumn();
$dislikes = (int) $db->query("SELECT COUNT(*) FROM votes WHERE value = -1 AND video_id = " . $db->quote($id))->fetchColumn();
$myVote = 0;
if ($me) {
    $mv = $db->prepare("SELECT value FROM votes WHERE video_id = ? AND user_id = ?");
    $mv->execute([$id, $me['id']]);
    $myVote = (int) ($mv->fetchColumn() ?: 0);
}

// Subscriber count + am I subscribed.
$subCount = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE channel_id = " . (int) $v['uploader_id'])->fetchColumn();
$isSub = false;
if ($me) {
    $s = $db->prepare("SELECT 1 FROM subscriptions WHERE subscriber_id = ? AND channel_id = ?");
    $s->execute([$me['id'], $v['uploader_id']]);
    $isSub = (bool) $s->fetch();
}
$isOwner = $me && ((int) $me['id'] === (int) $v['uploader_id']);
$canModerate = $isOwner || ($me && !empty($me['is_admin']));

// Comments.
$cs = $db->prepare(
    "SELECT c.*, u.username, u.avatar FROM comments c JOIN users u ON u.id = c.user_id
      WHERE c.video_id = ? AND c.status = 'live' ORDER BY c.created_at DESC LIMIT 500"
);
$cs->execute([$id]);
$comments = $cs->fetchAll();

$flash = $_GET['flash'] ?? '';

render_header($v['title']);
?>
<?php if ($flash): ?><div class="v-flash"><?= e($flash) ?></div><?php endif; ?>

<div class="v-watch">
  <div class="v-player">
    <video controls playsinline preload="metadata" poster="<?= e(thumb_url($v)) ?>">
      <source src="/videos/stream.php?v=<?= urlencode($id) ?>" type="<?= e($v['mime']) ?>">
    </video>
  </div>

  <h1 class="v-watch-title"><?= e($v['title']) ?></h1>

  <div class="v-watch-bar">
    <div class="v-chan-block">
      <a class="v-chan-name" href="/videos/channel.php?u=<?= urlencode($v['username']) ?>"><?= e($v['username']) ?></a>
      <span class="v-dim"><?= fmt_count($subCount) ?> subscriber<?= $subCount === 1 ? '' : 's' ?></span>
    </div>
    <div class="v-actions">
      <?php if ($me && !$isOwner): ?>
        <form method="post" action="/videos/action.php" class="v-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <input type="hidden" name="channel_id" value="<?= (int) $v['uploader_id'] ?>">
          <input type="hidden" name="action" value="<?= $isSub ? 'unsubscribe' : 'subscribe' ?>">
          <button class="v-btn <?= $isSub ? '' : 'v-btn-accent' ?>"><?= $isSub ? 'Subscribed ✓' : 'Subscribe' ?></button>
        </form>
      <?php endif; ?>

      <div class="v-votes">
        <form method="post" action="/videos/action.php" class="v-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <input type="hidden" name="video_id" value="<?= e($id) ?>">
          <input type="hidden" name="value" value="1">
          <button name="action" value="vote" class="v-vote <?= $myVote === 1 ? 'on' : '' ?>"><span>▲</span> <?= fmt_count($likes) ?></button>
        </form>
        <form method="post" action="/videos/action.php" class="v-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <input type="hidden" name="video_id" value="<?= e($id) ?>">
          <input type="hidden" name="value" value="-1">
          <button name="action" value="vote" class="v-vote <?= $myVote === -1 ? 'on' : '' ?>"><span>▼</span> <?= fmt_count($dislikes) ?></button>
        </form>
      </div>

      <details class="v-report">
        <summary class="v-btn">⚑ Report</summary>
        <form method="post" action="/videos/action.php" class="v-form v-report-form">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <input type="hidden" name="action" value="report">
          <input type="hidden" name="target_type" value="video">
          <input type="hidden" name="target_id" value="<?= e($id) ?>">
          <textarea name="reason" rows="2" maxlength="<?= REPORT_REASON_MAX ?>" placeholder="What's wrong with this video? (optional)"></textarea>
          <button class="v-btn v-btn-danger">Submit report</button>
        </form>
      </details>

      <?php if ($canModerate): ?>
        <form method="post" action="/videos/action.php" class="v-inline"
              onsubmit="return confirm('Delete this video permanently?');">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <input type="hidden" name="action" value="delete_video">
          <input type="hidden" name="video_id" value="<?= e($id) ?>">
          <button class="v-btn v-btn-danger">Delete</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="v-watch-meta">
    <div class="v-dim"><?= fmt_count((int) $v['views']) ?> views · <?= e(time_ago((int) $v['created_at'])) ?></div>
    <?php if (trim($v['description']) !== ''): ?>
      <div class="v-desc"><?= nl2br(e($v['description'])) ?></div>
    <?php endif; ?>
  </div>

  <section class="v-comments" id="comments">
    <h2><?= count($comments) ?> comment<?= count($comments) === 1 ? '' : 's' ?></h2>

    <?php if ($me): ?>
      <form method="post" action="/videos/action.php" class="v-comment-form">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <input type="hidden" name="action" value="comment">
        <input type="hidden" name="video_id" value="<?= e($id) ?>">
        <textarea name="body" rows="2" maxlength="<?= COMMENT_MAX ?>" placeholder="Add a comment…" required></textarea>
        <button class="v-btn v-btn-accent">Comment</button>
      </form>
    <?php else: ?>
      <p class="v-dim"><a href="/videos/login.php?next=<?= urlencode($back) ?>">Log in</a> to comment.</p>
    <?php endif; ?>

    <?php foreach ($comments as $c): ?>
      <div class="v-comment">
        <a class="v-comment-av" href="/videos/channel.php?u=<?= urlencode($c['username']) ?>"><?= avatar_html($c['username'], $c['avatar'], 'v-chip-av') ?></a>
        <div class="v-comment-main">
        <div class="v-comment-head">
          <a href="/videos/channel.php?u=<?= urlencode($c['username']) ?>" class="v-comment-author"><?= e($c['username']) ?></a>
          <span class="v-dim"><?= e(time_ago((int) $c['created_at'])) ?></span>
        </div>
        <div class="v-comment-body"><?= nl2br(e($c['body'])) ?></div>
        <div class="v-comment-actions">
          <?php if ($me): ?>
            <form method="post" action="/videos/action.php" class="v-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="back" value="<?= e($back) ?>">
              <input type="hidden" name="action" value="report">
              <input type="hidden" name="target_type" value="comment">
              <input type="hidden" name="target_id" value="<?= (int) $c['id'] ?>">
              <button class="v-link-btn">report</button>
            </form>
          <?php endif; ?>
          <?php if ($canModerate || ($me && (int) $me['id'] === (int) $c['user_id'])): ?>
            <form method="post" action="/videos/action.php" class="v-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="back" value="<?= e($back) ?>">
              <input type="hidden" name="action" value="delete_comment">
              <input type="hidden" name="comment_id" value="<?= (int) $c['id'] ?>">
              <button class="v-link-btn">delete</button>
            </form>
          <?php endif; ?>
        </div>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
</div>
<?php render_footer();
