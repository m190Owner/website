<?php
require __DIR__ . '/lib/bootstrap.php';

$me = require_login();
$db = videos_db();

// Channels the user follows.
$chs = $db->prepare(
    "SELECT u.id, u.username,
            (SELECT COUNT(*) FROM videos v WHERE v.user_id = u.id AND v.status = 'live') AS n
       FROM subscriptions s JOIN users u ON u.id = s.channel_id
      WHERE s.subscriber_id = ? ORDER BY u.username COLLATE NOCASE"
);
$chs->execute([$me['id']]);
$channels = $chs->fetchAll();

// Latest videos across those channels.
$vs = $db->prepare(
    "SELECT v.*, u.username FROM videos v JOIN users u ON u.id = v.user_id
      WHERE v.status = 'live'
        AND v.user_id IN (SELECT channel_id FROM subscriptions WHERE subscriber_id = ?)
      ORDER BY v.created_at DESC LIMIT 60"
);
$vs->execute([$me['id']]);
$rows = $vs->fetchAll();

render_header('Subscriptions');
?>
<div class="v-feed-head"><h1>Your subscriptions</h1></div>

<?php if ($channels): ?>
  <div class="v-chips">
    <?php foreach ($channels as $c): ?>
      <a class="v-chip" href="/videos/channel.php?u=<?= urlencode($c['username']) ?>">
        <span class="v-chip-av"><?= e(strtoupper(substr($c['username'], 0, 1))) ?></span>
        <?= e($c['username']) ?> <span class="v-dim">· <?= (int) $c['n'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
video_grid($rows, "You're not subscribed to any channels yet. Find a creator and hit Subscribe.");
render_footer();
