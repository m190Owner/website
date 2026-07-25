<?php
// Streams a video file with proper HTTP Range support. Browsers require Range
// (206 Partial Content + Accept-Ranges) to play/seek MP4/WebM; serving the raw
// file works on Apache but not on every host (or the PHP dev server), so we
// always go through here for reliable, seekable playback everywhere.

require __DIR__ . '/lib/bootstrap.php';

$id = $_GET['v'] ?? '';
$st = videos_db()->prepare("SELECT filename, mime FROM videos WHERE id = ? AND status = 'live'");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit; }

$path = VIDEOS_MEDIA_DIR . '/' . basename($row['filename']);
if (!is_file($path)) { http_response_code(404); exit; }

$size = filesize($path);
$mime = $row['mime'] ?: 'application/octet-stream';

// Clear any output buffering so we stream raw bytes.
while (ob_get_level() > 0) ob_end_clean();
@set_time_limit(0);

$start = 0;
$end   = $size - 1;

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=604800');

// Parse a single-range request: "bytes=start-end", "bytes=start-", "bytes=-suffix".
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
    if ($m[1] === '' && $m[2] === '') {
        // "bytes=-" is invalid.
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    if ($m[1] === '') {
        // Suffix range: last N bytes.
        $len   = (int) $m[2];
        $start = max(0, $size - $len);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') $end = (int) $m[2];
    }
    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $end = min($end, $size - 1);
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

// HEAD requests just want the headers.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

$fp = fopen($path, 'rb');
if ($fp === false) { http_response_code(500); exit; }
fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, (int) min(8192, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fp);
