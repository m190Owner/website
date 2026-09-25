<?php
// Private relay storage. No media-server credentials or arbitrary URLs enter the browser.
const BC_MAX_BYTES = 250 * 1024 * 1024;
const BC_CHUNK_BYTES = 4 * 1024 * 1024;
function bc_db(): PDO {
    static $db;
    if ($db) return $db;
    $file = defined('BC_TEST_DB') ? BC_TEST_DB : __DIR__ . '/../data/broadcast.sqlite';
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS state (id INTEGER PRIMARY KEY, payload TEXT NOT NULL, received INTEGER NOT NULL, active_until INTEGER NOT NULL DEFAULT 0)');
    $db->exec("INSERT OR IGNORE INTO state VALUES (1, '{}', 0, 0)");
    $db->exec('CREATE TABLE IF NOT EXISTS commands (id TEXT PRIMARY KEY, action TEXT NOT NULL, args TEXT NOT NULL, status TEXT NOT NULL, created INTEGER NOT NULL, expires INTEGER NOT NULL, result TEXT NOT NULL DEFAULT \'\')');
    $db->exec('CREATE TABLE IF NOT EXISTS uploads (id TEXT PRIMARY KEY, name TEXT NOT NULL, size INTEGER NOT NULL, received INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL, created INTEGER NOT NULL)');
    return $db;
}
function bc_json(array $value, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function bc_input(int $limit = 4096): array {
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if (strlen($raw) > $limit) throw new RuntimeException('Request too large.');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Invalid request.');
    return $data;
}
function bc_validate(string $action, array $args): array {
    $fields = match ($action) { 'interrupt' => ['sessionId','itemId','clipId'], 'stop', 'restore' => ['id'], 'upload' => ['uploadId'], default => throw new RuntimeException('Unknown command.') };
    $clean = [];
    foreach ($fields as $key) {
        $value = $args[$key] ?? null;
        if (!is_string($value) || !preg_match('/\A[a-fA-F0-9-]{16,64}\z/', $value)) throw new RuntimeException('Invalid command target.');
        $clean[$key] = $value;
    }
    return $clean;
}
function bc_prune(): void {
    $db = bc_db(); $now = time();
    $db->prepare("UPDATE commands SET status='expired', result='Command expired; no automatic retry.' WHERE status='queued' AND expires < ?")->execute([$now]);
    $db->prepare("UPDATE commands SET status='uncertain', result='No acknowledgement received. Check playback before retrying.' WHERE status='claimed' AND created < ?")->execute([$now - 600]);
    $db->prepare('DELETE FROM commands WHERE created < ?')->execute([$now - 86400]);
    $q = $db->prepare('SELECT id FROM uploads WHERE created < ?'); $q->execute([$now - 3600]);
    foreach ($q as $row) { @unlink(bc_upload_path($row['id'])); }
    $db->prepare('DELETE FROM uploads WHERE created < ?')->execute([$now - 3600]);
}
function bc_state(): array {
    $row = bc_db()->query('SELECT * FROM state WHERE id=1')->fetch();
    $data = json_decode($row['payload'], true) ?: [];
    $age = max(0, time() - (int)$row['received']);
    return ['sessions' => $data['sessions'] ?? [], 'jobs' => $data['jobs'] ?? [], 'clips' => $data['clips'] ?? [], 'uploading' => !empty($data['uploading']), 'online' => $age < 25 && empty($data['error']), 'ageSec' => $age, 'error' => $data['error'] ?? null];
}
function bc_enqueue(string $action, array $args): string {
    $args = bc_validate($action, $args); $db = bc_db();
    if ((int)$db->query("SELECT COUNT(*) FROM commands WHERE status='queued'")->fetchColumn() >= 20) throw new RuntimeException('Too many pending commands.');
    $id = bin2hex(random_bytes(16));
    $ttl = $action === 'upload' ? 600 : 20;
    $db->prepare('INSERT INTO commands (id,action,args,status,created,expires) VALUES (?,?,?,\'queued\',?,?)')->execute([$id, $action, json_encode($args), time(), time()+$ttl]);
    return $id;
}
function bc_exchange(array $snapshot, array $results): array {
    $db = bc_db(); $db->exec('BEGIN IMMEDIATE');
    try {
        bc_prune();
        // Persist only display data, never agent credentials or local CSRF tokens.
        $safe = array_intersect_key($snapshot, array_flip(['sessions','jobs','clips','uploading','error']));
        $db->prepare('UPDATE state SET payload=?,received=? WHERE id=1')->execute([json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE), time()]);
        $acked = [];
        foreach (array_slice($results, 0, 50) as $result) {
            $id = (string)($result['id'] ?? '');
            if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) continue;
            $status = !empty($result['ok']) ? 'done' : 'failed';
            $db->prepare("UPDATE commands SET status=?,result=? WHERE id=? AND status IN ('claimed','uncertain')")->execute([$status, substr((string)($result['message'] ?? ''), 0, 500), $id]);
            $acked[] = $id;
            $q = $db->prepare("SELECT args FROM commands WHERE id=? AND action='upload' AND status IN ('done','failed')"); $q->execute([$id]);
            if ($args = $q->fetchColumn()) {
                $upload = json_decode($args, true)['uploadId'];
                @unlink(bc_upload_path($upload));
                $db->prepare('DELETE FROM uploads WHERE id=?')->execute([$upload]);
            }
        }
        // At most once delivery: ambiguous network failures are never replayed into a viewer's player.
        $commands = $db->query("SELECT * FROM commands WHERE status='queued' ORDER BY created,rowid LIMIT 10")->fetchAll();
        foreach ($commands as &$c) {
            $c['args'] = json_decode($c['args'], true);
            $db->prepare("UPDATE commands SET status='claimed' WHERE id=?")->execute([$c['id']]);
        }
        unset($c);
        $active = (int)$db->query('SELECT active_until FROM state WHERE id=1')->fetchColumn() > time();
        $db->exec('COMMIT');
        return ['commands' => $commands, 'acked' => $acked, 'pollSeconds' => $active ? 2 : 15];
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function bc_upload_path(string $id): string {
    if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) throw new RuntimeException('Invalid upload ID.');
    return (defined('BC_TEST_UPLOAD_DIR') ? BC_TEST_UPLOAD_DIR : __DIR__.'/../data') . '/broadcast-upload-' . $id . '.bin';
}
function bc_upload_begin(string $name, int $size): string {
    if ($size < 1 || $size > BC_MAX_BYTES) throw new RuntimeException('Choose a clip smaller than 250 MB.');
    bc_prune(); $db = bc_db(); $db->exec('BEGIN IMMEDIATE');
    try {
        if ((int)$db->query('SELECT COALESCE(SUM(size),0) FROM uploads')->fetchColumn() + $size > 500*1024*1024) throw new RuntimeException('Upload staging is full. Wait for existing clips to finish.');
        $id = bin2hex(random_bytes(16));
        $name = substr(preg_replace('/[\x00-\x1f]/', '', $name), 0, 120);
        $db->prepare('INSERT INTO uploads (id,name,size,status,created) VALUES (?,?,?,\'receiving\',?)')->execute([$id, $name, $size, time()]);
        $db->exec('COMMIT'); return $id;
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function bc_upload_chunk(string $id, int $offset, string $chunk): int {
    $path = bc_upload_path($id); $db = bc_db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $q = $db->prepare('SELECT * FROM uploads WHERE id=?'); $q->execute([$id]); $row = $q->fetch();
        $size = strlen($chunk);
        if (!$row || $row['status'] !== 'receiving' || $offset !== (int)$row['received'] || $size < 1 || $size > BC_CHUNK_BYTES || $offset+$size > $row['size']) throw new RuntimeException('Upload chunk rejected. Please start the upload again.');
        $handle = fopen($path, 'c+b');
        if (!$handle) throw new RuntimeException('Could not store the upload.');
        try {
            if (!ftruncate($handle, $offset) || fseek($handle, $offset) !== 0 || fwrite($handle, $chunk) !== $size) throw new RuntimeException('Upload storage failed.');
        } finally { fclose($handle); }
        $db->prepare('UPDATE uploads SET received=? WHERE id=?')->execute([$offset+$size, $id]);
        $db->exec('COMMIT'); return $offset+$size;
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function bc_upload_finish(string $id): string {
    $path = bc_upload_path($id); $db = bc_db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $q = $db->prepare('SELECT * FROM uploads WHERE id=?'); $q->execute([$id]); $r = $q->fetch();
        if (!$r || $r['status'] !== 'receiving' || $r['received'] !== $r['size'] || !is_file($path) || filesize($path) !== (int)$r['size']) throw new RuntimeException('Upload is incomplete.');
        $db->prepare("UPDATE uploads SET status='ready' WHERE id=?")->execute([$id]);
        $command = bc_enqueue('upload', ['uploadId' => $id]);
        $db->exec('COMMIT'); return $command;
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
