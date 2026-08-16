<?php
// The signed-in user's own identifiers — the only things a scan searches for.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    osint_csrf_require();
    enforceRateLimit('osint_profile', 30, 60);
    $p = scan_profile_set((int) $u['id'], (array) ($_POST['username'] ?? []), (array) ($_POST['email'] ?? []));
    $saved = true;
} else {
    $p = scan_profile_get((int) $u['id']);
}
$usernames = array_pad($p['usernames'], OSINT_MAX_USERNAMES, '');
$emails    = array_pad($p['emails'], OSINT_MAX_EMAILS, '');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Your profile · m190 finder</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/osint/assets/osint.css?v=<?= @filemtime(__DIR__ . '/assets/osint.css') ?: 1 ?>">
</head>
<body>
<header class="os-top">
  <div class="os-top-l"><span class="os-mark">✷</span><b>m190 finder</b></div>
  <div class="os-top-r">
    <a class="os-btn os-btn-sm" href="/osint/">Dashboard</a>
    <span>signed in as <?= ose($u['username']) ?></span>
    <a class="os-btn os-btn-sm" href="/osint/logout.php">Sign out</a>
  </div>
</header>

<main class="os-main os-main-narrow">
  <?php if ($saved): ?><div class="os-ok">Profile saved. You can run a scan from the dashboard.</div><?php endif; ?>
  <div class="os-panel">
    <h2>Your identifiers</h2>
    <p>A scan only ever searches for what you put here — your own usernames and email addresses. Nothing else, and never someone else's.</p>
    <form method="post" class="os-form" autocomplete="off" style="margin-top:16px">
      <?= osint_csrf_field() ?>
      <div class="os-fieldgroup">
        <span class="os-grouplabel">Usernames <span class="os-dim">(up to <?= OSINT_MAX_USERNAMES ?>)</span></span>
        <?php foreach ($usernames as $v): ?>
          <input type="text" name="username[]" maxlength="40" value="<?= ose($v) ?>" placeholder="e.g. yourhandle">
        <?php endforeach; ?>
      </div>
      <div class="os-fieldgroup">
        <span class="os-grouplabel">Email addresses <span class="os-dim">(up to <?= OSINT_MAX_EMAILS ?>)</span></span>
        <?php foreach ($emails as $v): ?>
          <input type="email" name="email[]" maxlength="120" value="<?= ose($v) ?>" placeholder="you@example.com">
        <?php endforeach; ?>
      </div>
      <button type="submit" class="os-btn os-btn-accent">Save profile</button>
    </form>
  </div>
  <p class="os-fineprint">Stored only for your account, on this server, in a store that is never exposed to the web. Delete a value and save to remove it.</p>
</main>
</body>
</html>
