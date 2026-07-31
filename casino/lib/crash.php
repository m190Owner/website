<?php
// Crash: a shared, server-authoritative multiplier game. One global round runs
// at a time; the server owns a pre-committed (hashed) bust point and advances a
// betting -> flying -> over state machine. Clients only send intent (bet /
// cash out) and render the rocket from the server clock. Provably fair: the
// seed's SHA-256 is published before takeoff and the seed revealed after, so
// anyone can recompute the bust.

require_once __DIR__ . '/casino.php';

const CRASH_MIN = 10, CRASH_MAX = 5000;   // per-round bet, matches the other games
const CRASH_BET_MS      = 6000;           // betting window
const CRASH_COOLDOWN_MS = 4000;           // pause after a bust before the next round
const CRASH_RATE        = 0.00008;        // multiplier growth: m(t) = e^(RATE * t_ms)
const CRASH_HMAC_KEY     = 'ls-crash-v1'; // published so players can verify the bust
const CRASH_EDGE_DIVISOR = 25;            // ~1/25 rounds instant-bust at 1.00x (~4% house edge)

function crash_now_ms(): int { return (int) round(microtime(true) * 1000); }

/** Multiplier shown at $ms into the flight (floored to 0.01, min 1.00). */
function crash_mult_at(int $ms): float {
    if ($ms < 0) $ms = 0;
    return max(1.00, floor(exp(CRASH_RATE * $ms) * 100) / 100);
}
/** Milliseconds into the flight at which the multiplier reaches $mult. */
function crash_time_for(float $mult): int {
    if ($mult <= 1.0) return 0;
    return (int) ceil(log($mult) / CRASH_RATE);
}

/**
 * Deterministic bust point from the round seed. Published formula:
 *   h8   = first 8 hex of HMAC_SHA256(seed, "ls-crash-v1")
 *   if h8 % 25 == 0  -> bust 1.00 (the house edge)
 *   else h = next 13 hex (52-bit); bust = floor((100*2^52 - h) / (2^52 - h)) / 100
 */
function crash_bust_from_seed(string $seed): float {
    $hash = hash_hmac('sha256', $seed, CRASH_HMAC_KEY);
    if (hexdec(substr($hash, 0, 8)) % CRASH_EDGE_DIVISOR === 0) return 1.00;
    $h = hexdec(substr($hash, 8, 13));            // 52-bit slice (fits a 64-bit int)
    $e = 2 ** 52;
    return max(1.01, floor((100 * $e - $h) / ($e - $h)) / 100);
}

function crash_tables_init(): void {
    videos_db()->exec(
        "CREATE TABLE IF NOT EXISTS crash_rounds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seed TEXT NOT NULL,
            seed_hash TEXT NOT NULL,
            bust REAL NOT NULL,
            phase TEXT NOT NULL,
            betting_ends INTEGER NOT NULL,
            fly_started INTEGER NOT NULL DEFAULT 0,
            ended_at INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL);
         CREATE TABLE IF NOT EXISTS crash_bets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            round_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            bet INTEGER NOT NULL,
            auto REAL NOT NULL DEFAULT 0,
            cashed REAL NOT NULL DEFAULT 0,
            payout INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            UNIQUE(round_id, user_id));
         CREATE INDEX IF NOT EXISTS idx_crash_bets_round ON crash_bets(round_id);"
    );
}

function crash_latest(): ?array {
    $r = videos_db()->query("SELECT * FROM crash_rounds ORDER BY id DESC LIMIT 1")->fetch();
    return $r ?: null;
}

function crash_new_round(int $now): array {
    $seed = bin2hex(random_bytes(32));
    $hash = hash('sha256', $seed);
    $bust = crash_bust_from_seed($seed);
    videos_db()->prepare(
        "INSERT INTO crash_rounds (seed, seed_hash, bust, phase, betting_ends, created_at)
         VALUES (?, ?, ?, 'betting', ?, ?)"
    )->execute([$seed, $hash, $bust, $now + CRASH_BET_MS, intdiv($now, 1000)]);
    return crash_latest();
}

/** Pay out any uncashed auto-cashout bets whose target has been reached (and is
 *  below the bust). Called each tick while flying. */
function crash_settle_autos(array $round, float $curMult): void {
    $db = videos_db();
    $bust = (float) $round['bust'];
    $st = $db->prepare("SELECT id, user_id, bet, auto FROM crash_bets WHERE round_id = ? AND cashed = 0 AND auto > 1.0 AND auto <= ?");
    $st->execute([$round['id'], $curMult]);
    foreach ($st->fetchAll() as $b) {
        $auto = (float) $b['auto'];
        if ($auto >= $bust) continue;                       // would have crashed first
        $payout = (int) floor($b['bet'] * $auto);
        $db->prepare("UPDATE crash_bets SET cashed = ?, payout = ? WHERE id = ? AND cashed = 0")
           ->execute([$auto, $payout, $b['id']]);
        casino_credit((int) $b['user_id'], $payout);
    }
}

