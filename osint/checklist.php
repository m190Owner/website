<?php
// AJAX endpoint for the removal + hardening checklists. Gated + CSRF + rate-limited.
// Sets one item's status (todo|started|done) for the signed-in user. JSON only.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_checklist', 200, 60);

$list   = (string) ($_POST['list'] ?? '');
$item   = (string) ($_POST['item'] ?? '');
$status = (string) ($_POST['status'] ?? '');
if (!in_array($list, ['brokers', 'harden'], true) || $item === '') {
    echo json_encode(['ok' => false, 'error' => 'bad request']); exit;
}
echo json_encode(['ok' => scan_checklist_set((int) $u['id'], $list, $item, $status)]);
