<?php
// AJAX: set the triage status on one of the signed-in user's findings. Gated + CSRF
// + rate-limited. JSON only.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_finding', 200, 60);

$fid    = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
$ok = $fid > 0 && scan_set_finding_status((int) $u['id'], $fid, $status);
echo json_encode(['ok' => $ok, 'status' => $status]);
