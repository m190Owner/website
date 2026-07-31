<?php
require __DIR__ . '/lib/casino.php';

const ROULETTE_MIN = 10, ROULETTE_MAX = 5000;   // total stake per spin
// American wheel order, clockwise. 0 and 00 are green.
const ROU_WHEEL = [0, 28, 9, 26, 30, 11, 7, 20, 32, 17, 5, 22, 34, 15, 3, 24, 36, 13, 1, '00', 27, 10, 25, 29, 12, 8, 19, 31, 18, 6, 21, 33, 16, 4, 23, 35, 14, 2];
const ROU_RED   = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

/** @param int|string $n */
function rou_color($n): string {
    if ($n === 0 || $n === '00') return 'green';
    return in_array((int) $n, ROU_RED, true) ? 'red' : 'black';
}

function rou_valid_key(string $k): bool {
    if (in_array($k, ['red', 'black', 'odd', 'even', 'low', 'high', 'd1', 'd2', 'd3', 'c1', 'c2', 'c3'], true)) return true;
    if (strncmp($k, 's:', 2) === 0) {
        $s = substr($k, 2);
        if ($s === '00') return true;
        return ctype_digit($s) && (int) $s >= 0 && (int) $s <= 36;
    }
    return false;
}

/** Payout multiplier ("to 1") for a bet key given the winning pocket, or -1 to lose. */
function rou_win_mult(string $key, $n): int {
    if (strncmp($key, 's:', 2) === 0) {
        $sel = substr($key, 2);
        $sel = $sel === '00' ? '00' : (int) $sel;
        return $sel === $n ? 35 : -1;
    }
    if ($n === 0 || $n === '00') return -1;   // green loses every outside bet
    $n = (int) $n;
    switch ($key) {
        case 'red':   return rou_color($n) === 'red' ? 1 : -1;
        case 'black': return rou_color($n) === 'black' ? 1 : -1;
        case 'odd':   return $n % 2 === 1 ? 1 : -1;
        case 'even':  return $n % 2 === 0 ? 1 : -1;
        case 'low':   return $n <= 18 ? 1 : -1;
        case 'high':  return $n >= 19 ? 1 : -1;
        case 'd1':    return $n <= 12 ? 2 : -1;
        case 'd2':    return ($n >= 13 && $n <= 24) ? 2 : -1;
        case 'd3':    return $n >= 25 ? 2 : -1;
        case 'c1':    return $n % 3 === 1 ? 2 : -1;
        case 'c2':    return $n % 3 === 2 ? 2 : -1;
        case 'c3':    return $n % 3 === 0 ? 2 : -1;
    }
    return -1;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'spin') {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_roulette', 120, 60);
    $uid = (int) $u['id'];

    $bets = json_decode($_POST['bets'] ?? '', true);
    if (!is_array($bets) || !$bets) json_out(['ok' => false, 'error' => 'Place a bet first.']);
    if (count($bets) > 100) json_out(['ok' => false, 'error' => 'Too many separate bets.']);

    $clean = []; $total = 0;
    foreach ($bets as $key => $amt) {
        $key = (string) $key; $amt = (int) $amt;
        if (!rou_valid_key($key) || $amt < 1) json_out(['ok' => false, 'error' => 'Invalid bet.']);
        $clean[$key] = ($clean[$key] ?? 0) + $amt;
        $total += $amt;
    }
    if ($total < ROULETTE_MIN || $total > ROULETTE_MAX) json_out(['ok' => false, 'error' => 'Total stake must be between ' . ROULETTE_MIN . ' and ' . ROULETTE_MAX . '.']);
    if (!casino_bet($uid, $total)) json_out(['ok' => false, 'error' => 'Not enough coins.']);

    // Spin: 38 pockets (0, 00, 1..36).
    $pockets = array_merge([0, '00'], range(1, 36));
    $n = $pockets[random_int(0, 37)];

    $results = []; $payout = 0;
    foreach ($clean as $key => $amt) {
        $mult = rou_win_mult($key, $n);
        if ($mult > 0) { $win = $amt * ($mult + 1); $payout += $win; $results[$key] = ['win' => true, 'amount' => $amt, 'paid' => $win]; }
        else           { $results[$key] = ['win' => false, 'amount' => $amt, 'paid' => 0]; }
    }
    if ($payout > 0) casino_credit($uid, $payout);

    json_out([
        'ok' => true,
        'winning' => is_int($n) ? (string) $n : $n,
        'color' => rou_color($n),
        'results' => $results,
        'totalBet' => $total, 'totalPayout' => $payout, 'net' => $payout - $total,
        'balance' => casino_balance($uid),
    ]);
}

