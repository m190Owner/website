<?php
require __DIR__.'/lib/audit.php';
require __DIR__.'/lib/broadcast.php';
if (!owner_is_configured() || !owner_is_authed()) bc_json(['error'=>'Sign in to the owner console.'], 401);
$token = owner_csrf_token();
session_write_close(); // uploads must not block Stop broadcast in another request
header('X-Content-Type-Options: nosniff');
enforceRateLimit('owner_broadcast', 240, 60);
try {
    $action = (string)($_GET['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
        bc_prune();
        bc_db()->prepare('UPDATE state SET active_until=? WHERE id=1')->execute([time()+30]);
        $state = bc_state();
        $state['commands'] = bc_db()->query('SELECT id,action,status,result FROM commands ORDER BY created DESC,rowid DESC LIMIT 12')->fetchAll();
        bc_json($state);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bc_json(['error'=>'Method not allowed.'], 405);
    if (!hash_equals($token, (string)($_SERVER['HTTP_X_M190_CSRF'] ?? ''))) bc_json(['error'=>'Refresh the page before sending commands.'], 403);
    if (!bc_state()['online']) throw new RuntimeException('The media-server controller is offline.');
    if ($action === 'upload-chunk') {
        $chunk = file_get_contents('php://input', false, null, 0, BC_CHUNK_BYTES+1);
        bc_json(['received'=>bc_upload_chunk((string)($_GET['id']??''), (int)($_GET['offset']??-1), $chunk)]);
    }
    $data = bc_input();
    if ($action === 'upload-begin') bc_json(['id'=>bc_upload_begin((string)($data['name']??'Clip'), (int)($data['size']??0))]);
    if ($action === 'upload-finish') bc_json(['commandId'=>bc_upload_finish((string)($data['id']??''))], 202);
    if (!in_array($action, ['interrupt','stop','restore'], true)) throw new RuntimeException('Unknown action.');
    $id = bc_enqueue($action, $data);
    audit_log('broadcast_command', 'info', ['actor'=>'owner','detail'=>'Queued '.$action,'push'=>false]);
    bc_json(['commandId'=>$id], 202);
} catch (Throwable $e) { bc_json(['error'=>$e instanceof RuntimeException ? $e->getMessage() : 'Broadcast service unavailable.'], 400); }
