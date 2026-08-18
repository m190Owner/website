<?php
// AJAX: map the internet-facing attack surface of one of the user's OWN profile domains —
// resolve the apex + any cached live subdomains to IPs, then pull open ports / known CVEs
// for each host from Shodan's keyless InternetDB. Gated + CSRF + rate-limited. JSON; cached.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_surface', 10, 60);
@set_time_limit(60);

$domain = scan_domain_normalize((string) ($_POST['domain'] ?? ''));
$p = scan_profile_get((int) $u['id']);
if ($domain === null || !in_array($domain, $p['domains'], true)) {
    echo json_encode(['ok' => false, 'error' => 'That domain is not on your profile.']); exit;
}
$data = scan_attack_surface((int) $u['id'], $domain);
if (isset($data['error'])) { echo json_encode(['ok' => false, 'error' => $data['error']]); exit; }
scan_domain_cache_set((int) $u['id'], 'surface:' . $domain, $data);
echo json_encode($data);
