<?php
require __DIR__ . '/../videos/lib/bootstrap.php';
require __DIR__ . '/lib.php';

$me = require_admin();     // owner only
$configured = jf_configured();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jellyfin · Logan Sandivar</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<link rel="stylesheet" href="/jellyfin/assets/dashboard.css?v=<?= @filemtime(__DIR__ . '/assets/dashboard.css') ?: 1 ?>">
</head>
<body>
<header class="jf-top">
  <div class="jf-top-l">
    <a href="/" class="jf-back" title="Back to site">&#8592;</a>
    <h1>🎬 Jellyfin</h1>
    <span class="jf-status<?= $configured ? '' : ' err' ?>" id="jf-conn"><span class="jf-dot"></span> <?= $configured ? 'connecting…' : 'not configured' ?></span>
  </div>
  <div class="jf-top-r">
    <span class="jf-dim">signed in as <?= e($me['username']) ?></span>
    <a href="https://logansandivar.duckdns.org" target="_blank" rel="noopener" class="jf-btn jf-btn-sm">Open Jellyfin ↗</a>
  </div>
</header>

<?php if (!$configured): ?>
<main class="jf-main">
  <div class="jf-setup">
    <h2>Almost there — add your Jellyfin key</h2>
    <p class="jf-dim">This dashboard needs a server URL and API key. They live in a gitignored config that never reaches the browser or the repo.</p>
    <ol>
      <li>In Jellyfin: <b>Dashboard → API Keys → +</b>, name it <code>website-dashboard</code>.</li>
      <li>On the host, copy <code>jellyfin/config.example.php</code> to <code>jellyfin/config.php</code> and fill in the URL + key.</li>
      <li>Reload this page.</li>
    </ol>
  </div>
</main>
<?php else: ?>
<main class="jf-main" id="jf-app">
  <section class="jf-stats" id="jf-stats">
    <div class="jf-stat"><b id="s-movies">—</b><span>movies</span></div>
    <div class="jf-stat"><b id="s-series">—</b><span>series</span></div>
    <div class="jf-stat"><b id="s-episodes">—</b><span>episodes</span></div>
    <div class="jf-stat"><b id="s-users">—</b><span>users</span></div>
    <div class="jf-stat jf-server"><b id="s-server">—</b><span id="s-version">Jellyfin</span></div>
  </section>

  <section class="jf-section">
    <div class="jf-sec-head"><h2>Now Playing</h2><span class="jf-dim" id="np-count"></span></div>
    <div class="jf-nowplaying" id="jf-nowplaying"><p class="jf-dim">Loading…</p></div>
  </section>

  <section class="jf-section">
    <div class="jf-sec-head"><h2>Media server stack</h2><span class="jf-dim" id="jf-stack-fresh"></span></div>
    <div class="jf-cards2"><div id="jf-vpn"></div><div id="jf-qbit"></div></div>
    <div class="jf-torrents" id="jf-torrents" style="display:none"></div>
    <div class="jf-disk" id="jf-disk"></div>
    <div class="jf-trends" id="jf-trends"></div>
    <div class="jf-stack-grid" id="jf-stack"><p class="jf-dim">Loading…</p></div>
  </section>

  <section class="jf-section" id="jf-req-sec" style="display:none">
    <div class="jf-sec-head"><h2>Media requests</h2><span class="jf-dim" id="jf-req-counts"></span></div>
    <div class="jf-requests" id="jf-requests"></div>
  </section>

  <section class="jf-section" id="jf-grabs-sec" style="display:none">
    <div class="jf-sec-head"><h2>Recent downloads</h2></div>
    <ul class="jf-activity" id="jf-grabs"></ul>
  </section>

  <div class="jf-grid2">
    <section class="jf-section">
      <div class="jf-sec-head"><h2>Recent activity</h2></div>
      <ul class="jf-activity" id="jf-activity"></ul>
    </section>
    <section class="jf-section">
      <div class="jf-sec-head"><h2>Users</h2></div>
      <ul class="jf-users" id="jf-users"></ul>
    </section>
  </div>

  <section class="jf-section jf-danger">
    <div class="jf-sec-head"><h2>Controls</h2></div>
    <div class="jf-controls">
      <button class="jf-btn" id="jf-scan">🔄 Scan libraries</button>
      <button class="jf-btn jf-btn-danger" id="jf-restart">⚠ Restart Jellyfin</button>
      <span class="jf-dim" id="jf-ctl-msg"></span>
    </div>
  </section>
</main>
<script src="/jellyfin/assets/dashboard.js?v=<?= @filemtime(__DIR__ . '/assets/dashboard.js') ?: 1 ?>"></script>
<?php endif; ?>
</body>
</html>
