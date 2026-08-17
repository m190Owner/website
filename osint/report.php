<?php
// Printable exposure report: one clean, self-contained document summarising the latest
// scan, domain posture, removal + hardening progress, and prioritised next steps.
// "Print / Save as PDF" uses the browser's own print (print CSS hides the chrome).
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);

$scan = scan_latest((int) $u['id']);
$findings = $scan ? scan_findings((int) $u['id'], (int) $scan['id']) : [];
$has = fn($f, $n) => strpos((string) ($f['exposes'] ?? ''), $n) !== false;
$live = fn($f) => ($f['status'] ?? 'new') !== 'false';
$accounts = array_values(array_filter($findings, fn($f) => $f['category'] === 'account' && !$has($f, 'email') && $live($f)));
$identity = array_values(array_filter($findings, fn($f) => $f['category'] === 'account' && $has($f, 'email') && $live($f)));
$breaches = array_values(array_filter($findings, fn($f) => $f['category'] === 'breach' && $live($f)));
$phones   = array_values(array_filter($findings, fn($f) => $f['category'] === 'phone' && $live($f)));
$exposure = scan_exposure($findings);

// Checklist progress
$brokerData = json_decode((string) @file_get_contents(__DIR__ . '/assets/brokers.json'), true);
$brokerTotal = count($brokerData['brokers'] ?? []);
$brokerDone = count(array_filter(scan_checklist_get((int) $u['id'], 'brokers'), fn($s) => $s === 'done'));
$hardenData = json_decode((string) @file_get_contents(__DIR__ . '/assets/harden.json'), true);
$hardenTotal = 0; foreach (($hardenData['groups'] ?? []) as $g) $hardenTotal += count($g['items'] ?? []);
$hardenDone = count(array_filter(scan_checklist_get((int) $u['id'], 'harden'), fn($s) => $s === 'done'));

// Domain posture (from cache)
$domains = [];
foreach ($p['domains'] as $d) { $c = scan_domain_cache_get((int) $u['id'], $d); if ($c) $domains[] = $c; }

// Prioritised recommendations
$recs = [];
if ($exposure['pw']) $recs[] = 'A breach exposed passwords — change any reused password now and turn on two-factor auth (see Passwords + Hardening).';
if ($exposure['breaches']) $recs[] = 'You appear in ' . $exposure['breaches'] . ' breach record(s) — confirm which passwords were involved and rotate them.';
if (count($accounts) + count($identity) > 0) $recs[] = 'Review the ' . (count($accounts) + count($identity)) . ' account(s) found and close any you no longer use.';
if ($brokerTotal && $brokerDone < $brokerTotal) $recs[] = 'Finish the remaining ' . ($brokerTotal - $brokerDone) . ' data-broker opt-out(s) in the removal center.';
foreach ($domains as $d) {
    if (!$d['spf']) $recs[] = 'Domain ' . $d['domain'] . ' has no SPF record — add one to stop email spoofing.';
    if (($d['dmarc_policy'] ?? null) !== 'reject' && ($d['dmarc_policy'] ?? null) !== 'quarantine') $recs[] = 'Domain ' . $d['domain'] . ' has no enforced DMARC policy — add one.';
}
if ($hardenTotal && $hardenDone < $hardenTotal) $recs[] = 'Complete the remaining ' . ($hardenTotal - $hardenDone) . ' hardening step(s).';
if (!$recs) $recs[] = 'Nothing urgent flagged — re-scan periodically and keep working the hardening checklist.';

