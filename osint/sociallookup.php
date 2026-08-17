<?php
// AJAX for the Social tab. Profile aggregation + impersonation are scoped to the user's
// OWN profile usernames; the Fediverse resolver and og:meta extractor take arbitrary
// input (public infra, like the Lookups tab). Gated + CSRF + rate-limited.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

header('Content-Type: application/json; charset=utf-8');
osint_csrf_require();
enforceRateLimit('osint_social', 30, 60);

$action = (string) ($_POST['action'] ?? '');
$q = (string) ($_POST['q'] ?? '');

if ($action === 'profile' || $action === 'impersonate') {
    $p = scan_profile_get((int) $u['id']);
    if (!in_array($q, $p['usernames'], true)) { echo json_encode(['error' => 'That username is not on your profile.']); exit; }
    echo json_encode($action === 'profile' ? scan_social_lookup($q) : scan_impersonation($q));
    exit;
}
if ($action === 'fediverse') { echo json_encode(scan_fediverse($q)); exit; }
if ($action === 'ogmeta')    { echo json_encode(scan_og_meta($q)); exit; }
echo json_encode(['error' => 'unknown action']);
