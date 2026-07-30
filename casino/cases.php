<?php
require __DIR__ . '/lib/items.php';

$action = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action) {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_case', 120, 60);
    items_table();
    $uid = (int) $u['id'];

    if ($action === 'open') {
        $case  = $_POST['case'] ?? '';
        $count = (int) ($_POST['count'] ?? 1);
        [$items, $err] = case_open_many($uid, $case, $count);
        if ($err) json_out(['ok' => false, 'error' => $err]);
        foreach ($items as $it) if ($it['rarity'] === 'exceedingly') activity_log('🔪', $u['username'] . ' unboxed ' . $it['name'] . '!');
        json_out([
            'ok' => true, 'items' => $items, 'count' => count($items),
            'totalCost' => CASES[$case]['price'] * count($items),
            'balance' => casino_balance($uid),
        ]);
    }
    if ($action === 'quicksell') {
        [$amt, $err] = item_quicksell($uid, (int) ($_POST['item_id'] ?? 0));
        if ($err) json_out(['ok' => false, 'error' => $err]);
        json_out(['ok' => true, 'amount' => $amt, 'balance' => casino_balance($uid)]);
    }
    json_out(['ok' => false, 'error' => 'bad action']);
}

$u = require_casino_user();
items_table();
render_casino_header('Cases', $u);
?>
<div class="c-game-page" style="max-width:820px">
  <h1>📦 Case Opening</h1>
  <p class="c-sub">Open a case for a chance at rare guns, gloves and knives. Sell your pulls on the <a href="/casino/market.php">Marketplace</a> or keep them in your <a href="/casino/inventory.php">Inventory</a>.</p>

  <div class="c-betbar" style="justify-content:flex-start;margin:6px 0 4px">
    <span class="c-dim">Open</span>
    <div class="c-bet-chips" id="c-count-chips">
      <button class="c-chip on" data-count="1">1</button>
      <button class="c-chip" data-count="3">3</button>
      <button class="c-chip" data-count="5">5</button>
      <button class="c-chip" data-count="10">10</button>
    </div>
    <span class="c-dim">at a time</span>
  </div>

  <div class="case-reveal" id="case-reveal" style="display:none">
    <div class="case-reel-window" id="case-reel-window"><div class="case-reel" id="case-reel"></div><div class="case-marker"></div></div>
    <div class="case-multi" id="case-multi" style="display:none"></div>
    <div class="case-result" id="case-result"></div>
  </div>

  <div class="c-games" id="case-list">
    <?php foreach (CASES as $cid => $c): ?>
      <div class="c-game case-card">
        <div class="c-game-ico">📦</div>
        <div class="c-game-name"><?= e($c['name']) ?></div>
        <div class="c-game-desc">
          <?php $rr = []; foreach ($c['odds'] as $r => $p) $rr[] = '<span style="color:' . RARITIES[$r][1] . '">' . RARITIES[$r][0] . '</span>'; echo implode(' · ', $rr); ?>
        </div>
        <button class="c-btn c-btn-gold" data-case="<?= $cid ?>" data-price="<?= (int) $c['price'] ?>">Open · 🪙 <?= fmt_coins($c['price']) ?></button>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
window.ITEMS = <?= json_encode(array_map('item_def', array_combine(array_keys(ITEMS), array_keys(ITEMS)))) ?>;
window.CASES = <?= json_encode(array_map(fn($c) => ['name' => $c['name'], 'price' => $c['price'], 'pool' => array_merge(...array_values($c['pool']))], CASES)) ?>;
</script>
<script src="<?= casset('/assets/cases.js') ?>"></script>
<?php render_casino_footer();
