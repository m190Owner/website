<?php
// Owner console → media control. The ONLY place control commands originate:
// owner-gated (behind 2FA) + CSRF. Each button enqueues an allowlisted command
// (jf_cmd_enqueue) and audits it; the agent pulls + runs it locally. This page
// never touches the box directly — it just queues intent.
require __DIR__ . '/lib/audit.php';            // owner_auth + audit_log
require __DIR__ . '/../jellyfin/lib.php';      // jf_cmd_*, jf_stack_read
owner_require();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    owner_csrf_require();
    enforceRateLimit('owner_media_cmd', 30, 60);
    $action = (string) ($_POST['cmd'] ?? '');
    $args = [];
    if (isset($_POST['id']))   $args['id']   = (int) $_POST['id'];
    if (isset($_POST['name'])) $args['name'] = (string) $_POST['name'];
    if (isset($_POST['hash'])) $args['hash'] = (string) $_POST['hash'];
    $label = (string) ($_POST['label'] ?? $action);
    $flash = '';
    if (jf_cmd_enqueue($action, $args, 'owner')) {
        audit_log('media_command', 'crit', ['actor' => 'owner', 'target' => mb_substr($label, 0, 80),
            'detail' => 'Queued ' . $action . ' — ' . $label, 'push' => true]);
        $flash = 'Queued: ' . $action . ' · ' . $label . ' — the agent runs it within ~60s.';
    } else {
        $flash = 'ERR:Rejected — not an allowed command.';
    }
    $_SESSION['owner_media_flash'] = $flash;
    header('Location: /owner/media.php'); exit;                // PRG: no resubmit on reload
}

$msg = ''; $err = '';
if (!empty($_SESSION['owner_media_flash'])) {
    $f = $_SESSION['owner_media_flash']; unset($_SESSION['owner_media_flash']);
    if (str_starts_with($f, 'ERR:')) $err = substr($f, 4); else $msg = $f;
}

$stack = jf_stack_read();
$age   = $stack ? (int) ($stack['ageSec'] ?? 999999) : null;
$js    = is_array($stack['jellyseerr'] ?? null) ? $stack['jellyseerr'] : null;
$reqs  = $js ? array_filter($js['requests'] ?? [], fn($r) => (int) ($r['reqStatus'] ?? 0) === 1 && (int) ($r['id'] ?? 0) > 0) : [];
$containers = is_array($stack['containers'] ?? null) ? $stack['containers'] : [];
$torrents   = array_filter($stack['services']['qbit']['list'] ?? [], fn($t) => ($t['hash'] ?? '') !== '');
$cmds  = array_slice(array_reverse(jf_cmd_read()), 0, 12);

