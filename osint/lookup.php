<?php
// AJAX: investigation lookups on public infrastructure data — a URL's redirect chain,
// an IP's geo/reputation, a domain's DNS, or a pasted certificate. Gated + CSRF +
// rate-limited. These operate on public network facts (not personal data).
require __DIR__ . '/lib/scan.php';
osint_require();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_lookup', 40, 60);

$action = (string) ($_POST['action'] ?? '');
$q = (string) ($_POST['q'] ?? '');

if ($action === 'url') { echo json_encode(scan_url_trace($q)); exit; }
if ($action === 'dns') { echo json_encode(scan_dns_all($q)); exit; }
if ($action === 'cert') { echo json_encode(scan_cert_pem($q)); exit; }
if ($action === 'ip') {
    $ip = trim($q);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) { echo json_encode(['error' => 'Not a valid IP address.']); exit; }
    $info = scan_ip_footprint($ip);
    if (empty($info['private'])) $info['ptr'] = scan_ptr($ip);
    $info['ok'] = !isset($info['error']);
    echo json_encode($info); exit;
}
echo json_encode(['error' => 'unknown action']);
