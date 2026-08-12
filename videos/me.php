<?php
// Personal dashboard — a private, login-only overview that unifies the user's
// videos with their casino wallet (coins, net worth, inventory). Read-only.
// The public per-user page stays channel.php; this is "your account" view.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/../casino/lib/items.php';   // casino_balance, inventory_list/value, fmt_coins

$u   = require_login();
$uid = (int) $u['id'];
$db  = videos_db();

$coins    = casino_balance($uid);
$inv      = inventory_list($uid);
$invVal   = inventory_value($uid);
$netWorth = $coins + $invVal;

$vstat = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(views), 0) AS v FROM videos WHERE user_id = ? AND status = 'live'");
$vstat->execute([$uid]);
$vrow     = $vstat->fetch();
$vidCount = (int) $vrow['n'];
$vidViews = (int) $vrow['v'];

$subs = (int) $db->query("SELECT COUNT(*) FROM subscriptions WHERE channel_id = " . $uid)->fetchColumn();

$vs = $db->prepare(
    "SELECT v.*, u.username FROM videos v JOIN users u ON u.id = v.user_id
      WHERE v.user_id = ? AND v.status = 'live' ORDER BY v.created_at DESC LIMIT 120"
);
$vs->execute([$uid]);
$videos = $vs->fetchAll();

// Top items by value for a compact preview.
$topItems = $inv;
usort($topItems, fn($a, $b) => $b['value'] <=> $a['value']);
$topItems = array_slice($topItems, 0, 6);

render_header('Dashboard');
?>
<div class="v-dash-head">
  <?= avatar_html($u['username'], $u['avatar'], 'v-avatar') ?>
  <div class="v-dash-id">
    <h1><?= e($u['username']) ?><?php if (!empty($u['is_admin'])): ?> <span class="v-badge">owner</span><?php endif; ?></h1>
    <div class="v-dim">Member since <?= e(time_ago((int) $u['created_at'])) ?> &middot;
      <a href="/videos/channel.php?u=<?= urlencode($u['username']) ?>">View public channel &rarr;</a></div>
  </div>
  <div class="v-dash-actions">
    <a href="/videos/upload.php" class="v-btn v-btn-accent">&uarr; Upload</a>
    <a href="/videos/settings.php" class="v-btn">&#9998; Edit profile</a>
  </div>
</div>

<div class="v-stats">
  <div class="v-stat"><span class="v-stat-num">🪙 <?= fmt_coins($coins) ?></span><span class="v-stat-lbl">LS coins</span></div>
  <div class="v-stat"><span class="v-stat-num">💰 <?= fmt_coins($netWorth) ?></span><span class="v-stat-lbl">net worth</span></div>
  <div class="v-stat"><span class="v-stat-num">🎬 <?= fmt_count($vidCount) ?></span><span class="v-stat-lbl">video<?= $vidCount === 1 ? '' : 's' ?></span></div>
  <div class="v-stat"><span class="v-stat-num">👥 <?= fmt_count($subs) ?></span><span class="v-stat-lbl">subscriber<?= $subs === 1 ? '' : 's' ?></span></div>
  <div class="v-stat"><span class="v-stat-num">👁 <?= fmt_count($vidViews) ?></span><span class="v-stat-lbl">total views</span></div>
</div>

<section class="v-dash-sec">
  <div class="v-sec-head">
    <h2 class="v-section">🎰 Wallet</h2>
    <div class="v-sec-actions"><a href="/casino/" class="v-btn">Casino</a><a href="/casino/inventory.php" class="v-btn">Inventory</a></div>
  </div>
  <div class="v-wallet">
    <div class="v-wallet-bal">🪙 <b><?= fmt_coins($coins) ?></b> <span class="v-dim">LS coins</span></div>
    <div class="v-dim">Net worth <b class="v-net"><?= fmt_coins($netWorth) ?></b> &middot; <?= count($inv) ?> item<?= count($inv) === 1 ? '' : 's' ?> worth <?= fmt_coins($invVal) ?></div>
    <?php if ($topItems): ?>
      <div class="v-items">
        <?php foreach ($topItems as $it): ?>
          <span class="v-item-chip" style="border-color:<?= e($it['color']) ?>55">
            <span class="v-item-dot" style="background:<?= e($it['color']) ?>"></span>
            <span class="v-item-name"><?= e($it['name']) ?></span>
            <span class="v-item-val">🪙<?= fmt_coins((int) $it['value']) ?></span>
          </span>
        <?php endforeach; ?>
        <?php if (count($inv) > count($topItems)): ?>
          <a href="/casino/inventory.php" class="v-item-more">+<?= count($inv) - count($topItems) ?> more &rarr;</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p class="v-dim">No items yet. <a href="/casino/cases.php">Open a case &rarr;</a></p>
    <?php endif; ?>
  </div>
</section>

<section class="v-dash-sec">
  <div class="v-sec-head">
    <h2 class="v-section">🎬 Your videos <span class="v-dim">(<?= $vidCount ?>)</span></h2>
    <a href="/videos/upload.php" class="v-btn v-btn-accent">&uarr; Upload</a>
  </div>
  <?php video_grid($videos, "You haven't uploaded anything yet. Share your first video!"); ?>
</section>
<?php render_footer();
