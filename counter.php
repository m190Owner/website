<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$countFile = __DIR__ . '/visitor_count.txt';
$activeDir = __DIR__ . '/active_visitors';

if (!file_exists($countFile)) {
    file_put_contents($countFile, '0');
}

if (!is_dir($activeDir)) {
    mkdir($activeDir, 0755, true);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'visit';
$visitorId = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id']) : '';

if ($action === 'visit') {
    // New visit — increment total count
    $count = (int) file_get_contents($countFile);
    $count++;
    file_put_contents($countFile, (string) $count);
} else {
    $count = (int) file_get_contents($countFile);
}

// Heartbeat — mark this visitor as active
if ($visitorId !== '') {
    file_put_contents($activeDir . '/' . $visitorId, time());
}

// Count active visitors (heartbeat within last 45 seconds)
$online = 0;
$now = time();
$files = glob($activeDir . '/*');
foreach ($files as $f) {
    $lastSeen = (int) file_get_contents($f);
    if ($now - $lastSeen < 45) {
        $online++;
    } else {
        unlink($f); // clean up stale entries
    }
}

echo json_encode(['count' => $count, 'online' => $online]);
