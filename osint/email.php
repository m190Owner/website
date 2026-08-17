<?php
// Email intel tab (Mosint-style): a consolidated public-intelligence report for each of
// the user's own profile emails — deliverability, disposable/free classification, domain
// spoofability, Gravatar, breach corpora, and registered-account signals. Scoped to the
// signed-in user's own addresses; the heavy lookup runs on demand (email.js -> emailintel.php).
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);

osint_head('Email intel · m190 finder', 'email');
?>
  <div class="os-panel">
    <h2>Email intelligence</h2>
    <p>Everything one of your addresses reveals in public, gathered into a single report: whether it can receive mail, whether its domain can be spoofed, any Gravatar profile, and every breach corpus and account signal tied to it. All keyless — no email is ever sent to the address.</p>
    <?php if (!$p['emails']): ?>
      <p class="os-dim" style="margin-top:12px">No emails yet. <a href="/osint/profile.php">Add an email you own</a> to build its intelligence report.</p>
    <?php endif; ?>
  </div>

  <?php foreach ($p['emails'] as $i => $email):
    $cached = scan_domain_cache_get((int) $u['id'], 'email:' . $email); ?>
    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">✉ <?= ose($email) ?></h3>
        <button type="button" class="os-btn os-btn-sm" data-email="<?= ose($email) ?>" data-eidx="<?= $i ?>"><?= $cached ? 'Re-analyze' : 'Analyze' ?></button>
      </div>
      <div id="os-estatus-<?= $i ?>" class="os-dim" style="font-size:.8rem;margin-top:4px">
        <?= $cached ? 'Last analyzed ' . ose(date('Y-m-d H:i', (int) $cached['ts'])) : 'Not analyzed yet — hit Analyze.' ?>
      </div>
      <div id="os-email-<?= $i ?>" class="os-emailout"<?= $cached ? '' : ' hidden' ?>><?php
        if ($cached) echo '<script type="application/json" class="os-email-data">' . json_encode($cached, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . '</script>';
      ?></div>
    </div>
  <?php endforeach; ?>
<?php
osint_foot(['email.js']);
