<?php
// Owner-only Jellyfin proxy. Every call requires the site admin session; write
// actions additionally require a CSRF token. The browser sends an `action` from
// a fixed whitelist — never a URL/path — so this cannot be used as an open proxy.
require __DIR__ . '/../videos/lib/bootstrap.php';
require __DIR__ . '/lib.php';

// Owner 2FA session once the owner console is configured, else the videos admin.
if (owner_is_configured()) owner_require(); else require_admin();
enforceRateLimit('jf_api', 300, 60);

$action = $_REQUEST['action'] ?? '';

if (!jf_configured()) {
    json_out(['ok' => false, 'error' => 'Jellyfin dashboard is not configured yet (copy config.example.php to config.php and add your URL + API key).'], 503);
}

$sid = fn() => preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['session'] ?? ''));

switch ($action) {
    // ---- reads ----
    case 'overview':
        json_out(jf_overview());

    case 'sessions':
        json_out(['ok' => true, 'sessions' => jf_sessions()]);

    case 'stack':
        json_out(['ok' => true, 'stack' => jf_stack_read()]);

    case 'history':
        json_out(['ok' => true] + jf_history_view());

    case 'playback':
        json_out(['ok' => true, 'playback' => jf_playback_cached()]);

    // Poster/image proxy: GET so it can back an <img>. Read-only, admin-gated,
    // ids are sanitised, and only the Primary image is ever fetched.
    case 'image': {
        $item = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['item'] ?? ''));
        $tag  = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['tag'] ?? ''));
        if ($item === '') { http_response_code(400); exit; }
        $q = ['fillHeight' => 300, 'fillWidth' => 200, 'quality' => 90];
        if ($tag !== '') $q['tag'] = $tag;
        $r = jf_request('GET', "/Items/$item/Images/Primary", $q, null, true);
        if (!$r['ok'] || $r['raw'] === '') { http_response_code(404); exit; }
        header('Content-Type: ' . ($r['contentType'] ?: 'image/jpeg'));
        header('Cache-Control: private, max-age=600');
        echo $r['raw'];
        exit;
    }
}

// ---- writes (state-changing): admin + CSRF ----
$writes = ['stop', 'pause', 'unpause', 'message', 'scan', 'restart'];
if (in_array($action, $writes, true)) {
    if (owner_is_configured()) owner_csrf_require(); else csrf_require(true);
    enforceRateLimit('jf_write', 60, 60);

    switch ($action) {
        case 'stop':    case 'pause':    case 'unpause':
            $id = $sid();
            if ($id === '') json_out(['ok' => false, 'error' => 'No session.']);
            $cmd = ['stop' => 'Stop', 'pause' => 'Pause', 'unpause' => 'Unpause'][$action];
            $r = jf_post("/Sessions/$id/Playing/$cmd");
            break;
        case 'message':
            $id = $sid();
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($id === '') json_out(['ok' => false, 'error' => 'No session.']);
            if ($text === '') json_out(['ok' => false, 'error' => 'Message is empty.']);
            $r = jf_post("/Sessions/$id/Message", ['Header' => 'Message from admin', 'Text' => mb_substr($text, 0, 300), 'TimeoutMs' => 8000]);
            break;
        case 'scan':
            $r = jf_post('/Library/Refresh');
            break;
        case 'restart':
            $r = jf_post('/System/Restart');
            break;
    }
    if (!$r['ok']) json_out(['ok' => false, 'error' => 'Jellyfin returned HTTP ' . $r['status'] . ($r['error'] ? ' (' . $r['error'] . ')' : '')]);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
