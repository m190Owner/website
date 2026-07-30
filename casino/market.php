<?php
require __DIR__ . '/lib/items.php';

$action = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action) {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_market', 120, 60);
    items_table();
    $uid = (int) $u['id'];

    if ($action === 'buy') {
        [$item, $err] = market_buy($uid, (int) ($_POST['item_id'] ?? 0));
        if ($err) json_out(['ok' => false, 'error' => $err, 'market' => market_all()]);
        json_out(['ok' => true, 'bought' => $item, 'balance' => casino_balance($uid), 'market' => market_all()]);
    }
    json_out(['ok' => false, 'error' => 'bad action']);
}

$u = require_casino_user();
items_table();
$mkt = market_all();
render_casino_header('Marketplace', $u);
?>
<div class="c-game-page" style="max-width:960px">
  <h1>🛒 Marketplace</h1>
  <p class="c-sub">Buy items other players have listed. List your own from your <a href="/casino/inventory.php">Inventory</a>. <span class="c-dim">(sellers pay a <?= (int) round(MARKET_FEE * 100) ?>% fee)</span></p>
  <div class="item-grid" id="mkt-grid"></div>
  <p class="c-dim" id="mkt-empty" style="display:none">Nothing listed right now. Be the first — list something from your inventory.</p>
</div>
<script>window.MKT = <?= json_encode($mkt) ?>; window.MEID = <?= (int) $u['id'] ?>;</script>
<script src="<?= casset('/assets/market.js') ?>"></script>
<?php render_casino_footer();
