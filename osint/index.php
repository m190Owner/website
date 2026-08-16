<?php
// Gated dashboard for the removal / footprint tool. Run a scan, watch it progress,
// see the latest results. The scan itself is driven from osint/assets/osint.js
// against osint/scan.php in small batches.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);
$nId = count($p['usernames']) + count($p['emails']);
$latest = scan_latest((int) $u['id']);
$siteCount = count(scan_sites());
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>m190 finder</title>
<meta name="osint-csrf" content="<?= ose(osint_csrf_token()) ?>">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/osint/assets/osint.css?v=<?= @filemtime(__DIR__ . '/assets/osint.css') ?: 1 ?>">
</head>
<body>
<header class="os-top">
  <div class="os-top-l"><span class="os-mark">✷</span><b>m190 finder</b></div>
  <div class="os-top-r">
    <a class="os-btn os-btn-sm" href="/osint/profile.php">Profile</a>
    <?php if ($latest): ?><a class="os-btn os-btn-sm" href="/osint/results.php">Results</a><?php endif; ?>
    <span>signed in as <?= ose($u['username']) ?></span>
    <a class="os-btn os-btn-sm" href="/osint/logout.php">Sign out</a>
  </div>
</header>

<main class="os-main">
  <div class="os-panel">
    <h2>See what the internet knows about you</h2>
    <p>This checks your usernames against <?= (int) $siteCount ?> public sites and your emails against breach databases, then tells you what it found — and, honestly, what it couldn't check. A hit is a lead to verify, not proof.</p>
  </div>

  <div class="os-grid2">
    <div class="os-panel">
      <h3 class="os-h3">Your profile</h3>
      <?php if ($nId === 0): ?>
        <p>You haven't added anything to scan yet.</p>
        <a class="os-btn os-btn-accent" href="/osint/profile.php" style="margin-top:12px;display:inline-block">Add usernames &amp; emails</a>
      <?php else: ?>
        <p><b><?= count($p['usernames']) ?></b> username(s), <b><?= count($p['emails']) ?></b> email(s) on file.</p>
        <a class="os-btn os-btn-sm" href="/osint/profile.php" style="margin-top:12px;display:inline-block">Edit profile</a>
      <?php endif; ?>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Run a scan</h3>
      <?php if ($nId === 0): ?>
        <p class="os-dim">Add at least one username or email first.</p>
      <?php else: ?>
        <p class="os-dim">Around <?= (int) (count($p['usernames']) * $siteCount + count($p['emails']) * 2) ?> checks. Takes a minute or two — keep this tab open.</p>
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
</main>

<script src="/osint/assets/osint.js?v=<?= @filemtime(__DIR__ . '/assets/osint.js') ?: 1 ?>"></script>
</body>
</html>