/** Advance the global round state machine to "now" and return the live round. */
function crash_tick(): array {
    $db = videos_db();
    $now = crash_now_ms();
    $round = crash_latest();
    if (!$round) return crash_new_round($now);

    if ($round['phase'] === 'betting' && $now >= (int) $round['betting_ends']) {
        $db->prepare("UPDATE crash_rounds SET phase = 'flying', fly_started = ? WHERE id = ?")
           ->execute([$now, $round['id']]);
        $round['phase'] = 'flying';
        $round['fly_started'] = $now;
    }

    if ($round['phase'] === 'flying') {
        $bustMs = crash_time_for((float) $round['bust']);
        $overAt = (int) $round['fly_started'] + $bustMs;
        $curMult = crash_mult_at(min($now, $overAt) - (int) $round['fly_started']);
        crash_settle_autos($round, $curMult);
        if ($now >= $overAt) {
            $db->prepare("UPDATE crash_rounds SET phase = 'over', ended_at = ? WHERE id = ?")
               ->execute([$overAt, $round['id']]);
            $round['phase'] = 'over';
            $round['ended_at'] = $overAt;
        }
    }

    if ($round['phase'] === 'over' && $now >= (int) $round['ended_at'] + CRASH_COOLDOWN_MS) {
        return crash_new_round($now);
    }
    return $round;
}

/** Place a bet for the current round (betting phase only). Returns error or null. */
function crash_place_bet(array $round, array $u, int $bet, float $auto): ?string {
    if ($round['phase'] !== 'betting') return 'Betting is closed for this round.';
    if ($bet < CRASH_MIN || $bet > CRASH_MAX) return 'Bet must be between ' . CRASH_MIN . ' and ' . CRASH_MAX . '.';
    if ($auto !== 0.0 && $auto < 1.01) return 'Auto cash-out must be 1.01 or higher (or off).';
    if ($auto > 100000) $auto = 100000.0;

    $db = videos_db();
    $uid = (int) $u['id'];
    $chk = $db->prepare("SELECT 1 FROM crash_bets WHERE round_id = ? AND user_id = ?");
    $chk->execute([$round['id'], $uid]);
    if ($chk->fetch()) return "You're already in this round.";

    if (!casino_bet($uid, $bet)) return 'Not enough coins.';
    $db->prepare("INSERT INTO crash_bets (round_id, user_id, username, bet, auto, created_at) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$round['id'], $uid, $u['username'], $bet, $auto, time()]);
    return null;
}

/** Cash out the caller's bet at the current server multiplier (flying only). */
function crash_cashout(array $round, int $uid): ?string {
    if ($round['phase'] !== 'flying') {
        return $round['phase'] === 'over' ? 'Round already crashed at ' . rtrim(rtrim(number_format((float) $round['bust'], 2), '0'), '.') . 'x.' : 'The round has not started.';
    }
    $db = videos_db();
    $st = $db->prepare("SELECT id, bet, cashed FROM crash_bets WHERE round_id = ? AND user_id = ?");
    $st->execute([$round['id'], $uid]);
    $b = $st->fetch();
    if (!$b) return "You have no bet in this round.";
    if ((float) $b['cashed'] > 0) return 'Already cashed out.';

    $mult = crash_mult_at($ms = crash_now_ms() - (int) $round['fly_started']);
    if ($mult >= (float) $round['bust']) return 'Too late — it crashed.';   // guarded by the tick, belt-and-braces
    $payout = (int) floor($b['bet'] * $mult);
    $done = $db->prepare("UPDATE crash_bets SET cashed = ?, payout = ? WHERE id = ? AND cashed = 0");
    $done->execute([$mult, $payout, $b['id']]);
    if ($done->rowCount() !== 1) return 'Already cashed out.';
    casino_credit($uid, $payout);
    return null;
}

/** The client-facing snapshot for polling. Bust/seed only revealed once over. */
function crash_view(array $round, int $uid): array {
    $db = videos_db();
    $now = crash_now_ms();
    $over = $round['phase'] === 'over';

    $bets = $db->prepare("SELECT username, bet, auto, cashed, payout FROM crash_bets WHERE round_id = ? ORDER BY bet DESC, id ASC");
    $bets->execute([$round['id']]);
    $board = array_map(fn($b) => [
        'username' => $b['username'], 'bet' => (int) $b['bet'],
        'auto' => (float) $b['auto'], 'cashed' => (float) $b['cashed'], 'payout' => (int) $b['payout'],
    ], $bets->fetchAll());

    $mine = null;
    $ms = $db->prepare("SELECT bet, auto, cashed, payout FROM crash_bets WHERE round_id = ? AND user_id = ?");
    $ms->execute([$round['id'], $uid]);
    if ($row = $ms->fetch()) {
        $mine = ['bet' => (int) $row['bet'], 'auto' => (float) $row['auto'], 'cashed' => (float) $row['cashed'], 'payout' => (int) $row['payout']];
    }

    $hist = $db->query("SELECT bust FROM crash_rounds WHERE phase = 'over' ORDER BY id DESC LIMIT 15")->fetchAll();
    $history = array_reverse(array_map(fn($h) => (float) $h['bust'], $hist));

    $elapsed = $round['phase'] === 'flying' ? $now - (int) $round['fly_started'] : 0;

    return [
        'ok' => true,
        'now' => $now,
        'phase' => $round['phase'],
        'roundId' => (int) $round['id'],
        'seedHash' => $round['seed_hash'],
        'seed' => $over ? $round['seed'] : null,
        'bust' => $over ? (float) $round['bust'] : null,
        'bettingEndsIn' => $round['phase'] === 'betting' ? max(0, (int) $round['betting_ends'] - $now) : 0,
        'elapsed' => $elapsed,
        'multiplier' => $round['phase'] === 'flying' ? crash_mult_at($elapsed) : ($over ? (float) $round['bust'] : 1.00),
        'board' => $board,
        'me' => $mine,
        'history' => $history,
        'balance' => casino_balance($uid),
    ];
}
