<?php
// PUBLIC tracking-pixel handler. /t/{id}.gif (rewritten from .htaccess) → record the
// trip, then always return a valid 1x1 transparent GIF — even for an unknown id — so
// it's indistinguishable from any other pixel and never reveals the canary system.
require __DIR__ . '/../owner/lib/tokens.php';

$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['t'] ?? ''));
if ($id !== '') token_record_hit($id, token_client_meta());   // best-effort, never throws

// No-cache so EVERY render phones home rather than being served from cache.
$gif = token_pixel_bytes();
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Length: ' . strlen($gif));
echo $gif;
