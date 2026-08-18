<?php
// The m190 finder hub. Run a footprint scan, watch it progress, and jump to every
// tool in the suite. The scan itself is driven from osint/assets/osint.js against
// osint/scan.php in small batches.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'set_threat') {
    osint_csrf_require();
    enforceRateLimit('osint_threat', 30, 60);
    scan_threat_set((int) $u['id'], (string) ($_POST['threat'] ?? 'general'));
    header('Location: /osint/'); exit;
}

$p = scan_profile_get((int) $u['id']);
$nId = count($p['usernames']) + count($p['emails']) + count($p['phones']) + count($p['domains']);
$latest = scan_latest((int) $u['id']);
$threat = scan_threat_get((int) $u['id']);
$threatModels = scan_threat_models();
$threatBrief = scan_threat_brief($threat, $latest ? scan_findings((int) $u['id'], (int) $latest['id']) : []);
$siteCount = count(scan_sites());
$mon = scan_monitor_get((int) $u['id']);
$ct = scan_ct_get((int) $u['id']);
$handle = scan_handle_get((int) $u['id']);
$monDue = ($mon['enabled'] || $ct['enabled'] || $handle['enabled']) && (time() - (int) $mon['last_check'] >= OSINT_MONITOR_INTERVAL);
$expiryWarn = scan_expiry_warnings((int) $u['id']);

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
  <?php if ($monDue): ?><div id="os-mon-auto" hidden></div><?php endif; ?>
  <?php if ($expiryWarn): ?>
    <div class="os-panel os-alertbox">
      <h3 class="os-h3">&#9888; Expiring soon</h3>
      <ul class="os-rlist">
        <?php foreach ($expiryWarn as $w): ?>
          <li><b><?= ose($w['domain']) ?></b> <?= ose($w['kind']) ?> <?= $w['days'] < 0 ? 'has <b>expired</b>' : 'expires in <b>' . (int) $w['days'] . ' day' . ($w['days'] === 1 ? '' : 's') . '</b>' ?> — <a href="/osint/domain.php">details</a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php if ($mon['pending']): ?>
    <div class="os-panel os-alertbox" id="os-mon-alert">
      <h3 class="os-h3">&#9888; New exposure since your last check</h3>
      <p class="os-dim os-mb"><b><?= count($mon['pending']) ?></b> new breach record(s) appeared for your monitored emails:</p>
      <ul class="os-rlist">
        <?php foreach (array_reverse($mon['pending']) as $pi): ?>
          <li><b><?= ose($pi['email']) ?></b> in the <b><?= ose($pi['breach']) ?></b> breach <span class="os-dim"><?= ose(date('Y-m-d', (int) $pi['at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="os-inrow" style="margin-top:12px">
        <a class="os-btn os-btn-sm" href="/osint/harden.php">What to do</a>
        <button type="button" class="os-btn os-btn-sm" id="os-mon-dismiss">Got it, dismiss</button>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($ct['pending']): ?>
    <div class="os-panel os-alertbox" id="os-ct-alert">
      <h3 class="os-h3">&#9888; New certificate issued for your domain</h3>
      <p class="os-dim os-mb"><b><?= count($ct['pending']) ?></b> new certificate(s) appeared in the public logs for your monitored domains — verify you (or your host) issued them; if not, it may be phishing infrastructure or a takeover:</p>
      <ul class="os-rlist">
        <?php foreach (array_reverse($ct['pending']) as $ci): ?>
          <li><b><?= ose($ci['name']) ?></b> <span class="os-dim">via <?= ose($ci['issuer']) ?><?= $ci['nb'] ? ' · ' . ose($ci['nb']) : '' ?></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="os-inrow" style="margin-top:12px">
        <a class="os-btn os-btn-sm" href="/osint/domain.php">Review domains</a>
        <button type="button" class="os-btn os-btn-sm" id="os-ct-dismiss">Got it, dismiss</button>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($handle['pending']): ?>
    <div class="os-panel os-alertbox" id="os-hn-alert">
      <h3 class="os-h3">&#9888; New look-alike account of your handle</h3>
      <p class="os-dim os-mb"><b><?= count($handle['pending']) ?></b> new account(s) matching a variation of your handle appeared — verify they aren't impersonating you:</p>
      <ul class="os-rlist">
        <?php foreach (array_reverse($handle['pending']) as $hi): ?>
          <li><span class="os-code"><?= ose($hi['variant']) ?></span> on <b><?= ose($hi['platform']) ?></b></li>
        <?php endforeach; ?>
      </ul>
      <div class="os-inrow" style="margin-top:12px">
        <a class="os-btn os-btn-sm" href="/osint/social.php">Review on Social</a>
        <button type="button" class="os-btn os-btn-sm" id="os-hn-dismiss">Got it, dismiss</button>
      </div>
    </div>
  <?php endif; ?>

  <div class="os-panel">
    <h2>See what the internet knows about you</h2>
    <p>A private, invite-only footprint tool. It checks <b>your own</b> identifiers against <?= (int) $siteCount ?> public sites, breach databases, and public records — then tells you what it found, what it couldn't check, and how to get it removed. A hit is a lead to verify, not proof.</p>
  </div>

  <div class="os-panel">
    <div class="os-sec-head">
      <h3 class="os-h3"><?= $threatBrief['meta']['icon'] ?> Threat lens</h3>
      <span class="os-dim" style="font-size:.8rem">who are you defending against?</span>
    </div>
    <p class="os-dim os-mb">Pick the adversary you care about most. The whole suite re-prioritizes around what <b>this</b> threat actually wants — the results, the attacker view, and your hardening plan all reorder to match.</p>
    <form method="post">
      <?= osint_csrf_field() ?><input type="hidden" name="action" value="set_threat">
      <div class="os-threatpick">
        <?php foreach ($threatModels as $key => $tm): ?>
          <button type="submit" name="threat" value="<?= ose($key) ?>" class="os-threatopt<?= $key === $threat ? ' on' : '' ?>" title="<?= ose($tm['desc']) ?>">
            <span class="os-threatopt-ic"><?= $tm['icon'] ?></span>
            <span class="os-threatopt-l"><?= ose($tm['label']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </form>
    <div class="os-threatbrief">
      <p><b><?= ose($threatBrief['meta']['label']) ?> —</b> <?= ose($threatBrief['meta']['desc']) ?> <span class="os-dim"><?= ose($threatBrief['meta']['wants']) ?></span></p>
      <?php if ($latest && $threatBrief['total']): ?>
        <p class="os-dim" style="margin-top:10px">From your last scan: <b style="color:var(--os-danger)"><?= (int) $threatBrief['high'] ?></b> top-priority and <b><?= (int) $threatBrief['total'] ?></b> relevant exposure(s) for this threat.</p>
        <div class="os-list" style="margin-top:8px">
          <?php foreach (array_slice($threatBrief['top'], 0, 5) as $t): ?>
            <div class="os-row"><div class="os-row-main"><div class="os-row-t"><span class="os-prio os-prio-<?= (int) $t['score'] ?>" title="priority <?= (int) $t['score'] ?>/3"></span> <?= ose($t['title']) ?></div><?= $t['detail'] ? '<div class="os-row-d">' . ose($t['detail']) . '</div>' : '' ?></div></div>
          <?php endforeach; ?>
        </div>
        <a class="os-btn os-btn-sm" href="/osint/attacker.php" style="margin-top:12px;display:inline-block">See the attacker's view &rarr;</a>
      <?php elseif (!$latest): ?>
        <p class="os-dim" style="margin-top:10px">Run a scan and this lens will rank what matters most against this adversary.</p>
      <?php else: ?>
        <p class="os-dim" style="margin-top:10px">Nothing in your last scan maps to this threat — a good sign for this adversary.</p>
      <?php endif; ?>
    </div>
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

  <?php if ($p['emails']): ?>
    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">Breach monitoring</h3>
        <label class="os-switch"><input type="checkbox" id="os-mon-toggle" <?= $mon['enabled'] ? 'checked' : '' ?>><span class="os-switch-t"></span></label>
      </div>
      <p class="os-dim" id="os-mon-status"><?php if ($mon['enabled']): ?>On — we automatically re-check your emails when you visit (no setup needed) and flag any <b>new</b> breach here<?= $mon['last_check'] ? '. Last checked ' . ose(date('Y-m-d H:i', $mon['last_check'])) : '' ?>.<?php else: ?>Off — turn it on to be alerted when a <b>new</b> breach includes one of your emails, without re-running a full scan.<?php endif; ?></p>
    </div>
  <?php endif; ?>

  <?php if ($p['domains']): ?>
    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">Certificate transparency monitoring</h3>
        <label class="os-switch"><input type="checkbox" id="os-ct-toggle" <?= $ct['enabled'] ? 'checked' : '' ?>><span class="os-switch-t"></span></label>
      </div>
      <p class="os-dim" id="os-ct-status"><?php if ($ct['enabled']): ?>On — we watch the public certificate-transparency logs for your domains and flag any <b>newly-issued</b> certificate here. An unexpected one is an early warning for phishing infrastructure or a subdomain takeover.<?php else: ?>Off — turn it on to be alerted the moment a certificate is issued for any name under your domains (early warning for impersonation or takeover), checked automatically when you visit.<?php endif; ?></p>
    </div>
  <?php endif; ?>

  <?php if ($p['usernames']): ?>
    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">Handle-takeover monitoring</h3>
        <label class="os-switch"><input type="checkbox" id="os-hn-toggle" <?= $handle['enabled'] ? 'checked' : '' ?>><span class="os-switch-t"></span></label>
      </div>
      <p class="os-dim" id="os-hn-status"><?php if ($handle['enabled']): ?>On — we re-check variations of your handle across platforms when you visit and flag any <b>new</b> look-alike account, so you catch impersonators early.<?php else: ?>Off — turn it on to be alerted when a <b>new</b> account resembling your handle appears on the checked platforms (impersonation early warning).<?php endif; ?></p>
    </div>
  <?php endif; ?>

  <div class="os-panel" id="os-live" hidden>
    <h3 class="os-h3">Found so far <span class="os-livecount" id="os-livecount">0</span></h3>
    <ul class="os-findlist" id="os-findlist"></ul>
    <a class="os-btn os-btn-accent" id="os-viewresults" href="/osint/results.php" hidden style="margin-top:12px;display:inline-block">View full results</a>
  </div>

  <?php if ($latest): $ageDays = (int) floor((time() - (int) $latest['started_at']) / 86400); ?>
    <div class="os-panel">
      <h3 class="os-h3">Last scan</h3>
      <p><b><?= (int) $latest['found'] ?></b> found · <b><?= (int) $latest['unreachable'] ?></b> couldn't be checked ·
        <?= (int) $latest['total'] ?> checks · <?= ose(date('Y-m-d H:i', (int) $latest['started_at'])) ?>
        <?= $latest['status'] === 'running' ? ' <span class="os-dim">(incomplete)</span>' : '' ?></p>
      <?php if ($ageDays >= 30): ?>
        <p class="os-note" style="margin-top:10px">It's been <b><?= $ageDays ?> days</b> since your last scan — new breaches and listings appear constantly. Re-scan to stay current.</p>
      <?php endif; ?>
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
osint_foot(['osint.js', 'monitor.js']);
