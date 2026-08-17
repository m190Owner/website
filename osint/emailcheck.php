<?php
// AJAX: email deliverability + disposable/role check. Keyless (DoH MX lookup + a bundled
// disposable-domain list). Gated + CSRF + rate-limited. Nothing is stored.
require __DIR__ . '/lib/scan.php';
osint_require();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_emailcheck', 40, 60);

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok' => false, 'error' => 'Not a valid email address.']); exit; }
[$local, $domain] = explode('@', $email, 2);

$res = scan_multi_get(['mx' => ['url' => 'https://dns.google/resolve?name=' . rawurlencode($domain) . '&type=MX', 'headers' => ['User-Agent: ' . OSINT_UA, 'Accept: application/dns-json'], 'follow' => true]]);
$mxHosts = array_values(array_filter(array_map(function ($m) { $p = preg_split('/\s+/', trim($m)); return rtrim((string) end($p), '.'); }, scan_doh_answers($res['mx'] ?? null, 15))));

$disp = json_decode((string) @file_get_contents(__DIR__ . '/assets/disposable.json'), true);
$dispSet = is_array($disp) ? array_flip($disp) : [];

$roles = ['admin', 'administrator', 'info', 'support', 'sales', 'contact', 'help', 'billing', 'noreply', 'no-reply',
          'postmaster', 'webmaster', 'abuse', 'office', 'hello', 'team', 'marketing', 'hr', 'jobs', 'careers',
          'service', 'security', 'root', 'mail', 'newsletter', 'notifications'];

echo json_encode([
    'ok'         => true,
    'domain'     => $domain,
    'mx'         => !empty($mxHosts),
    'mx_hosts'   => array_slice($mxHosts, 0, 4),
    'disposable' => isset($dispSet[$domain]),
    'role'       => in_array($local, $roles, true),
]);
