<?php
require __DIR__ . '/lib/casino.php';

const PLINKO_ROWS = 12;
// 13 slots, symmetric; binomial-weighted EV ≈ 0.97 (house edge ~3%).
const PLINKO_MULTS = [10, 3, 1.5, 1.2, 1, 0.9, 0.8, 0.9, 1, 1.2, 1.5, 3, 10];
const PLINKO_MIN = 10, PLINKO_MAX = 1000;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'drop') {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_plinko', 120, 60);
    $bet = (int) ($_POST['bet'] ?? 0);
    if ($bet < PLINKO_MIN || $bet > PLINKO_MAX) json_out(['ok' => false, 'error' => 'Bet must be between ' . PLINKO_MIN . ' and ' . PLINKO_MAX . '.']);
    if (!casino_bet((int) $u['id'], $bet)) json_out(['ok' => false, 'error' => 'Not enough coins.']);

    $path = []; $slot = 0;
    for ($i = 0; $i < PLINKO_ROWS; $i++) { $r = random_int(0, 1); $path[] = $r; $slot += $r; }
    $mult = PLINKO_MULTS[$slot];
    $payout = (int) floor($bet * $mult);
    if ($payout > 0) casino_credit((int) $u['id'], $payout);

    json_out([
        'ok' => true, 'path' => $path, 'slot' => $slot, 'mult' => $mult,
        'payout' => $payout, 'net' => $payout - $bet, 'balance' => casino_balance((int) $u['id']),
    ]);
}

$u = require_casino_user();
render_casino_header('Plinko', $u);
?>
<div class="c-game-page" style="max-width:640px">
  <h1>🔻 Plinko</h1>
  <canvas id="plinko-canvas" width="560" height="560"></canvas>
  <div class="c-msg" id="plinko-msg">Drop a ball!</div>
  <div class="c-betbar">
    <span class="c-dim">Bet</span>
    <div class="c-bet-chips" id="c-bet-chips">
      <button class="c-chip" data-bet="10">10</button>
      <button class="c-chip on" data-bet="50">50</button>
      <button class="c-chip" data-bet="100">100</button>
      <button class="c-chip" data-bet="250">250</button>
    </div>
    <button class="c-btn c-btn-gold c-btn-lg" id="plinko-drop">DROP</button>
  </div>
</div>
<script>window.PLINKO = { rows: <?= PLINKO_ROWS ?>, mults: <?= json_encode(PLINKO_MULTS) ?> };</script>
<script src="<?= casset('/assets/plinko.js') ?>"></script>
<?php render_casino_footer();
