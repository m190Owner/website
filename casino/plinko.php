<?php
require __DIR__ . '/lib/casino.php';

const PLINKO_ROWS = 12;
// 13 slots, symmetric; binomial-weighted EV ≈ 0.97 (house edge ~3%).
const PLINKO_MULTS = [10, 3, 1.5, 1.2, 1, 0.9, 0.8, 0.9, 1, 1.2, 1.5, 3, 10];
const PLINKO_MIN = 10, PLINKO_MAX = 5000;

const PLINKO_MAX_BALLS = 100;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'drop') {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_plinko', 120, 60);
    $bet = (int) ($_POST['bet'] ?? 0);
    $balls = max(1, min(PLINKO_MAX_BALLS, (int) ($_POST['balls'] ?? 1)));
    if ($bet < PLINKO_MIN || $bet > PLINKO_MAX) json_out(['ok' => false, 'error' => 'Bet must be between ' . PLINKO_MIN . ' and ' . PLINKO_MAX . ' per ball.']);
    $total = $bet * $balls;
    if (!casino_bet((int) $u['id'], $total)) json_out(['ok' => false, 'error' => 'Not enough coins for ' . $balls . ' ball' . ($balls === 1 ? '' : 's') . ' (' . $total . ').']);

    $results = []; $totalPayout = 0;
    for ($b = 0; $b < $balls; $b++) {
        $path = []; $slot = 0;
        for ($i = 0; $i < PLINKO_ROWS; $i++) { $r = random_int(0, 1); $path[] = $r; $slot += $r; }
        $mult = PLINKO_MULTS[$slot];
        $payout = (int) floor($bet * $mult);
        $totalPayout += $payout;
        $results[] = ['path' => $path, 'slot' => $slot, 'mult' => $mult, 'payout' => $payout];
    }
    if ($totalPayout > 0) casino_credit((int) $u['id'], $totalPayout);

    json_out([
        'ok' => true, 'balls' => $results, 'bet' => $bet,
        'totalBet' => $total, 'totalPayout' => $totalPayout, 'net' => $totalPayout - $total,
        'balance' => casino_balance((int) $u['id']),
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
      <button class="c-chip" data-bet="250">250</button>
      <button class="c-chip" data-bet="1000">1k</button>
      <button class="c-chip" data-bet="5000">5k</button>
    </div>
    <span class="c-dim">Balls</span>
    <div class="c-bet-chips" id="c-balls-chips">
      <button class="c-chip on" data-balls="1">1</button>
      <button class="c-chip" data-balls="10">10</button>
      <button class="c-chip" data-balls="50">50</button>
      <button class="c-chip" data-balls="100">100</button>
    </div>
    <button class="c-btn c-btn-gold c-btn-lg" id="plinko-drop">DROP</button>
  </div>
  <div class="c-dim" id="plinko-stake" style="margin-top:-6px">Total stake: <b>50</b> LS</div>
</div>
<script>window.PLINKO = { rows: <?= PLINKO_ROWS ?>, mults: <?= json_encode(PLINKO_MULTS) ?> };</script>
<script src="<?= casset('/assets/plinko.js') ?>"></script>
<?php render_casino_footer();
