<?php
// Owner console → OSINT tool administration. Owner-gated (2FA): the ONLY place
// invites are minted and users are managed. Invited users themselves never see this
// — they use the separate /osint/ login. "Only I can invite" == this page.
require __DIR__ . '/lib/audit.php';                 // owner_auth + audit_log
require __DIR__ . '/../osint/lib/osint_auth.php';   // osint_invite_* / osint_users_* / ose()
require __DIR__ . '/../osint/lib/scan.php';          // scan_cron_token() for the monitoring cron
owner_require();

$origin = (osint_https() ? 'https' : 'http') . '://' . (preg_match('/^[A-Za-z0-9.\-:]{1,255}$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) ? $_SERVER['HTTP_HOST'] : 'logansandivar.com');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    owner_csrf_require();
    enforceRateLimit('owner_osint', 40, 60);
    $act = (string) ($_POST['act'] ?? '');
    $flash = '';
    if ($act === 'invite') {
        $inv = osint_invite_create((string) ($_POST['note'] ?? ''), (int) ($_POST['ttl'] ?? 14));
        if ($inv) {
            audit_log('osint_invite', 'crit', ['actor' => 'owner', 'target' => mb_substr((string) ($_POST['note'] ?? ''), 0, 80),
                'detail' => 'Minted an OSINT invite', 'push' => true]);
            $flash = 'INVITE:' . $inv['code'];
        } else { $flash = 'ERR:Could not create the invite.'; }
    } elseif ($act === 'revoke' && !empty($_POST['code'])) {
        osint_invite_revoke((string) $_POST['code']);
        audit_log('osint_invite_revoke', 'warn', ['actor' => 'owner', 'detail' => 'Revoked an OSINT invite']);
        $flash = 'Invite revoked.';
    } elseif ($act === 'user' && !empty($_POST['id'])) {
        $dis = ((string) ($_POST['disabled'] ?? '')) === '1';
        osint_user_set_disabled((int) $_POST['id'], $dis);
        audit_log('osint_user', 'warn', ['actor' => 'owner', 'detail' => ($dis ? 'Disabled' : 'Enabled') . ' OSINT user #' . (int) $_POST['id']]);
        $flash = 'User updated.';
    }
    $_SESSION['owner_osint_flash'] = $flash;
    header('Location: /owner/osint.php'); exit;
}

$msg = ''; $err = ''; $newCode = '';
if (!empty($_SESSION['owner_osint_flash'])) {
    $f = $_SESSION['owner_osint_flash']; unset($_SESSION['owner_osint_flash']);
    if (str_starts_with($f, 'ERR:')) $err = substr($f, 4);
    elseif (str_starts_with($f, 'INVITE:')) { $newCode = substr($f, 7); $msg = 'Invite created — copy the link below and send it to the person you\'re inviting.'; }
    else $msg = $f;
}

$invites = osint_invites_list();
$users   = osint_users_list();
$now = time();
function os_inv_status(array $i, int $now): array {
    if ((int) $i['revoked'] === 1)                                 return ['revoked', 'ow-sev-warn'];
    if ($i['used_by'] !== null)                                    return ['used', 'ow-sev-ok'];
    if ($i['expires_at'] !== null && $now > (int) $i['expires_at'])return ['expired', 'ow-sev-warn'];
    return ['open', 'ow-sev-info'];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>OSINT Invites · Owner Console</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/owner/assets/owner.css?v=<?= @filemtime(__DIR__ . '/assets/owner.css') ?: 1 ?>">
</head>
<body>
<nav class="ow-nav">
  <div class="ow-brand"><a href="/owner/" style="text-decoration:none;color:inherit"><span class="ow-lock-sm" aria-hidden="true">&#128274;</span> Owner Console</a> <span class="ow-sep">/</span> OSINT invites</div>
  <div class="ow-nav-right"><a class="ow-btn" href="/owner/">&larr; Security log</a><a class="ow-btn" href="/osint/" target="_blank" rel="noopener">Open tool &#8599;</a><a class="ow-btn" href="/owner/logout.php">Sign out</a></div>
</nav>

<main class="ow-main">
  <?php if ($msg): ?><div class="ow-flash ow-flash-ok"><?= oe($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ow-error"><?= oe($err) ?></div><?php endif; ?>

  <?php if ($newCode !== ''): $link = $origin . '/osint/register.php?code=' . $newCode; ?>
    <div class="ow-copy" style="max-width:640px;margin-bottom:14px">
      <input readonly value="<?= oe($link) ?>">
      <button type="button" class="ow-btn ow-copy-btn" data-copy="<?= oe($link) ?>">Copy link</button>
    </div>
  <?php endif; ?>

  <h2 class="ow-mh">Mint an invite</h2>
  <form method="post" class="ow-tok-mint" autocomplete="off">
    <?= owner_csrf_field() ?>
    <input type="hidden" name="act" value="invite">
    <input type="text" name="note" maxlength="120" placeholder="Who is this for? (e.g. jane@…, a note to yourself)" class="ow-tok-memo">
    <select name="ttl" aria-label="Expiry">
      <option value="7">expires in 7 days</option>
      <option value="14" selected>expires in 14 days</option>
      <option value="30">expires in 30 days</option>
      <option value="0">no expiry</option>
    </select>
    <button class="ow-btn ow-btn-accent">Create invite</button>
  </form>

  <h2 class="ow-mh">Invites</h2>
  <?php if (!$invites): ?><p class="ow-dim">No invites yet.</p><?php else: ?>
    <table class="ow-table"><thead><tr><th>Status</th><th>Note</th><th>Link / used by</th><th>Expires</th><th></th></tr></thead><tbody>
      <?php foreach ($invites as $i): [$lbl, $cls] = os_inv_status($i, $now); $open = $lbl === 'open'; $link = $origin . '/osint/register.php?code=' . $i['code']; ?>
        <tr>
          <td><span class="ow-sev <?= $cls ?>"><?= oe($lbl) ?></span></td>
          <td><?= oe($i['note'] ?: '—') ?></td>
          <td class="ow-detail">
            <?php if ($i['used_by'] !== null): ?><span class="ow-mono"><?= oe($i['used_by']) ?></span>
            <?php elseif ($open): ?>
              <span class="ow-copy" style="max-width:420px"><input readonly value="<?= oe($link) ?>"><button type="button" class="ow-btn ow-btn-sm ow-copy-btn" data-copy="<?= oe($link) ?>">Copy</button></span>
            <?php else: ?><span class="ow-dim">—</span><?php endif; ?>
          </td>
          <td class="ow-when"><?= $i['expires_at'] ? oe(date('Y-m-d', (int) $i['expires_at'])) : 'never' ?></td>
          <td><?php if ($open): ?><form method="post" class="ow-cbtn"><?= owner_csrf_field() ?><input type="hidden" name="act" value="revoke"><input type="hidden" name="code" value="<?= oe($i['code']) ?>"><button class="ow-btn ow-btn-sm ow-btn-danger">Revoke</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>

  <h2 class="ow-mh">Users</h2>
  <?php if (!$users): ?><p class="ow-dim">No accounts yet.</p><?php else: ?>
    <table class="ow-table"><thead><tr><th>User</th><th>Created</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($users as $u): $dis = (int) $u['disabled'] === 1; ?>
        <tr>
          <td class="ow-mono"><?= oe($u['username']) ?></td>
          <td class="ow-when"><?= oe(date('Y-m-d', (int) $u['created_at'])) ?></td>
          <td class="ow-when"><?= $u['last_login'] ? oe(date('Y-m-d H:i', (int) $u['last_login'])) : '—' ?></td>
          <td><span class="ow-sev <?= $dis ? 'ow-sev-warn' : 'ow-sev-ok' ?>"><?= $dis ? 'disabled' : 'active' ?></span></td>
          <td><form method="post" class="ow-cbtn"><?= owner_csrf_field() ?><input type="hidden" name="act" value="user"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="disabled" value="<?= $dis ? '0' : '1' ?>"><button class="ow-btn ow-btn-sm <?= $dis ? '' : 'ow-btn-danger' ?>"><?= $dis ? 'Enable' : 'Disable' ?></button></form></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>

  <?php $cronUrl = $origin . '/osint/cron.php?key=' . scan_cron_token(); ?>
  <h2 class="ow-mh">Breach-monitoring cron</h2>
  <p class="ow-dim" style="max-width:660px">Users can opt into automatic breach monitoring on the tool's dashboard. Trigger the re-check on a schedule (e.g. a daily Hostinger cron) with this token-gated URL — or run <span class="ow-mono">php&nbsp;/path/to/osint/cron.php</span> from a CLI cron. Keep the token private.</p>
  <div class="ow-copy" style="max-width:660px">
    <input readonly value="<?= oe($cronUrl) ?>">
    <button type="button" class="ow-btn ow-copy-btn" data-copy="<?= oe($cronUrl) ?>">Copy URL</button>
  </div>
</main>

<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.ow-copy-btn'); if (!b) return;
  navigator.clipboard.writeText(b.getAttribute('data-copy') || '').then(function () {
    var o = b.textContent; b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = o; }, 1200);
  });
});
</script>
</body>
</html>
