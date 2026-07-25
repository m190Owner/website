<?php
// Shared bootstrap for the /videos/ section. Every page/endpoint requires this
// first. Pulls in the site-wide security helpers (config.php), defines the
// video-specific paths + caps, and wires up the db/auth/util libraries.

require_once __DIR__ . '/../../config.php';   // setCorsHeaders, enforceRateLimit,
                                              // containsProfanity, sanitizeHandle, ...

// ---- Paths ----
define('VIDEOS_ROOT', dirname(__DIR__));      // .../website/videos
define('VIDEOS_DATA_DIR',   VIDEOS_ROOT . '/data');
define('VIDEOS_MEDIA_DIR',  VIDEOS_ROOT . '/media');
define('VIDEOS_THUMB_DIR',  VIDEOS_ROOT . '/thumbs');
define('VIDEOS_AVATAR_DIR', VIDEOS_ROOT . '/avatars');

foreach ([VIDEOS_DATA_DIR, VIDEOS_MEDIA_DIR, VIDEOS_THUMB_DIR, VIDEOS_AVATAR_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

// ---- Caps / limits (tune freely) ----
define('VIDEO_MAX_BYTES',         128 * 1024 * 1024);   // per file: 128 MB
define('VIDEO_MAX_DURATION_SEC',  900);                 // per file: 15 min
define('VIDEO_GLOBAL_CAP_BYTES',  5 * 1024 * 1024 * 1024); // total media/: 5 GB
define('VIDEO_UPLOADS_PER_DAY',   10);                  // per user
define('THUMB_MAX_BYTES',         2 * 1024 * 1024);     // thumbnail image: 2 MB
define('AVATAR_MAX_BYTES',        2 * 1024 * 1024);     // profile pic: 2 MB
define('WARN_BAN_THRESHOLD',      3);                   // auto-ban at N warnings

// Text length caps.
define('TITLE_MAX',       120);
define('DESC_MAX',        5000);
define('COMMENT_MAX',     2000);
define('ABOUT_MAX',       1000);
define('REPORT_REASON_MAX', 500);

// The account that gets the admin panel. Whoever registers this username is
// flagged is_admin (username is unique, so register it first). Override via the
// VIDEOS_ADMIN env var on the host.
define('VIDEO_ADMIN_USERNAME', getenv('VIDEOS_ADMIN') ?: 'logan');

// Accepted video containers (browser-playable; we can't transcode on shared PHP).
// Maps a sniffed MIME type -> stored file extension.
const VIDEO_MIME_EXT = [
    'video/mp4'  => 'mp4',
    'video/webm' => 'webm',
];
const THUMB_MIME_EXT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/auth.php';
