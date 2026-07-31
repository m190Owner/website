<?php
require __DIR__ . '/lib/crash.php';

// Advance the global round atomically (BEGIN IMMEDIATE serialises concurrent
// pollers so the state machine never double-advances), run the caller's intent,
// then return the fresh view.
function crash_txn(int $uid, ?callable $mutate): array {
    $db = videos_db();
    crash_tables_init();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $round = crash_tick();
        $err = $mutate ? $mutate($round) : null;
        $db->exec('COMMIT');
        return [$round, $err];
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

$action = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action) {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_crash', 300, 60);
    $uid = (int) $u['id'];

    if ($action === 'poll') {
        [$round] = crash_txn($uid, null);
        json_out(crash_view($round, $uid));
    }
    if ($action === 'bet') {
        $bet  = (int) ($_POST['bet'] ?? 0);
        $auto = (float) ($_POST['auto'] ?? 0);
        [$round, $err] = crash_txn($uid, fn($r) => crash_place_bet($r, $u, $bet, $auto));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(crash_view($round, $uid));
    }
    if ($action === 'cashout') {
        [$round, $err] = crash_txn($uid, fn($r) => crash_cashout($r, $uid));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(crash_view($round, $uid));
    }
    json_out(['ok' => false, 'error' => 'bad action']);
}

$u = require_casino_user();
render_casino_header('Crash', $u);
?>
<div class="crash-page">
  <div class="crash-head">
    <h1>🚀 Crash</h1>
    <p class="c-sub">Bet before takeoff, then cash out before the rocket busts. Everyone's on the same round. <span class="c-dim">Provably fair — the round hash is shown before it flies and the seed revealed after.</span></p>
  </div>

  <div class="crash-history" id="crash-history"></div>

  <div class="crash-stage">
    <canvas id="crash-canvas" width="720" height="380"></canvas>
    <div class="crash-mult" id="crash-mult">1.00×</div>
    <div class="crash-phase" id="crash-phase">Connecting…</div>
  </div>

  <div class="crash-controls">
    <div class="c-betbar" style="margin:0">
      <span class="c-dim">Bet</span>
      <div class="c-bet-chips" id="c-bet-chips">
        <button class="c-chip" data-bet="10">10</button>
        <button class="c-chip on" data-bet="50">50</button>
        <button class="c-chip" data-bet="250">250</button>
        <button class="c-chip" data-bet="1000">1k</button>
        <button class="c-chip" data-bet="5000">5k</button>
      </div>
      <label class="crash-auto">Auto&nbsp;cashout
        <input type="number" id="crash-auto" min="1.01" step="0.1" placeholder="off" inputmode="decimal">
        <span>×</span>
      </label>
    </div>
    <button class="c-btn c-btn-gold c-btn-lg" id="crash-action">BET</button>
    <div class="c-msg" id="crash-msg"></div>
  </div>

  <div class="crash-board">
    <div class="crash-board-head"><span id="crash-board-count">0 players</span><span class="crash-fair" id="crash-fair"></span></div>
    <div class="crash-board-list" id="crash-board-list"></div>
  </div>
</div>
<script>window.CRASH = { rate: <?= CRASH_RATE ?>, min: <?= CRASH_MIN ?>, max: <?= CRASH_MAX ?> };</script>
<script src="<?= casset('/assets/crash.js') ?>"></script>
<?php render_casino_footer();
