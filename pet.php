<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$file = __DIR__ . '/pet_data.json';

function getDefaultPet() {
    return [
        'name' => 'Byte',
        'hunger' => 80,
        'happiness' => 80,
        'energy' => 80,
        'x' => 0,
        'z' => 0,
        'lastUpdate' => time(),
        'lastFed' => time(),
        'lastPet' => time(),
        'lastPlayed' => time(),
        'totalInteractions' => 0,
    ];
}

if (!file_exists($file)) {
    file_put_contents($file, json_encode(getDefaultPet()));
}

$pet = json_decode(file_get_contents($file), true);
if (!$pet || !isset($pet['hunger'])) {
    $pet = getDefaultPet();
}

// Decay stats over time (1 point per 3 minutes)
$elapsed = time() - ($pet['lastUpdate'] ?? time());
$decay = floor($elapsed / 180);
if ($decay > 0) {
    $pet['hunger'] = max(0, ($pet['hunger'] ?? 80) - $decay);
    $pet['happiness'] = max(0, ($pet['happiness'] ?? 80) - $decay);
    $pet['energy'] = max(0, min(100, ($pet['energy'] ?? 80) + floor($decay * 0.5))); // energy recovers slowly
    $pet['lastUpdate'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = preg_replace('/[^a-z]/', '', $input['action'] ?? '');

    $cooldown = 3; // seconds between actions per type
    $now = time();

    switch ($action) {
        case 'feed':
            if ($now - ($pet['lastFed'] ?? 0) >= $cooldown) {
                $pet['hunger'] = min(100, $pet['hunger'] + 15);
                $pet['happiness'] = min(100, $pet['happiness'] + 3);
                $pet['lastFed'] = $now;
                $pet['totalInteractions'] = ($pet['totalInteractions'] ?? 0) + 1;
            }
            break;
        case 'pet':
            if ($now - ($pet['lastPet'] ?? 0) >= $cooldown) {
                $pet['happiness'] = min(100, $pet['happiness'] + 15);
                $pet['hunger'] = min(100, $pet['hunger'] + 2);
                $pet['lastPet'] = $now;
                $pet['totalInteractions'] = ($pet['totalInteractions'] ?? 0) + 1;
            }
            break;
        case 'play':
            if ($now - ($pet['lastPlayed'] ?? 0) >= $cooldown) {
                $pet['happiness'] = min(100, $pet['happiness'] + 10);
                $pet['energy'] = max(0, $pet['energy'] - 8);
                $pet['hunger'] = max(0, $pet['hunger'] - 5);
                $pet['lastPlayed'] = $now;
                $pet['totalInteractions'] = ($pet['totalInteractions'] ?? 0) + 1;
            }
            break;
        case 'move':
            // Pet AI position sync
            $pet['x'] = floatval($input['x'] ?? $pet['x']);
            $pet['z'] = floatval($input['z'] ?? $pet['z']);
            break;
    }

    $pet['lastUpdate'] = $now;
    file_put_contents($file, json_encode($pet));
    echo json_encode(['ok' => true, 'pet' => $pet]);
} else {
    file_put_contents($file, json_encode($pet));
    echo json_encode($pet);
}
