<?php
require __DIR__ . '/lib/casino.php';

const BJ_MIN = 10, BJ_MAX = 1000;

function bj_table(): void {
    videos_db()->exec("CREATE TABLE IF NOT EXISTS casino_bj (
        user_id INTEGER PRIMARY KEY, bet INTEGER, doubled INTEGER DEFAULT 0,
        deck TEXT, player TEXT, dealer TEXT, status TEXT)");
}
function bj_load(int $uid): ?array {
    $st = videos_db()->prepare("SELECT * FROM casino_bj WHERE user_id = ?");
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['deck'] = json_decode($r['deck'], true) ?: [];
    $r['player'] = json_decode($r['player'], true) ?: [];
    $r['dealer'] = json_decode($r['dealer'], true) ?: [];
    return $r;
}
function bj_save(int $uid, array $g): void {
    videos_db()->prepare("REPLACE INTO casino_bj (user_id,bet,doubled,deck,player,dealer,status) VALUES (?,?,?,?,?,?,?)")
        ->execute([$uid, $g['bet'], $g['doubled'], json_encode($g['deck']), json_encode($g['player']), json_encode($g['dealer']), $g['status']]);
}

// Build the client view. Hole card hidden until the round is over.
function bj_view(int $uid, array $g, ?string $result = null, int $delta = 0): array {
    [$pv] = bj_value($g['player']);
    $over = $g['status'] === 'done';
    $dealer = $over ? card_codes($g['dealer'])
                    : array_merge([card_code($g['dealer'][0])], array_fill(0, count($g['dealer']) - 1, '??'));
    [$dv] = bj_value($g['dealer']);
    $canDouble = $g['status'] === 'player' && count($g['player']) === 2;
    return [
        'ok' => true, 'status' => $g['status'],
        'player' => card_codes($g['player']), 'playerVal' => $pv,
        'dealer' => $dealer, 'dealerVal' => $over ? $dv : null,
        'bet' => (int) $g['bet'], 'doubled' => (int) $g['doubled'],
        'canDouble' => $canDouble,
        'result' => $result, 'delta' => $delta,
        'balance' => casino_balance($uid),
    ];
}

// Dealer draws to 17+, then settle and pay out.
function bj_finish(int $uid, array &$g): array {
    while (bj_value($g['dealer'])[0] < 17) $g['dealer'][] = array_shift($g['deck']);
    [$pv] = bj_value($g['player']);
    [$dv] = bj_value($g['dealer']);
    $stake = $g['doubled'] ? $g['bet'] * 2 : $g['bet'];
    $result = ''; $credit = 0;
    if ($pv > 21)                 { $result = 'Bust — you lose'; }
    elseif ($dv > 21)             { $result = 'Dealer busts — you win!'; $credit = $stake * 2; }
    elseif ($pv > $dv)            { $result = "You win {$pv} to {$dv}!"; $credit = $stake * 2; }
    elseif ($pv < $dv)            { $result = "Dealer wins {$dv} to {$pv}"; }
    else                          { $result = "Push on {$pv}"; $credit = $stake; }
    $g['status'] = 'done';
    if ($credit > 0) casino_credit($uid, $credit);
    bj_save($uid, $g);
    return bj_view($uid, $g, $result, $credit - $stake);
}

