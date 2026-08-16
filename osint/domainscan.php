<?php
// AJAX: run a live domain-footprint lookup for one of the user's OWN profile domains
// and cache it. Gated + CSRF + rate-limited. JSON only. The page reloads to render
// the freshly cached result.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_domain', 20, 60);

$domain = scan_domain_normalize((string) ($_POST['domain'] ?? ''));
$p = scan_profile_get((int) $u['id']);
if ($domain === null || !in_array($domain, $p['domains'], true)) {
    echo json_encode(['ok' => false, 'error' => 'That domain is not on your profile.']); exit;
}
$data = scan_domain_lookup($domain);
if (isset($data['error'])) { echo json_encode(['ok' => false, 'error' => $data['error']]); exit; }
scan_domain_cache_set((int) $u['id'], $domain, $data);
echo json_encode(['ok' => true, 'domain' => $domain]);
