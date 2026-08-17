<?php
// AJAX: active subdomain enumeration (theHarvester/Amass-style) for one of the user's
// OWN profile domains — certificate transparency + live cert SANs + DNS brute of common
// labels, with wildcard detection. Gated + CSRF + rate-limited. JSON; result cached.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_subs', 10, 60);
@set_time_limit(60);

$domain = scan_domain_normalize((string) ($_POST['domain'] ?? ''));
$p = scan_profile_get((int) $u['id']);
if ($domain === null || !in_array($domain, $p['domains'], true)) {
    echo json_encode(['ok' => false, 'error' => 'That domain is not on your profile.']); exit;
}
$data = scan_subdomain_enum($domain);
if (isset($data['error'])) { echo json_encode(['ok' => false, 'error' => $data['error']]); exit; }
scan_domain_cache_set((int) $u['id'], 'subs:' . $domain, $data);
echo json_encode($data);
