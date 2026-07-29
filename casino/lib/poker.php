<?php
// Texas Hold'em engine — hand evaluation + the authoritative table state machine.
// Included by casino/poker.php. Card ints 0..51 (rank = i%13 [0=2..12=A],
// suit = i div 13), same as casino.php.

require_once __DIR__ . '/casino.php';

// ---------------------------------------------------------------------------
// Hand evaluation. eval5 returns a comparable score array (bigger = better):
//   [category, ...tiebreakers]  where category 0=high .. 8=straight flush.
// PHP's array comparison then ranks two hands correctly element-by-element.
// ---------------------------------------------------------------------------
// Pad every score to a fixed length so PHP's array comparison (which orders by
// length first!) compares them element-by-element instead.
function pk_score(array $s): array { return array_pad(array_slice($s, 0, 6), 6, 0); }

function poker_eval5(array $cards): array {
    $ranks = []; $suits = [];
    foreach ($cards as $x) { $ranks[] = $x % 13; $suits[] = intdiv($x, 13); }
    rsort($ranks);                              // descending
    $flush = count(array_unique($suits)) === 1;

    $uniq = array_values(array_unique($ranks)); // descending distinct
    $straightHigh = -1;
    if (count($uniq) === 5) {
        if ($uniq[0] - $uniq[4] === 4) $straightHigh = $uniq[0];
        elseif ($uniq === [12, 3, 2, 1, 0]) $straightHigh = 3;   // wheel A-2-3-4-5 (5-high)
    }

    $cnt = array_count_values($ranks);          // rank => count
    $groups = [];
    foreach ($cnt as $r => $n) $groups[] = [$n, $r];
    usort($groups, fn($a, $b) => ($b[0] <=> $a[0]) ?: ($b[1] <=> $a[1]));
    $counts = array_column($groups, 0);
    $ordRanks = array_column($groups, 1);       // by count desc, then rank desc

    if ($straightHigh >= 0 && $flush)                 return pk_score([8, $straightHigh]);
    if ($counts[0] === 4)                             return pk_score(array_merge([7], $ordRanks));
    if ($counts[0] === 3 && ($counts[1] ?? 0) === 2)  return pk_score(array_merge([6], $ordRanks));
    if ($flush)                                       return pk_score(array_merge([5], $ranks));
    if ($straightHigh >= 0)                           return pk_score([4, $straightHigh]);
    if ($counts[0] === 3)                             return pk_score(array_merge([3], $ordRanks));
    if ($counts[0] === 2 && ($counts[1] ?? 0) === 2)  return pk_score(array_merge([2], $ordRanks));
    if ($counts[0] === 2)                             return pk_score(array_merge([1], $ordRanks));
    return pk_score(array_merge([0], $ordRanks));
}

/** Best 5-card score from 5..7 cards. */
function poker_eval_best(array $cards): array {
    $n = count($cards);
    if ($n <= 5) return poker_eval5($cards);
    $best = null;
    // all C(n,5) combinations
    $idx = range(0, 4);
    while (true) {
        $combo = [];
        foreach ($idx as $i) $combo[] = $cards[$i];
        $s = poker_eval5($combo);
        if ($best === null || $s > $best) $best = $s;
        // advance the combination indices
        $i = 4;
        while ($i >= 0 && $idx[$i] === $n - 5 + $i) $i--;
        if ($i < 0) break;
        $idx[$i]++;
        for ($j = $i + 1; $j < 5; $j++) $idx[$j] = $idx[$j - 1] + 1;
    }
    return $best;
}

/** Compare two score arrays: -1,0,1. */
function poker_cmp(array $a, array $b): int { return $a <=> $b; }

const HAND_NAMES = ['High card','Pair','Two pair','Three of a kind','Straight','Flush','Full house','Four of a kind','Straight flush'];
function poker_hand_name(array $score): string {
    $c = $score[0];
    if ($c === 8 && ($score[1] ?? 0) === 12) return 'Royal flush';
    return HAND_NAMES[$c] ?? '';
}

