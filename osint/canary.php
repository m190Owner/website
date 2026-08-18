<?php
// AJAX for the Canaries tab: mint a canary token, trip/untrip/delete one, or reverse-look-up
// a pasted string against the user's canaries. Gated + CSRF + rate-limited, own-scoped.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_canary', 60, 60);

$action = (string) ($_POST['action'] ?? '');
if ($action === 'create') {
    $c = scan_canary_create((int) $u['id'], (string) ($_POST['label'] ?? ''), (string) ($_POST['note'] ?? ''));
    echo json_encode($c ? ['ok' => true, 'canary' => $c] : ['ok' => false, 'error' => 'Could not create (200-canary limit reached?).']);
    exit;
}
if ($action === 'update') {
    $ok = scan_canary_update((int) $u['id'], (int) ($_POST['id'] ?? 0), (string) ($_POST['op'] ?? ''), (string) ($_POST['note'] ?? ''));
    echo json_encode(['ok' => $ok]); exit;
}
if ($action === 'match') {
    $hits = scan_canary_match((int) $u['id'], (string) ($_POST['q'] ?? ''));
    echo json_encode(['ok' => true, 'hits' => array_map(fn($c) => ['id' => (int) $c['id'], 'label' => $c['label'], 'token' => $c['token'], 'created_at' => (int) $c['created_at'], 'tripped' => (int) $c['tripped']], $hits)]);
    exit;
}
echo json_encode(['ok' => false, 'error' => 'unknown action']);
