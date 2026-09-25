<?php
// Dedicated outbound relay for the existing media server; no browser/owner-session access.
require __DIR__.'/../config.php';
require __DIR__.'/lib.php';
require __DIR__.'/../owner/lib/broadcast.php';
$secret = (string)(jf_config()['ingest_secret'] ?? '');
$sent = (string)($_SERVER['HTTP_X_AGENT_SECRET'] ?? '');
if (strlen($secret)<16 || !hash_equals($secret,$sent)) bc_json(['error'=>'Forbidden.'],403);
enforceRateLimit('broadcast_agent', 120, 60);
try {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $id = (string)($_GET['upload']??''); $path = bc_upload_path($id);
        $q=bc_db()->prepare("SELECT * FROM uploads WHERE id=? AND status='ready'"); $q->execute([$id]); $upload=$q->fetch();
        if (!$upload || !is_file($path)) bc_json(['error'=>'Upload not found.'],404);
        header('Content-Type: application/octet-stream'); header('Cache-Control: no-store');
        header('Content-Length: '.filesize($path)); header('X-Clip-Name: '.rawurlencode($upload['name']));
        readfile($path); exit;
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') bc_json(['error'=>'Method not allowed.'],405);
    $data=bc_input(256*1024);
    if (!is_array($data['snapshot']??null) || !is_array($data['results']??null)) throw new RuntimeException('Invalid snapshot.');
    bc_json(bc_exchange($data['snapshot'], $data['results']));
} catch (Throwable $e) { bc_json(['error'=>'Broadcast relay unavailable.'],400); }
