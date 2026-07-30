<?php
require __DIR__ . '/lib/items.php';

$action = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action) {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_inv', 120, 60);
    items_table();
    $uid = (int) $u['id'];

    if ($action === 'quicksell') {
        [$amt, $err] = item_quicksell($uid, (int) ($_POST['item_id'] ?? 0));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(['ok' => true, 'amount' => $amt, 'balance' => casino_balance($uid), 'inventory' => inventory_list($uid)]);
    }
    if ($action === 'quicksell_rarity') {
        [$cnt, $amt, $err] = item_quicksell_rarity($uid, (string) ($_POST['rarity'] ?? ''));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(['ok' => true, 'count' => $cnt, 'amount' => $amt, 'balance' => casino_balance($uid), 'inventory' => inventory_list($uid)]);
    }
    if ($action === 'list') {
        $err = market_list_item($uid, (int) ($_POST['item_id'] ?? 0), (int) ($_POST['price'] ?? 0));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(['ok' => true, 'balance' => casino_balance($uid), 'inventory' => inventory_list($uid)]);
    }
    if ($action === 'delist') {
        market_delist($uid, (int) ($_POST['item_id'] ?? 0));
        json_out(['ok' => true, 'balance' => casino_balance($uid), 'inventory' => inventory_list($uid)]);
    }
    json_out(['ok' => false, 'error' => 'bad action']);
}

$u = require_casino_user();
items_table();
$inv = inventory_list((int) $u['id']);
render_casino_header('Inventory', $u);
?>
<div class="c-game-page" style="max-width:900px">
  <h1>🎒 Inventory</h1>
  <p class="c-sub">Quick-sell items to the house for their value, or list them on the <a href="/casino/market.php">Marketplace</a> for other players.</p>
  <div class="inv-bulk" id="inv-bulk" style="display:none"></div>
  <div class="item-grid" id="inv-grid"></div>
  <p class="c-dim" id="inv-empty" style="display:none">No items yet — <a href="/casino/cases.php">open a case</a>!</p>
</div>
<script>window.INV = <?= json_encode($inv) ?>; window.RARITY_ORDER = <?= json_encode(array_keys(RARITIES)) ?>;</script>
<script src="<?= casset('/assets/inventory.js') ?>"></script>
<?php render_casino_footer();
