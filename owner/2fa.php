<?php
// Owner console 2FA enrollment + management. Owner-gated. Enable requires
// confirming a live code before it switches on; disable requires a current code.
require __DIR__ . '/lib/audit.php';           // audit_log + owner_auth
require __DIR__ . '/lib/owner_2fa.php';
require __DIR__ . '/lib/qr.php';
owner_require();

const OWNER_2FA_ISSUER = 'logansandivar.com';
const OWNER_2FA_LABEL  = 'owner';

$msg = ''; $err = ''; $backupCodes = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    owner_csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'confirm' && !owner_2fa_enabled()) {
        enforceRateLimit('owner_2fa_setup', 10, 600);
        $secret = (string) ($_SESSION['owner_2fa_setup'] ?? '');
        if ($secret === '') {
            $err = 'Setup timed out — reload and scan the new code.';
        } elseif (totp_verify($secret, (string) ($_POST['code'] ?? ''))) {
            $backupCodes = owner_2fa_gen_backup_codes();
            owner_2fa_enable($secret, $backupCodes);
            unset($_SESSION['owner_2fa_setup']);
            audit_log('twofa_enable', 'crit', ['actor' => 'owner', 'detail' => '2FA enabled on the owner console']);
            $msg = '2FA is on. Save your backup codes below — this is the only time they are shown.';
        } else {
            $err = 'That code didn\'t match. Check your phone\'s clock and enter the current 6-digit code.';
        }
    } elseif ($action === 'disable' && owner_2fa_enabled()) {
        enforceRateLimit('owner_2fa_setup', 10, 600);
        $res = owner_2fa_check((string) ($_POST['code'] ?? ''));
        if ($res['ok']) {
            owner_2fa_disable();
            audit_log('twofa_disable', 'crit', ['actor' => 'owner', 'detail' => '2FA disabled on the owner console']);
            $msg = '2FA has been disabled.';
        } else {
            $err = 'Wrong code — 2FA is still on.';
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['owner_2fa_setup']);
    }
}

$enabled = owner_2fa_enabled();

// Prepare a setup secret + QR when we're in the enrol state.
$setupSecret = ''; $qr = ''; $uri = '';
if (!$enabled && $backupCodes === null) {
    if (empty($_SESSION['owner_2fa_setup'])) $_SESSION['owner_2fa_setup'] = totp_secret_new();
    $setupSecret = (string) $_SESSION['owner_2fa_setup'];
    $uri = totp_uri($setupSecret, OWNER_2FA_LABEL, OWNER_2FA_ISSUER);
    $qr  = qr_svg($uri, 5, 4);
}
$keyGrouped = trim(chunk_split($setupSecret, 4, ' '));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>2FA · Owner Console</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/owner/assets/owner.css?v=<?= @filemtime(__DIR__ . '/assets/owner.css') ?: 1 ?>">
</head>
<body>
<nav class="ow-nav">
  <div class="ow-brand"><a href="/owner/" style="text-decoration:none;color:inherit"><span class="ow-lock-sm" aria-hidden="true">&#128274;</span> Owner Console</a> <span class="ow-sep">/</span> Two-Factor Auth</div>
  <div class="ow-nav-right"><a class="ow-btn" href="/owner/">&larr; Security log</a><a class="ow-btn" href="/owner/logout.php">Sign out</a></div>
</nav>

<main class="ow-main ow-narrow">
  <?php if ($msg): ?><div class="ow-flash ow-flash-ok"><?= oe($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ow-error"><?= oe($err) ?></div><?php endif; ?>

  <?php if ($backupCodes !== null): ?>
    <h1>Backup codes</h1>
    <p class="ow-dim">Store these somewhere safe. Each works once if you lose your authenticator. They will not be shown again.</p>
    <div class="ow-codes">
      <?php foreach ($backupCodes as $c): ?><code><?= oe($c) ?></code><?php endforeach; ?>
    </div>
    <a class="ow-btn ow-btn-accent" href="/owner/2fa.php">I&rsquo;ve saved them</a>

  <?php elseif ($enabled): ?>
    <h1>Two-factor authentication <span class="ow-sev ow-sev-info" style="vertical-align:middle">ON</span></h1>
    <p class="ow-dim">Sign-in requires your authenticator code. <?= (int) owner_2fa_backup_remaining() ?> backup code(s) remaining.</p>
    <form method="post" class="ow-form" autocomplete="off" onsubmit="return confirm('Turn OFF two-factor authentication?');">
      <?= owner_csrf_field() ?>
      <input type="hidden" name="action" value="disable">
      <label>Enter a current code to disable
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="14" placeholder="6-digit or backup code">
      </label>
      <button type="submit" class="ow-btn ow-btn-danger">Disable 2FA</button>
    </form>

  <?php else: ?>
    <h1>Set up two-factor authentication</h1>
    <ol class="ow-steps">
      <li>Scan this QR with your authenticator app (Google Authenticator, Aegis, 1Password…).</li>
      <li>Enter the 6-digit code it shows to confirm.</li>
    </ol>
    <div class="ow-enroll">
      <div class="ow-qr"><?= $qr ?></div>
      <div class="ow-enroll-side">
        <p class="ow-dim">Can&rsquo;t scan? Enter this key manually (type TOTP / time-based):</p>
        <code class="ow-key"><?= oe($keyGrouped) ?></code>
        <form method="post" class="ow-form" autocomplete="off">
          <?= owner_csrf_field() ?>
          <input type="hidden" name="action" value="confirm">
          <label>6-digit code
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" autofocus>
          </label>
          <button type="submit" class="ow-btn ow-btn-accent">Confirm &amp; enable</button>
        </form>
      </div>
    </div>
    <p class="ow-dim ow-hint">The QR is generated on this server — the secret never leaves the box.</p>
  <?php endif; ?>
</main>
</body>
</html>
