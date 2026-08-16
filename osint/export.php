<?php
// Export of a user's own data: a scan's findings as CSV (default), or the full account
// as JSON (?format=json) — profile, scan history, latest findings, checklist progress,
// and domain footprints. Scoped to the signed-in user only.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

if (($_GET['format'] ?? '') === 'json') {
    $prof = scan_profile_get((int) $u['id']);
    $latest = scan_latest((int) $u['id']);
    $data = [
        'tool'          => 'm190 finder',
        'generated_at'  => date('c'),
        'account'       => $u['username'] ?? '',
        'profile'       => $prof,
        'scans'         => scan_history((int) $u['id'], 50),
        'latest_findings' => $latest ? scan_findings((int) $u['id'], (int) $latest['id']) : [],
        'checklists'    => [
            'brokers' => scan_checklist_get((int) $u['id'], 'brokers'),
            'harden'  => scan_checklist_get((int) $u['id'], 'harden'),
        ],
        'domains'       => [],
    ];
    foreach ($prof['domains'] as $d) { $c = scan_domain_cache_get((int) $u['id'], $d); if ($c) $data['domains'][$d] = $c; }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="m190-finder-export.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$scan = isset($_GET['scan']) ? scan_get((int) $u['id'], (int) $_GET['scan']) : scan_latest((int) $u['id']);
if (!$scan) { http_response_code(404); exit('No such scan.'); }
$findings = scan_findings((int) $u['id'], (int) $scan['id']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="footprint-scan-' . (int) $scan['id'] . '.csv"');
$out = fopen('php://output', 'w');
// Pass all args incl. $escape='' — silences the PHP 8.4 fputcsv() deprecation
// (which would otherwise print into the download) and yields RFC-clean CSV.
fputcsv($out, ['category', 'finding', 'url', 'exposes'], ',', '"', '');
foreach ($findings as $f) {
    fputcsv($out, [$f['category'], $f['title'], $f['url'], $f['exposes']], ',', '"', '');
}
fclose($out);
