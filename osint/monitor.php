<?php
// AJAX: toggle breach monitoring on/off, or dismiss the "new exposure" alert. Gated +
// CSRF + rate-limited, scoped to the signed-in user.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_monitor', 40, 60);

$action = (string) ($_POST['action'] ?? '');
if ($action === 'toggle') {
    scan_monitor_set_enabled((int) $u['id'], ($_POST['on'] ?? '') === '1');
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'toggle_ct') {
    scan_ct_set_enabled((int) $u['id'], ($_POST['on'] ?? '') === '1');
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'dismiss') {
    scan_monitor_clear_pending((int) $u['id']);
    echo json_encode(['ok' => true]); exit;
}
if ($action === 'dismiss_ct') {
    scan_ct_clear_pending((int) $u['id']);
    echo json_encode(['ok' => true]); exit;
}
// Self-driving re-check: fired in the background from the dashboard so monitoring needs
// no external cron. Only actually runs once the last check is older than the interval.
// One sweep covers both breach monitoring and certificate-transparency monitoring.
if ($action === 'check') {
    $m = scan_monitor_get((int) $u['id']);
    $ct = scan_ct_get((int) $u['id']);
    if (!$m['enabled'] && !$ct['enabled']) { echo json_encode(['ok' => true, 'ran' => false]); exit; }
    if ($m['last_check'] > 0 && (time() - $m['last_check']) < OSINT_MONITOR_INTERVAL) {
        echo json_encode(['ok' => true, 'ran' => false, 'pending' => $m['pending'], 'ct_pending' => $ct['pending']]); exit;
    }
    $new   = $m['enabled'] ? scan_monitor_run((int) $u['id']) : 0;
    $ctNew = $ct['enabled'] ? scan_ct_run((int) $u['id']) : 0;
    // Make sure the shared sweep timestamp advances even if only CT ran.
    scan_db()->prepare("UPDATE osint_monitor SET last_check = ? WHERE user_id = ?")->execute([time(), (int) $u['id']]);
    echo json_encode(['ok' => true, 'ran' => true, 'new' => $new, 'pending' => scan_monitor_get((int) $u['id'])['pending'],
                      'ct_new' => $ctNew, 'ct_pending' => scan_ct_get((int) $u['id'])['pending']]); exit;
}
echo json_encode(['ok' => false, 'error' => 'unknown action']);
