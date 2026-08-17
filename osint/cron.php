<?php
// Breach-monitoring cron. Run on a schedule to re-check every opted-in user's emails for
// NEW breaches (recorded and surfaced in-app on their next visit). No session: over HTTP
// it is gated by a secret token (?key=), and it also runs directly from a CLI cron.
//   HTTP:  wget -qO- "https://<site>/osint/cron.php?key=<token>"
//   CLI:   php /path/to/osint/cron.php
// The token is shown in the owner console (owner/osint.php) and stored, gitignored, in
// osint/data/cron.key.
require __DIR__ . '/lib/scan.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    if ($key === '' || !hash_equals(scan_cron_token(), $key)) { http_response_code(403); exit("forbidden\n"); }
    enforceRateLimit('osint_cron', 20, 60);
}

$users = scan_monitor_enabled_users();
$new = 0;
foreach ($users as $uid) $new += scan_monitor_run((int) $uid);

$ctUsers = scan_ct_enabled_users();
$ctNew = 0;
foreach ($ctUsers as $uid) $ctNew += scan_ct_run((int) $uid);

echo 'ok: breach — checked ' . count($users) . ' user(s), ' . $new . " new exposure(s); "
   . 'CT — checked ' . count($ctUsers) . ' user(s), ' . $ctNew . " new cert(s)\n";
