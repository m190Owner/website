<?php
// Owner console — security audit log viewer. Owner-gated.
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/owner_2fa.php';
owner_require();

$filters = [
    'event'    => trim((string) ($_GET['event'] ?? '')),
    'severity' => in_array($_GET['severity'] ?? '', ['info', 'warn', 'crit'], true) ? $_GET['severity'] : '',
    'actor'    => trim((string) ($_GET['actor'] ?? '')),
];
$perPage = 100;
$page    = max(1, (int) ($_GET['p'] ?? 1));
$total   = audit_count($filters);
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min($page, $pages);
$rows    = audit_recent($filters, $perPage, ($page - 1) * $perPage);
$types   = audit_event_types();

function ow_ago(int $ts): string {
    $s = max(1, time() - $ts);
    foreach ([['d', 86400], ['h', 3600], ['m', 60]] as [$u, $sec]) {
        if ($s >= $sec) return intdiv($s, $sec) . $u . ' ago';
    }
    return $s . 's ago';
}
function ow_qs(array $over): string {
    return '?' . http_build_query(array_merge($_GET, $over));
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Security Log · Owner Console</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/owner/assets/owner.css?v=<?= @filemtime(__DIR__ . '/assets/owner.css') ?: 1 ?>">
</head>
<body>
<nav class="ow-nav">
  <div class="ow-brand"><span class="ow-lock-sm" aria-hidden="true">&#128274;</span> Owner Console <span class="ow-sep">/</span> Security Log</div>
  <div class="ow-nav-right">
    <span class="ow-dim"><?= number_format($total) ?> events</span>
    <a class="ow-btn" href="/jellyfin/">🎬 Dashboard</a>
    <a class="ow-btn" href="/owner/media.php">🎛 Controls</a>
    <a class="ow-btn" href="/owner/tokens.php">🎣 Tokens</a>
    <a class="ow-btn" href="/owner/2fa.php">&#128274; 2FA<?= owner_2fa_enabled() ? '' : ' <span class="ow-dot-warn" title="not enabled"></span>' ?></a>
    <a class="ow-btn" href="/owner/logout.php">Sign out</a>
  </div>
</nav>

<main class="ow-main">
  <?php if (!owner_2fa_enabled()): ?>
    <div class="ow-flash ow-flash-warn">Two-factor auth isn&rsquo;t enabled on this console. <a href="/owner/2fa.php">Set it up &rarr;</a></div>
  <?php endif; ?>
  <form class="ow-filters" method="get">
    <select name="event" aria-label="Event type">
      <option value="">All events</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= oe($t) ?>"<?= $filters['event'] === $t ? ' selected' : '' ?>><?= oe($t) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="severity" aria-label="Severity">
      <option value="">Any severity</option>
      <?php foreach (['crit' => 'Critical', 'warn' => 'Warning', 'info' => 'Info'] as $k => $lbl): ?>
        <option value="<?= $k ?>"<?= $filters['severity'] === $k ? ' selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="actor" value="<?= oe($filters['actor']) ?>" placeholder="Actor / username" maxlength="80">
    <button type="submit" class="ow-btn ow-btn-accent">Filter</button>
    <?php if ($filters['event'] || $filters['severity'] || $filters['actor']): ?>
      <a class="ow-btn" href="/owner/">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="ow-empty">No events<?= ($filters['event'] || $filters['severity'] || $filters['actor']) ? ' match those filters' : ' yet' ?>.</div>
  <?php else: ?>
  <div class="ow-tablewrap">
    <table class="ow-table">
      <thead><tr>
        <th>When</th><th>Severity</th><th>Event</th><th>Actor</th><th>IP</th><th>Target</th><th>Detail</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="ow-when" title="<?= oe(gmdate('Y-m-d H:i:s', (int) $r['ts'])) ?> UTC"><?= oe(ow_ago((int) $r['ts'])) ?></td>
          <td><span class="ow-sev ow-sev-<?= oe($r['severity']) ?>"><?= oe($r['severity']) ?></span></td>
          <td class="ow-mono"><?= oe($r['event']) ?></td>
          <td><?= $r['actor'] !== '' ? oe($r['actor']) : '<span class="ow-dim">—</span>' ?></td>
          <td class="ow-mono ow-ip"><?= $r['ip'] !== '' ? oe($r['ip']) : '<span class="ow-dim">—</span>' ?></td>
          <td><?= $r['target'] !== '' ? oe($r['target']) : '<span class="ow-dim">—</span>' ?></td>
          <td class="ow-detail"><?= $r['detail'] !== '' ? oe($r['detail']) : '<span class="ow-dim">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="ow-pager">
      <?php if ($page > 1): ?><a class="ow-btn" href="<?= oe(ow_qs(['p' => $page - 1])) ?>">&larr; Newer</a><?php endif; ?>
      <span class="ow-dim">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="ow-btn" href="<?= oe(ow_qs(['p' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
