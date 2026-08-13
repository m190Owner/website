<?php
// Manual / on-demand media-server digest → Discord. The AUTOMATIC weekly digest
// is sent by ingest.php (jf_digest_maybe_send) off the agent's heartbeat — no
// cron, nothing on the host. Hit this (CLI, or HTTP with the shared secret) only
// to send one NOW, e.g. to test. Composition lives in jf_digest_build().
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
