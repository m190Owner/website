<?php
require __DIR__ . '/lib/bootstrap.php';

$me = require_admin();
$db = videos_db();
$id = (int) ($_GET['id'] ?? 0);

$st = $db->prepare("SELECT * FROM users WHERE id = ?");
$st->execute([$id]);
$U = $st->fetch();
if (!$U) {
    http_response_code(404);
    render_header('User not found');
    echo '<div class="v-auth"><h1>No such user</h1><p class="v-dim"><a href="/videos/admin_users.php">Back to users</a>.</p></div>';
    render_footer();
    exit;
}

$back = '/videos/admin_user.php?id=' . $id;
$manageable = empty($U['is_admin']);   // never moderate an admin/owner

$nvids = (int) $db->query("SELECT COUNT(*) FROM videos WHERE user_id = $id AND status = 'live'")->fetchColumn();
$nsubs = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE channel_id = $id")->fetchColumn();

$warns = $db->prepare("SELECT w.*, a.username AS by_name FROM warnings w LEFT JOIN users a ON a.id = w.issued_by WHERE w.user_id = ? ORDER BY w.created_at DESC");
$warns->execute([$id]);
$warnings = $warns->fetchAll();

$vids = $db->prepare("SELECT * FROM videos WHERE user_id = ? AND status = 'live' ORDER BY created_at DESC LIMIT 100");
$vids->execute([$id]);
$videos = $vids->fetchAll();

$cmts = $db->prepare("SELECT c.*, v.title AS vtitle FROM comments c JOIN videos v ON v.id = c.video_id WHERE c.user_id = ? AND c.status = 'live' ORDER BY c.created_at DESC LIMIT 100");
$cmts->execute([$id]);
$comments = $cmts->fetchAll();

$flash = $_GET['flash'] ?? '';
render_header('User · ' . $U['username']);
?>
<?php if ($flash): ?><div class="v-flash"><?= e($flash) ?></div><?php endif; ?>

<div class="v-feed-head">
  <h1>Manage user</h1>
  <a href="/videos/admin_users.php" class="v-btn">← All users</a>
</div>

<div class="v-channel-head">
  <?= avatar_html($U['username'], $U['avatar'], 'v-avatar') ?>
  <div class="v-channel-info">
    <h1>
      <a href="/videos/channel.php?u=<?= urlencode($U['username']) ?>"><?= e($U['username']) ?></a>
      <?php if (!empty($U['is_admin'])): ?><span class="v-badge">owner</span>
      <?php elseif (!empty($U['is_banned'])): ?><span class="v-tag v-tag-banned">banned</span>
      <?php elseif (!empty($U['is_muted'])): ?><span class="v-tag v-tag-muted">muted</span><?php endif; ?>
    </h1>
    <div class="v-dim">joined <?= e(time_ago((int) $U['created_at'])) ?> · <?= $nvids ?> videos · <?= $nsubs ?> subscribers · <?= count($warnings) ?>/<?= WARN_BAN_THRESHOLD ?> warnings</div>
    <?php if (trim($U['about']) !== ''): ?><p class="v-about"><?= nl2br(e($U['about'])) ?></p><?php endif; ?>
  </div>
</div>

<?php if (!$manageable): ?>
  <p class="v-dim">This is an owner/admin account and can't be moderated.</p>
<?php else: ?>
  <div class="v-modpanel">
    <form method="post" action="/videos/action.php" class="v-warnform">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="warn">
      <input type="hidden" name="user_id" value="<?= $id ?>">
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <textarea name="reason" rows="2" maxlength="<?= REPORT_REASON_MAX ?>" placeholder="Reason for warning (shown to the user)…"></textarea>
      <button class="v-btn v-btn-danger">⚠ Warn (<?= count($warnings) ?>/<?= WARN_BAN_THRESHOLD ?> → ban)</button>
    </form>

    <div class="v-modbtns">
      <?php
        $btn = function (string $action, string $label, string $cls = 'v-btn', string $confirm = '') use ($id, $back) {
            $c = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ');"' : '';
            echo '<form method="post" action="/videos/action.php" class="v-inline"' . $c . '>'
               . csrf_field()
               . '<input type="hidden" name="action" value="' . e($action) . '">'
               . '<input type="hidden" name="user_id" value="' . $id . '">'
               . '<input type="hidden" name="back" value="' . e($back) . '">'
               . '<button class="' . e($cls) . '">' . e($label) . '</button></form>';
        };
        if ((int) $U['is_muted'] === 1) $btn('unmute', 'Unmute');
        else                           $btn('mute', 'Mute (suspend posting)', 'v-btn');
        if ((int) $U['is_banned'] === 1) $btn('unban', 'Unban', 'v-btn');
        else                            $btn('ban', 'Ban', 'v-btn-danger', 'Ban this user?');
        if ($nvids > 0)                 $btn('delete_user_videos', "Delete all $nvids videos", 'v-btn-danger', 'Delete every video by this user?');
      ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($warnings): ?>
  <h2 class="v-section">Warning history</h2>
  <div class="v-reports">
    <?php foreach ($warnings as $w): ?>
      <div class="v-report-row">
        <div class="v-report-main">
          <div class="v-report-reason"><?= trim($w['reason']) !== '' ? e($w['reason']) : '<span class="v-dim">no reason given</span>' ?></div>
          <div class="v-dim">by <?= e($w['by_name'] ?? 'unknown') ?> · <?= e(time_ago((int) $w['created_at'])) ?> · <?= $w['acknowledged'] ? 'acknowledged' : 'not yet seen' ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2 class="v-section">Videos <span class="v-dim">(<?= count($videos) ?>)</span></h2>
<?php if (!$videos): ?><p class="v-dim v-empty">No live videos.</p><?php else: ?>
  <div class="v-modlist">
    <?php foreach ($videos as $v): ?>
      <div class="v-modline">
        <a href="/videos/watch.php?v=<?= urlencode($v['id']) ?>"><?= e($v['title']) ?></a>
        <span class="v-dim"><?= fmt_count((int) $v['views']) ?> views · <?= e(time_ago((int) $v['created_at'])) ?></span>
        <form method="post" action="/videos/action.php" class="v-inline" onsubmit="return confirm('Delete this video?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_video">
          <input type="hidden" name="video_id" value="<?= e($v['id']) ?>">
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <button class="v-link-btn">delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2 class="v-section">Comments <span class="v-dim">(<?= count($comments) ?>)</span></h2>
<?php if (!$comments): ?><p class="v-dim v-empty">No comments.</p><?php else: ?>
  <div class="v-modlist">
    <?php foreach ($comments as $c): ?>
      <div class="v-modline">
        <span class="v-modcomment"><?= e(mb_strimwidth($c['body'], 0, 140, '…')) ?></span>
        <a class="v-dim" href="/videos/watch.php?v=<?= urlencode($c['video_id']) ?>#comments">on “<?= e(mb_strimwidth($c['vtitle'], 0, 40, '…')) ?>”</a>
        <form method="post" action="/videos/action.php" class="v-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_comment">
          <input type="hidden" name="comment_id" value="<?= (int) $c['id'] ?>">
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <button class="v-link-btn">delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php render_footer();
