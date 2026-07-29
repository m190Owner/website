<?php
require __DIR__ . '/lib/bootstrap.php';

$u = require_login();
$error = '';

$isXhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $error = handle_upload($u, $isXhr);   // returns only on error
    if ($isXhr) json_out(['ok' => false, 'error' => $error], 400);
}

/** Returns an error string; on success it redirects/JSONs and never returns. */
function handle_upload(array $u, bool $isXhr): string {
    csrf_require($isXhr);
    if ((int) $u['is_muted'] === 1) return 'Your account is suspended from posting.';
    enforceRateLimit('videos_upload_ip', 20, 3600);

    // Per-user daily cap.
    $st = videos_db()->prepare(
        "SELECT COUNT(*) FROM videos WHERE user_id = ? AND created_at > ?"
    );
    $st->execute([$u['id'], time() - 86400]);
    if ((int) $st->fetchColumn() >= VIDEO_UPLOADS_PER_DAY) {
        return 'Daily upload limit reached. Try again tomorrow.';
    }

    // File present + no PHP-level upload error.
    $f = $_FILES['video'] ?? null;
    if (!$f || !is_uploaded_file($f['tmp_name'] ?? '') || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return 'That file is too large.';
        }
        return 'No video was received.';
    }

    // Size caps.
    $size = (int) $f['size'];
    if ($size <= 0)                 return 'That file is empty.';
    if ($size > VIDEO_MAX_BYTES)    return 'Video exceeds the ' . human_size(VIDEO_MAX_BYTES) . ' limit.';

    // Global storage ceiling (protects the host).
    if (dir_size_bytes(VIDEOS_MEDIA_DIR) + $size > VIDEO_GLOBAL_CAP_BYTES) {
        return 'The video library is full right now. Please try again later.';
    }

    // Magic-byte MIME sniff — the client-sent type and original name are ignored.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    if (!isset(VIDEO_MIME_EXT[$mime])) {
        return 'Only MP4 or WebM videos are accepted.';
    }
    $ext = VIDEO_MIME_EXT[$mime];

    // Text fields.
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    if ($title === '')                 return 'Give your video a title.';
    if (mb_strlen($title) > TITLE_MAX) return 'Title is too long.';
    if (mb_strlen($desc)  > DESC_MAX)  return 'Description is too long.';
    if (containsProfanity($title) || containsProfanity($desc)) {
        return 'Please remove profanity from the title or description.';
    }

    $duration = max(0, min(VIDEO_MAX_DURATION_SEC + 1, (int) ($_POST['duration'] ?? 0)));
    if ($duration > VIDEO_MAX_DURATION_SEC) {
        return 'Videos are limited to ' . fmt_duration(VIDEO_MAX_DURATION_SEC) . '.';
    }

    // Unique slug + safe filename (never derived from user input).
    $db = videos_db();
    do {
        $id = random_slug(11);
        $chk = $db->prepare("SELECT 1 FROM videos WHERE id = ?");
        $chk->execute([$id]);
    } while ($chk->fetch());

    $filename = $id . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], VIDEOS_MEDIA_DIR . '/' . $filename)) {
        return 'Could not save the upload. Please try again.';
    }
    @chmod(VIDEOS_MEDIA_DIR . '/' . $filename, 0644);

    // Optional thumbnail (client-captured frame or user-supplied image).
    $thumbName = save_thumbnail($id);

    $ins = $db->prepare(
        "INSERT INTO videos
           (id, user_id, title, description, filename, thumb, mime, size_bytes, duration_sec, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([$id, $u['id'], $title, $desc, $filename, $thumbName, $mime, $size, $duration, time()]);
    activity_log('📹', $u['username'] . ' uploaded "' . $title . '"');

    $dest = '/videos/watch.php?v=' . $id;
    if ($isXhr) json_out(['ok' => true, 'redirect' => $dest]);
    redirect($dest);
}

/** Validate + store the thumbnail; returns stored filename or '' if none/invalid. */
function save_thumbnail(string $id): string {
    $t = $_FILES['thumb'] ?? null;
    if (!$t || !is_uploaded_file($t['tmp_name'] ?? '') || ($t['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return '';
    }
    if ((int) $t['size'] <= 0 || (int) $t['size'] > THUMB_MAX_BYTES) return '';

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($t['tmp_name']);
    if (!isset(THUMB_MIME_EXT[$mime])) return '';
    if (getimagesize($t['tmp_name']) === false) return '';   // confirm real image

    $name = $id . '_t.' . THUMB_MIME_EXT[$mime];
    if (!move_uploaded_file($t['tmp_name'], VIDEOS_THUMB_DIR . '/' . $name)) return '';
    @chmod(VIDEOS_THUMB_DIR . '/' . $name, 0644);
    return $name;
}

render_header('Upload');
?>
<div class="v-upload">
  <h1>Upload a video</h1>
  <p class="v-dim">
    MP4 or WebM · up to <?= human_size(VIDEO_MAX_BYTES) ?> · <?= fmt_duration(VIDEO_MAX_DURATION_SEC) ?> max.
    No transcoding happens, so use a browser-friendly file (H.264/AAC MP4 is safest).
  </p>
  <?php if ($error): ?><div class="v-error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="v-form" id="upload-form">
    <?= csrf_field() ?>
    <input type="hidden" name="duration" id="f-duration" value="0">

    <label>Video file
      <input type="file" name="video" id="f-video" accept="video/mp4,video/webm" required>
    </label>
    <div id="f-fileinfo" class="v-dim"></div>

    <div id="f-thumbwrap" class="v-thumbwrap" hidden>
      <span class="v-dim">Auto thumbnail (a frame from your video):</span>
      <img id="f-thumbprev" alt="thumbnail preview">
      <label class="v-thumb-custom">Use a custom image instead
        <input type="file" name="thumb_custom" id="f-thumbcustom" accept="image/jpeg,image/png,image/webp">
      </label>
    </div>
    <!-- The actual thumbnail bytes sent to the server (canvas frame or custom). -->
    <input type="file" name="thumb" id="f-thumb" hidden>

    <label>Title
      <input name="title" maxlength="<?= TITLE_MAX ?>" required value="<?= e($_POST['title'] ?? '') ?>">
    </label>
    <label>Description
      <textarea name="description" rows="4" maxlength="<?= DESC_MAX ?>"><?= e($_POST['description'] ?? '') ?></textarea>
    </label>

    <div class="v-progress" id="f-progress" hidden><div class="v-progress-bar" id="f-progress-bar"></div></div>
    <button type="submit" class="v-btn v-btn-accent v-btn-lg" id="f-submit">Publish</button>
  </form>
</div>
<script>
  window.VIDEO_LIMITS = {
    maxBytes: <?= VIDEO_MAX_BYTES ?>,
    maxDuration: <?= VIDEO_MAX_DURATION_SEC ?>,
    maxHuman: <?= json_encode(human_size(VIDEO_MAX_BYTES)) ?>
  };
</script>
<script src="/videos/assets/upload.js"></script>
<?php render_footer();
