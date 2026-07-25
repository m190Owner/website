<?php
// Single POST endpoint for all state-changing actions. Every action is CSRF-
// protected and re-checks its own authorization. Forms post here with an
// `action` field and a `back` field (where to return); we redirect back so the
// whole thing works without JavaScript.

require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('POST only.');
}
csrf_require();

$action = $_POST['action'] ?? '';
$back   = $_POST['back'] ?? '/videos/';
if (!is_string($back) || !str_starts_with($back, '/videos/')) $back = '/videos/';

$db = videos_db();

switch ($action) {

    case 'vote': {
        $u = require_login();
        $vid = $_POST['video_id'] ?? '';
        $val = (int) ($_POST['value'] ?? 0);
        if ($val !== 1 && $val !== -1) redirect($back);
        if (!video_exists($db, $vid)) redirect($back);

        // Toggle: clicking the same vote again removes it.
        $cur = $db->prepare("SELECT value FROM votes WHERE video_id = ? AND user_id = ?");
        $cur->execute([$vid, $u['id']]);
        $existing = $cur->fetchColumn();
        if ($existing !== false && (int) $existing === $val) {
            $db->prepare("DELETE FROM votes WHERE video_id = ? AND user_id = ?")->execute([$vid, $u['id']]);
        } else {
            $db->prepare(
                "INSERT INTO votes (video_id, user_id, value) VALUES (?, ?, ?)
                 ON CONFLICT(video_id, user_id) DO UPDATE SET value = excluded.value"
            )->execute([$vid, $u['id'], $val]);
        }
        redirect($back);
    }

    case 'subscribe':
    case 'unsubscribe': {
        $u = require_login();
        $channel = (int) ($_POST['channel_id'] ?? 0);
        if ($channel <= 0 || $channel === (int) $u['id']) redirect($back);
        if ($action === 'subscribe') {
            $db->prepare(
                "INSERT OR IGNORE INTO subscriptions (subscriber_id, channel_id, created_at) VALUES (?, ?, ?)"
            )->execute([$u['id'], $channel, time()]);
        } else {
            $db->prepare("DELETE FROM subscriptions WHERE subscriber_id = ? AND channel_id = ?")
               ->execute([$u['id'], $channel]);
        }
        redirect($back);
    }

    case 'comment': {
        $u = require_login();
        if ((int) $u['is_muted'] === 1) redirect(add_flash($back . '#comments', 'Your account is suspended from posting.'));
        enforceRateLimit('videos_comment', 15, 60);
        $vid  = $_POST['video_id'] ?? '';
        $body = trim($_POST['body'] ?? '');
        if (!video_exists($db, $vid) || $body === '') redirect($back);
        if (mb_strlen($body) > COMMENT_MAX)  redirect($back . '#comments');
        if (containsProfanity($body)) redirect($back . '#comments');
        $db->prepare(
            "INSERT INTO comments (video_id, user_id, body, created_at) VALUES (?, ?, ?, ?)"
        )->execute([$vid, $u['id'], $body, time()]);
        redirect($back . '#comments');
    }

    case 'report': {
        $u = require_login();
        enforceRateLimit('videos_report', 20, 3600);
        $type = ($_POST['target_type'] ?? '') === 'comment' ? 'comment' : 'video';
        $tid  = $_POST['target_id'] ?? '';
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, REPORT_REASON_MAX);
        if ($tid === '') redirect($back);
        $db->prepare(
            "INSERT INTO reports (target_type, target_id, reporter_id, reason, created_at)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$type, $tid, $u['id'], $reason, time()]);
        redirect(add_flash($back, 'Reported. Thanks — we\'ll take a look.'));
    }

    case 'delete_video': {
        $u = require_login();
        $vid = $_POST['video_id'] ?? '';
        $st = $db->prepare("SELECT * FROM videos WHERE id = ?");
        $st->execute([$vid]);
        $v = $st->fetch();
        if (!$v) redirect($back);
        if ((int) $v['user_id'] !== (int) $u['id'] && empty($u['is_admin'])) {
            http_response_code(403); exit('Forbidden.');
        }
        remove_video_files($v);
        $db->prepare("UPDATE videos SET status = 'removed' WHERE id = ?")->execute([$vid]);
        $db->prepare("UPDATE reports SET resolved = 1 WHERE target_type = 'video' AND target_id = ?")->execute([$vid]);
        redirect(str_contains($back, 'watch.php') ? '/videos/' : $back);
    }

    case 'delete_comment': {
        $u = require_login();
        $cid = (int) ($_POST['comment_id'] ?? 0);
        $st = $db->prepare(
            "SELECT c.*, v.user_id AS video_owner FROM comments c
               JOIN videos v ON v.id = c.video_id WHERE c.id = ?"
        );
        $st->execute([$cid]);
        $c = $st->fetch();
        if (!$c) redirect($back);
        $canDelete = (int) $c['user_id'] === (int) $u['id']
                  || (int) $c['video_owner'] === (int) $u['id']
                  || !empty($u['is_admin']);
        if (!$canDelete) { http_response_code(403); exit('Forbidden.'); }
        $db->prepare("UPDATE comments SET status = 'removed' WHERE id = ?")->execute([$cid]);
        $db->prepare("UPDATE reports SET resolved = 1 WHERE target_type = 'comment' AND target_id = ?")->execute([(string) $cid]);
        redirect($back . '#comments');
    }

    case 'ban':
    case 'unban': {
        require_admin();
        $uid = (int) ($_POST['user_id'] ?? 0);
        $db->prepare("UPDATE users SET is_banned = ? WHERE id = ? AND is_admin = 0")
           ->execute([$action === 'ban' ? 1 : 0, $uid]);
        redirect($back);
    }

    case 'resolve_report': {
        require_admin();
        $rid = (int) ($_POST['report_id'] ?? 0);
        $db->prepare("UPDATE reports SET resolved = 1 WHERE id = ?")->execute([$rid]);
        redirect($back);
    }

    case 'warn': {
        $me = require_admin();
        $uid = (int) ($_POST['user_id'] ?? 0);
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, REPORT_REASON_MAX);
        // Never warn an admin/owner or a nonexistent user.
        $chk = $db->prepare("SELECT 1 FROM users WHERE id = ? AND is_admin = 0");
        $chk->execute([$uid]);
        if ($chk->fetch()) {
            $db->prepare("INSERT INTO warnings (user_id, issued_by, reason, created_at) VALUES (?, ?, ?, ?)")
               ->execute([$uid, $me['id'], $reason, time()]);
            // Auto-ban once the warning count reaches the threshold.
            $cnt = (int) $db->query("SELECT COUNT(*) FROM warnings WHERE user_id = " . $uid)->fetchColumn();
            if ($cnt >= WARN_BAN_THRESHOLD) {
                $db->prepare("UPDATE users SET is_banned = 1 WHERE id = ?")->execute([$uid]);
            }
        }
        redirect($back);
    }

    case 'mute':
    case 'unmute': {
        require_admin();
        $uid = (int) ($_POST['user_id'] ?? 0);
        $db->prepare("UPDATE users SET is_muted = ? WHERE id = ? AND is_admin = 0")
           ->execute([$action === 'mute' ? 1 : 0, $uid]);
        redirect($back);
    }

    case 'delete_user_videos': {
        require_admin();
        $uid = (int) ($_POST['user_id'] ?? 0);
        $vs = $db->prepare("SELECT * FROM videos WHERE user_id = ? AND status = 'live'");
        $vs->execute([$uid]);
        foreach ($vs->fetchAll() as $v) remove_video_files($v);
        $db->prepare("UPDATE videos SET status = 'removed' WHERE user_id = ?")->execute([$uid]);
        redirect($back);
    }

    case 'ack_warning': {
        $u = require_login();
        $db->prepare("UPDATE warnings SET acknowledged = 1 WHERE user_id = ?")->execute([$u['id']]);
        redirect($back);
    }

    default:
        redirect($back);
}

// ---- helpers ----
function video_exists(PDO $db, string $id): bool {
    $st = $db->prepare("SELECT 1 FROM videos WHERE id = ? AND status = 'live'");
    $st->execute([$id]);
    return (bool) $st->fetch();
}

function remove_video_files(array $v): void {
    if (!empty($v['filename'])) @unlink(VIDEOS_MEDIA_DIR . '/' . basename($v['filename']));
    if (!empty($v['thumb']))    @unlink(VIDEOS_THUMB_DIR . '/' . basename($v['thumb']));
}

function add_flash(string $url, string $msg): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . 'flash=' . urlencode($msg);
}
