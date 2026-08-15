<?php
// Public threat feed for the globe. Serves the rolling cache as JSON; refreshes it
// from the live sources at most once every TM_REFRESH_SEC (see lib.php). No secrets,
// no user data — just aggregated public threat-intel. Rate-limited per IP.
require __DIR__ . '/../config.php';
require __DIR__ . '/lib.php';

enforceRateLimit('threatmap_feed', 60, 60);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Cache-Control: public, max-age=60');

echo json_encode(tm_view());
