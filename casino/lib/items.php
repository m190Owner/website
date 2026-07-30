<?php
// Item catalog, cases, inventory and marketplace for the casino. Items (guns,
// knives, gloves) are won from cases, live in a player's inventory, and can be
// quick-sold to the house or listed on the marketplace for other players to buy
// with LS coins. All economy moves go through casino.php's atomic helpers.

require_once __DIR__ . '/casino.php';

// rarity key => [label, colour]
const RARITIES = [
    'consumer'   => ['Consumer',   '#b0c3d9'],
    'industrial' => ['Industrial', '#5e98d9'],
    'milspec'    => ['Mil-Spec',   '#4b69ff'],
    'restricted' => ['Restricted', '#8847ff'],
    'classified' => ['Classified', '#d32ce6'],
    'covert'     => ['Covert',     '#eb4b4b'],
    'exceedingly'=> ['★ Exceedingly Rare', '#ffd700'],  // knives & gloves
];

// key => [name, type, rarity, base value in LS]
const ITEMS = [
    // consumer / industrial (cheap)
    'p250_sand'    => ['P250 | Sandstorm',        'Pistol', 'consumer',   60],
    'mp9_grey'     => ['MP9 | Ashfall',           'SMG',    'consumer',   80],
    'nova_forest'  => ['Nova | Forest Leaves',    'Shotgun','industrial', 140],
    'ppbizon_iron' => ['PP-Bizon | Iron Rust',    'SMG',    'industrial', 180],
    // mil-spec (blue)
    'mp7_circuit'  => ['MP7 | Circuitry',         'SMG',    'milspec',    240],
    'famas_neon'   => ['FAMAS | Neon Grid',       'Rifle',  'milspec',    300],
    'glock_bluefis'=> ['Glock-18 | Blue Fission', 'Pistol', 'milspec',    340],
    'ump_arctic'   => ['UMP-45 | Arctic Wolf',    'SMG',    'milspec',    380],
    // restricted (purple)
    'ak_redline'   => ['AK-47 | Redline',         'Rifle',  'restricted', 650],
    'm4_asiimov'   => ['M4A4 | Asiimov',          'Rifle',  'restricted', 800],
    'awp_atheris'  => ['AWP | Atheris',           'Sniper', 'restricted', 720],
    'usp_kill'     => ['USP-S | Kill Confirmed',  'Pistol', 'restricted', 600],
    // classified (pink)
    'ak_vulcan'    => ['AK-47 | Vulcan',          'Rifle',  'classified', 1800],
    'awp_hyper'    => ['AWP | Hyper Beast',       'Sniper', 'classified', 2200],
    'deagle_blaze' => ['Desert Eagle | Blaze',    'Pistol', 'classified', 1500],
    // covert (red)
    'ak_firesnake' => ['AK-47 | Fire Serpent',    'Rifle',  'covert',     6000],
    'awp_dragon'   => ['AWP | Dragon Lore',       'Sniper', 'covert',     12000],
    'm4_howl'      => ['M4A4 | Howl',             'Rifle',  'covert',     9000],
    // knives (gold)
    'knife_karam_fade' => ['★ Karambit | Fade',        'Knife', 'exceedingly', 42000],
    'knife_bfly_slaugh'=> ['★ Butterfly Knife | Slaughter', 'Knife', 'exceedingly', 30000],
    'knife_m9_doppler' => ['★ M9 Bayonet | Doppler',   'Knife', 'exceedingly', 36000],
    'knife_flip_marble'=> ['★ Flip Knife | Marble Fade','Knife', 'exceedingly', 24000],
    // gloves (gold)
    'glove_sport_hedge'=> ['★ Sport Gloves | Hedge Maze', 'Gloves', 'exceedingly', 28000],
    'glove_specialist' => ['★ Specialist Gloves | Crimson', 'Gloves', 'exceedingly', 33000],
];

// caseId => [name, price, rarity odds (must ~sum to 1), items grouped by rarity]
const CASES = [
    'starter' => [
        'name' => 'Starter Case', 'price' => 650,
        'odds' => ['milspec' => 0.7992, 'restricted' => 0.1598, 'classified' => 0.032, 'covert' => 0.0064, 'exceedingly' => 0.0026],
        'pool' => [
            'milspec'    => ['mp7_circuit', 'famas_neon', 'glock_bluefis', 'ump_arctic'],
            'restricted' => ['ak_redline', 'usp_kill', 'awp_atheris'],
            'classified' => ['deagle_blaze', 'ak_vulcan'],
            'covert'     => ['ak_firesnake'],
            'exceedingly'=> ['knife_flip_marble', 'knife_bfly_slaugh', 'glove_sport_hedge'],
        ],
    ],
    'premium' => [
        'name' => 'Premium Case', 'price' => 1800,
        'odds' => ['restricted' => 0.7992, 'classified' => 0.1598, 'covert' => 0.032, 'exceedingly' => 0.009],
        'pool' => [
            'restricted' => ['m4_asiimov', 'awp_atheris', 'ak_redline'],
            'classified' => ['ak_vulcan', 'awp_hyper', 'deagle_blaze'],
            'covert'     => ['ak_firesnake', 'awp_dragon', 'm4_howl'],
            'exceedingly'=> ['knife_karam_fade', 'knife_m9_doppler', 'knife_bfly_slaugh', 'glove_specialist', 'glove_sport_hedge'],
        ],
    ],
];

