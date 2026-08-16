<?php
// Account creation for the /osint/ tool — ONLY by redeeming a valid invite code.
// Invites are minted from the owner console (owner/osint.php); there is no other
// way to create an account.
require __DIR__ . '/lib/osint_auth.php';

osint_session_start();
if (osint_current_user()) { header('Location: /osint/'); exit; }

$code = trim((string) ($_REQUEST['code'] ?? ''));
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    osint_csrf_require();
    enforceRateLimit('osint_register', 10, 600);
    $user = (string) ($_POST['username'] ?? '');
    $pw   = (string) ($_POST['password'] ?? '');
    $pw2  = (string) ($_POST['confirm'] ?? '');
    if ($pw !== $pw2) {
        $error = 'The passwords do not match.';
    } else {
        [$uid, $err] = osint_register_with_invite($code, $user, $pw);
        if ($uid) {
            osint_login($uid);
            audit_log('osint_register', 'crit', ['actor' => 'invited-user', 'target' => sanitizeHandle($user) ?? '',
                'detail' => 'New OSINT tool account created via invite', 'push' => true]);
            header('Location: /osint/'); exit;
        }
        $error = $err;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Create account · m190 finder</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/osint/assets/osint.css?v=<?= @filemtime(__DIR__ . '/assets/osint.css') ?: 1 ?>">
</head>
<body class="os-centered">
  <form class="os-card os-form" method="post" autocomplete="off">
    <div class="os-brand"><span class="os-mark">✷</span></div>
    <h1>Create your account</h1>
    <p class="os-lead">Enter your invite code and choose a username and password.</p>
    <?php if ($error): ?><div class="os-error"><?= ose($error) ?></div><?php endif; ?>
    <?= osint_csrf_field() ?>
    <label>Invite code
      <input type="text" name="code" required maxlength="64" value="<?= ose($code) ?>" <?= $code === '' ? 'autofocus' : '' ?>>
    </label>
    <label>Username
      <input type="text" name="username" required maxlength="16" <?= $code !== '' ? 'autofocus' : '' ?>>
      <span class="os-hint">3–16 letters, numbers, or underscore.</span>
    </label>
    <label>Password
      <input type="password" name="password" required minlength="8" maxlength="200">
      <span class="os-hint">At least 8 characters.</span>
    </label>
    <label>Confirm password
      <input type="password" name="confirm" required minlength="8" maxlength="200">
    </label>
    <button type="submit" class="os-btn os-btn-accent">Create account</button>
    <p class="os-alt">Already have an account? <a href="/osint/login.php">Sign in</a></p>
  </form>
</body>
</html>
