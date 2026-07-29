<?php
require __DIR__ . '/lib/casino.php';

$me = require_login();
if (empty($me['is_admin'])) {
    http_response_code(403);
    render_casino_header('Forbidden', $me);
    echo '<div class="c-game-page"><h1>Forbidden</h1><p class="c-dim">Admins only.</p></div>';
    render_casino_footer();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    enforceRateLimit('casino_admin', 60, 60);
    $uname  = trim($_POST['username'] ?? '');
    $amount = (int) ($_POST['amount'] ?? 0);
    if ($amount < -1000000000 || $amount > 1000000000) $amount = 0;
    $flash = 'Enter a username and a non-zero amount.';
    if ($uname !== '' && $amount !== 0) {
        $st = videos_db()->prepare("SELECT id, username FROM users WHERE username = ?");
        $st->execute([$uname]);
        $t = $st->fetch();
        if (!$t) {
            $flash = 'No such user: ' . $uname;
        } else {
            $newbal = casino_admin_adjust((int) $t['id'], $amount);
            $flash = ($amount > 0 ? '➕ Gave ' : '➖ Took ') . fmt_coins(abs($amount)) . ' LS '
                   . ($amount > 0 ? 'to ' : 'from ') . $t['username'] . ' — new balance ' . fmt_coins($newbal) . '.';
        }
    }
    redirect('/casino/admin.php?flash=' . urlencode($flash) . '&q=' . urlencode($_POST['q'] ?? ''));
}

$flash = $_GET['flash'] ?? '';
$q = trim($_GET['q'] ?? '');
$base = "SELECT id, username, coins, is_admin FROM users ";
if ($q !== '') { $st = videos_db()->prepare($base . "WHERE username LIKE ? ORDER BY coins DESC LIMIT 200"); $st->execute(['%' . $q . '%']); }
else { $st = videos_db()->query($base . "ORDER BY coins DESC LIMIT 200"); }
$users = $st->fetchAll();

render_casino_header('Admin', $me);
?>
<div class="c-game-page" style="max-width:780px;text-align:left">
  <h1>🛠 Casino Admin</h1>
  <p class="c-dim">Grant or remove LS coins. Positive gives, negative takes (never below 0).</p>
  <?php if ($flash): ?><div class="c-flash"><?= e($flash) ?></div><?php endif; ?>

  <form method="post" class="c-admin-give">
    <?= csrf_field() ?>
    <input type="text" name="username" placeholder="username" autocomplete="off" required>
    <input type="number" name="amount" placeholder="coins (+/-)" required>
    <button class="c-btn c-btn-gold">Apply</button>
  </form>

  <form method="get" class="c-admin-search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="filter users" autocomplete="off">
    <button class="c-btn">Search</button>
  </form>

  <div class="c-admin-table">
    <div class="c-admin-row head"><span>User</span><span>Balance</span><span>Quick grant</span></div>
    <?php foreach ($users as $row): ?>
      <div class="c-admin-row">
        <span><?= e($row['username']) ?><?= $row['is_admin'] ? ' 🛠' : '' ?></span>
        <span class="c-lcoins">🪙 <?= fmt_coins((int) $row['coins']) ?></span>
        <form method="post" class="c-admin-quick">
          <?= csrf_field() ?>
          <input type="hidden" name="username" value="<?= e($row['username']) ?>">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <input type="number" name="amount" placeholder="±amt">
          <button class="c-btn">Give</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$users): ?><div class="c-dim" style="padding:10px">No users found.</div><?php endif; ?>
  </div>
</div>
<?php render_casino_footer();
