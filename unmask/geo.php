<?php
// AJAX: resolve the caller's own IP -> geo (city / ISP / ASN / lat-lon / hosting guess).
// Server-side so the client never talks to a third party. Nothing stored here.
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (rateLimited('unmask_geo', 40, 60)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate']); exit; }

$ip = unmask_client_ip();
if (!unmask_is_public_ip($ip)) { echo json_encode(['ok' => true, 'local' => true, 'ip' => $ip, 'geo' => null]); exit; }

echo json_encode(['ok' => true, 'ip' => $ip, 'geo' => unmask_geo($ip)]);