osint_head('Exposure report · m190 finder', '');
?>
  <div class="os-panel os-report">
    <div class="os-report-h">
      <img src="/osint/assets/m190-logo.png" alt="">
      <div>
        <h2 style="margin:0">Personal exposure report</h2>
        <p class="os-dim" style="margin:0">Prepared for <b><?= ose($u['username']) ?></b> · <?= ose(date('Y-m-d H:i')) ?></p>
      </div>
      <div class="os-noprint" style="margin-left:auto;display:flex;gap:8px">
        <a class="os-btn os-btn-sm" href="/osint/receipt.php">Timestamped receipt</a>
        <button class="os-btn os-btn-accent" onclick="window.print()">Print / Save as PDF</button>
      </div>
    </div>
    <p class="os-fineprint">A snapshot of what's publicly discoverable about your identifiers, and your progress reducing it. Scoped entirely to your own profile. For a dated, integrity-stamped record, generate a <a href="/osint/receipt.php">timestamped receipt</a>.</p>
  </div>

  <div class="os-panel">
    <div class="os-score">
      <?php $gc = $exposure['level'] === 'high' ? 'var(--os-danger)' : ($exposure['level'] === 'mid' ? 'var(--os-warn)' : 'var(--os-accent)'); ?>
      <div class="os-gauge" style="--v:<?= (int) $exposure['score'] ?>;--c:<?= $gc ?>"><div class="os-gauge-in"><b><?= (int) $exposure['score'] ?></b><span>exposure</span></div></div>
      <div class="os-score-txt">
        <h3 style="margin-bottom:4px">Exposure summary</h3>
        <div class="os-riskrow">
          <span class="os-pill"><b><?= count($accounts) ?></b> accounts</span>
          <span class="os-pill"><b><?= count($identity) ?></b> email identity</span>
          <span class="os-pill"><b><?= count($breaches) ?></b> breaches<?= $exposure['span'] ? ' (' . ose($exposure['span']) . ')' : '' ?></span>
          <?php if ($exposure['pw']): ?><span class="os-pill os-pill-bad">passwords exposed</span><?php endif; ?>
          <span class="os-pill"><b><?= $brokerDone ?>/<?= $brokerTotal ?></b> removals done</span>
          <span class="os-pill"><b><?= $hardenDone ?>/<?= $hardenTotal ?></b> hardening done</span>
        </div>
        <p class="os-dim" style="margin-top:8px"><?= $scan ? 'Last scan ' . ose(date('Y-m-d H:i', (int) $scan['started_at'])) : 'No scan run yet.' ?></p>
      </div>
    </div>
  </div>

  <div class="os-panel">
    <div class="os-rsec">
      <h3 class="os-h3">Identifiers monitored</h3>
      <ul class="os-rlist">
        <?php foreach ($p['usernames'] as $v): ?><li>Username · <span class="os-code"><?= ose($v) ?></span></li><?php endforeach; ?>
        <?php foreach ($p['emails'] as $v): ?><li>Email · <span class="os-code"><?= ose($v) ?></span></li><?php endforeach; ?>
        <?php foreach ($p['phones'] as $v): ?><li>Phone · <span class="os-code"><?= ose($v) ?></span></li><?php endforeach; ?>
        <?php foreach ($p['domains'] as $v): ?><li>Domain · <span class="os-code"><?= ose($v) ?></span></li><?php endforeach; ?>
        <?php if (!$p['usernames'] && !$p['emails'] && !$p['phones'] && !$p['domains']): ?><li class="os-dim">None yet.</li><?php endif; ?>
      </ul>
    </div>

    <?php
    $blocks = [
        ['Accounts &amp; profiles', $accounts, fn($f) => ose($f['title'])],
        ['Email identity', $identity, fn($f) => ose($f['title']) . ($f['detail'] ? ' <span class="os-dim">— ' . ose($f['detail']) . '</span>' : '')],
        ['Breach records', $breaches, fn($f) => ose(preg_replace('/^.* in the (.*) breach$/', '$1', $f['title'])) . ($f['detail'] ? ' <span class="os-dim">— ' . ose($f['detail']) . '</span>' : '')],
        ['Phone numbers', $phones, fn($f) => ose($f['title']) . ($f['detail'] ? ' <span class="os-dim">— ' . ose($f['detail']) . '</span>' : '')],
    ];
    foreach ($blocks as [$label, $items, $fmt]): if (!$items) continue; ?>
      <div class="os-rsec">
        <h3 class="os-h3"><?= $label ?> <span class="os-dim">(<?= count($items) ?>)</span></h3>
        <ul class="os-rlist"><?php foreach ($items as $f) echo '<li>' . $fmt($f) . '</li>'; ?></ul>
      </div>
    <?php endforeach; ?>

    <?php if ($exposure['dataclasses']): ?>
      <div class="os-rsec">
        <h3 class="os-h3">Data exposed across breaches</h3>
        <div class="os-taglist"><?php foreach (array_slice($exposure['dataclasses'], 0, 20) as $dc) echo '<span class="os-tag">' . ose($dc) . '</span>'; ?></div>
      </div>
    <?php endif; ?>

    <?php if ($domains): ?>
      <div class="os-rsec">
        <h3 class="os-h3">Domain footprint</h3>
        <?php foreach ($domains as $d): ?>
          <p style="font-size:.86rem;margin-top:8px"><b><?= ose($d['domain']) ?></b> —
            SPF <?= $d['spf'] ? 'present' : '<b>missing</b>' ?>,
            DMARC <?= ($d['dmarc_policy'] ?? null) ? 'p=' . ose($d['dmarc_policy']) : '<b>missing</b>' ?>,
            DNSSEC <?= $d['dnssec'] ? 'on' : 'off' ?>,
            <?= count($d['subdomains']) ?> subdomain(s) in CT logs.</p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="os-rsec">
      <h3 class="os-h3">Recommended next steps</h3>
      <ul class="os-rlist os-recs"><?php foreach ($recs as $r) echo '<li>' . ose($r) . '</li>'; ?></ul>
    </div>
  </div>

  <p class="os-fineprint os-noprint">Tip: in the print dialog choose &ldquo;Save as PDF&rdquo; to keep a dated copy. Re-run this monthly to track your progress.</p>
<?php
osint_foot();
