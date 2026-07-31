<?php
require __DIR__ . '/lib/casino.php';

$u = current_user();
if ($u) casino_ensure_funded((int) $u['id']);   // one-time 2000 starting stack

render_casino_header('Lobby', $u);
?>
<?php if (!$u): ?>
  <div class="c-hero">
    <h1>🎰 LS Casino</h1>
    <p class="c-sub">Play Slots, Blackjack, and live Texas Hold'em for <b>LS coins</b>. Play-money only — just for fun.</p>
    <a href="/videos/login.php?next=/casino/" class="c-btn c-btn-gold c-btn-lg">Log in to play</a>
    <p class="c-dim">Uses your Videos account. No account? <a href="/videos/register.php?next=/casino/">Sign up</a>.</p>
  </div>
<?php else: ?>
  <div class="c-lobby-head">
    <h1>Welcome, <?= e($u['username']) ?></h1>
    <div class="c-wallet">
      <div class="c-wallet-bal">🪙 <b id="c-balance-big"><?= fmt_coins(casino_balance((int) $u['id'])) ?></b> <span>LS</span></div>
      <?php if (!empty($u['is_admin'])): ?>
        <a href="/casino/admin.php" class="c-btn c-btn-gold">🛠 Admin — grant coins</a>
      <?php else: ?>
        <span class="c-dim">Out of coins? Ask Logan for a top-up.</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="c-games">
    <a class="c-game" href="/casino/slots.php">
      <div class="c-game-ico">🎰</div>
      <div class="c-game-name">Slots</div>
      <div class="c-game-desc">Spin the reels. Match symbols for up to 100× your bet.</div>
    </a>
    <a class="c-game" href="/casino/blackjack.php">
      <div class="c-game-ico">🃏</div>
      <div class="c-game-name">Blackjack</div>
      <div class="c-game-desc">Beat the dealer to 21. Blackjack pays 3:2.</div>
    </a>
    <a class="c-game" href="/casino/poker.php">
      <div class="c-game-ico">♠️</div>
      <div class="c-game-name">Texas Hold'em</div>
      <div class="c-game-desc">Live multiplayer tables against other players.</div>
    </a>
    <a class="c-game" href="/casino/cases.php">
      <div class="c-game-ico">📦</div>
      <div class="c-game-name">Case Opening</div>
      <div class="c-game-desc">Unbox rare guns, gloves & knives — trade them on the market.</div>
    </a>
    <a class="c-game" href="/casino/upgrader.php">
      <div class="c-game-ico">🔧</div>
      <div class="c-game-name">Item Upgrader</div>
      <div class="c-game-desc">Gamble your items for a shot at a bigger skin.</div>
    </a>
    <a class="c-game" href="/casino/plinko.php">
      <div class="c-game-ico">🔻</div>
      <div class="c-game-name">Plinko</div>
      <div class="c-game-desc">Drop the ball, chase the 10× edges.</div>
    </a>
    <a class="c-game" href="/casino/crash.php">
      <div class="c-game-ico">🚀</div>
      <div class="c-game-name">Crash</div>
      <div class="c-game-desc">Cash out before the rocket busts. Shared live rounds.</div>
    </a>
    <a class="c-game" href="/casino/roulette.php">
      <div class="c-game-ico">🎡</div>
      <div class="c-game-name">Roulette</div>
      <div class="c-game-desc">American wheel — straight up, red/black, dozens & more.</div>
    </a>
    <a class="c-game" href="/casino/market.php">
      <div class="c-game-ico">🛒</div>
      <div class="c-game-name">Marketplace</div>
      <div class="c-game-desc">Buy & sell unboxed items with other players.</div>
    </a>
  </div>

  <div class="c-leaders">
    <h2>🏆 Richest players</h2>
    <ol class="c-leader-list">
      <?php
      $top = videos_db()->query("SELECT username, coins FROM users WHERE coins > 0 ORDER BY coins DESC LIMIT 10")->fetchAll();
      foreach ($top as $i => $row):
      ?>
        <li<?= $u['username'] === $row['username'] ? ' class="me"' : '' ?>>
          <span class="c-rank"><?= $i + 1 ?></span>
          <span class="c-lname"><?= e($row['username']) ?></span>
          <span class="c-lcoins">🪙 <?= fmt_coins((int) $row['coins']) ?></span>
        </li>
      <?php endforeach; ?>
      <?php if (!$top): ?><li class="c-dim">No high rollers yet.</li><?php endif; ?>
    </ol>
  </div>
<?php endif; ?>
<?php render_casino_footer();
