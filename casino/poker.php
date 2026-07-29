<?php
require __DIR__ . '/lib/poker.php';

// Run a state mutation atomically (BEGIN IMMEDIATE serialises concurrent players),
// always ticking the engine forward and saving. Returns [$state, $error].
function poker_txn(string $id, int $uid, ?callable $mutate): array {
    $db = videos_db();
    poker_table_init();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $s = poker_load($id);
        poker_touch($s, $uid);
        $err = $mutate ? $mutate($s) : null;
        poker_tick($s);
        poker_save($s);
        $db->exec('COMMIT');
        return [$s, $err];
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

$action = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action) {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_poker', 300, 60);
    $uid = (int) $u['id'];
    $id  = $_POST['t'] ?? '';
    if (!isset(POKER_TABLES[$id])) json_out(['ok' => false, 'error' => 'Unknown table.']);

    if ($action === 'poll') {
        [$s] = poker_txn($id, $uid, null);
        json_out(poker_view($s, $uid));
    }
    if ($action === 'sit') {
        $seat  = (int) ($_POST['seat'] ?? -1);
        $buyin = (int) ($_POST['buyin'] ?? 0);
        [$s, $err] = poker_txn($id, $uid, fn(&$st) => poker_sit($st, $u, $seat, $buyin));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(poker_view($s, $uid));
    }
    if ($action === 'leave') {
        [$s] = poker_txn($id, $uid, function (&$st) use ($uid) { poker_leave($st, $uid); return null; });
        json_out(poker_view($s, $uid));
    }
    if ($action === 'act') {
        $mv  = $_POST['move'] ?? '';
        $amt = (int) ($_POST['amount'] ?? 0);
        [$s, $err] = poker_txn($id, $uid, fn(&$st) => poker_action($st, $uid, $mv, $amt));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(poker_view($s, $uid));
    }
    json_out(['ok' => false, 'error' => 'bad action']);
}

$u = require_casino_user();
$table = $_GET['t'] ?? '';

// ---- Lobby: choose a table ----
if (!isset(POKER_TABLES[$table])) {
    poker_table_init();
    render_casino_header("Texas Hold'em", $u);
    ?>
    <div class="c-game-page" style="max-width:760px">
      <h1>♠️ Texas Hold'em</h1>
      <p class="c-sub">Live multiplayer tables. Sit down, buy in with LS coins, and play against everyone else online.</p>
      <div class="c-games">
        <?php foreach (POKER_TABLES as $tid => $t):
            $st = poker_load($tid);
            $players = count(array_filter($st['seats'], fn($p) => $p !== null));
        ?>
          <a class="c-game" href="/casino/poker.php?t=<?= $tid ?>">
            <div class="c-game-ico">♠️</div>
            <div class="c-game-name"><?= e($t['name']) ?></div>
            <div class="c-game-desc">Blinds <?= $t['sb'] ?>/<?= $t['bb'] ?> · buy-in <?= fmt_coins($t['minBuy']) ?>–<?= fmt_coins($t['maxBuy']) ?></div>
            <div class="c-dim"><?= $players ?>/<?= $t['seats'] ?> seated</div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    render_casino_footer();
    exit;
}

// ---- Table view ----
$t = POKER_TABLES[$table];
render_casino_header($t['name'] . " · Hold'em", $u);
?>
<div class="poker-page" data-table="<?= e($table) ?>" data-minbuy="<?= $t['minBuy'] ?>" data-maxbuy="<?= $t['maxBuy'] ?>" data-bb="<?= $t['bb'] ?>">
  <div class="poker-top">
    <a href="/casino/poker.php" class="c-btn">← Tables</a>
    <h1><?= e($t['name']) ?> <span class="c-dim">· <?= $t['sb'] ?>/<?= $t['bb'] ?></span></h1>
    <button class="c-btn c-btn-danger" id="pk-leave" style="display:none">Leave table</button>
  </div>

  <div class="poker-table" id="pk-table">
    <div class="poker-board" id="pk-board"></div>
    <div class="poker-pot" id="pk-pot"></div>
    <div class="poker-status" id="pk-status">Connecting…</div>
    <div class="poker-seats" id="pk-seats"></div>
  </div>

  <div class="poker-actions" id="pk-actions" style="display:none">
    <button class="c-btn c-btn-lg" data-move="fold">Fold</button>
    <button class="c-btn c-btn-lg" id="pk-checkcall" data-move="call">Call</button>
    <div class="pk-raisewrap">
      <input type="range" id="pk-raise-range" min="0" max="0" step="1">
      <input type="number" id="pk-raise-amt" class="pk-raise-amt">
      <button class="c-btn c-btn-gold c-btn-lg" id="pk-raise-btn" data-move="raise">Raise</button>
    </div>
  </div>
  <div class="poker-log" id="pk-log"></div>
</div>
<script src="<?= casset('/assets/cards.js') ?>"></script>
<script src="<?= casset('/assets/poker.js') ?>"></script>
<?php render_casino_footer();
