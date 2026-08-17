<?php
// AJAX: consolidated email intelligence (Mosint-style) for one of the user's OWN
// profile emails — deliverability + disposable/role/free class, domain spoofability,
// Gravatar, breach corpora, and registered-account signals. Gated + CSRF + rate-limited.
// JSON; result cached. Nothing is emailed to the address.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_emailintel', 15, 60);
@set_time_limit(45);

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$p = scan_profile_get((int) $u['id']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($email, $p['emails'], true)) {
    echo json_encode(['ok' => false, 'error' => 'That email is not on your profile.']); exit;
}
$data = scan_email_intel($email);
if (isset($data['error'])) { echo json_encode(['ok' => false, 'error' => $data['error']]); exit; }
scan_domain_cache_set((int) $u['id'], 'email:' . $email, $data);
echo json_encode($data);
