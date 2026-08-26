<?php
// Cards Against Lag — server-authoritative party-game engine. Guest room-code play
// (no accounts); each player holds a secret token that authorises their actions. The
// server owns every hidden thing (draw piles, hands, who submitted what) and only ever
// sends a player their OWN hand — anti-cheat by omission. State advances on each poll.
require_once __DIR__ . '/../../config.php';   // rateLimited()

const CAH_HAND       = 10;     // white cards per hand
const CAH_MIN        = 3;      // minimum players to start
const CAH_DISCONNECT = 25;     // seconds since last poll => treated as away
const CAH_ROUNDOVER  = 6;      // seconds to show the round winner before next round
const CAH_TTL        = 7200;   // idle room lifetime (2h)

function cah_db(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    try {
        if (!is_dir(__DIR__ . '/../data')) @mkdir(__DIR__ . '/../data', 0755, true);
        $pdo = new PDO('sqlite:' . __DIR__ . '/../data/cards.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            code TEXT PRIMARY KEY, phase TEXT NOT NULL DEFAULT 'lobby', deck TEXT NOT NULL DEFAULT 'clean',
            host_id INTEGER, czar_id INTEGER, round_no INTEGER NOT NULL DEFAULT 0, target INTEGER NOT NULL DEFAULT 7,
            black TEXT, white_deck TEXT NOT NULL DEFAULT '[]', black_deck TEXT NOT NULL DEFAULT '[]',
            judge_order TEXT, winner_id INTEGER, win_sub TEXT, created_at INTEGER, updated_at INTEGER )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, name TEXT NOT NULL, token TEXT NOT NULL,
            score INTEGER NOT NULL DEFAULT 0, hand TEXT NOT NULL DEFAULT '[]', last_seen INTEGER, joined_at INTEGER,
            is_bot INTEGER NOT NULL DEFAULT 0 )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_p_code ON players(code)");
        try { $pdo->exec("ALTER TABLE players ADD COLUMN is_bot INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        $pdo->exec("CREATE TABLE IF NOT EXISTS submissions (
            code TEXT NOT NULL, round_no INTEGER NOT NULL, player_id INTEGER NOT NULL, cards TEXT NOT NULL,
            PRIMARY KEY (code, round_no, player_id) )");
    } catch (\Throwable $e) { $pdo = null; }
    return $pdo;
}

function cah_deck(string $id): array {
    static $cache = [];
    $id = $id === 'nsfw' ? 'nsfw' : 'clean';
    if (isset($cache[$id])) return $cache[$id];
    $d = json_decode((string) @file_get_contents(__DIR__ . '/../decks/' . $id . '.json'), true);
    return $cache[$id] = is_array($d) ? $d : ['black' => [], 'white' => []];
}

function cah_err(string $m): array { return ['ok' => false, 'error' => $m]; }
function cah_now(): int { return time(); }
function cah_code(): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no ambiguous 0/O/1/I
    $s = ''; for ($i = 0; $i < 4; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

function cah_room(PDO $db, string $code): ?array {
    $st = $db->prepare("SELECT * FROM rooms WHERE code = ?"); $st->execute([$code]);
    $r = $st->fetch(); return $r ?: null;
}
function cah_players(PDO $db, string $code): array {
    $st = $db->prepare("SELECT * FROM players WHERE code = ? ORDER BY id"); $st->execute([$code]);
    return $st->fetchAll();
}
function cah_active(array $players, int $now): array {
    return array_values(array_filter($players, fn($p) => (int) ($p['is_bot'] ?? 0) === 1 || ($now - (int) $p['last_seen']) <= CAH_DISCONNECT));
}
function cah_by_token(array $players, string $token): ?array {
    foreach ($players as $p) if (hash_equals($p['token'], $token)) return $p;
    return null;
}

/** Refill a player's hand to CAH_HAND from the room's white draw pile (reshuffles when dry). */
function cah_refill(PDO $db, array &$room, array $player): void {
    $deck = cah_deck($room['deck']);
    $pile = json_decode($room['white_deck'], true) ?: [];
    $hand = json_decode($player['hand'], true) ?: [];
    while (count($hand) < CAH_HAND) {
        if (!$pile) { $pile = range(0, max(0, count($deck['white']) - 1)); shuffle($pile); }
        $idx = array_pop($pile);
        $txt = $deck['white'][$idx] ?? null;
        if ($txt !== null) $hand[] = $txt;
    }
    $room['white_deck'] = json_encode($pile);
    $db->prepare("UPDATE players SET hand = ? WHERE id = ?")->execute([json_encode($hand), $player['id']]);
}

/** Draw the next black card into the room (reshuffles when dry). */
function cah_draw_black(array &$room): void {
    $deck = cah_deck($room['deck']);
    $pile = json_decode($room['black_deck'], true) ?: [];
    if (!$pile) { $pile = range(0, max(0, count($deck['black']) - 1)); shuffle($pile); }
    $idx = array_pop($pile);
    $room['black'] = json_encode($deck['black'][$idx] ?? ['t' => '_', 'pick' => 1]);
    $room['black_deck'] = json_encode($pile);
}

/** Begin a new round: rotate czar among active players, draw a black card, clear subs. */
function cah_begin_round(PDO $db, array &$room, array $active): void {
    $room['round_no'] = (int) $room['round_no'] + 1;
    $room['czar_id']  = (int) $active[((int) $room['round_no'] - 1) % count($active)]['id'];
    cah_draw_black($room);
    $room['judge_order'] = null; $room['winner_id'] = null; $room['win_sub'] = null;
    $room['phase'] = 'playing';
    $db->prepare("DELETE FROM submissions WHERE code = ? AND round_no <> ?")->execute([$room['code'], $room['round_no']]);
    foreach ($active as $p) cah_refill($db, $room, $p);
}

function cah_persist(PDO $db, array $room): void {
    $db->prepare("UPDATE rooms SET phase=?, czar_id=?, round_no=?, black=?, white_deck=?, black_deck=?,
        judge_order=?, winner_id=?, win_sub=?, host_id=?, updated_at=? WHERE code=?")
       ->execute([$room['phase'], $room['czar_id'], $room['round_no'], $room['black'], $room['white_deck'],
        $room['black_deck'], $room['judge_order'], $room['winner_id'], $room['win_sub'], $room['host_id'], cah_now(), $room['code']]);
}

function cah_bot_names(): array {
    return ['Clanka', 'GLaDOS-lite', 'Bit', 'Byte', 'Roomba', 'HAL 9001', 'Deep Fold', 'Circuit', 'Nibbles', 'Cortex', 'Ada'];
}
/** Per-bot, per-round "thinking" delay (1–4s) so bots don't all answer in the same instant. */
function cah_bot_delay(int $botId, int $round): int { return 1 + (int) (crc32($botId . ':' . $round) % 4); }

/** Bots have no client to poll — they act here, driven by the humans' ticks. Runs inside the
 *  caller's transaction. Bots answer (playing) and judge (when a bot is czar) after a short stagger. */
function cah_bots_act(PDO $db, array $room, array $players): void {
    $bots = array_values(array_filter($players, fn($p) => (int) ($p['is_bot'] ?? 0) === 1));
    if (!$bots) return;
    $now = cah_now();
    foreach ($bots as $b) $db->prepare("UPDATE players SET last_seen = ? WHERE id = ?")->execute([$now, $b['id']]);  // never "disconnect"
    $since = $now - (int) $room['updated_at'];

    if ($room['phase'] === 'playing') {
        $black = json_decode($room['black'], true) ?: ['pick' => 1];
        $pick = max(1, (int) ($black['pick'] ?? 1));
        $done = $db->prepare("SELECT player_id FROM submissions WHERE code=? AND round_no=?");
        $done->execute([$room['code'], $room['round_no']]);
        $submitted = array_map('intval', array_column($done->fetchAll(), 'player_id'));
        foreach ($bots as $b) {
            if ((int) $b['id'] === (int) $room['czar_id'] || in_array((int) $b['id'], $submitted, true)) continue;
            if ($since < cah_bot_delay((int) $b['id'], (int) $room['round_no'])) continue;   // still thinking
            $hand = json_decode($b['hand'], true) ?: [];
            if (count($hand) < $pick) continue;
            shuffle($hand);
            $play = array_slice($hand, 0, $pick);
            $rest = array_slice($hand, $pick);
            $db->prepare("INSERT OR IGNORE INTO submissions (code, round_no, player_id, cards) VALUES (?,?,?,?)")
               ->execute([$room['code'], $room['round_no'], $b['id'], json_encode(array_values($play))]);
            $db->prepare("UPDATE players SET hand = ? WHERE id = ?")->execute([json_encode(array_values($rest)), $b['id']]);
        }
    } elseif ($room['phase'] === 'judging') {
        $czar = null; foreach ($bots as $b) if ((int) $b['id'] === (int) $room['czar_id']) $czar = $b;
        if ($czar && $since >= cah_bot_delay((int) $czar['id'], (int) $room['round_no']) + 1) {
            $subs = $db->prepare("SELECT player_id, cards FROM submissions WHERE code=? AND round_no=?");
            $subs->execute([$room['code'], $room['round_no']]);
            $rows = $subs->fetchAll();
            if ($rows) {
                $win = $rows[random_int(0, count($rows) - 1)];
                $db->prepare("UPDATE players SET score = score + 1 WHERE id = ?")->execute([$win['player_id']]);
                $phase = 'roundover';
                $sc = $db->prepare("SELECT score FROM players WHERE id = ?"); $sc->execute([$win['player_id']]);
                if ((int) $sc->fetchColumn() >= (int) $room['target']) $phase = 'gameover';
                $db->prepare("UPDATE rooms SET phase=?, winner_id=?, win_sub=?, updated_at=? WHERE code=?")
                   ->execute([$phase, $win['player_id'], $win['cards'], cah_now(), $room['code']]);
            }
        }
    }
}

/** The heartbeat: advance server-authoritative state. Serialized per DB by an immediate tx. */
function cah_tick(PDO $db, string $code): void {
    try { $db->beginTransaction(); $db->exec('SELECT 1'); } catch (\Throwable $e) {}
    try {
        $room = cah_room($db, $code); if (!$room) { if ($db->inTransaction()) $db->commit(); return; }
        $now = cah_now();
        $players = cah_players($db, $code);
        cah_bots_act($db, $room, $players);          // bots take their turn (submit / judge)
        $room = cah_room($db, $code); if (!$room) { if ($db->inTransaction()) $db->commit(); return; }
        $players = cah_players($db, $code);
        $active  = cah_active($players, $now);

        if ($room['phase'] === 'playing') {
            $czarPresent = false; foreach ($active as $p) if ((int) $p['id'] === (int) $room['czar_id']) $czarPresent = true;
            $need = array_values(array_filter($active, fn($p) => (int) $p['id'] !== (int) $room['czar_id']));
            $subs = $db->prepare("SELECT player_id FROM submissions WHERE code=? AND round_no=?");
            $subs->execute([$code, $room['round_no']]);
            $done = array_column($subs->fetchAll(), 'player_id');
            $allIn = count($need) > 0 && count(array_intersect(array_map(fn($p) => (int) $p['id'], $need), array_map('intval', $done))) === count($need);
            if (!$czarPresent && count($active) >= CAH_MIN) {           // czar vanished -> new round
                cah_begin_round($db, $room, $active); cah_persist($db, $room);
            } elseif ($allIn) {                                         // everyone answered -> judging
                $order = array_map('intval', $done); shuffle($order);
                $room['phase'] = 'judging'; $room['judge_order'] = json_encode($order); cah_persist($db, $room);
            } elseif (count($active) < CAH_MIN) {                       // too few -> back to lobby
                $room['phase'] = 'lobby'; cah_persist($db, $room);
            }
        } elseif ($room['phase'] === 'judging') {
            $czarPresent = false; foreach ($active as $p) if ((int) $p['id'] === (int) $room['czar_id']) $czarPresent = true;
            if (!$czarPresent && count($active) >= CAH_MIN) { cah_begin_round($db, $room, $active); cah_persist($db, $room); }
        } elseif ($room['phase'] === 'roundover') {
            if ($now - (int) $room['updated_at'] >= CAH_ROUNDOVER) {
                if (count($active) >= CAH_MIN) cah_begin_round($db, $room, $active);
                else $room['phase'] = 'lobby';
                cah_persist($db, $room);
            }
        }
        if ($db->inTransaction()) $db->commit();
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); }

    // opportunistic cleanup of dead rooms (outside the tx)
    try { $old = $db->prepare("SELECT code FROM rooms WHERE updated_at < ?"); $old->execute([cah_now() - CAH_TTL]);
        foreach ($old->fetchAll() as $r) { $db->prepare("DELETE FROM players WHERE code=?")->execute([$r['code']]);
            $db->prepare("DELETE FROM submissions WHERE code=?")->execute([$r['code']]);
            $db->prepare("DELETE FROM rooms WHERE code=?")->execute([$r['code']]); }
    } catch (\Throwable $e) {}
}

// ---- actions ----
function cah_create(string $name, string $deck, int $target): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $name = cah_name($name); if ($name === null) return cah_err('Enter a name (1–20 chars).');
    $deck = $deck === 'nsfw' ? 'nsfw' : 'clean';
    $target = max(3, min(15, $target));
    for ($try = 0; $try < 8; $try++) { $code = cah_code(); if (!cah_room($db, $code)) break; }
    $token = bin2hex(random_bytes(16)); $now = cah_now();
    $db->prepare("INSERT INTO rooms (code, phase, deck, target, created_at, updated_at) VALUES (?,?,?,?,?,?)")
       ->execute([$code, 'lobby', $deck, $target, $now, $now]);
    $db->prepare("INSERT INTO players (code, name, token, last_seen, joined_at) VALUES (?,?,?,?,?)")
       ->execute([$code, $name, $token, $now, $now]);
    $pid = (int) $db->lastInsertId();
    $db->prepare("UPDATE rooms SET host_id = ? WHERE code = ?")->execute([$pid, $code]);
    return ['ok' => true, 'code' => $code, 'token' => $token, 'player_id' => $pid];
}

function cah_join(string $code, string $name): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $code = strtoupper(trim($code));
    $room = cah_room($db, $code); if (!$room) return cah_err('No room with that code.');
    $name = cah_name($name); if ($name === null) return cah_err('Enter a name (1–20 chars).');
    if (count(cah_players($db, $code)) >= 12) return cah_err('That room is full (12 max).');
    $token = bin2hex(random_bytes(16)); $now = cah_now();
    $db->prepare("INSERT INTO players (code, name, token, last_seen, joined_at) VALUES (?,?,?,?,?)")
       ->execute([$code, $name, $token, $now, $now]);
    $pid = (int) $db->lastInsertId();
    // deal a hand right away so they can play from the next round
    if ($room['phase'] !== 'lobby') { $p = ['id' => $pid, 'hand' => '[]']; cah_refill($db, $room, $p); cah_persist($db, $room); }
    return ['ok' => true, 'code' => $code, 'token' => $token, 'player_id' => $pid];
}

