<?php
// Attacker view: your own footprint, flipped to the adversary's side of the table.
// The dossier they could compile, the attack vectors it enables, a spear-phish built
// from your real exposed data (a clearly-labelled SIMULATION — nothing is sent), and
// which account-recovery / security questions your public data already answers.
// All derived from the signed-in user's own latest scan; no new lookups, nothing stored.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$d = scan_attacker_dossier((int) $u['id']);
$quiz = $d['has_scan'] ? scan_phish_quiz((int) $u['id']) : null;

/** A labelled dossier group; $items already HTML-escaped tags. */
function os_doss(string $label, string $body): string {
    if (trim($body) === '') return '';
    return '<div class="os-doss"><div class="os-doss-k">' . ose($label) . '</div><div class="os-doss-v">' . $body . '</div></div>';
}
function os_tags(array $vals): string {
    $vals = array_values(array_filter($vals, fn($v) => trim((string) $v) !== ''));
    if (!$vals) return '';
    return implode('', array_map(fn($v) => '<span class="os-code">' . ose((string) $v) . '</span>', $vals));
}

osint_head('Attacker view · m190 finder', 'attacker');
?>
  <div class="os-panel">
    <h2>Attacker view</h2>
    <p>This is you seen from the other side of the table — everything the scan found, reassembled the way an adversary would use it. Nothing here is new data; it's your own exposure, weaponised, so you can see what it enables and shut it down.</p>
    <p class="os-dim" style="margin-top:8px">Lens: <b><?= $d['threat_meta']['icon'] ?> <?= ose($d['threat_meta']['label']) ?></b> — change it on the <a href="/osint/">dashboard</a>.</p>
  </div>

  <?php if (!$d['has_scan']): ?>
    <div class="os-panel"><p class="os-dim">Run a footprint scan from the <a href="/osint/">dashboard</a> first — this view is built entirely from your findings.</p></div>
  <?php else: ?>

    <div class="os-panel">
      <h3 class="os-h3">🗂️ The dossier</h3>
      <p class="os-dim os-mb">What someone could compile about you in minutes from public sources alone.</p>
      <div class="os-dosslist">
        <?php
        echo os_doss('Name(s)', $d['identity']['names'] ? os_tags($d['identity']['names']) : '<span class="os-dim">none directly exposed — but "Names" in your breaches means it\'s obtainable</span>');
        echo os_doss('Location', os_tags($d['identity']['locations']) ?: '<span class="os-dim">no precise city surfaced</span>');
        echo os_doss('Bio / role hints', $d['identity']['bios'] ? implode('', array_map(fn($b) => '<div class="os-dim">“' . ose($b) . '”</div>', $d['identity']['bios'])) : '');
        echo os_doss('Emails', os_tags($d['contact']['emails']));
        echo os_doss('Phones', os_tags($d['contact']['phones']));
        echo os_doss('Domains', os_tags($d['contact']['domains']));
        echo os_doss('Handles', os_tags($d['usernames']));
        echo os_doss('Accounts', $d['handles'] ? implode('', array_map(fn($a) => '<a class="os-code" href="' . ose($a['url']) . '" target="_blank" rel="noopener nofollow">' . ose($a['platform']) . '</a>', $d['handles'])) : '');
        echo os_doss('Leaked passwords', $d['credentials']['pw_breaches'] ? '<span class="os-tag os-tag-hi">yes — ' . count($d['credentials']['pw_breaches']) . ' breach(es)</span> ' . os_tags($d['credentials']['pw_breaches']) : '<span class="os-dim">none flagged</span>');
        echo os_doss('Data exposed', os_tags($d['credentials']['classes']));
        ?>
      </div>
    </div>

    <?php if ($d['vectors']): ?>
      <div class="os-panel">
        <h3 class="os-h3">🎯 How they'd come at you</h3>
        <p class="os-dim os-mb">The attack paths your specific exposure opens, most viable first.</p>
        <div class="os-corr-list">
          <?php foreach ($d['vectors'] as $v): ?>
            <div class="os-corr os-corr-<?= $v['sev'] === 'high' ? 'high' : ($v['sev'] === 'med' ? 'med' : 'low') ?>">
              <div class="os-corr-h"><span class="os-corr-sev"><?= $v['sev'] === 'high' ? 'High' : ($v['sev'] === 'med' ? 'Medium' : 'Low') ?></span><b><?= ose($v['name']) ?></b></div>
              <p class="os-corr-d"><?= ose($v['why']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="os-panel">
      <h3 class="os-h3">✉️ Spear-phish simulation</h3>
      <div class="os-warn-box" style="margin-top:0">This is a <b>SIMULATION</b> generated from your own exposed data to show how convincing a targeted phish could be. <b>Nothing was sent.</b> No real service, domain, or link here is genuine.</div>
      <div class="os-phish">
        <div class="os-phish-hdr">
          <div><span class="os-phish-k">From</span> <?= ose($d['phish']['from']) ?></div>
          <div><span class="os-phish-k">To</span> <?= ose($d['phish']['to']) ?></div>
          <div><span class="os-phish-k">Subject</span> <b><?= ose($d['phish']['subject']) ?></b></div>
        </div>
        <pre class="os-phish-body"><?= ose($d['phish']['body']) ?></pre>
      </div>
      <?php if ($d['phish']['why']): ?>
        <div class="os-subhead">Why this would land on you</div>
        <ul class="os-rlist">
          <?php foreach ($d['phish']['why'] as $w): ?><li><?= ose($w) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php if ($quiz): ?>
      <div class="os-panel">
        <h3 class="os-h3">🎣 Spot the phish <span class="os-dim">— train your eye</span></h3>
        <p class="os-dim os-mb">Some of these messages are phishing (a few built from your own exposed data), some are legit. Judge each, then see the tells. This is how a targeted attack will actually reach you — practice catching it.</p>
        <div id="os-quiz"></div>
        <script type="application/json" id="os-quiz-data"><?= json_encode($quiz, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
      </div>
    <?php endif; ?>

    <div class="os-panel">
      <h3 class="os-h3">🔐 Security-question exposure</h3>
      <p class="os-dim os-mb">Account-recovery / "prove it's you" questions, and whether your public data already answers them. This is how accounts get taken over without ever cracking a password.</p>
      <div class="os-list">
        <?php foreach ($d['kba'] as $k):
          $cls = $k['ans'] === 'yes' ? 'bad' : ($k['ans'] === 'maybe' ? 'warn' : 'ok');
          $lbl = $k['ans'] === 'yes' ? 'Answerable' : ($k['ans'] === 'maybe' ? 'Partly' : 'Not seen'); ?>
          <div class="os-row"><div class="os-row-main">
            <div class="os-row-t"><span class="os-pdot os-pdot-<?= $cls ?>"></span> <?= ose($k['q']) ?> <span class="os-tag<?= $k['ans'] === 'yes' ? ' os-tag-hi' : '' ?>"><?= $lbl ?></span></div>
            <div class="os-row-d"><?= ose($k['src']) ?></div>
          </div></div>
        <?php endforeach; ?>
      </div>
      <p class="os-fineprint">Defence: treat security-question answers like passwords — use random, unrelated answers stored in your password manager, never the real ones.</p>
    </div>

  <?php endif; ?>
<?php
osint_foot(['quiz.js']);
