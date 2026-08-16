<?php
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    osint_csrf_require();
    enforceRateLimit('osint_clear', 20, 60);
    scan_clear((int) $u['id']);
    header('Location: /osint/'); exit;
}

$scan = isset($_GET['scan']) ? scan_get((int) $u['id'], (int) $_GET['scan']) : scan_latest((int) $u['id']);
$findings = $scan ? scan_findings((int) $u['id'], (int) $scan['id']) : [];
$has = fn($f, $needle) => strpos((string) ($f['exposes'] ?? ''), $needle) !== false;
$accounts = array_values(array_filter($findings, fn($f) => $f['category'] === 'account' && !$has($f, 'email')));
$identity = array_values(array_filter($findings, fn($f) => $f['category'] === 'account' && $has($f, 'email')));
$breaches = array_values(array_filter($findings, fn($f) => $f['category'] === 'breach'));

// Breach span (earliest / most recent year) from the stored detail ("YYYY · data").
$years = [];
foreach ($breaches as $b) { if (preg_match('/\b(19|20)\d\d\b/', (string) $b['detail'], $m)) $years[] = (int) $m[0]; }
$span = $years ? (min($years) === max($years) ? (string) min($years) : min($years) . '–' . max($years)) : '';

function os_avatar(array $f): string {
    $a = (string) ($f['avatar'] ?? '');
    if ($a !== '' && preg_match('#^https?://#i', $a)) {
        return '<img class="os-av-img" src="' . ose($a) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';this.parentNode.classList.add(\'os-av-none\')">';
    }
    return '';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Results · m190 finder</title>
<meta name="osint-csrf" content="<?= ose(osint_csrf_token()) ?>">
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
        <div class="os-resultbtns">
          <a class="os-btn os-btn-sm" href="/osint/export.php?scan=<?= (int) $scan['id'] ?>">Export CSV</a>
          <form method="post" class="os-inline" onsubmit="return confirm('Delete all your scan results? This cannot be undone.')">
            <?= osint_csrf_field() ?><input type="hidden" name="action" value="clear">
            <button class="os-btn os-btn-sm os-btn-danger">Clear results</button>
          </form>
        </div>
      </div>
      <div class="os-statrow">
        <div class="os-stat"><b><?= count($accounts) ?></b><span>accounts</span></div>
        <div class="os-stat"><b><?= count($identity) ?></b><span>email identity</span></div>
        <div class="os-stat"><b><?= count($breaches) ?></b><span>breach records</span></div>
        <div class="os-stat os-stat-warn"><b><?= (int) $scan['unreachable'] ?></b><span>couldn't check</span></div>
      </div>
      <p class="os-fineprint">Avatars are pulled from each site's public page so you can eyeball whether a hit is really you — short or common handles collide with other people. "Couldn't check" means a site blocked us, not that you're clear there.</p>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Accounts &amp; profiles <span class="os-dim">(<?= count($accounts) ?>)</span></h3>
      <p class="os-dim os-mb">Yours to delete or lock down. Open one to confirm it's you.</p>
      <?php if (!$accounts): ?><p class="os-dim">Nothing matched.</p><?php else: ?>
        <div class="os-cardgrid">
          <?php foreach ($accounts as $f): ?>
            <a class="os-acard" href="<?= ose($f['url']) ?>" target="_blank" rel="noopener nofollow">
              <span class="os-av"><?= os_avatar($f) ?></span>
              <span class="os-acard-t"><?= ose($f['title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($identity): ?>
      <div class="os-panel">
        <h3 class="os-h3">Email identity <span class="os-dim">(<?= count($identity) ?>)</span></h3>
        <p class="os-dim os-mb">Profile pictures and accounts tied to your email addresses.</p>
        <div class="os-cardgrid">
          <?php foreach ($identity as $f): $isG = $has($f, 'google'); ?>
            <a class="os-acard" href="<?= ose($f['url']) ?>" target="_blank" rel="noopener nofollow">
              <span class="os-av <?= $isG ? 'os-av-g' : '' ?>"><?= $isG ? 'G' : os_avatar($f) ?></span>
              <span class="os-acard-t"><?= ose($f['title']) ?><?php if ($f['detail']): ?><br><span class="os-dim"><?= ose($f['detail']) ?></span><?php endif; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">Breach records <span class="os-dim">(<?= count($breaches) ?>)</span></h3>
        <?php if ($span): ?><span class="os-dim os-badge"><?= ose($span) ?></span><?php endif; ?>
      </div>
      <p class="os-dim os-mb">A breach already happened — you can't undo it. Change the password anywhere you reused it.</p>
      <?php if (!$breaches): ?><p class="os-dim">No breach records reported.</p><?php else: ?>
        <div class="os-breachlist">
          <?php foreach ($breaches as $f):
            $name = preg_replace('/^.* in the (.*) breach$/', '$1', $f['title']); ?>
            <div class="os-bcard">
              <span class="os-blogo"><?= os_avatar($f) ?></span>
              <span class="os-bcard-t"><b><?= ose($name) ?></b><?php if ($f['detail']): ?><span class="os-bmeta"><?= ose($f['detail']) ?></span><?php endif; ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
