<?php
// Small helpers: output escaping, formatting, slugs, and the shared page shell
// (header/footer) so every page looks consistent and pulls the same theme.

/** Escape for HTML output. Use on EVERY piece of user-supplied text. */
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** URL-safe random slug for video ids and stored filenames. */
function random_slug(int $len = 11): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function dir_size_bytes(string $dir): int {
    $total = 0;
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f)) $total += filesize($f);
    }
    return $total;
}

function human_size(int $bytes): string {
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? (int) $n : round($n, 1)) . ' ' . $u[$i];
}

function time_ago(int $ts): string {
    $d = max(0, time() - $ts);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return floor($d / 60) . 'm ago';
    if ($d < 86400)  return floor($d / 3600) . 'h ago';
    if ($d < 2592000) return floor($d / 86400) . 'd ago';
    if ($d < 31536000) return floor($d / 2592000) . 'mo ago';
    return floor($d / 31536000) . 'y ago';
}

function fmt_duration(int $sec): string {
    $sec = max(0, $sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

function fmt_count(int $n): string {
    if ($n < 1000) return (string) $n;
    if ($n < 1_000_000) return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M';
}

// ---- Reusable pieces ------------------------------------------------------

/** Public URL for a video's thumbnail (or a placeholder if none). */
function thumb_url(array $v): string {
    return $v['thumb'] !== ''
        ? '/videos/thumbs/' . rawurlencode($v['thumb'])
        : '/videos/assets/placeholder.svg';
}

/** Avatar element: the uploaded image if set, else a letter tile. $cls sizes it. */
function avatar_html(string $username, string $avatar, string $cls): string {
    if ($avatar !== '') {
        return '<img class="' . e($cls) . ' v-av-img" src="/videos/avatars/'
             . e(rawurlencode($avatar)) . '" alt="">';
    }
    return '<span class="' . e($cls) . '">' . e(strtoupper(mb_substr($username, 0, 1))) . '</span>';
}

/**
 * Validate + store an uploaded image (avatar or thumbnail). Returns the stored
 * filename on success, or null if absent/invalid. Sniffs the real MIME, confirms
 * it decodes as an image, and never trusts the client filename.
 */
function store_uploaded_image(array $file, string $destDir, string $baseName, int $maxBytes): ?string {
    if (!is_uploaded_file($file['tmp_name'] ?? '') || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxBytes) return null;

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(THUMB_MIME_EXT[$mime]))       return null;
    if (getimagesize($file['tmp_name']) === false) return null;

    $name = $baseName . '.' . THUMB_MIME_EXT[$mime];
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $name)) return null;
    @chmod($destDir . '/' . $name, 0644);
    return $name;
}

/** Render one video card. $v needs: id,title,thumb,username,views,created_at,duration_sec.
 *  Note: no nested <a> — the channel link is a sibling of the thumb/title links,
 *  since nesting anchors is invalid HTML and fragments the card in the grid. */
function video_card(array $v): void {
    $watch = '/videos/watch.php?v=' . urlencode($v['id']);
    ?>
    <div class="v-card">
      <a class="v-card-thumb" href="<?= e($watch) ?>">
        <img src="<?= e(thumb_url($v)) ?>" alt="" loading="lazy">
        <span class="v-card-dur"><?= e(fmt_duration((int) $v['duration_sec'])) ?></span>
      </a>
      <div class="v-card-meta">
        <a class="v-card-title" href="<?= e($watch) ?>"><?= e($v['title']) ?></a>
        <div class="v-card-sub">
          <a class="v-card-chan" href="/videos/channel.php?u=<?= urlencode($v['username']) ?>"><?= e($v['username']) ?></a>
          · <?= fmt_count((int) $v['views']) ?> views · <?= e(time_ago((int) $v['created_at'])) ?>
        </div>
      </div>
    </div>
    <?php
}