// ===========================================================================
// Table engine
// ===========================================================================
const POKER_TABLES = [
    'lo' => ['name' => 'Low Stakes',  'sb' => 5,  'bb' => 10, 'minBuy' => 200,  'maxBuy' => 2000,  'seats' => 6],
    'hi' => ['name' => 'High Roller', 'sb' => 25, 'bb' => 50, 'minBuy' => 1000, 'maxBuy' => 10000, 'seats' => 6],
];
const PK_ACT_TIMEOUT = 25;   // seconds a player has to act
const PK_HAND_DELAY  = 6;    // seconds between hands (showdown display)
const PK_SEAT_IDLE   = 30;   // seconds of no polling before a seat is emptied

function poker_table_init(): void {
    videos_db()->exec("CREATE TABLE IF NOT EXISTS casino_poker (id TEXT PRIMARY KEY, state TEXT)");
}
function poker_default_state(string $id): array {
    $t = POKER_TABLES[$id];
    return ['id' => $id, 'sb' => $t['sb'], 'bb' => $t['bb'], 'nseats' => $t['seats'],
            'seats' => array_fill(0, $t['seats'], null), 'hand' => null, 'button' => -1,
            'result' => null, 'nextHandAt' => 0, 'log' => [], 'updated' => time()];
}
function poker_load(string $id): array {
    $st = videos_db()->prepare("SELECT state FROM casino_poker WHERE id = ?");
    $st->execute([$id]);
    $raw = $st->fetchColumn();
    $s = $raw ? json_decode($raw, true) : null;
    return is_array($s) ? $s : poker_default_state($id);
}
function poker_save(array $s): void {
    $s['updated'] = time();
    videos_db()->prepare("REPLACE INTO casino_poker (id, state) VALUES (?, ?)")
               ->execute([$s['id'], json_encode($s)]);
}
function pk_log(array &$s, string $msg): void {
    $s['log'][] = ['t' => time(), 'm' => $msg];
    if (count($s['log']) > 30) $s['log'] = array_slice($s['log'], -30);
}

// seat index lists
function pk_seated(array $s): array {          // seats with a live player
    $out = [];
    foreach ($s['seats'] as $i => $p) if ($p && $p['stack'] > 0 && empty($p['leaving'])) $out[] = $i;
    return $out;
}
function pk_inhand(array $s): array {           // dealt in and not folded
    $out = [];
    foreach ($s['seats'] as $i => $p) if ($p && !empty($p['inhand']) && empty($p['folded'])) $out[] = $i;
    return $out;
}
function pk_canact(array $s): array {           // still able to bet
    $out = [];
    foreach ($s['seats'] as $i => $p) if ($p && !empty($p['inhand']) && empty($p['folded']) && empty($p['allin'])) $out[] = $i;
    return $out;
}
function pk_next(array $s, int $from, array $pool): ?int {
    $n = $s['nseats'];
    for ($k = 1; $k <= $n; $k++) { $i = ($from + $k) % $n; if (in_array($i, $pool, true)) return $i; }
    return null;
}

// ---- sit / leave / heartbeat ----
function poker_sit(array &$s, array $u, int $seat, int $buyin): ?string {
    $t = POKER_TABLES[$s['id']];
    if ($seat < 0 || $seat >= $s['nseats'] || $s['seats'][$seat]) return 'Seat taken.';
    foreach ($s['seats'] as $p) if ($p && (int) $p['uid'] === (int) $u['id']) return 'You are already seated.';
    if ($buyin < $t['minBuy'] || $buyin > $t['maxBuy']) return "Buy-in must be {$t['minBuy']}–{$t['maxBuy']}.";
    if (!casino_bet((int) $u['id'], $buyin)) return 'Not enough coins.';
    $s['seats'][$seat] = ['uid' => (int) $u['id'], 'name' => $u['username'], 'av' => '', 'stack' => $buyin,
                          'seen' => time(), 'inhand' => false];
    pk_log($s, $u['username'] . ' sits down (' . $buyin . ')');
    return null;
}
function poker_leave(array &$s, int $uid): void {
    foreach ($s['seats'] as $i => $p) {
        if ($p && (int) $p['uid'] === $uid) {
            if ($s['hand'] && !empty($p['inhand']) && empty($p['folded'])) {
                $s['seats'][$i]['folded'] = true;         // fold; chips already in pot
                $s['seats'][$i]['leaving'] = true;
                pk_log($s, $p['name'] . ' leaves (folds)');
            } else {
                casino_credit($uid, (int) $p['stack']);   // cash out remaining chips
                pk_log($s, $p['name'] . ' leaves');
                $s['seats'][$i] = null;
            }
            return;
        }
    }
}
function poker_touch(array &$s, int $uid): void {
    foreach ($s['seats'] as $i => $p) if ($p && (int) $p['uid'] === $uid) { $s['seats'][$i]['seen'] = time(); return; }
}