function cah_start(string $code, string $token): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $room = cah_room($db, $code); if (!$room) return cah_err('gone');
    $players = cah_players($db, $code); $me = cah_by_token($players, $token);
    if (!$me) return cah_err('not in room');
    if ((int) $me['id'] !== (int) $room['host_id']) return cah_err('Only the host can start.');
    $active = cah_active($players, cah_now());
    if (count($active) < CAH_MIN) return cah_err('Need at least ' . CAH_MIN . ' players.');
    if ($room['phase'] !== 'lobby' && $room['phase'] !== 'gameover') return cah_err('Already started.');
    // fresh game
    $db->prepare("UPDATE players SET score = 0, hand = '[]' WHERE code = ?")->execute([$code]);
    $db->prepare("DELETE FROM submissions WHERE code = ?")->execute([$code]);
    $room['round_no'] = 0; $room['white_deck'] = '[]'; $room['black_deck'] = '[]';
    $players = cah_players($db, $code); $active = cah_active($players, cah_now());
    cah_begin_round($db, $room, $active); cah_persist($db, $room);
    return ['ok' => true];
}

function cah_submit(string $code, string $token, array $cards): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $room = cah_room($db, $code); if (!$room || $room['phase'] !== 'playing') return cah_err('Not accepting cards now.');
    $players = cah_players($db, $code); $me = cah_by_token($players, $token);
    if (!$me) return cah_err('not in room');
    if ((int) $me['id'] === (int) $room['czar_id']) return cah_err('You are the Card Czar this round.');
    $black = json_decode($room['black'], true) ?: ['pick' => 1];
    $pick = max(1, (int) ($black['pick'] ?? 1));
    $cards = array_values(array_slice(array_map('strval', $cards), 0, $pick));
    if (count($cards) !== $pick) return cah_err('Play exactly ' . $pick . ' card' . ($pick > 1 ? 's' : '') . '.');
    $hand = json_decode($me['hand'], true) ?: [];
    foreach ($cards as $c) { $k = array_search($c, $hand, true); if ($k === false) return cah_err('bad card'); array_splice($hand, $k, 1); }
    $exists = $db->prepare("SELECT 1 FROM submissions WHERE code=? AND round_no=? AND player_id=?");
    $exists->execute([$code, $room['round_no'], $me['id']]);
    if ($exists->fetch()) return cah_err('Already submitted.');
    $db->prepare("INSERT INTO submissions (code, round_no, player_id, cards) VALUES (?,?,?,?)")
       ->execute([$code, $room['round_no'], $me['id'], json_encode($cards)]);
    $db->prepare("UPDATE players SET hand = ?, last_seen = ? WHERE id = ?")->execute([json_encode($hand), cah_now(), $me['id']]);
    cah_tick($db, $code);
    return ['ok' => true];
}

