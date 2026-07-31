<?php
require __DIR__ . '/lib/items.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'upgrade') {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_upgrade', 120, 60);
    items_table();
    $uid = (int) $u['id'];

    $stake = json_decode($_POST['stake'] ?? '[]', true);
    if (!is_array($stake)) $stake = [];
    [$res, $err] = item_upgrade($uid, $stake, (string) ($_POST['target'] ?? ''));
    if ($err) json_out(['ok' => false, 'error' => $err]);
    json_out(['ok' => true, 'result' => $res, 'inventory' => inventory_list($uid), 'balance' => casino_balance($uid)]);
}

$u = require_casino_user();
items_table();
$inv = inventory_list((int) $u['id']);
render_casino_header('Upgrader', $u);
?>
<div class="c-game-page" style="max-width:900px">
  <h1>🔧 Item Upgrader</h1>
  <p class="c-sub">Stake items you own for a shot at a bigger one. Higher target = longer odds. Win and it's yours; lose and the staked items are gone. <span class="c-dim">Odds = your value ÷ target value × 0.9.</span></p>

  <div class="upg-gauge">
    <div class="upg-bar"><div class="upg-bar-green" id="upg-green" style="width:0%"></div><div class="upg-pointer" id="upg-pointer"></div></div>
    <div class="upg-chance" id="upg-chance">—</div>
  </div>
  <div class="c-msg" id="upg-msg">Pick items to stake, then a target.</div>

  <div class="upg-cols">
    <div class="upg-col">
      <h2 class="c-sub">Your items <span class="c-dim">· staked value <b id="upg-stakeval">0</b></span></h2>
      <div class="item-grid upg-stake" id="upg-inv"></div>
      <p class="c-dim" id="upg-inv-empty" style="display:none">No items — <a href="/casino/cases.php">open a case</a> first.</p>
    </div>
    <div class="upg-col">
      <h2 class="c-sub">Target <span class="c-dim">(worth more than your stake)</span></h2>
      <div class="item-grid upg-targets" id="upg-targets"></div>
    </div>
  </div>

  <div style="text-align:center;margin-top:16px">
    <button class="c-btn c-btn-gold c-btn-lg" id="upg-go" disabled>UPGRADE</button>
  </div>
</div>
<script>
window.ITEMS = <?= json_encode(array_map('item_def', array_combine(array_keys(ITEMS), array_keys(ITEMS)))) ?>;
window.INV = <?= json_encode($inv) ?>;
window.UPGRADE = { factor: <?= UPGRADE_FACTOR ?>, max: <?= UPGRADE_MAX ?> };
</script>
<script src="<?= casset('/assets/upgrader.js') ?>"></script>
<?php render_casino_footer();
