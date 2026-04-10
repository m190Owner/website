<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();
enforceRateLimit('counter', 60, 60);

$countFile = __DIR__ . '/visitor_count.txt';
$clickFile = __DIR__ . '/click_count.txt';
$activeDir = __DIR__ . '/active_visitors';
$mapFile = __DIR__ . '/visitor_locations.json';

$migrated = __DIR__ . '/counter_migrated.txt';
if (!file_exists($countFile) || !file_exists($migrated)) {
    file_put_contents($countFile, '46', LOCK_EX);
    file_put_contents($migrated, '1', LOCK_EX);
}

if (!file_exists($clickFile)) {
    file_put_contents($clickFile, '0', LOCK_EX);
}

if (!is_dir($activeDir)) {
    mkdir($activeDir, 0755, true);
}

if (!file_exists($mapFile)) {
    writeJsonFile($mapFile, []);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'visit';
$visitorId = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id']) : '';

$count = (int) file_get_contents($countFile);
$clicks = (int) file_get_contents($clickFile);

if ($action === 'visit') {
    $count++;
    file_put_contents($countFile, (string) $count, LOCK_EX);

    // Geolocate visitor IP and store
    $ip = $_SERVER['REMOTE_ADDR'];
    if (OWNER_IP === '' || $ip !== OWNER_IP) {
        $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=lat,lon,country,city");
        if ($geo) {
            $geoData = json_decode($geo, true);
            if (isset($geoData['lat'])) {
                $locations = readJsonFile($mapFile, []);
                $locations[] = [
                    'lat' => $geoData['lat'],
                    'lon' => $geoData['lon'],
                    'city' => $geoData['city'] ?? '',
                    'country' => $geoData['country'] ?? '',
                    'time' => time()
                ];
                // Keep last 200 entries
                if (count($locations) > 200) {
                    $locations = array_slice($locations, -200);
                }
                writeJsonFile($mapFile, $locations);
            }
        }
    }
} elseif ($action === 'click') {
    $clicks++;
    file_put_contents($clickFile, (string) $clicks, LOCK_EX);
}

// Heartbeat
if ($visitorId !== '') {
    file_put_contents($activeDir . '/' . $visitorId, time(), LOCK_EX);
}

// Count active visitors
$online = 0;
$now = time();
$files = glob($activeDir . '/*');
foreach ($files as $f) {
    $lastSeen = (int) file_get_contents($f);
    if ($now - $lastSeen < 45) {
        $online++;
    } else {
        @unlink($f);
    }
}

// Load locations for map
$locations = readJsonFile($mapFile, []);

echo json_encode([
    'count' => $count,
    'online' => $online,
    'clicks' => $clicks,
    'locations' => $locations
]);
