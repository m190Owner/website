<?php
require __DIR__ . '/lib/bootstrap.php';

$me = require_admin();
$db = videos_db();
$back = '/videos/admin.php';

// Password reset — handled inline (never redirect: the temp password must not
// end up in a URL). Shows the new credential once for the admin to pass along.
$resetCred = null; $resetErr = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reset_pw') {
    csrf_require();
    enforceRateLimit('videos_admin_reset', 60, 60);
    $uid = (int) ($_POST['user_id'] ?? 0);
    if ($uid <= 0 && trim($_POST['username'] ?? '') !== '') $uid = user_id_by_name($_POST['username']) ?? 0;
    $resetCred = $uid > 0 ? admin_reset_password($uid) : null;
    if (!$resetCred) $resetErr = 'No such user.';
}
$resets = pending_resets();

// Open reports (newest first).
$reports = $db->query(
    "SELECT r.*, u.username AS reporter
       FROM reports r LEFT JOIN users u ON u.id = r.reporter_id
      WHERE r.resolved = 0 ORDER BY r.created_at DESC LIMIT 200"
)->fetchAll();

// Attach a preview of each report's target.
foreach ($reports as &$r) {
    if ($r['target_type'] === 'video') {
        $s = $db->prepare("SELECT v.title, v.status, u.username FROM videos v JOIN users u ON u.id = v.user_id WHERE v.id = ?");
        $s->execute([$r['target_id']]);
        $r['target'] = $s->fetch() ?: null;
    } else {
        $s = $db->prepare("SELECT c.body, c.status, c.video_id, u.username FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?");
        $s->execute([(int) $r['target_id']]);
        $r['target'] = $s->fetch() ?: null;
    }
}
unset($r);

// Stats.
$nVideos = (int) $db->query("SELECT COUNT(*) FROM videos WHERE status = 'live'")->fetchColumn();
$nUsers  = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$nBanned = (int) $db->query("SELECT COUNT(*) FROM users WHERE is_banned = 1")->fetchColumn();
$used    = dir_size_bytes(VIDEOS_MEDIA_DIR);
$pct     = min(100, round($used / VIDEO_GLOBAL_CAP_BYTES * 100, 1));

render_header('Admin');
?>
<div class="v-feed-head">
  <h1>Admin</h1>
  <a href="/videos/admin_users.php" class="v-btn v-btn-accent">👥 Manage users</a>
</div>

<div class="v-stats">
  <div class="v-stat"><b><?= $nVideos ?></b><span>videos</span></div>
  <div class="v-stat"><b><?= $nUsers ?></b><span>users</span></div>
  <div class="v-stat"><b><?= $nBanned ?></b><span>banned</span></div>
  <div class="v-stat"><b><?= human_size($used) ?></b><span>of <?= human_size(VIDEO_GLOBAL_CAP_BYTES) ?> used</span></div>
</div>
<div class="v-progress" style="max-width:420px"><div class="v-progress-bar" style="width:<?= $pct ?>%"></div></div>

<h2 class="v-section">Password resets <span class="v-dim">(<?= count($resets) ?> pending)</span></h2>

<?php if ($resetCred): ?>
  <div class="v-flash">🔑 New password for <b><?= e($resetCred['username']) ?></b>: <code class="v-pw"><?= e($resetCred['password']) ?></code> — send it to them (they can change it after logging in).</div>
<?php elseif ($resetErr): ?>
  <div class="v-error"><?= e($resetErr) ?></div>
<?php endif; ?>

<form method="post" class="v-pwreset-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="reset_pw">
  <input type="text" name="username" placeholder="reset any user by username" maxlength="16" autocomplete="off">
  <button class="v-btn v-btn-accent" onsubmit="return confirm('Reset this user\'s password?');">Reset password</button>
</form>

<?php if ($resets): ?>
  <div class="v-reports">
    <?php foreach ($resets as $rq): ?>
      <div class="v-report-row">
        <div class="v-report-main">
          <span class="v-tag">reset</span>
          <a href="/videos/channel.php?u=<?= urlencode($rq['username']) ?>"><?= e($rq['username']) ?></a>
          <span class="v-dim">requested <?= e(time_ago((int) $rq['created_at'])) ?></span>
        </div>
        <div class="v-report-buttons">
          <form method="post" class="v-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_pw">
            <input type="hidden" name="user_id" value="<?= (int) $rq['user_id'] ?>">
            <button class="v-btn v-btn-accent">Approve &amp; reset</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <p class="v-dim v-empty">No pending reset requests.</p>
<?php endif; ?>

<h2 class="v-section">Reports <span class="v-dim">(<?= count($reports) ?> open)</span></h2>

<?php if (!$reports): ?>
  <p class="v-dim v-empty">No open reports. 🎉</p>
<?php else: ?>
  <div class="v-reports">
    <?php foreach ($reports as $r): ?>
      <div class="v-report-row">
        <div class="v-report-main">
          <span class="v-tag"><?= e($r['target_type']) ?></span>
          <?php if ($r['target'] === null): ?>
            <span class="v-dim">target no longer exists</span>
          <?php elseif ($r['target_type'] === 'video'): ?>
            <a href="/videos/watch.php?v=<?= urlencode($r['target_id']) ?>"><?= e($r['target']['title']) ?></a>
            <span class="v-dim">by <?= e($r['target']['username']) ?><?= $r['target']['status'] !== 'live' ? ' · already removed' : '' ?></span>
          <?php else: ?>
            <a href="/videos/watch.php?v=<?= urlencode($r['target']['video_id']) ?>#comments">comment by <?= e($r['target']['username']) ?></a>
            <div class="v-report-quote"><?= e(mb_strimwidth($r['target']['body'], 0, 200, '…')) ?></div>
          <?php endif; ?>
          <?php if (trim($r['reason']) !== ''): ?><div class="v-report-reason">“<?= e($r['reason']) ?>”</div><?php endif; ?>
          <div class="v-dim">reported by <?= e($r['reporter'] ?? 'unknown') ?> · <?= e(time_ago((int) $r['created_at'])) ?></div>
        </div>
        <div class="v-report-buttons">
          <?php if ($r['target'] !== null && ($r['target']['status'] ?? '') === 'live'): ?>
            <?php if ($r['target_type'] === 'video'): ?>
              <form method="post" action="/videos/action.php" class="v-inline" onsubmit="return confirm('Delete this video?');">
                <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                <input type="hidden" name="action" value="delete_video">
                <input type="hidden" name="video_id" value="<?= e($r['target_id']) ?>">
                <button class="v-btn v-btn-danger">Delete video</button>
              </form>
            <?php else: ?>
              <form method="post" action="/videos/action.php" class="v-inline">
                <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                <input type="hidden" name="action" value="delete_comment">
                <input type="hidden" name="comment_id" value="<?= (int) $r['target_id'] ?>">
                <button class="v-btn v-btn-danger">Delete comment</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" action="/videos/action.php" class="v-inline">
            <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
            <input type="hidden" name="action" value="resolve_report">
            <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
            <button class="v-btn">Dismiss</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php render_footer();
