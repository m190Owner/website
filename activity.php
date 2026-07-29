<?php
// Serves the recent site-activity feed for the homepage ticker. Events are
// written server-side by each feature via activity_log() (see config.php).
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
setCorsHeaders();
enforceRateLimit('activity', 120, 60);

echo json_encode([
    'now'    => time(),
    'events' => array_reverse(activity_recent(40)), // newest first
]);
