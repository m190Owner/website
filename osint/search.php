<?php
// Self-search: ready-made search-engine and "dork" links for the user's OWN saved
// identifiers, so they can quickly see where they surface. Pure links — nothing is
// sent to this server; the queries run in the user's browser on each engine.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);

const OS_ENGINES = [
    'Google'     => ['https://www.google.com/search?q=',  '🔍'],
    'Bing'       => ['https://www.bing.com/search?q=',     'Ⓑ'],
    'DuckDuckGo' => ['https://duckduckgo.com/?q=',         '🦆'],
    'Yandex'     => ['https://yandex.com/search/?text=',   'Я'],
    'Brave'      => ['https://search.brave.com/search?q=', '🦁'],
];

/** Engine links for a raw query string. */
function os_engine_links(string $q): array {
    $out = [];
    foreach (OS_ENGINES as $name => [$base, $ic]) $out[] = ['label' => $name, 'url' => $base . rawurlencode($q), 'ic' => $ic];
    return $out;
}
/** A Google-routed dork (site:/filetype:/intext: syntax). */
function os_dork(string $label, string $q): array {
    return ['label' => $label, 'url' => 'https://www.google.com/search?q=' . rawurlencode($q), 'ic' => '🎯'];
}
function os_link(string $label, string $url, string $ic = '↗'): array { return ['label' => $label, 'url' => $url, 'ic' => $ic]; }

/** Render a labelled block of link buttons. */
function os_srch_block(string $title, array $links): void {
    echo '<div class="os-subhead">' . ose($title) . '</div><div class="os-srch">';
    foreach ($links as $l) {
        echo '<a href="' . ose($l['url']) . '" target="_blank" rel="noopener nofollow"><span class="os-srch-ic">' . $l['ic'] . '</span>' . ose($l['label']) . '</a>';
    }
    echo '</div>';
}

/** Common handle variations — to hunt for alt accounts or impersonators of a username. */
function os_username_variants(string $u): array {
    $u = trim($u);
    $stripped = preg_replace('/[._\-]/', '', $u);
    $set = [$u, $stripped,
        str_replace(['.', '_', '-'], '_', $u),
        str_replace(['.', '_', '-'], '.', $u),
        'the' . $u, 'real' . $u, 'official' . $u, 'its' . $u];
    foreach (['1', '01', '123', '2024', '2025', '_', 'official', 'hq', 'tv', 'yt'] as $s) $set[] = $u . $s;
    return array_slice(array_values(array_unique(array_filter($set, fn($x) => $x !== ''))), 0, 16);
}

