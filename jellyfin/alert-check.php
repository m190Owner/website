<?php
// Staleness watchdog for the media-server status agent. Meant to be hit by a
// cron ON THE WEBSITE HOST (not the media box — that would die with it) every
// few minutes. If the agent has stopped reporting (stack.json gone stale), it
// posts a "server offline" alert to Discord, and a "back online" when reports
// resume. Edge-triggered via a small state flag so it fires once per transition.
require __DIR__ . '/../videos/lib/bootstrap.php';
require __DIR__ . '/lib.php';

// The host's PHP cron runs this file directly (CLI) — that's already trusted, so
// no secret is required there. Over HTTP (e.g. an uptime service), the secret is.
$cli = (PHP_SAPI === 'cli');
$cfg = jf_config();
if (!$cli) {
    enforceRateLimit('jf_alertcheck', 60, 60);
    $secret = is_array($cfg) ? (string) ($cfg['ingest_secret'] ?? '') : '';
    $sent = (string) ($_SERVER['HTTP_X_AGENT_SECRET'] ?? ($_GET['key'] ?? ''));
    if ($secret === '' || strlen($sent) < 16 || !hash_equals($secret, $sent)) { http_response_code(403); exit('forbidden'); }
}

$webhook = (string) ($cfg['alert_webhook'] ?? '');
if ($webhook === '') { if (!$cli) echo 'no webhook configured'; exit; }

$snap = jf_stack_read();
if (!$snap || empty($snap['storedAt'])) { if (!$cli) echo 'no snapshot yet'; exit; }   // never reported — nothing to judge

$staleSec = (int) (($cfg['stale_alert_sec'] ?? 0) ?: 300);
$age = (int) $snap['ageSec'];
$state = jf_alert_state_read();
$decision = jf_stale_decision($age, $staleSec, !empty($state['offline']));

if ($decision === 'offline') {
    $mins = max(1, (int) round($age / 60));
    jf_discord_alert($webhook, ['color' => 0xE5555F, 'title' => '🔴 Media server offline',
        'desc' => "No status update for **{$mins} min** — the media-server box or WSL may be down. Last report " . gmdate('H:i', (int) $snap['storedAt']) . ' UTC.']);
    jf_alert_state_write(['offline' => true, 'changedAt' => time()]);
    $out = 'alerted offline';
} elseif ($decision === 'online') {
    jf_discord_alert($webhook, ['color' => 0x43D17A, 'title' => '🟢 Media server back online',
        'desc' => "Status updates have resumed ({$age}s ago)."]);
    jf_alert_state_write(['offline' => false, 'changedAt' => time()]);
    $out = 'alerted online';
} else {
    $out = $age > $staleSec ? 'still offline' : 'ok';
}

// Over HTTP, always respond. Under the host's PHP cron, stay silent unless an
// alert actually fired, so cron doesn't email a status line every few minutes.
if (!$cli || $decision !== 'none') echo $out . "\n";
