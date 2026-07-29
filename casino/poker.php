<?php
require __DIR__ . '/lib/casino.php';
$u = require_casino_user();
render_casino_header("Texas Hold'em", $u);
?>
<div class="c-game-page">
  <h1>♠️ Texas Hold'em</h1>
  <div class="c-table" style="text-align:center">
    <div style="font-size:2.4rem">🃏♠️♥️♦️♣️</div>
    <p class="c-sub" style="margin-top:10px">Live multiplayer tables are being dealt in…</p>
    <p class="c-dim">Coming very soon. In the meantime, try <a href="/casino/slots.php">Slots</a> or <a href="/casino/blackjack.php">Blackjack</a>.</p>
  </div>
</div>
<?php render_casino_footer();
