<?php
// Casino shared library. Authenticates + stores balances through the Videos
// accounts (users.coins), so LS coins are one real per-account currency. Every
// balance change goes through the atomic, never-negative helpers below, and
// every game outcome is rolled server-side — clients only send intent.

require_once __DIR__ . '/../../videos/lib/bootstrap.php'; // current_user, require_login, videos_db, csrf*, e, json_out, redirect

// ---- Economy ----
const LS_WELCOME = 2000;  // one-time starting stack; there are no other bonuses

function casino_balance(int $uid): int {
    $st = videos_db()->prepare("SELECT coins FROM users WHERE id = ?");
    $st->execute([$uid]);
    return (int) $st->fetchColumn();
}

// Grant the one-time starting stack. last_bonus flips from 0 so it never repeats;
// after this, more coins only come from the admin top-up page.
function casino_ensure_funded(int $uid): void {
    videos_db()->prepare("UPDATE users SET coins = coins + ?, last_bonus = ? WHERE id = ? AND last_bonus = 0")
               ->execute([LS_WELCOME, time(), $uid]);
}

// Admin adjustment (positive = give, negative = take). Never drops below 0.
function casino_admin_adjust(int $uid, int $delta): int {
    videos_db()->prepare("UPDATE users SET coins = MAX(0, coins + ?) WHERE id = ?")->execute([$delta, $uid]);
    return casino_balance($uid);
}

/** Atomically remove a stake. Returns true if it was affordable and applied. */
function casino_bet(int $uid, int $amount): bool {
    if ($amount <= 0) return false;
    $st = videos_db()->prepare("UPDATE users SET coins = coins - ? WHERE id = ? AND coins >= ?");
    $st->execute([$amount, $uid, $amount]);
    return $st->rowCount() === 1;
}

/** Pay coins in (winnings / refunds). */
function casino_credit(int $uid, int $amount): void {
    if ($amount <= 0) return;
    videos_db()->prepare("UPDATE users SET coins = coins + ? WHERE id = ?")->execute([$amount, $uid]);
}

function fmt_coins(int $n): string {
    return number_format($n);
}

/** Cache-busted URL for a casino asset (appends the file's mtime), so browsers
 *  always pick up updates instead of serving a stale cached copy. */
function casset(string $rel): string {
    $v = @filemtime(dirname(__DIR__) . $rel) ?: 1;
    return '/casino' . $rel . '?v=' . $v;
}

// ---- Cards (0..51: rank = i%13 [0=2 .. 12=A], suit = i div 13 [0=s,1=h,2=d,3=c]) ----
const RANK_CHARS = ['2','3','4','5','6','7','8','9','T','J','Q','K','A'];
const SUIT_CHARS = ['s','h','d','c'];

function fresh_deck(): array { return range(0, 51); }

/** Cryptographic Fisher-Yates shuffle (server-only randomness). */
function shuffle_deck(array &$d): void {
    for ($i = count($d) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$d[$i], $d[$j]] = [$d[$j], $d[$i]];
    }
}

function card_code(int $c): string { return RANK_CHARS[$c % 13] . SUIT_CHARS[intdiv($c, 13)]; }
function card_rank(int $c): int { return $c % 13; }          // 0..12
function card_codes(array $cards): array { return array_map('card_code', $cards); }

/** Best blackjack total for a set of cards, plus whether it's soft. */
function bj_value(array $cards): array {
    $total = 0; $aces = 0;
    foreach ($cards as $c) {
        $r = card_rank($c);
        if ($r === 12) { $aces++; $total += 11; }         // Ace
        elseif ($r >= 8) $total += 10;                     // T,J,Q,K
        else $total += $r + 2;                             // 2..9
    }
    $soft = false;
    while ($total > 21 && $aces > 0) { $total -= 10; $aces--; }
    if ($aces > 0 && $total <= 21) $soft = true;
    return [$total, $soft];
}

// ---- Page shell ----
function require_casino_user(): array {
    $u = require_login();                                   // videos login (redirects if needed)
    casino_ensure_funded((int) $u['id']);                  // one-time 2000 starting stack
    return $u;
}

function render_casino_header(string $title, ?array $u = null): void {
    $u = $u ?? current_user();
    $bal = $u ? casino_balance((int) $u['id']) : 0;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> · Casino · Logan Sandivar</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="<?= casset('/assets/casino.css') ?>">
<?php if ($u): ?><meta name="csrf" content="<?= e(csrf_token()) ?>"><?php endif; ?>
<script src="/js/noinspect.js"></script>
<script src="<?= casset('/assets/sfx.js') ?>"></script>
<script src="<?= casset('/assets/casino.js') ?>"></script>
</head>
<body>
<nav class="c-nav">
  <div class="c-nav-left">
    <a href="/" class="c-back" title="Back to logansandivar.com">&#8592;</a>
    <a href="/casino/" class="c-brand">🎰 LS Casino</a>
  </div>
  <div class="c-nav-links">
    <a href="/casino/slots.php">Slots</a>
    <a href="/casino/blackjack.php">Blackjack</a>
    <a href="/casino/poker.php">Poker</a>
    <a href="/casino/crash.php">Crash</a>
    <a href="/casino/roulette.php">Roulette</a>
    <a href="/casino/cases.php">Cases</a>
    <a href="/casino/upgrader.php">Upgrader</a>
    <a href="/casino/plinko.php">Plinko</a>
    <a href="/casino/market.php">Market</a>
    <?php if ($u && !empty($u['is_admin'])): ?><a href="/casino/admin.php">🛠 Admin</a><?php endif; ?>
  </div>
  <div class="c-nav-right">
    <button id="c-mute" class="c-btn" title="Sound on/off">🔊</button>
    <?php if ($u): ?>
      <a href="/casino/inventory.php" class="c-btn" title="Inventory">🎒</a>
      <span class="c-balance" id="c-balance">🪙 <b><?= fmt_coins($bal) ?></b> LS</span>
      <a href="/videos/logout.php" class="c-btn">Logout</a>
    <?php else: ?>
      <a href="/videos/login.php?next=/casino/" class="c-btn c-btn-gold">Log in to play</a>
    <?php endif; ?>
  </div>
</nav>
<main class="c-main"><?php
}

function render_casino_footer(): void {
    ?></main>
<footer class="c-footer">
  <span>LS Casino · play-money only · part of <a href="/">logansandivar.com</a></span>
  <span class="c-dim">🪙 LS coins have no real-world value.</span>
</footer>
</body>
</html><?php
}
