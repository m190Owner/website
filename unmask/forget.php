<?php
// AJAX: delete the caller's own stored fingerprint by the delete token they were handed
// on reveal (kept in their localStorage). This is the "delete mine" button — the payoff
// of the whole point: your data is yours to remove.
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (rateLimited('unmask_forget', 40, 60)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate']); exit; }

$db = unmask_db();
if (!$db) { echo json_encode(['ok' => false, 'error' => 'store unavailable']); exit; }

$token = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_POST['token'] ?? '')));
if (strlen($token) < 8) { echo json_encode(['ok' => false, 'error' => 'bad token']); exit; }

try {
    $st = $db->prepare("DELETE FROM prints WHERE token = ?");
    $st->execute([$token]);
    echo json_encode(['ok' => true, 'deleted' => $st->rowCount()]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'delete failed']);
}
