<?php
// CSV export of a scan's findings — owner of the scan only.
require __DIR__ . '/lib/scan.php';
osint_require();
$u = osint_current_user();

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
