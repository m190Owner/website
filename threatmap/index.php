<?php require __DIR__ . '/../config.php'; ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Cyber Threat Map</title>
<meta name="description" content="A live globe of real-world malicious infrastructure — botnet command-and-control servers and top internet attackers, pulled from public threat-intel feeds.">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/threatmap/assets/threatmap.css?v=<?= @filemtime(__DIR__ . '/assets/threatmap.css') ?: 1 ?>">
</head>
<body>
<canvas id="globe"></canvas>

<a href="/" class="tm-back" title="Back to site">&#8592; logansandivar.com</a>

<header class="tm-head">
  <h1>Live cyber threat map</h1>
  <p class="tm-sub">Real malicious infrastructure from public threat-intel feeds — botnet C2 servers and top internet attackers, geolocated onto the globe.</p>
  <p class="tm-updated" id="tm-updated">loading feeds…</p>
</header>

<div class="tm-stats" id="tm-stats">
  <div class="tm-stat"><b id="tm-total">—</b><span>live sources</span></div>
  <div class="tm-stat tm-c2"><b id="tm-c2">—</b><span>botnet C2</span></div>
  <div class="tm-stat tm-atk"><b id="tm-atk">—</b><span>attackers</span></div>
</div>

<div class="tm-feed" id="tm-feed" aria-live="polite"></div>

<div class="tm-legend">
  <div class="tm-leg-row"><span class="tm-dot tm-dot-c2"></span> botnet command &amp; control</div>
  <div class="tm-leg-row"><span class="tm-dot tm-dot-atk"></span> scanning / attacking host</div>
  <div class="tm-leg-row"><span class="tm-dot tm-dot-tgt"></span> major target hub</div>
  <p class="tm-attr">Sources: <b>abuse.ch Feodo Tracker</b> + <b>SANS ISC DShield</b> (live, public). Points are real malicious IPs at their country location; target arcs are representative internet hubs. Drag to spin.</p>
</div>

<aside class="tm-panel" id="tm-panel" hidden>
  <button class="tm-panel-x" id="tm-panel-x" aria-label="Close">&times;</button>
  <div class="tm-panel-kind" id="tm-panel-kind">—</div>
  <table class="tm-panel-tbl" id="tm-panel-tbl"></table>
</aside>

<script src="/threatmap/assets/globe.js?v=<?= @filemtime(__DIR__ . '/assets/globe.js') ?: 1 ?>"></script>
</body>
</html>
