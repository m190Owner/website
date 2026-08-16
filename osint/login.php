<?php
// Invited-user login for the /osint/ tool. No public signup — accounts come only
// from redeeming an invite (register.php). Access attempts are audit-logged.
require __DIR__ . '/lib/osint_auth.php';

osint_session_start();
$next = osint_safe_next($_REQUEST['next'] ?? '');
if (osint_current_user()) { header('Location: ' . $next); exit; }

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    osint_csrf_require();
    enforceRateLimit('osint_login', 8, 600);
    [$uid, $err] = osint_authenticate((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($uid) {
        osint_login($uid);
        audit_log('osint_login', 'info', ['actor' => (string) ($_POST['username'] ?? ''), 'detail' => 'OSINT tool sign-in']);
        header('Location: ' . $next); exit;
    }
    $error = $err;
    audit_log('osint_login_fail', 'warn', ['actor' => mb_substr((string) ($_POST['username'] ?? ''), 0, 40), 'detail' => 'Failed OSINT tool sign-in']);
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · m190 finder</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/osint/assets/osint.css?v=<?= @filemtime(__DIR__ . '/assets/osint.css') ?: 1 ?>">
</head>
<body class="os-centered">
  <form class="os-card os-form" method="post" autocomplete="off">
    <div class="os-brand"><span class="os-mark">✷</span></div>
    <h1>m190 finder</h1>
    <p class="os-lead">Private, invite-only. Sign in to continue.</p>
    <?php if ($error): ?><div class="os-error"><?= ose($error) ?></div><?php endif; ?>
    <?= osint_csrf_field() ?>
    <input type="hidden" name="next" value="<?= ose($next) ?>">
    <label>Username
      <input type="text" name="username" autofocus required maxlength="32">
    </label>
    <label>Password
      <input type="password" name="password" required maxlength="200">
    </label>
    <button type="submit" class="os-btn os-btn-accent">Sign in</button>
    <p class="os-alt">Have an invite code? <a href="/osint/register.php">Create your account</a></p>
  </form>
</body>
</html>
