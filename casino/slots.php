<?php
require __DIR__ . '/lib/casino.php';

// Reels: symbol => [weight, 3-of-a-kind payout multiplier]. Rarer = bigger pay.
const SLOT_SYMBOLS = [
    '🍒' => [30, 5],
    '🍋' => [26, 8],
    '🔔' => [20, 12],
    '⭐' => [14, 20],
    '💎' => [8, 40],
    '7️⃣' => [5, 100],
];
const SLOT_MIN = 10, SLOT_MAX = 1000;

function slot_spin_one(): string {
    $total = 0;
    foreach (SLOT_SYMBOLS as $w) $total += $w[0];
    $r = random_int(1, $total);
    foreach (SLOT_SYMBOLS as $sym => $w) { $r -= $w[0]; if ($r <= 0) return $sym; }
    return array_key_first(SLOT_SYMBOLS);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'spin') {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_slots', 120, 60);

    $bet = (int) ($_POST['bet'] ?? 0);
    if ($bet < SLOT_MIN || $bet > SLOT_MAX) json_out(['ok' => false, 'error' => 'Bet must be between ' . SLOT_MIN . ' and ' . SLOT_MAX . '.']);
    if (!casino_bet((int) $u['id'], $bet)) json_out(['ok' => false, 'error' => 'Not enough coins.']);

    $reels = [slot_spin_one(), slot_spin_one(), slot_spin_one()];
    $mult = 0; $line = '';
    if ($reels[0] === $reels[1] && $reels[1] === $reels[2]) {
        $mult = SLOT_SYMBOLS[$reels[0]][1];
        $line = 'THREE ' . $reels[0] . ' — ' . $mult . '×!';
    } else {
        $cherries = count(array_filter($reels, fn($s) => $s === '🍒'));
        if ($cherries === 2) { $mult = 2; $line = 'Two 🍒 — 2×'; }
        elseif ($cherries === 1) { $mult = 1; $line = 'One 🍒 — bet back'; }
    }
    $payout = $bet * $mult;
    if ($payout > 0) casino_credit((int) $u['id'], $payout);

    json_out([
        'ok'      => true,
        'reels'   => $reels,
        'payout'  => $payout,
        'net'     => $payout - $bet,
        'line'    => $line,
        'balance' => casino_balance((int) $u['id']),
    ]);
}

$u = require_casino_user();
$bal = casino_balance((int) $u['id']);
render_casino_header('Slots', $u);
?>
<div class="c-game-page">
  <h1>🎰 Slots</h1>
  <div class="slot-machine">
    <div class="slot-reels" id="slot-reels">
      <div class="slot-reel">🍒</div><div class="slot-reel">🍋</div><div class="slot-reel">🔔</div>
    </div>
    <div class="slot-result" id="slot-result">Pick a bet and spin!</div>
  </div>

  <div class="c-betbar">
    <span class="c-dim">Bet</span>
    <div class="c-bet-chips" id="c-bet-chips">
      <button class="c-chip" data-bet="10">10</button>
      <button class="c-chip on" data-bet="50">50</button>
      <button class="c-chip" data-bet="100">100</button>
      <button class="c-chip" data-bet="250">250</button>
      <button class="c-chip" data-bet="1000">1000</button>
    </div>
    <button class="c-btn c-btn-gold c-btn-lg" id="slot-spin">SPIN</button>
  </div>

  <details class="c-paytable">
    <summary>Paytable</summary>
    <table>
      <?php foreach (SLOT_SYMBOLS as $sym => $w): ?>
        <tr><td><?= $sym ?> <?= $sym ?> <?= $sym ?></td><td><?= $w[1] ?>× bet</td></tr>
      <?php endforeach; ?>
      <tr><td>🍒 🍒 (any two)</td><td>2× bet</td></tr>
    </table>
  </details>
</div>
<script>window.SLOT = { min: <?= SLOT_MIN ?>, max: <?= SLOT_MAX ?>, symbols: <?= json_encode(array_keys(SLOT_SYMBOLS)) ?> };</script>
<script src="<?= casset('/assets/slots.js') ?>"></script>
<?php render_casino_footer();