function cah_pick(string $code, string $token, int $winnerId): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $room = cah_room($db, $code); if (!$room || $room['phase'] !== 'judging') return cah_err('Not judging now.');
    $players = cah_players($db, $code); $me = cah_by_token($players, $token);
    if (!$me) return cah_err('not in room');
    if ((int) $me['id'] !== (int) $room['czar_id']) return cah_err('Only the Card Czar picks.');
    $sub = $db->prepare("SELECT cards FROM submissions WHERE code=? AND round_no=? AND player_id=?");
    $sub->execute([$code, $room['round_no'], $winnerId]);
    $row = $sub->fetch(); if (!$row) return cah_err('bad pick');
    $db->prepare("UPDATE players SET score = score + 1 WHERE id = ?")->execute([$winnerId]);
    $room['winner_id'] = $winnerId; $room['win_sub'] = $row['cards']; $room['phase'] = 'roundover';
    // check for game win
    $sc = $db->prepare("SELECT score FROM players WHERE id = ?"); $sc->execute([$winnerId]);
    if ((int) $sc->fetchColumn() >= (int) $room['target']) $room['phase'] = 'gameover';
    cah_persist($db, $room);
    return ['ok' => true];
}

function cah_leave(string $code, string $token): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $room = cah_room($db, $code); if (!$room) return ['ok' => true];
    $players = cah_players($db, $code); $me = cah_by_token($players, $token);
    if (!$me) return ['ok' => true];
    $db->prepare("DELETE FROM players WHERE id = ?")->execute([$me['id']]);
    $db->prepare("DELETE FROM submissions WHERE code=? AND player_id=?")->execute([$code, $me['id']]);
    $rest = cah_players($db, $code);
    if (!$rest) { $db->prepare("DELETE FROM rooms WHERE code=?")->execute([$code]); return ['ok' => true]; }
    if ((int) $room['host_id'] === (int) $me['id']) $db->prepare("UPDATE rooms SET host_id=? WHERE code=?")->execute([(int) $rest[0]['id'], $code]);
    cah_tick($db, $code);
    return ['ok' => true];
}

