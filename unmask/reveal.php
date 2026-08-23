<?php
// AJAX: store one fingerprint on the full reveal (implied consent) and return the
// uniqueness stat. Stores the fingerprint HASH + coarse buckets (city/ISP/GPU) + a
// per-record delete token — never the raw IP. Rows auto-expire after UNMASK_TTL.
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (rateLimited('unmask_reveal', 20, 60)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate']); exit; }

$db = unmask_db();
if (!$db) { echo json_encode(['ok' => false, 'error' => 'store unavailable']); exit; }

$fp  = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_POST['fp'] ?? '')));
$gpu = mb_substr(trim((string) ($_POST['gpu'] ?? '')), 0, 120);
if (strlen($fp) < 8) { echo json_encode(['ok' => false, 'error' => 'bad fingerprint']); exit; }

$geo   = unmask_geo(unmask_client_ip());   // authoritative, server-side
$token = bin2hex(random_bytes(16));

try {
    $db->prepare("DELETE FROM prints WHERE created_at < ?")->execute([time() - UNMASK_TTL]);
    $db->prepare("INSERT INTO prints (fp, token, city, region, country, isp, gpu, created_at) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$fp, $token, $geo['city'] ?? '', $geo['region'] ?? '', $geo['country'] ?? '', $geo['isp'] ?? '', $gpu, time()]);

    $total = (int) $db->query("SELECT COUNT(*) FROM prints")->fetchColumn();
    $uniq  = (int) $db->query("SELECT COUNT(DISTINCT fp) FROM prints")->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM prints WHERE fp = ?");
    $st->execute([$fp]);
    $twins = max(0, (int) $st->fetchColumn() - 1);   // others sharing your exact fingerprint
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'store failed']); exit;
}

echo json_encode([
    'ok'         => true,
    'token'      => $token,
    'visitor_no' => $total,
    'total'      => $total,
    'unique'     => $uniq,
    'twins'      => $twins,
]);