// ---- start a hand ----
function poker_start_hand(array &$s): void {
    $occ = pk_seated($s);
    if (count($occ) < 2) return;
    $button = pk_next($s, $s['button'], $occ);
    $s['button'] = $button;
    $s['result'] = null;

    foreach ($s['seats'] as $i => $p) {
        if (!$p) continue;
        $in = in_array($i, $occ, true);
        $s['seats'][$i] = array_merge($p, ['inhand' => $in, 'hole' => [], 'bet' => 0, 'put' => 0,
                                            'folded' => false, 'allin' => false, 'acted' => false]);
    }
    $deck = fresh_deck(); shuffle_deck($deck);
    $heads = count($occ) === 2;
    $sbSeat = $heads ? $button : pk_next($s, $button, $occ);
    $bbSeat = pk_next($s, $sbSeat, $occ);

    $pot = 0;
    $post = function (int $seat, int $amt) use (&$s, &$pot) {
        $p = &$s['seats'][$seat];
        $a = min($amt, $p['stack']);
        $p['stack'] -= $a; $p['bet'] += $a; $p['put'] += $a; $pot += $a;
        if ($p['stack'] === 0) $p['allin'] = true;
        unset($p);
    };
    $post($sbSeat, $s['sb']); $post($bbSeat, $s['bb']);

    foreach ($occ as $seat) { $s['seats'][$seat]['hole'] = [array_shift($deck), array_shift($deck)]; }

    $first = $heads ? $button : pk_next($s, $bbSeat, $occ);
    $s['hand'] = [
        'button' => $button, 'street' => 'preflop', 'board' => [], 'deck' => $deck,
        'pot' => $pot, 'currentBet' => $s['bb'], 'minRaise' => $s['bb'],
        'toAct' => $first, 'deadline' => time() + PK_ACT_TIMEOUT, 'sb' => $sbSeat, 'bb' => $bbSeat,
    ];
    pk_log($s, 'New hand — blinds ' . $s['sb'] . '/' . $s['bb']);
}

// next seat that still needs to act, or null if the betting round is settled
function poker_next_actor(array $s, int $from): ?int {
    $canact = pk_canact($s);
    if (!$canact) return null;
    $bet = $s['hand']['currentBet'];
    foreach (range(1, $s['nseats']) as $k) {
        $i = ($from + $k) % $s['nseats'];
        if (!in_array($i, $canact, true)) continue;
        $p = $s['seats'][$i];
        if (!$p['acted'] || $p['bet'] < $bet) return $i;
    }
    return null;
}