function cah_again(string $code, string $token): array { return cah_start($code, $token); }

function cah_addbot(string $code, string $token): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $room = cah_room($db, $code); if (!$room) return cah_err('gone');
    $players = cah_players($db, $code); $me = cah_by_token($players, $token);
    if (!$me || (int) $me['id'] !== (int) $room['host_id']) return cah_err('Only the host can add bots.');
    if (count($players) >= 12) return cah_err('Room is full (12 max).');
    $used = array_map(fn($p) => $p['name'], $players);
    $pick = null;
    foreach (cah_bot_names() as $n) if (!in_array($n, $used, true)) { $pick = $n; break; }
    if ($pick === null) $pick = 'Bot ' . random_int(10, 99);
    $now = cah_now();
    $db->prepare("INSERT INTO players (code, name, token, last_seen, joined_at, is_bot) VALUES (?,?,?,?,?,1)")
       ->execute([$code, $pick, 'bot-' . bin2hex(random_bytes(6)), $now, $now]);
    $pid = (int) $db->lastInsertId();
    if ($room['phase'] !== 'lobby' && $room['phase'] !== 'gameover') { $p = ['id' => $pid, 'hand' => '[]']; cah_refill($db, $room, $p); cah_persist($db, $room); }
    return ['ok' => true];
}
function cah_removebot(string $code, string $token, int $botId): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $room = cah_room($db, $code); if (!$room) return cah_err('gone');
    $players = cah_players($db, $code); $me = cah_by_token($players, $token);
    if (!$me || (int) $me['id'] !== (int) $room['host_id']) return cah_err('Only the host can remove bots.');
    $isBot = false; foreach ($players as $p) if ((int) $p['id'] === $botId && (int) ($p['is_bot'] ?? 0) === 1) $isBot = true;
    if (!$isBot) return cah_err('not a bot');
    $db->prepare("DELETE FROM players WHERE id = ?")->execute([$botId]);
    $db->prepare("DELETE FROM submissions WHERE code=? AND player_id=?")->execute([$code, $botId]);
    cah_tick($db, $code);
    return ['ok' => true];
}

