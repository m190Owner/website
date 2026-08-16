<?php
// The m190 finder hub. Run a footprint scan, watch it progress, and jump to every
// tool in the suite. The scan itself is driven from osint/assets/osint.js against
// osint/scan.php in small batches.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);
$nId = count($p['usernames']) + count($p['emails']) + count($p['phones']) + count($p['domains']);
$latest = scan_latest((int) $u['id']);
$siteCount = count(scan_sites());

// Tool cards for the hub grid: [icon, title, href, description].
$tools = [
    ['🗂️', 'Removal center', '/osint/brokers.php', 'Opt out of the data brokers and people-search sites that list your name, address, and phone.'],
    ['🔎', 'Self-search', '/osint/search.php', 'One-click search-engine and dork links to find where your identifiers surface.'],
    ['🌐', 'Domain footprint', '/osint/domain.php', 'DNS, email security (SPF/DMARC/DNSSEC), and subdomains exposed by your domains.'],
    ['🔑', 'Password exposure', '/osint/password.php', 'Check a password against breach corpora — hashed in your browser, never sent or stored.'],
    ['📡', 'Network footprint', '/osint/network.php', "What your current connection reveals — IP, location, ISP, and threat-feed status."],
    ['🛡️', 'Hardening checklist', '/osint/harden.php', 'A tracked, step-by-step plan to lock down your accounts and shrink your footprint.'],
    ['📄', 'Exposure report', '/osint/report.php', 'A clean, printable summary of everything found and what to do about it.'],
    ['👤', 'Your profile', '/osint/profile.php', 'The identifiers a scan searches for — your usernames, emails, phones, and domains.'],
];
osint_head('m190 finder', 'dashboard');
?>
  <div class="os-panel">
    <h2>See what the internet knows about you</h2>
    <p>A private, invite-only footprint tool. It checks <b>your own</b> identifiers against <?= (int) $siteCount ?> public sites, breach databases, and public records — then tells you what it found, what it couldn't check, and how to get it removed. A hit is a lead to verify, not proof.</p>
  </div>

  <div class="os-grid2">
    <div class="os-panel">
      <h3 class="os-h3">Your profile</h3>
      <?php if ($nId === 0): ?>
        <p>You haven't added anything to scan yet.</p>
        <a class="os-btn os-btn-accent" href="/osint/profile.php" style="margin-top:12px;display:inline-block">Add your identifiers</a>
      <?php else: ?>
        <p><b><?= count($p['usernames']) ?></b> username(s), <b><?= count($p['emails']) ?></b> email(s)<?php if ($p['phones']): ?>, <b><?= count($p['phones']) ?></b> phone(s)<?php endif; ?><?php if ($p['domains']): ?>, <b><?= count($p['domains']) ?></b> domain(s)<?php endif; ?> on file.</p>
        <a class="os-btn os-btn-sm" href="/osint/profile.php" style="margin-top:12px;display:inline-block">Edit profile</a>
      <?php endif; ?>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Run a scan</h3>
      <?php if (!$p['usernames'] && !$p['emails'] && !$p['phones']): ?>
        <p class="os-dim">Add at least one username, email, or phone first.</p>
      <?php else: ?>
        <p class="os-dim">Around <?= (int) (count($p['usernames']) * $siteCount + count($p['emails']) * 3) ?> checks. Takes a minute or two — keep this tab open.</p>
        <?php if ($p['emails']):
          $mods = osint_deep_modules();
          $deepNames  = implode(', ', array_map(fn($m) => $m['name'], array_filter($mods, fn($m) => empty($m['emails']))));
          $probeNames = implode(', ', array_map(fn($m) => $m['name'], array_filter($mods, fn($m) => !empty($m['emails']))));
        ?>
          <?php if ($deepNames): ?>
          <label class="os-deeprow"><input type="checkbox" id="os-deep"> <span>Deep email checks <span class="os-dim">— also ask <?= ose($deepNames) ?> whether your email has an account there. Slower; a site may notice the lookup.</span></span></label>
          <?php endif; ?>
          <?php if ($probeNames): ?>
          <label class="os-deeprow os-deeprow-warn"><input type="checkbox" id="os-probe"> <span>Aggressive checks <span class="os-dim">— probe <?= ose($probeNames) ?> via password reset. <b>This sends a real password-reset email to your address</b> if an account exists. Usually blocked from servers (shows &ldquo;couldn&rsquo;t check&rdquo;).</span></span></label>
          <?php endif; ?>
        <?php endif; ?>
        <button id="os-run" class="os-btn os-btn-accent" style="margin-top:12px">Start scan</button>
      <?php endif; ?>
      <div id="os-progress" class="os-progress" hidden>
        <div class="os-progbar"><div class="os-progbar-fill" id="os-progfill"></div></div>
        <div class="os-progmeta"><span id="os-progtext">Starting…</span><span id="os-progcount"></span></div>
      </div>
    </div>
  </div>

  <div class="os-panel" id="os-live" hidden>
    <h3 class="os-h3">Found so far <span class="os-livecount" id="os-livecount">0</span></h3>
    <ul class="os-findlist" id="os-findlist"></ul>
    <a class="os-btn os-btn-accent" id="os-viewresults" href="/osint/results.php" hidden style="margin-top:12px;display:inline-block">View full results</a>
  </div>

  <?php if ($latest): ?>
    <div class="os-panel">
      <h3 class="os-h3">Last scan</h3>
      <p><b><?= (int) $latest['found'] ?></b> found · <b><?= (int) $latest['unreachable'] ?></b> couldn't be checked ·
        <?= (int) $latest['total'] ?> checks · <?= ose(date('Y-m-d H:i', (int) $latest['started_at'])) ?>
        <?= $latest['status'] === 'running' ? ' <span class="os-dim">(incomplete)</span>' : '' ?></p>
      <a class="os-btn os-btn-sm" href="/osint/results.php" style="margin-top:12px;display:inline-block">View results</a>
    </div>
  <?php endif; ?>

  <div class="os-panel">
    <h3 class="os-h3">The full toolkit</h3>
    <p class="os-dim os-mb">Everything here is scoped to your own identifiers, and most of it works with no scan at all.</p>
    <div class="os-toolgrid">
      <?php foreach ($tools as [$ic, $title, $href, $desc]): ?>
        <a class="os-tool" href="<?= ose($href) ?>">
          <div class="os-tool-h"><span class="os-tool-ic"><?= $ic ?></span><?= ose($title) ?></div>
          <p><?= ose($desc) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php
osint_foot(['osint.js']);