$action = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action) {
    $u = require_login();
    csrf_require(true);
    enforceRateLimit('casino_bj', 120, 60);
    bj_table();
    $uid = (int) $u['id'];

    if ($action === 'deal') {
        $bet = (int) ($_POST['bet'] ?? 0);
        if ($bet < BJ_MIN || $bet > BJ_MAX) json_out(['ok' => false, 'error' => 'Bet must be between ' . BJ_MIN . ' and ' . BJ_MAX . '.']);
        $existing = bj_load($uid);
        if ($existing && $existing['status'] !== 'done') json_out(['ok' => false, 'error' => 'Finish your current hand first.']);
        if (!casino_bet($uid, $bet)) json_out(['ok' => false, 'error' => 'Not enough coins.']);

        $deck = fresh_deck(); shuffle_deck($deck);
        $g = ['bet' => $bet, 'doubled' => 0,
              'player' => [array_shift($deck), array_shift($deck)],
              'dealer' => [array_shift($deck), array_shift($deck)],
              'deck' => $deck, 'status' => 'player'];
        [$pv] = bj_value($g['player']); [$dv] = bj_value($g['dealer']);
        if ($pv === 21 || $dv === 21) {           // naturals settle immediately
            $g['status'] = 'done';
            if ($pv === 21 && $dv === 21) { $r = 'Push — both blackjack'; $credit = $bet; }
            elseif ($pv === 21)          { $r = 'Blackjack! Pays 3:2'; $credit = $bet + (int) floor($bet * 1.5); }
            else                         { $r = 'Dealer blackjack'; $credit = 0; }
            if ($credit > 0) casino_credit($uid, $credit);
            bj_save($uid, $g);
            json_out(bj_view($uid, $g, $r, $credit - $bet));
        }
        bj_save($uid, $g);
        json_out(bj_view($uid, $g));
    }

    $g = bj_load($uid);
    if (!$g || $g['status'] !== 'player') json_out(['ok' => false, 'error' => 'No hand in progress — deal first.']);

    if ($action === 'hit') {
        $g['player'][] = array_shift($g['deck']);
        if (bj_value($g['player'])[0] > 21) json_out(bj_finish($uid, $g));   // bust -> settle
        bj_save($uid, $g);
        json_out(bj_view($uid, $g));
    }
    if ($action === 'stand') { json_out(bj_finish($uid, $g)); }
    if ($action === 'double') {
        if (count($g['player']) !== 2) json_out(['ok' => false, 'error' => 'Can only double on your first two cards.']);
        if (!casino_bet($uid, $g['bet'])) json_out(['ok' => false, 'error' => 'Not enough coins to double.']);
        $g['doubled'] = 1;
        $g['player'][] = array_shift($g['deck']);
        json_out(bj_finish($uid, $g));   // one card then stand
    }
    json_out(['ok' => false, 'error' => 'unknown action']);
}

$u = require_casino_user();
bj_table();
// Restore an in-progress hand so a refresh/return doesn't strand the player.
$cur = bj_load((int) $u['id']);
$initState = ($cur && $cur['status'] === 'player') ? bj_view((int) $u['id'], $cur) : null;
render_casino_header('Blackjack', $u);
?>
<div class="c-game-page">
  <h1>🃏 Blackjack</h1>
  <div class="c-table">
    <div class="c-seat-label">Dealer <span id="bj-dval"></span></div>
    <div class="c-hand" id="bj-dealer"></div>
    <div class="c-seat-label" style="margin-top:16px">You <span id="bj-pval"></span></div>
    <div class="c-hand" id="bj-player"></div>
  </div>
  <div class="c-msg" id="bj-msg">Place your bet to deal.</div>

  <div class="c-betbar" id="bj-betbar">
    <span class="c-dim">Bet</span>
    <div class="c-bet-chips" id="c-bet-chips">
      <button class="c-chip" data-bet="10">10</button>
      <button class="c-chip on" data-bet="50">50</button>
      <button class="c-chip" data-bet="100">100</button>
      <button class="c-chip" data-bet="250">250</button>
    </div>
    <button class="c-btn c-btn-gold c-btn-lg" id="bj-deal">DEAL</button>
  </div>
  <div class="c-betbar" id="bj-actions" style="display:none">
    <button class="c-btn c-btn-lg" id="bj-hit">Hit</button>
    <button class="c-btn c-btn-lg" id="bj-stand">Stand</button>
    <button class="c-btn c-btn-lg" id="bj-double">Double</button>
  </div>
</div>
<script>window.BJ_STATE = <?= json_encode($initState) ?>;</script>
<script src="<?= casset('/assets/cards.js') ?>"></script>
<script src="<?= casset('/assets/blackjack.js') ?>"></script>
<?php render_casino_footer();