// ---- apply a player action ----
function poker_action(array &$s, int $uid, string $action, int $amount): ?string {
    if (!$s['hand']) return 'No hand in progress.';
    $seat = null;
    foreach ($s['seats'] as $i => $p) if ($p && (int) $p['uid'] === $uid) { $seat = $i; break; }
    if ($seat === null) return 'You are not seated.';
    if ($s['hand']['toAct'] !== $seat) return 'Not your turn.';

    $p = &$s['seats'][$seat];
    $h = &$s['hand'];
    $toCall = $h['currentBet'] - $p['bet'];

    if ($action === 'fold') {
        $p['folded'] = true; $p['acted'] = true;
        pk_log($s, $p['name'] . ' folds');
    } elseif ($action === 'check') {
        if ($toCall > 0) { unset($p, $h); return 'You cannot check — there is a bet.'; }
        $p['acted'] = true;
        pk_log($s, $p['name'] . ' checks');
    } elseif ($action === 'call') {
        $pay = min($toCall, $p['stack']);
        $p['stack'] -= $pay; $p['bet'] += $pay; $p['put'] += $pay; $h['pot'] += $pay;
        if ($p['stack'] === 0) $p['allin'] = true;
        $p['acted'] = true;
        pk_log($s, $p['name'] . ($pay > 0 ? ' calls ' . $pay : ' checks'));
    } elseif ($action === 'raise' || $action === 'bet') {
        // $amount = the total this player wants their street bet to become
        $target = $amount;
        $maxTotal = $p['bet'] + $p['stack'];
        if ($target >= $maxTotal) $target = $maxTotal;               // treat as all-in
        $minTarget = $h['currentBet'] + $h['minRaise'];
        $isAllIn = $target === $maxTotal;
        if ($target <= $h['currentBet']) { unset($p, $h); return 'Raise must be higher than the current bet.'; }
        if ($target < $minTarget && !$isAllIn) { unset($p, $h); return 'Minimum raise is to ' . $minTarget . '.'; }
        $add = $target - $p['bet'];
        $p['stack'] -= $add; $p['bet'] += $add; $p['put'] += $add; $h['pot'] += $add;
        if ($p['stack'] === 0) $p['allin'] = true;
        $raiseSize = $target - $h['currentBet'];
        if ($raiseSize >= $h['minRaise']) $h['minRaise'] = $raiseSize;   // legal raise resets the bar
        $h['currentBet'] = $target;
        // everyone else must respond again
        foreach ($s['seats'] as $j => $q) if ($q && !empty($q['inhand']) && !$q['folded'] && !$q['allin'] && $j !== $seat) $s['seats'][$j]['acted'] = false;
        $p['acted'] = true;
        pk_log($s, $p['name'] . ($isAllIn ? ' is all-in ' . $target : ' raises to ' . $target));
    } else {
        unset($p, $h); return 'Unknown action.';
    }
    unset($p, $h);
    // advance turn / street
    $s['hand']['toAct'] = poker_next_actor($s, $seat);
    if ($s['hand']['toAct'] !== null) $s['hand']['deadline'] = time() + PK_ACT_TIMEOUT;
    return null;
}

// ---- deal streets / run out / showdown ----
function poker_advance_street(array &$s): void {
    $h = &$s['hand'];
    foreach ($s['seats'] as $i => $p) if ($p && !empty($p['inhand'])) { $s['seats'][$i]['bet'] = 0; $s['seats'][$i]['acted'] = false; }
    $h['currentBet'] = 0; $h['minRaise'] = $s['bb'];
    if ($h['street'] === 'preflop') { $h['street'] = 'flop';  $h['board'] = array_merge($h['board'], [array_shift($h['deck']), array_shift($h['deck']), array_shift($h['deck'])]); }
    elseif ($h['street'] === 'flop') { $h['street'] = 'turn';  $h['board'][] = array_shift($h['deck']); }
    elseif ($h['street'] === 'turn') { $h['street'] = 'river'; $h['board'][] = array_shift($h['deck']); }
    $first = poker_next_actor_from_button($s);
    $h['toAct'] = $first;
    if ($first !== null) $h['deadline'] = time() + PK_ACT_TIMEOUT;
    unset($h);
}
function poker_next_actor_from_button(array $s): ?int {
    $canact = pk_canact($s);
    if (count($canact) < 2) return null;         // no more betting (all-in situation)
    return pk_next($s, $s['hand']['button'], $canact);
}