$u = require_casino_user();
render_casino_header('Roulette', $u);

// Number grid: 3 rows (top 3,6..36 / mid 2,5..35 / bottom 1,4..34), 12 columns.
$rows = [];
for ($r = 0; $r < 3; $r++) { $row = []; for ($c = 0; $c < 12; $c++) $row[] = 3 * $c + (3 - $r); $rows[] = $row; }
$colKeys = ['c3', 'c2', 'c1']; // 2:1 cell at the end of each row (top row is column 3)
?>
<div class="rou-page">
  <h1>🎡 Roulette <span class="c-dim">· American</span></h1>

  <div class="rou-reelwrap"><div class="rou-reel" id="rou-reel"></div><div class="rou-marker"></div></div>
  <div class="c-msg" id="rou-msg">Place your bets.</div>

  <div class="rou-board" id="rou-board">
    <div class="rou-main">
      <div class="rou-zeros">
        <button class="rou-cell green" data-bet="s:0">0</button>
        <button class="rou-cell green" data-bet="s:00">00</button>
      </div>
      <?php foreach ($rows as $ri => $row): ?>
        <?php foreach ($row as $n): ?>
          <button class="rou-cell <?= rou_color($n) ?>" data-bet="s:<?= $n ?>"><?= $n ?></button>
        <?php endforeach; ?>
        <button class="rou-cell rou-side" data-bet="<?= $colKeys[$ri] ?>">2:1</button>
      <?php endforeach; ?>
    </div>
    <div class="rou-dozens">
      <div class="rou-spacer"></div>
      <button class="rou-cell rou-wide" data-bet="d1">1st 12</button>
      <button class="rou-cell rou-wide" data-bet="d2">2nd 12</button>
      <button class="rou-cell rou-wide" data-bet="d3">3rd 12</button>
    </div>
    <div class="rou-outside">
      <div class="rou-spacer"></div>
      <button class="rou-cell rou-out" data-bet="low">1–18</button>
      <button class="rou-cell rou-out" data-bet="even">EVEN</button>
      <button class="rou-cell red rou-out" data-bet="red">RED</button>
      <button class="rou-cell black rou-out" data-bet="black">BLACK</button>
      <button class="rou-cell rou-out" data-bet="odd">ODD</button>
      <button class="rou-cell rou-out" data-bet="high">19–36</button>
    </div>
  </div>

  <div class="c-betbar" style="margin:14px 0">
    <span class="c-dim">Chip</span>
    <div class="c-bet-chips" id="rou-chips">
      <button class="c-chip on" data-chip="5">5</button>
      <button class="c-chip" data-chip="25">25</button>
      <button class="c-chip" data-chip="100">100</button>
      <button class="c-chip" data-chip="500">500</button>
    </div>
    <span class="c-dim">Staked <b id="rou-total">0</b></span>
    <button class="c-btn" id="rou-clear">Clear</button>
    <button class="c-btn c-btn-gold c-btn-lg" id="rou-spin">SPIN</button>
  </div>
</div>
<script>window.ROULETTE = { wheel: <?= json_encode(ROU_WHEEL) ?>, red: <?= json_encode(ROU_RED) ?>, min: <?= ROULETTE_MIN ?>, max: <?= ROULETTE_MAX ?> };</script>
<script src="<?= casset('/assets/roulette.js') ?>"></script>
<?php render_casino_footer();
