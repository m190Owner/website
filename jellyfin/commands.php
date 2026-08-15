<?php
// Agent-facing control-command endpoint. Machine-to-machine: authed by the shared
// agent secret (NOT the admin session), rate-limited. GET → the pending allowlisted
// commands for the agent to run; POST → the agent reporting a result. This endpoint
// NEVER executes anything — the agent re-validates + runs each action locally.
require __DIR__ . '/../videos/lib/bootstrap.php';
require __DIR__ . '/lib.php';
enforceRateLimit('jf_commands', 120, 60);

$cfg    = jf_config();
$secret = is_array($cfg) ? (string) ($cfg['ingest_secret'] ?? '') : '';
$sent   = (string) ($_SERVER['HTTP_X_AGENT_SECRET'] ?? '');
if ($secret === '' || strlen($sent) < 16 || !hash_equals($secret, $sent)) { http_response_code(403); exit('forbidden'); }

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
    $id = is_array($in) ? (string) ($in['id'] ?? '') : '';
    if ($id !== '') {
        $ok = !empty($in['ok']);
        $result = (string) ($in['result'] ?? '');
        if (jf_cmd_report($id, $ok, $result) && function_exists('audit_log')) {
            audit_log('media_command_result', $ok ? 'info' : 'warn',
                ['actor' => 'agent', 'ip' => '', 'detail' => ($ok ? '✅ ' : '❌ FAILED ') . mb_substr($result, 0, 150)]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => true, 'commands' => jf_cmd_claim_pending()]);