/** Render one command button as its own mini POST form. */
function cbtn(string $cmd, array $fields, string $label, string $cls = 'ow-btn', string $confirm = ''): string {
    $h = '<form method="post" class="ow-cbtn"' . ($confirm ? ' onsubmit="return confirm(' . oe(json_encode($confirm)) . ')"' : '') . '>'
       . owner_csrf_field() . '<input type="hidden" name="cmd" value="' . oe($cmd) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . oe($k) . '" value="' . oe((string) $v) . '">';
    return $h . '<button class="' . oe($cls) . '">' . oe($label) . '</button></form>';
}
$statusCls = ['done' => 'ok', 'failed' => 'crit', 'queued' => 'pend', 'claimed' => 'pend', 'expired' => 'warn'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Media Control · Owner Console</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/owner/assets/owner.css?v=<?= @filemtime(__DIR__ . '/assets/owner.css') ?: 1 ?>">
</head>
<body>
<nav class="ow-nav">
  <div class="ow-brand"><a href="/owner/" style="text-decoration:none;color:inherit"><span class="ow-lock-sm" aria-hidden="true">&#128274;</span> Owner Console</a> <span class="ow-sep">/</span> Media Control</div>
  <div class="ow-nav-right"><a class="ow-btn" href="/jellyfin/">&larr; Dashboard</a><a class="ow-btn" href="/owner/">Security log</a><a class="ow-btn" href="/owner/logout.php">Sign out</a></div>
</nav>

<main class="ow-main">
  <?php if ($msg): ?><div class="ow-flash ow-flash-ok"><?= oe($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ow-error"><?= oe($err) ?></div><?php endif; ?>
  <?php if ($stack === null): ?>
    <div class="ow-empty">No agent report yet — controls appear once the media-server agent has reported in.</div>
  <?php else: ?>
    <?php if ($age !== null && $age > 180): ?><div class="ow-flash ow-flash-warn">Agent last reported <?= (int) round($age / 60) ?>m ago — commands still queue, but the box may be offline.</div><?php endif; ?>

    <h2 class="ow-mh">Pending requests <span class="ow-dim">(approve / deny)</span></h2>
    <?php if (!$reqs): ?><p class="ow-dim">No requests awaiting approval.</p><?php else: ?>
      <div class="ow-ctl-list">
        <?php foreach ($reqs as $r): $lbl = ($r['type'] === 'tv' ? '📺 ' : '🎬 ') . $r['title']; ?>
          <div class="ow-ctl-row"><span class="ow-ctl-name"><?= oe($lbl) ?> <span class="ow-dim">· <?= oe($r['user']) ?></span></span>
            <span class="ow-ctl-btns"><?= cbtn('jellyseerr_approve', ['id' => (int) $r['id'], 'label' => $lbl], 'Approve', 'ow-btn ow-btn-accent') ?><?= cbtn('jellyseerr_decline', ['id' => (int) $r['id'], 'label' => $lbl], 'Deny', 'ow-btn ow-btn-danger') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h2 class="ow-mh">Containers <span class="ow-dim">(restart)</span></h2>
    <div class="ow-cgrid">
      <?php foreach ($containers as $c): $n = $c['name'] ?? ''; if (!in_array($n, JF_CONTAINERS, true)) continue;
        $running = ($c['state'] ?? '') === 'running'; ?>
        <div class="ow-ccard">
          <span class="ow-ccard-name"><span class="ow-cdot <?= $running ? 'up' : 'down' ?>" title="<?= oe($c['state'] ?? '') ?>"></span><?= oe($n) ?></span>
          <?= cbtn('container_restart', ['name' => $n, 'label' => $n], '↻ Restart', 'ow-btn', 'Restart ' . $n . '? Streams from it drop briefly.') ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($torrents): ?>
      <h2 class="ow-mh">Torrents <span class="ow-dim">(pause / resume / delete)</span></h2>
      <div class="ow-ctl-list">
        <?php foreach (array_slice($torrents, 0, 25) as $t): $h = $t['hash']; $tn = mb_substr($t['name'] ?? '', 0, 70);
          $dl = ($t['state'] ?? ''); $paused = str_contains($dl, 'paused') || str_contains($dl, 'stopped'); ?>
          <div class="ow-ctl-row"><span class="ow-ctl-name"><?= oe($tn) ?> <span class="ow-dim">· <?= (int) round(($t['progress'] ?? 0) * 100) ?>% · <?= oe($dl) ?></span></span>
            <span class="ow-ctl-btns"><?= $paused
                ? cbtn('torrent_resume', ['hash' => $h, 'label' => $tn], '▶ Resume', 'ow-btn ow-btn-accent')
                : cbtn('torrent_pause', ['hash' => $h, 'label' => $tn], '⏸ Pause', 'ow-btn') ?><?= cbtn('torrent_delete', ['hash' => $h, 'label' => $tn], '🗑 Delete', 'ow-btn ow-btn-danger', 'Delete torrent "' . $tn . '" from qBittorrent? (files kept)') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <h2 class="ow-mh">Recent commands</h2>
  <?php if (!$cmds): ?><p class="ow-dim">No commands issued yet.</p><?php else: ?>
    <table class="ow-table"><thead><tr><th>When</th><th>Action</th><th>Target</th><th>Status</th><th>Result</th></tr></thead><tbody>
      <?php foreach ($cmds as $c): ?>
        <tr><td class="ow-when"><?= oe(date('H:i', (int) ($c['createdAt'] ?? 0))) ?></td>
          <td class="ow-mono"><?= oe($c['action'] ?? '') ?></td>
          <td><?= oe(($c['args']['name'] ?? '') ?: (isset($c['args']['id']) ? '#' . $c['args']['id'] : (isset($c['args']['hash']) ? substr($c['args']['hash'], 0, 8) : '—'))) ?></td>
          <td><span class="ow-sev ow-sev-<?= $statusCls[$c['status'] ?? ''] ?? 'info' ?>"><?= oe($c['status'] ?? '') ?></span></td>
          <td class="ow-detail"><?= oe($c['result'] ?? '') ?: '<span class="ow-dim">—</span>' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</main>
</body>
</html>