// Award pots (handles side pots for all-ins) and finish the hand.
function poker_showdown(array &$s): void {
    $h = $s['hand'];
    $board = $h['board'];
    // contributions
    $contribs = [];
    foreach ($s['seats'] as $i => $p) if ($p && !empty($p['inhand'])) $contribs[$i] = ['put' => (int) $p['put'], 'folded' => !empty($p['folded'])];

    // scores for non-folded (for showdown reveal + winner calc)
    $scores = [];
    foreach ($contribs as $i => $c) if (!$c['folded']) $scores[$i] = poker_eval_best(array_merge($s['seats'][$i]['hole'], $board));

    // build side pots
    $pots = [];
    $rem = $contribs;
    while (true) {
        $pos = array_filter($rem, fn($c) => $c['put'] > 0);
        if (!$pos) break;
        $min = min(array_map(fn($c) => $c['put'], $pos));
        $amount = 0; $eligible = [];
        foreach ($rem as $i => $c) {
            if ($c['put'] <= 0) continue;
            $amount += $min; $rem[$i]['put'] -= $min;
            if (!$c['folded']) $eligible[] = $i;
        }
        $pots[] = ['amount' => $amount, 'eligible' => $eligible];
    }

    $winTotals = [];
    foreach ($pots as $pot) {
        $elig = array_values(array_filter($pot['eligible'], fn($i) => isset($scores[$i])));
        if (!$elig) { // everyone eligible folded (shouldn't happen) — give to any contributor
            continue;
        }
        $best = null; $winners = [];
        foreach ($elig as $i) {
            if ($best === null || $scores[$i] > $best) { $best = $scores[$i]; $winners = [$i]; }
            elseif ($scores[$i] === $best) $winners[] = $i;
        }
        $each = intdiv($pot['amount'], count($winners));
        $rembr = $pot['amount'] - $each * count($winners);
        foreach ($winners as $k => $i) {
            $add = $each + ($k < $rembr ? 1 : 0);       // odd chips to first winners
            $s['seats'][$i]['stack'] += $add;
            $winTotals[$i] = ($winTotals[$i] ?? 0) + $add;
        }
    }

    // build result view
    $showdown = [];
    if (count($scores) > 1) {
        foreach ($scores as $i => $sc) $showdown[] = ['seat' => $i, 'name' => $s['seats'][$i]['name'],
            'hole' => card_codes($s['seats'][$i]['hole']), 'hand' => poker_hand_name($sc)];
    }
    $winners = [];
    foreach ($winTotals as $i => $amt) {
        $winners[] = ['seat' => $i, 'name' => $s['seats'][$i]['name'], 'amount' => $amt,
                      'hand' => isset($scores[$i]) ? poker_hand_name($scores[$i]) : ''];
        pk_log($s, $s['seats'][$i]['name'] . ' wins ' . $amt . (isset($scores[$i]) && count($showdown) ? ' (' . poker_hand_name($scores[$i]) . ')' : ''));
    }
    $s['result'] = ['board' => card_codes($board), 'winners' => $winners, 'showdown' => $showdown];
    poker_end_hand($s);
}

function poker_end_hand(array &$s): void {
    $s['hand'] = null;
    $s['nextHandAt'] = time() + PK_HAND_DELAY;
    // clear hand fields + remove busted/leaving players
    foreach ($s['seats'] as $i => $p) {
        if (!$p) continue;
        if (!empty($p['leaving']) || $p['stack'] <= 0) {
            if ($p['stack'] > 0) casino_credit((int) $p['uid'], (int) $p['stack']);
            $s['seats'][$i] = null;
            continue;
        }
        foreach (['inhand','hole','bet','put','folded','allin','acted'] as $k) unset($s['seats'][$i][$k]);
    }
}

