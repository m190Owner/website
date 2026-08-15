<?php
// Owner console login. Two-step when 2FA is enrolled: password, then a TOTP or
// backup code. Password-only until you enrol (so you can't lock yourself out).
require __DIR__ . '/lib/audit.php';           // pulls owner_auth + gives audit_log()
require __DIR__ . '/lib/owner_2fa.php';

owner_session_start();
if (owner_is_authed()) { header('Location: /owner/'); exit; }

$error = '';
$stage = owner_pending_active() ? 'code' : 'password';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    owner_csrf_require();

    if (($_POST['step'] ?? '') === 'code' && owner_pending_active()) {
        // Second step: verify the 2FA / backup code.
        enforceRateLimit('owner_2fa', 6, 600);
        $res = owner_2fa_check((string) ($_POST['code'] ?? ''));
        if ($res['ok']) {
            owner_login_ok();
            audit_log('owner_login', 'crit', ['actor' => 'owner',
                'detail' => 'Owner console sign-in (2FA' . ($res['backup'] ? ' backup code' : '') . ')']);
            if ($res['backup']) {
                audit_log('twofa_backup_used', 'warn', ['actor' => 'owner',
                    'detail' => 'Backup code used — ' . owner_2fa_backup_remaining() . ' remaining']);
            }
            header('Location: /owner/'); exit;
        }
        $error = 'Invalid code. Try again.';
        $stage = 'code';
        audit_log('twofa_fail', 'warn', ['actor' => 'owner', 'detail' => 'Failed owner 2FA code']);
    } else {
        // First step: password.
        enforceRateLimit('owner_login', 8, 600);
        $pw = (string) ($_POST['password'] ?? '');
        if (!owner_is_configured()) {
            $error = 'The owner console has no password set yet. Create owner/config.php from config.example.php.';
        } elseif (owner_check_password($pw)) {
            if (owner_2fa_enabled()) {
                owner_pending_begin();
                $stage = 'code';
            } else {
                owner_login_ok();
                audit_log('owner_login', 'crit', ['actor' => 'owner', 'detail' => 'Owner console sign-in']);
                header('Location: /owner/'); exit;
            }
        } else {
            $error = 'Incorrect password.';
            audit_log('owner_login_fail', 'warn', ['detail' => 'Failed owner console sign-in']);
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Owner Console</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/owner/assets/owner.css?v=<?= @filemtime(__DIR__ . '/assets/owner.css') ?: 1 ?>">
</head>
<body class="ow-centered">
  <form class="ow-login" method="post" autocomplete="off">
    <div class="ow-lock" aria-hidden="true">&#128274;</div>
    <h1>Owner Console</h1>
    <?php if ($error): ?><div class="ow-error"><?= oe($error) ?></div><?php endif; ?>
    <?= owner_csrf_field() ?>
    <?php if ($stage === 'code'): ?>
      <input type="hidden" name="step" value="code">
      <label>Authenticator code
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
               autofocus required maxlength="14" placeholder="6-digit code" pattern="[0-9A-Za-z \-]{6,14}">
      </label>
      <button type="submit">Verify</button>
      <p class="ow-dim">Enter the 6-digit code from your authenticator, or a backup code.</p>
    <?php else: ?>
      <label>Password
        <input type="password" name="password" autofocus required maxlength="200">
      </label>
      <button type="submit">Sign in</button>
      <p class="ow-dim">Private area. Access attempts are logged.</p>
    <?php endif; ?>
  </form>
</body>
</html>
