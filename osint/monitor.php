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
if ($action === 'dismiss') {
    scan_monitor_clear_pending((int) $u['id']);
    echo json_encode(['ok' => true]); exit;
}
echo json_encode(['ok' => false, 'error' => 'unknown action']);
