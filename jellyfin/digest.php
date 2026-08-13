<?php
// Weekly media-server digest → Discord. Run by a cron ON THE WEBSITE HOST (CLI =
// trusted, no secret) or hit over HTTP with the shared secret. Reads the latest
// snapshot + trend history and posts a one-embed summary. Composition lives in
// jf_digest_build() (testable); this file just gates access and sends.
require __DIR__ . '/../videos/lib/bootstrap.php';
require __DIR__ . '/lib.php';

$cli = (PHP_SAPI === 'cli');
$cfg = jf_config();
if (!$cli) {
    enforceRateLimit('jf_digest', 5, 60);
    $secret = is_array($cfg) ? (string) ($cfg['ingest_secret'] ?? '') : '';
    $sent = (string) ($_SERVER['HTTP_X_AGENT_SECRET'] ?? ($_GET['key'] ?? ''));
    if ($secret === '' || strlen($sent) < 16 || !hash_equals($secret, $sent)) { http_response_code(403); exit('forbidden'); }
}

$webhook = (string) ($cfg['alert_webhook'] ?? '');
if ($webhook === '') { if (!$cli) echo 'no webhook configured'; exit; }
$st = jf_stack_read();
if (!$st) { if (!$cli) echo 'no snapshot yet'; exit; }

jf_discord_alert($webhook, jf_digest_build($st));
if (!$cli) echo 'sent';