$total = count($p['usernames']) + count($p['emails']) + count($p['phones']) + count($p['domains']);
osint_head('Self-search · m190 finder', 'search');
?>
  <div class="os-panel">
    <h2>Search for yourself</h2>
    <p>The fastest OSINT check is the one anyone can run on you: a search engine. These are pre-built queries for <b>your own</b> saved identifiers — exact-match phrases and targeted &ldquo;dorks&rdquo; that surface pastes, leaked documents, and mentions. They open on each engine in a new tab; nothing is sent to this server.</p>
    <?php if ($total === 0): ?>
      <p class="os-dim" style="margin-top:12px">No identifiers yet. <a href="/osint/profile.php">Add some to your profile</a> to generate searches. The reverse-image tool below works without a profile.</p>
    <?php endif; ?>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Reverse-image &amp; face search</h3>
    <p class="os-dim os-mb">Paste the URL of a photo of yourself (e.g. your public profile picture) to find everywhere that image appears online.</p>
    <form id="os-rev" class="os-inrow">
      <input type="url" id="os-revurl" class="os-input" placeholder="https://…/your-photo.jpg" autocomplete="off">
      <button class="os-btn os-btn-accent" type="submit">Build links</button>
    </form>
    <div class="os-srch" id="os-revout" style="margin-top:10px"></div>
    <p class="os-fineprint">Face-search engines like <a href="https://pimeyes.com/en" target="_blank" rel="noopener nofollow">PimEyes</a> and <a href="https://images.google.com/" target="_blank" rel="noopener nofollow">Google Images</a> need the photo uploaded directly — use them to see where your face shows up, then request removal where it doesn't belong.</p>
  </div>

  <?php foreach ($p['usernames'] as $v): $q = '"' . $v . '"'; ?>
    <div class="os-panel">
      <h3 class="os-h3">Username · <span class="os-code"><?= ose($v) ?></span></h3>
      <?php
        os_srch_block('Exact match on each engine', os_engine_links($q));
        os_srch_block('Targeted', [
            os_dork('Pastebin dumps', $q . ' site:pastebin.com'),
            os_dork('Leak / dump mentions', $q . ' (leak OR dump OR database OR breach)'),
            os_dork('Documents', $q . ' (filetype:pdf OR filetype:xlsx OR filetype:csv)'),
            os_link('GitHub users', 'https://github.com/search?q=' . rawurlencode($q) . '&type=users', '🐙'),
            os_link('Reddit', 'https://www.reddit.com/search/?q=' . rawurlencode($q), '👽'),
            os_link('Archive.org', 'https://archive.org/search?query=' . rawurlencode($v), '📚'),
        ]);
        os_srch_block('Handle variations — find alt accounts / impersonators',
            array_map(fn($x) => ['label' => $x, 'url' => 'https://www.google.com/search?q=' . rawurlencode('"' . $x . '"'), 'ic' => '🔁'], os_username_variants($v)));
      ?>
    </div>
  <?php endforeach; ?>

  <?php foreach ($p['emails'] as $v): $q = '"' . $v . '"'; ?>
    <div class="os-panel">
      <h3 class="os-h3">Email · <span class="os-code"><?= ose($v) ?></span></h3>
      <?php
        os_srch_block('Exact match on each engine', os_engine_links($q));
        os_srch_block('Targeted', [
            os_dork('Pastebin / pastes', $q . ' (site:pastebin.com OR site:ghostbin.com OR site:justpaste.it)'),
            os_dork('Leaked documents', $q . ' (filetype:pdf OR filetype:xlsx OR filetype:csv OR filetype:txt)'),
            os_dork('Any mention', 'intext:' . $q),
            os_link('Have I Been Pwned', 'https://haveibeenpwned.com/', '🔓'),
            os_link('IntelligenceX', 'https://intelx.io/?s=' . rawurlencode($v), '🕵️'),
        ]);
      ?>
    </div>
  <?php endforeach; ?>

  <?php foreach ($p['phones'] as $v): $m = scan_phone_meta($v); ?>
    <div class="os-panel">
      <h3 class="os-h3">Phone · <span class="os-code"><?= ose($v) ?></span></h3>
      <?php
        $variants = [$v];
        if ($m) { $nat = $m['nat']; if (strlen($nat) >= 7) { $variants[] = substr($nat, 0, 3) . '-' . substr($nat, 3); $variants[] = '(' . substr($nat, 0, 3) . ') ' . substr($nat, 3); } }
        $eng = [];
        foreach (OS_ENGINES as $name => [$base, $ic]) $eng[] = ['label' => $name, 'url' => $base . rawurlencode('"' . $v . '"'), 'ic' => $ic];
        os_srch_block('Exact match on each engine', $eng);
        os_srch_block('Targeted', [
            os_dork('Formatted variants', implode(' OR ', array_map(fn($x) => '"' . $x . '"', $variants))),
            os_dork('People-search sites', '"' . $v . '"'),
            os_dork('Documents', '"' . $v . '" (filetype:pdf OR filetype:xlsx)'),
        ]);
      ?>
    </div>
  <?php endforeach; ?>

  <?php foreach ($p['domains'] as $v): ?>
    <div class="os-panel">
      <h3 class="os-h3">Domain · <span class="os-code"><?= ose($v) ?></span> <a class="os-btn os-btn-sm" href="/osint/domain.php" style="float:right">Full footprint &rarr;</a></h3>
      <?php
        os_srch_block('Indexed pages', [
            os_dork('All indexed pages', 'site:' . $v),
            os_dork('Subdomains', 'site:*.' . $v . ' -www.' . $v),
            os_dork('Documents', 'site:' . $v . ' (filetype:pdf OR filetype:xls OR filetype:doc OR filetype:csv)'),
            os_dork('Login / admin pages', 'site:' . $v . ' (inurl:login OR inurl:admin OR inurl:portal)'),
        ]);
        os_srch_block('Public records', [
            os_link('crt.sh certificates', 'https://crt.sh/?q=' . rawurlencode('%.' . $v), '📜'),
            os_link('Wayback Machine', 'https://web.archive.org/web/*/' . rawurlencode($v) . '/*', '🕰️'),
            os_link('Shodan', 'https://www.shodan.io/search?query=' . rawurlencode('hostname:' . $v), '🛰️'),
            os_link('DNSDumpster', 'https://dnsdumpster.com/', '🗺️'),
        ]);
      ?>
    </div>
  <?php endforeach; ?>

  <?php if ($total > 0): ?>
    <p class="os-fineprint">Tip: run the exact-match phrase on more than one engine — Bing and Yandex index pages Google drops, and vice-versa. Found something you want gone? The <a href="/osint/brokers.php">removal center</a> covers the data brokers, and <a href="/osint/harden.php">hardening</a> covers the accounts.</p>
  <?php endif; ?>
<?php
osint_foot(['search.js']);
