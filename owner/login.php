<?php
// Owner console login. Password-only for now; the TOTP 2FA feature adds a second
// step here. Independent of the videos accounts.
require __DIR__ . '/lib/audit.php';           // pulls owner_auth + gives audit_log()

owner_session_start();
if (owner_is_authed()) { header('Location: /owner/'); exit; }

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    owner_csrf_require();
    enforceRateLimit('owner_login', 8, 600);   // 8 tries / 10 min
    $pw = (string) ($_POST['password'] ?? '');

    if (!owner_is_configured()) {
        $error = 'The owner console has no password set yet. Create owner/config.php from config.example.php.';
    } elseif (owner_check_password($pw)) {
        owner_login_ok();
        audit_log('owner_login', 'crit', ['actor' => 'owner', 'detail' => 'Owner console sign-in']);
        header('Location: /owner/'); exit;
    } else {
        $error = 'Incorrect password.';
        audit_log('owner_login_fail', 'warn', ['detail' => 'Failed owner console sign-in']);
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
<link rel="stylesheet" href="/owner/assets/owner.css">
</head>
<body class="ow-centered">
  <form class="ow-login" method="post" autocomplete="off">
    <div class="ow-lock" aria-hidden="true">&#128274;</div>
    <h1>Owner Console</h1>
    <?php if ($error): ?><div class="ow-error"><?= oe($error) ?></div><?php endif; ?>
    <?= owner_csrf_field() ?>
    <label>Password
      <input type="password" name="password" autofocus required maxlength="200">
    </label>
    <button type="submit">Sign in</button>
    <p class="ow-dim">Private area. Access attempts are logged.</p>
  </form>
</body>
</html>