/** The poll: touch presence, advance state, return this player's tailored view. */
function cah_state(string $code, string $token): array {
    $db = cah_db(); if (!$db) return cah_err('unavailable');
    $code = strtoupper(trim($code));
    $room = cah_room($db, $code); if (!$room) return cah_err('Room closed.');
    $players = cah_players($db, $code); $me = cah_by_token($players, $token);
    if (!$me) return cah_err('not in room');
    $db->prepare("UPDATE players SET last_seen = ? WHERE id = ?")->execute([cah_now(), $me['id']]);
    cah_tick($db, $code);
    // reload after tick
    $room = cah_room($db, $code); if (!$room) return cah_err('Room closed.');
    $players = cah_players($db, $code); $me = cah_by_token($players, $token) ?: $me;
    $now = cah_now();

    $subs = $db->prepare("SELECT player_id FROM submissions WHERE code=? AND round_no=?");
    $subs->execute([$code, $room['round_no']]); $done = array_map(fn($r) => (int) $r['player_id'], $subs->fetchAll());

    $plist = array_map(function ($p) use ($room, $now, $done) {
        return ['id' => (int) $p['id'], 'name' => $p['name'], 'score' => (int) $p['score'],
                'bot' => (int) ($p['is_bot'] ?? 0) === 1,
                'connected' => (int) ($p['is_bot'] ?? 0) === 1 || ($now - (int) $p['last_seen']) <= CAH_DISCONNECT,
                'czar' => (int) $p['id'] === (int) $room['czar_id'],
                'host' => (int) $p['id'] === (int) $room['host_id'],
                'submitted' => in_array((int) $p['id'], $done, true)];
    }, $players);

    $black = $room['black'] ? json_decode($room['black'], true) : null;
    $out = [
        'ok' => true, 'code' => $code, 'phase' => $room['phase'], 'deck' => $room['deck'],
        'nsfw' => $room['deck'] === 'nsfw', 'round' => (int) $room['round_no'], 'target' => (int) $room['target'],
        'players' => $plist, 'black' => $black,
        'me' => ['id' => (int) $me['id'], 'name' => $me['name'], 'hand' => json_decode($me['hand'], true) ?: [],
                 'czar' => (int) $me['id'] === (int) $room['czar_id'], 'host' => (int) $me['id'] === (int) $room['host_id'],
                 'submitted' => in_array((int) $me['id'], $done, true)],
    ];
    if ($room['phase'] === 'judging') {
        $order = json_decode($room['judge_order'], true) ?: $done;
        $map = [];
        $q = $db->prepare("SELECT player_id, cards FROM submissions WHERE code=? AND round_no=?");
        $q->execute([$code, $room['round_no']]);
        foreach ($q->fetchAll() as $r) $map[(int) $r['player_id']] = json_decode($r['cards'], true);
        $out['submissions'] = [];
        foreach ($order as $pid) if (isset($map[$pid])) $out['submissions'][] = ['sid' => (int) $pid, 'cards' => $map[$pid]];
    }
    if (($room['phase'] === 'roundover' || $room['phase'] === 'gameover') && $room['winner_id']) {
        $wn = ''; foreach ($players as $p) if ((int) $p['id'] === (int) $room['winner_id']) $wn = $p['name'];
        $out['winner'] = ['id' => (int) $room['winner_id'], 'name' => $wn,
                          'cards' => json_decode($room['win_sub'], true) ?: []];
    }
    return $out;
}

function cah_name(string $n): ?string {
    $n = trim(preg_replace('/\s+/', ' ', $n));
    $n = preg_replace('/[\x00-\x1F\x7F]/', '', $n);
    if ($n === '' || mb_strlen($n) > 20) return null;
    return mb_substr($n, 0, 20);
}
