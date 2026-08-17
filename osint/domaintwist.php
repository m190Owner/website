<?php
// AJAX: generate + resolve look-alike / typosquat domains (dnstwist-style) for one of
// the user's OWN profile domains. Gated + CSRF + rate-limited. JSON only. Returns the
// full result (also cached under a "twist:" key so a revisit renders instantly).
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_twist', 10, 60);
@set_time_limit(60);

$domain = scan_domain_normalize((string) ($_POST['domain'] ?? ''));
$p = scan_profile_get((int) $u['id']);
if ($domain === null || !in_array($domain, $p['domains'], true)) {
    echo json_encode(['ok' => false, 'error' => 'That domain is not on your profile.']); exit;
}
$data = scan_domain_twist($domain);
if (isset($data['error'])) { echo json_encode(['ok' => false, 'error' => $data['error']]); exit; }
scan_domain_cache_set((int) $u['id'], 'twist:' . $domain, $data);
echo json_encode($data);
