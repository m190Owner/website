<?php
// AJAX driver for the incremental scan. The browser POSTs action=start once, then
// action=chunk repeatedly until status=done. Gated + CSRF + rate-limited. Returns
// JSON only.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_scan', 300, 60);

$action = (string) ($_POST['action'] ?? '');
if ($action === 'start') {
    [$scan, $err] = scan_start((int) $u['id']);
    echo json_encode($scan ? (['ok' => true] + $scan) : ['ok' => false, 'error' => $err]);
    exit;
}
if ($action === 'chunk') {
    echo json_encode(scan_chunk((int) $u['id'], (int) ($_POST['scan_id'] ?? 0)));
    exit;
}
echo json_encode(['ok' => false, 'error' => 'unknown action']);
