<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$countFile = __DIR__ . '/visitor_count.txt';
$clickFile = __DIR__ . '/click_count.txt';
$activeDir = __DIR__ . '/active_visitors';
$mapFile = __DIR__ . '/visitor_locations.json';

$migrated = __DIR__ . '/counter_migrated.txt';
if (!file_exists($countFile) || !file_exists($migrated)) {
    file_put_contents($countFile, '46');
    file_put_contents($migrated, '1');
}

if (!file_exists($clickFile)) {
    file_put_contents($clickFile, '0');
}

if (!is_dir($activeDir)) {
    mkdir($activeDir, 0755, true);
}

if (!file_exists($mapFile)) {
    file_put_contents($mapFile, '[]');
}

$action = isset($_GET['action']) ? $_GET['action'] : 'visit';
$visitorId = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id']) : '';

$count = (int) file_get_contents($countFile);
$clicks = (int) file_get_contents($clickFile);

if ($action === 'visit') {
    $count++;
    file_put_contents($countFile, (string) $count);

    // Geolocate visitor IP and store
    $ip = $_SERVER['REMOTE_ADDR'];
    if ($ip !== '202.170.174.123') { // skip owner
        $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=lat,lon,country,city");
        if ($geo) {
            $geoData = json_decode($geo, true);
            if (isset($geoData['lat'])) {
                $locations = json_decode(file_get_contents($mapFile), true) ?: [];
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
                file_put_contents($mapFile, json_encode($locations));
            }
        }
    }
} elseif ($action === 'click') {
    $clicks++;
    file_put_contents($clickFile, (string) $clicks);
}

// Heartbeat
if ($visitorId !== '') {
    file_put_contents($activeDir . '/' . $visitorId, time());
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
        unlink($f);
    }
}

// Load locations for map
$locations = json_decode(file_get_contents($mapFile), true) ?: [];

echo json_encode([
    'count' => $count,
    'online' => $online,
    'clicks' => $clicks,
    'locations' => $locations
]);
