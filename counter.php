<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$file = __DIR__ . '/visitor_count.txt';

if (!file_exists($file)) {
    file_put_contents($file, '0');
}

$count = (int) file_get_contents($file);
$count++;
file_put_contents($file, (string) $count);

echo json_encode(['count' => $count]);
