<?php
// Exposure receipt: a timestamped, integrity-stamped snapshot of the user's exposure
// state for their own records / disputes. Live-verifies domain mail security (the
// checkable part of the hardening plan) at generation time, and stamps the whole record
// with a SHA-256 fingerprint so the holder can prove it wasn't altered afterwards.
//   ?format=json  → download the machine-readable receipt (payload + hash).
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();

$r = scan_exposure_receipt((int) $u['id'], (string) ($u['username'] ?? ''));

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="m190-exposure-receipt-' . date('Ymd-His', $r['ts']) . '.json"');
    echo json_encode(['payload' => $r['payload'], 'integrity_sha256' => $r['hash']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$pl = $r['payload'];
$ex = $pl['exposure'];
osint_head('Exposure receipt · m190 finder', '');
?>
  <div class="os-panel os-report">
    <div class="os-report-h">
      <img src="/osint/assets/m190-logo.png" alt="">
      <div>
        <h2 style="margin:0">Exposure receipt</h2>
        <p class="os-dim" style="margin:0">Subject <b><?= ose($pl['subject']) ?></b> · generated <?= ose(str_replace(['T', 'Z'], [' ', ' UTC'], $pl['generated_at'])) ?></p>
      </div>
      <div class="os-noprint" style="margin-left:auto;display:flex;gap:8px">
        <a class="os-btn os-btn-sm" href="/osint/receipt.php?format=json">Download JSON</a>
        <button class="os-btn os-btn-accent" onclick="window.print()">Print / Save PDF</button>
      </div>
    </div>
    <p class="os-fineprint">A dated, tamper-evident record of what was publicly discoverable about your identifiers, and your progress reducing it. Self-generated — not a legal attestation, but a solid record for tracking and disputes.</p>
    <div class="os-receipt-hash">
      <div class="os-doss-k">Integrity fingerprint (SHA-256)</div>
      <code><?= ose($r['hash']) ?></code>
      <p class="os-fineprint" style="margin-top:6px">Keep this fingerprint. Re-hashing the downloaded JSON later must produce exactly this value — that's how you prove the record hasn't been changed since <?= ose(str_replace(['T', 'Z'], [' ', ' UTC'], $pl['generated_at'])) ?>.</p>
    </div>
  </div>

  <div class="os-panel">
    <div class="os-score">
      <?php $gc = $ex['level'] === 'high' ? 'var(--os-danger)' : ($ex['level'] === 'mid' ? 'var(--os-warn)' : 'var(--os-accent)'); ?>
      <div class="os-gauge" style="--v:<?= (int) $ex['score'] ?>;--c:<?= $gc ?>"><div class="os-gauge-in"><b><?= (int) $ex['score'] ?></b><span>exposure</span></div></div>
      <div class="os-score-txt">
        <h3 style="margin-bottom:4px">As of <?= ose(str_replace(['T', 'Z'], [' ', ' UTC'], $pl['generated_at'])) ?></h3>
        <div class="os-riskrow">
          <span class="os-pill"><b><?= (int) $ex['accounts'] ?></b> accounts</span>
          <span class="os-pill"><b><?= (int) $ex['email_identity'] ?></b> email identity</span>
          <span class="os-pill"><b><?= (int) $ex['breaches'] ?></b> breaches<?= $ex['breach_span'] ? ' (' . ose($ex['breach_span']) . ')' : '' ?></span>
          <?php if ($ex['passwords_exposed']): ?><span class="os-pill os-pill-bad">passwords exposed</span><?php endif; ?>
          <span class="os-pill"><b><?= (int) $pl['progress']['brokers_verified_removed'] ?></b>/<?= (int) $pl['progress']['brokers_total'] ?> removals verified</span>
          <span class="os-pill"><b><?= (int) $pl['progress']['hardening_done'] ?></b>/<?= (int) $pl['progress']['hardening_total'] ?> hardening done</span>
        </div>
        <p class="os-dim" style="margin-top:8px"><?= $pl['last_scan_at'] ? 'Based on the scan of ' . ose(str_replace(['T', 'Z'], [' ', ' UTC'], $pl['last_scan_at'])) : 'No scan on record.' ?></p>
      </div>
    </div>
  </div>

  <?php if ($pl['domain_mail_security']): ?>
    <div class="os-panel">
      <h3 class="os-h3">Domain mail security <span class="os-dim">(verified live at generation)</span></h3>
      <p class="os-dim os-mb">Checked fresh against public DNS as this receipt was created — the auditable, machine-checkable part of your hardening.</p>
      <div class="os-list">
        <?php foreach ($pl['domain_mail_security'] as $d): if (empty($d['ok'])) continue;
          $ok = $d['spf'] && $d['enforced']; ?>
          <div class="os-row"><div class="os-row-main">
            <div class="os-row-t"><span class="os-pdot os-pdot-<?= $ok ? 'ok' : 'warn' ?>"></span> <?= ose($d['domain']) ?></div>
            <div class="os-row-d">SPF <?= $d['spf'] ? '&#10003; present' : '&times; missing' ?> ·
              DMARC <?= $d['dmarc'] ? ($d['enforced'] ? '&#10003; enforced (p=' . ose($d['dmarc']) . ')' : 'p=' . ose($d['dmarc']) . ' (not enforced)') : '&times; missing' ?> ·
              DNSSEC <?= $d['dnssec'] ? '&#10003; on' : 'off' ?></div>
          </div></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="os-panel">
    <h3 class="os-h3">Findings on record <span class="os-dim">(<?= count($pl['findings']) ?>)</span></h3>
    <?php if (!$pl['findings']): ?>
      <p class="os-dim">No active findings recorded in the latest scan.</p>
    <?php else: ?>
      <ul class="os-rlist"><?php foreach (array_slice($pl['findings'], 0, 80) as $f): ?><li><span class="os-tag"><?= ose($f['category']) ?></span> <?= ose($f['title']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($ex['data_classes']): ?>
      <div class="os-subhead">Data classes exposed</div>
      <div class="os-taglist"><?php foreach ($ex['data_classes'] as $dc) echo '<span class="os-tag">' . ose($dc) . '</span>'; ?></div>
    <?php endif; ?>
  </div>

  <p class="os-fineprint os-noprint">This receipt live-verifies your domains' mail security and stamps the whole snapshot. Generate one after each round of removals/hardening to keep a dated, verifiable trail of your progress.</p>
<?php
osint_foot();