function items_table(): void {
    videos_db()->exec("CREATE TABLE IF NOT EXISTS casino_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        item TEXT NOT NULL,
        listed INTEGER NOT NULL DEFAULT 0,
        price INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS idx_items_owner ON casino_items(owner_id);
        CREATE INDEX IF NOT EXISTS idx_items_listed ON casino_items(listed);");
}

function item_def(string $key): ?array {
    if (!isset(ITEMS[$key])) return null;
    [$name, $type, $rarity, $value] = ITEMS[$key];
    [$rlabel, $rcolor] = RARITIES[$rarity];
    return ['key' => $key, 'name' => $name, 'type' => $type, 'rarity' => $rarity,
            'rarityLabel' => $rlabel, 'color' => $rcolor, 'value' => $value];
}

/** Roll a case: returns the item key won (weighted by rarity odds, uniform within). */
function case_roll(string $caseId): string {
    $c = CASES[$caseId];
    $r = mt_rand() / mt_getrandmax();
    $acc = 0; $chosen = null;
    foreach ($c['odds'] as $rarity => $p) { $acc += $p; if ($r <= $acc) { $chosen = $rarity; break; } }
    if ($chosen === null) $chosen = array_key_last($c['odds']);
    $pool = $c['pool'][$chosen];
    return $pool[random_int(0, count($pool) - 1)];
}

/** Open a case: charge price, roll an item, add to inventory. Returns [itemDef, error]. */
function case_open(int $uid, string $caseId): array {
    if (!isset(CASES[$caseId])) return [null, 'Unknown case.'];
    if (!casino_bet($uid, CASES[$caseId]['price'])) return [null, 'Not enough coins.'];
    $key = case_roll($caseId);
    $db = videos_db();
    $db->prepare("INSERT INTO casino_items (owner_id, item, created_at) VALUES (?, ?, ?)")
       ->execute([$uid, $key, time()]);
    $def = item_def($key);
    $def['invId'] = (int) $db->lastInsertId();
    return [$def, null];
}

function inventory_list(int $uid): array {
    $st = videos_db()->prepare("SELECT id, item, listed, price FROM casino_items WHERE owner_id = ? ORDER BY id DESC");
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $d = item_def($row['item']); if (!$d) continue;
        $out[] = $d + ['id' => (int) $row['id'], 'listed' => (int) $row['listed'], 'listPrice' => (int) $row['price']];
    }
    return $out;
}

/** Quick-sell an owned, unlisted item to the house for its base value. */
function item_quicksell(int $uid, int $itemId): array {
    $st = videos_db()->prepare("SELECT item, listed FROM casino_items WHERE id = ? AND owner_id = ?");
    $st->execute([$itemId, $uid]);
    $row = $st->fetch();
    if (!$row) return [0, 'Item not found.'];
    if ((int) $row['listed'] === 1) return [0, 'Delist it from the market first.'];
    $d = item_def($row['item']);
    videos_db()->prepare("DELETE FROM casino_items WHERE id = ? AND owner_id = ?")->execute([$itemId, $uid]);
    casino_credit($uid, $d['value']);
    return [$d['value'], null];
}

function market_list_item(int $uid, int $itemId, int $price): ?string {
    if ($price < 1 || $price > 100000000) return 'Enter a valid price.';
    $st = videos_db()->prepare("UPDATE casino_items SET listed = 1, price = ? WHERE id = ? AND owner_id = ? AND listed = 0");
    $st->execute([$price, $itemId, $uid]);
    return $st->rowCount() === 1 ? null : 'Could not list that item.';
}
function market_delist(int $uid, int $itemId): void {
    videos_db()->prepare("UPDATE casino_items SET listed = 0, price = 0 WHERE id = ? AND owner_id = ?")->execute([$itemId, $uid]);
}

/** All active listings (item + seller). */
function market_all(int $limit = 200): array {
    $rows = videos_db()->query(
        "SELECT ci.id, ci.item, ci.price, ci.owner_id, u.username AS seller
           FROM casino_items ci JOIN users u ON u.id = ci.owner_id
          WHERE ci.listed = 1 ORDER BY ci.price ASC LIMIT $limit")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $d = item_def($r['item']); if (!$d) continue;
        $out[] = $d + ['id' => (int) $r['id'], 'price' => (int) $r['price'], 'sellerId' => (int) $r['owner_id'], 'seller' => $r['seller']];
    }
    return $out;
}

const MARKET_FEE = 0.05; // 5% burned on a sale (coin sink)

/** Buy a listed item. Atomic: buyer pays, seller is paid (minus fee), ownership moves. */
function market_buy(int $uid, int $itemId): array {
    $db = videos_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $st = $db->prepare("SELECT owner_id, item, listed, price FROM casino_items WHERE id = ?");
        $st->execute([$itemId]);
        $row = $st->fetch();
        if (!$row || (int) $row['listed'] !== 1) { $db->exec('ROLLBACK'); return [null, 'That item is no longer for sale.']; }
        $seller = (int) $row['owner_id']; $price = (int) $row['price'];
        if ($seller === $uid) { $db->exec('ROLLBACK'); return [null, 'That is your own listing.']; }
        if (!casino_bet($uid, $price)) { $db->exec('ROLLBACK'); return [null, 'Not enough coins.']; }
        $payout = (int) floor($price * (1 - MARKET_FEE));
        casino_credit($seller, $payout);
        $db->prepare("UPDATE casino_items SET owner_id = ?, listed = 0, price = 0 WHERE id = ?")->execute([$uid, $itemId]);
        $db->exec('COMMIT');
        return [item_def($row['item']), null];
    } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
