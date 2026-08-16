<?php
// Gated landing for the /osint/ removal tool. Everything under /osint/ that should
// require a signed-in invited user starts with these two lines. The actual tool
// (your PHP app) drops into the main area below / replaces this file.
require __DIR__ . '/lib/osint_auth.php';
osint_require();
$u = osint_current_user();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Removal Tool</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/osint/assets/osint.css?v=<?= @filemtime(__DIR__ . '/assets/osint.css') ?: 1 ?>">
</head>
<body>
<header class="os-top">
  <div class="os-top-l"><span class="os-mark">✷</span><b>Removal tool</b></div>
  <div class="os-top-r">
    <span>signed in as <?= ose($u['username']) ?></span>
    <a class="os-btn os-btn-sm" href="/osint/logout.php">Sign out</a>
  </div>
</header>

<main class="os-main">
  <div class="os-panel">
    <h2>Welcome, <?= ose($u['username']) ?></h2>
    <p>This is your private removal workspace. Anything you enter here stays within this account.</p>
  </div>

  <div class="os-panel">
    <div class="os-placeholder">
      The removal tool mounts here.<br>Drop your PHP app into <code>/osint/</code> and gate its entry
      points with <code>require __DIR__.'/lib/osint_auth.php'; osint_require();</code>
    </div>
  </div>
</main>
</body>
</html>
