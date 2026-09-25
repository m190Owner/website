<?php
require __DIR__.'/lib/owner_auth.php';
owner_require();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Broadcast Control · Owner Console</title><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="<?= oe(owner_csrf_token()) ?>"><link rel="stylesheet" href="/owner/assets/broadcast.css?v=1"></head>
<body><main><header><span class="brand">M190<span>TRANSMISSION CONTROL</span></span><a class="back" href="/owner/">← Owner console</a><span id="connection">CONNECTING</span></header>
<section class="hero"><p class="eyebrow">YOUR CHANNEL. YOUR INTERRUPTION.</p><h1>Take over the<br><em>broadcast.</em></h1><p class="intro">Choose one viewer. Drop in your M190 clip. Return to the movie.</p></section>
<div id="notice" role="status" aria-live="polite"></div>
<div class="layout"><section class="panel"><div class="section-title"><h2>On air</h2><button id="refresh" class="quiet">Refresh</button></div><div id="viewers"><p>Checking connected players…</p></div></section>
<aside class="panel"><p class="eyebrow">CHOOSE YOUR TRANSMISSION</p><h2>Clip library</h2><label for="clip-select">Clip to broadcast</label><select id="clip-select"></select><p class="note">Selected clip · <span id="duration">—</span> seconds</p><form id="upload-form"><label for="clip-file">Add a video</label><input id="clip-file" type="file" accept="video/*,.mkv" required><p class="note">1–90 seconds · up to 250 MB</p><button id="upload" class="quiet" type="submit">Add clip</button><p id="upload-status" class="note" role="status" aria-live="polite"></p></form><div class="steps"><p><b>01</b> Save and pause the movie</p><p><b>02</b> Play your selected clip</p><p><b>03</b> Restore the saved position</p></div><p class="note">Stop broadcast ends the interruption early and returns to the movie. Your normal pre-movie intro stays the same.</p><p class="note">Keep this controller running until the movie resumes. Test new players on your own session first.</p></aside></div>
<section class="panel history"><div class="section-title"><h2>Transmission log</h2><span>RECOVERY CHECKPOINTS</span></div><div id="commands"></div><div id="jobs"><p>No broadcasts yet.</p></div></section>
<footer>OWNER ACCESS ONLY <span>•</span> Manual broadcasts <span>•</span> No scheduled interruptions</footer></main><script src="/owner/assets/broadcast.js?v=1"></script></body></html>