// ---- main resolver: run automatic transitions as far as possible ----
function poker_tick(array &$s): void {
    $now = time();
    // prune idle seats not in a hand
    foreach ($s['seats'] as $i => $p) {
        if ($p && ($now - ($p['seen'] ?? 0) > PK_SEAT_IDLE)) {
            if ($s['hand'] && !empty($p['inhand']) && empty($p['folded'])) { $s['seats'][$i]['folded'] = true; $s['seats'][$i]['leaving'] = true; }
            elseif (!$s['hand'] || empty($p['inhand'])) { casino_credit((int) $p['uid'], (int) $p['stack']); $s['seats'][$i] = null; }
        }
    }

    $guard = 0;
    while ($guard++ < 40) {
        if (!$s['hand']) {
            if (count(pk_seated($s)) >= 2 && $now >= $s['nextHandAt']) { poker_start_hand($s); continue; }
            break;
        }
        $h = &$s['hand'];
        // everyone folded but one -> that player wins the whole pot
        $live = pk_inhand($s);
        if (count($live) <= 1) {
            if (count($live) === 1) { $s['seats'][$live[0]]['stack'] += $h['pot'];
                $s['result'] = ['board' => card_codes($h['board']), 'winners' => [['seat' => $live[0], 'name' => $s['seats'][$live[0]]['name'], 'amount' => $h['pot'], 'hand' => '']], 'showdown' => []];
                pk_log($s, $s['seats'][$live[0]]['name'] . ' wins ' . $h['pot']); }
            else { // 0 live (everyone folded/left at once) — refund each contributor
                foreach ($s['seats'] as $i => $p) if ($p && !empty($p['inhand'])) $s['seats'][$i]['stack'] += (int) $p['put'];
            }
            unset($h); poker_end_hand($s); continue;
        }
        // waiting on a live player with time left?
        if ($h['toAct'] !== null) {
            if ($now > $h['deadline']) {                 // auto-act on timeout
                $seat = $h['toAct']; $toCall = $h['currentBet'] - $s['seats'][$seat]['bet'];
                unset($h);
                poker_action($s, (int) $s['seats'][$seat]['uid'], $toCall > 0 ? 'fold' : 'check', 0);
                continue;
            }
            unset($h); break;                            // genuinely waiting
        }
        // betting round complete
        if (count(pk_canact($s)) < 2) {                  // rest are all-in -> run out remaining streets
            while ($h['street'] !== 'river') { unset($h); poker_advance_street($s); $h = &$s['hand']; }
            unset($h); poker_showdown($s); continue;
        }
        if ($h['street'] === 'river') { unset($h); poker_showdown($s); continue; }
        unset($h); poker_advance_street($s); // next street, betting continues
    }
}

// ---- client view (hides other players' hole cards until showdown) ----
function poker_view(array $s, int $uid): array {
    $seats = [];
    $mySeat = null;
    foreach ($s['seats'] as $i => $p) {
        if (!$p) { $seats[$i] = null; continue; }
        if ((int) $p['uid'] === $uid) $mySeat = $i;
        $inHand = !empty($p['inhand']);
        $revealAll = $s['result'] && !empty($s['result']['showdown']);
        $showHole = ((int) $p['uid'] === $uid) || ($revealAll && $inHand && empty($p['folded']));
        $seats[$i] = [
            'name' => $p['name'], 'stack' => (int) $p['stack'],
            'inHand' => $inHand, 'folded' => !empty($p['folded']), 'allin' => !empty($p['allin']),
            'bet' => (int) ($p['bet'] ?? 0),
            'hole' => ($inHand && !empty($p['hole'])) ? ($showHole ? card_codes($p['hole']) : ['??','??']) : [],
        ];
    }
    $h = $s['hand'];
    $me = $mySeat !== null ? $s['seats'][$mySeat] : null;
    $view = [
        'ok' => true, 'id' => $s['id'], 'sb' => $s['sb'], 'bb' => $s['bb'], 'nseats' => $s['nseats'],
        'seats' => $seats, 'mySeat' => $mySeat, 'button' => $s['button'],
        'board' => $h ? card_codes($h['board']) : ($s['result']['board'] ?? []),
        'pot' => $h['pot'] ?? 0, 'street' => $h['street'] ?? null,
        'toAct' => $h['toAct'] ?? null,
        'toActName' => ($h && $h['toAct'] !== null && $s['seats'][$h['toAct']]) ? $s['seats'][$h['toAct']]['name'] : null,
        'deadline' => $h['deadline'] ?? null, 'now' => time(),
        'currentBet' => $h['currentBet'] ?? 0, 'minRaise' => $h['minRaise'] ?? $s['bb'],
        'result' => $s['result'],
        'log' => array_slice($s['log'], -8),
        'nextHandIn' => (!$h && $s['nextHandAt'] > time()) ? $s['nextHandAt'] - time() : 0,
    ];
    // my action context
    if ($me && $h && $h['toAct'] === $mySeat) {
        $toCall = $h['currentBet'] - (int) $me['bet'];
        $view['myTurn'] = true;
        $view['toCall'] = min($toCall, (int) $me['stack']);
        $view['canCheck'] = $toCall <= 0;
        $view['myStack'] = (int) $me['stack'];
        $view['myBet'] = (int) $me['bet'];
        $view['minRaiseTo'] = $h['currentBet'] + $h['minRaise'];
        $view['maxRaiseTo'] = (int) $me['bet'] + (int) $me['stack'];
    } else {
        $view['myTurn'] = false;
    }
    return $view;
}
