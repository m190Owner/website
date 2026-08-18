<?php
// AJAX: exposed-services (open ports / known CVEs) for the CALLER's OWN public IP, via
// Shodan's keyless InternetDB. On-demand so it never blocks the network-page load. The
// IP is determined server-side (os_client_ip) — the client never supplies it. Gated +
// CSRF + rate-limited. Nothing is stored.
require __DIR__ . '/lib/scan.php';
osint_require();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_ipservices', 20, 60);

$ip = os_client_ip();
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    echo json_encode(['ok' => true, 'private' => true, 'ip' => $ip]); exit;
}
echo json_encode(['ok' => true, 'ip' => $ip, 'services' => scan_internetdb($ip)]);
