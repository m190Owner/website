<?php
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

$scan = isset($_GET['scan']) ? scan_get((int) $u['id'], (int) $_GET['scan']) : scan_latest((int) $u['id']);
$findings = $scan ? scan_findings((int) $u['id'], (int) $scan['id']) : [];
$accounts = array_values(array_filter($findings, fn($f) => $f['category'] === 'account'));
$breaches = array_values(array_filter($findings, fn($f) => $f['category'] === 'breach'));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Results · m190 finder</title>
<link rel="icon" type="image/png" href="/osint/assets/m190-logo.png">
<link rel="stylesheet" href="/osint/assets/osint.css?v=<?= @filemtime(__DIR__ . '/assets/osint.css') ?: 1 ?>">
</head>
<body>
<header class="os-top">
  <div class="os-top-l"><img class="os-logo" src="/osint/assets/m190-logo.png" alt="m190 OPSEC Team"><b>m190 finder</b></div>
  <div class="os-top-r">
    <a class="os-btn os-btn-sm" href="/osint/">Dashboard</a>
    <span>signed in as <?= ose($u['username']) ?></span>
    <a class="os-btn os-btn-sm" href="/osint/logout.php">Sign out</a>
  </div>
</header>

<main class="os-main">
  <?php if (!$scan): ?>
    <div class="os-panel"><h2>No scans yet</h2><p>Run one from the <a href="/osint/">dashboard</a>.</p></div>
  <?php else: ?>
    <div class="os-panel">
      <div class="os-resulthead">
        <div>
          <h2>Scan results</h2>
          <p class="os-dim"><?= ose(date('Y-m-d H:i', (int) $scan['started_at'])) ?><?= $scan['status'] === 'running' ? ' · incomplete' : '' ?></p>
        </div>
        <a class="os-btn os-btn-sm" href="/osint/export.php?scan=<?= (int) $scan['id'] ?>">Export CSV</a>
      </div>
      <div class="os-statrow">
        <div class="os-stat"><b><?= (int) $scan['found'] ?></b><span>found</span></div>
        <div class="os-stat"><b><?= count($accounts) ?></b><span>accounts</span></div>
        <div class="os-stat"><b><?= count($breaches) ?></b><span>breach records</span></div>
        <div class="os-stat os-stat-warn"><b><?= (int) $scan['unreachable'] ?></b><span>couldn't check</span></div>
      </div>
      <p class="os-fineprint">"Couldn't check" means a site blocked us or timed out — not that you're clear there. A hit is a lead to open and verify, not proof: short or common handles collide with other people.</p>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Accounts &amp; profiles <span class="os-dim">(<?= count($accounts) ?>)</span></h3>
      <p class="os-dim os-mb">These are yours to delete or lock down.</p>
      <?php if (!$accounts): ?><p class="os-dim">Nothing matched.</p><?php else: ?>
        <ul class="os-findlist">
          <?php foreach ($accounts as $f): ?>
            <li><a href="<?= ose($f['url']) ?>" target="_blank" rel="noopener nofollow"><?= ose($f['title']) ?> ↗</a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Breach records <span class="os-dim">(<?= count($breaches) ?>)</span></h3>
      <p class="os-dim os-mb">A breach already happened — you can't undo it. The response is a password change and, ideally, unique passwords per site.</p>
      <?php if (!$breaches): ?><p class="os-dim">No breach records reported.</p><?php else: ?>
        <ul class="os-findlist">
          <?php foreach ($breaches as $f): ?><li><?= ose($f['title']) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
