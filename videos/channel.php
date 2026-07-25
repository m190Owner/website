<?php
require __DIR__ . '/lib/bootstrap.php';

$uname = $_GET['u'] ?? '';
$db = videos_db();
$st = $db->prepare("SELECT * FROM users WHERE username = ?");
$st->execute([$uname]);
$ch = $st->fetch();

if (!$ch) {
    http_response_code(404);
    render_header('Channel not found');
    echo '<div class="v-auth"><h1>No such channel</h1><p class="v-dim"><a href="/videos/">Back to videos</a>.</p></div>';
    render_footer();
    exit;
}

$me = current_user();
$back = '/videos/channel.php?u=' . urlencode($ch['username']);

$subCount = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE channel_id = " . (int) $ch['id'])->fetchColumn();
$isSub = false;
if ($me) {
    $s = $db->prepare("SELECT 1 FROM subscriptions WHERE subscriber_id = ? AND channel_id = ?");
    $s->execute([$me['id'], $ch['id']]);
    $isSub = (bool) $s->fetch();
}
$isSelf = $me && (int) $me['id'] === (int) $ch['id'];

$vs = $db->prepare(
    "SELECT v.*, u.username FROM videos v JOIN users u ON u.id = v.user_id
      WHERE v.user_id = ? AND v.status = 'live' ORDER BY v.created_at DESC LIMIT 120"
);
$vs->execute([$ch['id']]);
$rows = $vs->fetchAll();

render_header($ch['username'] . '’s channel');
?>
<div class="v-channel-head">
  <div class="v-avatar"><?= e(strtoupper(substr($ch['username'], 0, 1))) ?></div>
  <div class="v-channel-info">
    <h1><?= e($ch['username']) ?><?php if (!empty($ch['is_admin'])): ?> <span class="v-badge">owner</span><?php endif; ?></h1>
    <div class="v-dim"><?= fmt_count($subCount) ?> subscriber<?= $subCount === 1 ? '' : 's' ?> · <?= count($rows) ?> video<?= count($rows) === 1 ? '' : 's' ?></div>
    <?php if (trim($ch['about']) !== ''): ?><p class="v-about"><?= nl2br(e($ch['about'])) ?></p><?php endif; ?>
  </div>
  <div class="v-channel-actions">
    <?php if ($isSelf): ?>
      <a href="/videos/upload.php" class="v-btn v-btn-accent">↑ Upload</a>
    <?php elseif ($me): ?>
      <form method="post" action="/videos/action.php" class="v-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <input type="hidden" name="channel_id" value="<?= (int) $ch['id'] ?>">
        <input type="hidden" name="action" value="<?= $isSub ? 'unsubscribe' : 'subscribe' ?>">
        <button class="v-btn <?= $isSub ? '' : 'v-btn-accent' ?>"><?= $isSub ? 'Subscribed ✓' : 'Subscribe' ?></button>
      </form>
    <?php endif; ?>
    <?php if ($me && !empty($me['is_admin']) && !$isSelf && empty($ch['is_admin'])): ?>
      <form method="post" action="/videos/action.php" class="v-inline"
            onsubmit="return confirm('<?= $ch['is_banned'] ? 'Unban' : 'Ban' ?> this user?');">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <input type="hidden" name="user_id" value="<?= (int) $ch['id'] ?>">
        <input type="hidden" name="action" value="<?= $ch['is_banned'] ? 'unban' : 'ban' ?>">
        <button class="v-btn v-btn-danger"><?= $ch['is_banned'] ? 'Unban' : 'Ban' ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
video_grid($rows, $isSelf ? "You haven't uploaded anything yet." : 'No videos on this channel yet.');
render_footer();