/** Render a grid of cards, or an empty-state message. */
function video_grid(array $rows, string $empty = 'No videos yet.'): void {
    if (!$rows) { echo '<p class="v-dim v-empty">' . e($empty) . '</p>'; return; }
    echo '<div class="v-grid">';
    foreach ($rows as $r) video_card($r);
    echo '</div>';
}

/** Escape a string for use inside a SQL LIKE pattern (escaping \ % _). */
function like_escape(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

// ---- Shared page shell ----------------------------------------------------

function render_header(string $title, string $active = ''): void {
    $u = current_user();
    $q = e($_GET['q'] ?? '');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> · Videos · Logan Sandivar</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="stylesheet" href="/videos/assets/videos.css">
<?php if ($u): ?><meta name="csrf" content="<?= e(csrf_token()) ?>"><?php endif; ?>
<script src="/js/noinspect.js"></script>
</head>
<body>
<nav class="v-nav">
  <div class="v-nav-left">
    <a href="/" class="v-back" title="Back to logansandivar.com">&#8592;</a>
    <a href="/videos/" class="v-brand">▶ Videos</a>
  </div>
  <form class="v-search" action="/videos/index.php" method="get" role="search">
    <input type="search" name="q" placeholder="Search videos" value="<?= $q ?>" maxlength="80" autocomplete="off">
    <button type="submit" aria-label="Search">🔍</button>
  </form>
  <div class="v-nav-right">
    <?php if ($u): ?>
      <a href="/videos/upload.php" class="v-btn v-btn-accent">↑ Upload</a>
      <?php if (!empty($u['is_admin'])): ?><a href="/videos/admin.php" class="v-btn">Admin</a><?php endif; ?>
      <a href="/videos/subscriptions.php" class="v-btn">Subs</a>
      <a href="/videos/channel.php?u=<?= urlencode($u['username']) ?>" class="v-btn v-me"><?= e($u['username']) ?></a>
      <a href="/videos/logout.php" class="v-btn">Logout</a>
    <?php else: ?>
      <a href="/videos/login.php" class="v-btn">Log in</a>
      <a href="/videos/register.php" class="v-btn v-btn-accent">Sign up</a>
    <?php endif; ?>
  </div>
</nav>
<main class="v-main"><?php
    render_warning_banner($u);
}

/** Show any unacknowledged warnings to the user, with an acknowledge button. */
function render_warning_banner(?array $u): void {
    if (!$u) return;
    $st = videos_db()->prepare(
        "SELECT reason, created_at FROM warnings WHERE user_id = ? AND acknowledged = 0 ORDER BY created_at"
    );
    $st->execute([$u['id']]);
    $warns = $st->fetchAll();
    if (!$warns) return;

    $total = (int) videos_db()->query("SELECT COUNT(*) FROM warnings WHERE user_id = " . (int) $u['id'])->fetchColumn();
    $left = max(0, WARN_BAN_THRESHOLD - $total);
    ?>
    <div class="v-warn-banner">
      <div class="v-warn-head">⚠ You have received a warning from a moderator</div>
      <?php foreach ($warns as $w): ?>
        <div class="v-warn-reason"><?= trim($w['reason']) !== '' ? e($w['reason']) : 'No reason given.' ?>
          <span class="v-dim">· <?= e(time_ago((int) $w['created_at'])) ?></span></div>
      <?php endforeach; ?>
      <div class="v-warn-foot">
        <?= $left > 0
            ? e($left) . ' more warning' . ($left === 1 ? '' : 's') . ' will result in a ban.'
            : 'Further violations will result in a ban.' ?>
      </div>
      <form method="post" action="/videos/action.php" class="v-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ack_warning">
        <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/videos/') ?>">
        <button class="v-btn">I understand</button>
      </form>
    </div>
    <?php
}

function render_footer(): void {
    ?></main>
<footer class="v-footer">
  <span>Videos · part of <a href="/">logansandivar.com</a></span>
  <span class="v-dim">Uploads are user-submitted. <a href="/videos/index.php">Report</a> anything that shouldn't be here.</span>
</footer>
<script src="/videos/assets/videos.js"></script>
</body>
</html><?php
}
