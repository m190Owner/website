<?php
// Mirrors the latest uploads from Logan's YouTube channel by reading the public
// RSS feed server-side (no API key — the feed exposes the 15 most recent videos)
// and caching the result to JSON. Same shape as github_latest.php. Consumed by
// the homepage "Latest Videos" strip and the dedicated /youtube/ page; both just
// render clickable thumbnails that open the video on YouTube.
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
// Revalidate on the client each load; freshness is governed by the server cache below.
header('Cache-Control: no-cache, no-store, must-revalidate');
setCorsHeaders();
enforceRateLimit('youtube_latest', 30, 60);

// Public identifiers — not secrets. The channel id is fixed and never user-supplied,
// so the upstream URL can't be steered (no SSRF/open-proxy).
const YT_CHANNEL_ID  = 'UCVwJ4uFmNmOxGOtO3sGW6vw';
const YT_CHANNEL_URL = 'https://www.youtube.com/@LoganSandivar';

$cacheFile = __DIR__ . '/youtube_cache.json';
// 15 min: uploads are infrequent, so this caps upstream calls and stays polite to
// YouTube while still surfacing a new video within the window. Stale cache is
// served if the fetch ever fails (same resilience as github_latest.php).
$cacheTTL = 900;

if (file_exists($cacheFile)) {
    $cache = readJsonFile($cacheFile);
    if (!empty($cache['fetched_at']) && time() - $cache['fetched_at'] < $cacheTTL) {
        echo json_encode($cache);
        exit;
    }
}

$feedUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . YT_CHANNEL_ID;
$ch = curl_init($feedUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => ['User-Agent: logansandivar-site'],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Helper: return stale cache on any failure, else a small not-ok payload.
$fail = function () use ($cacheFile) {
    echo file_exists($cacheFile) ? file_get_contents($cacheFile) : json_encode(['ok' => false]);
    exit;
};

if (!$raw || $code !== 200) $fail();

// LIBXML_NONET blocks any network/entity fetch during parse; external entities are
// already off by default in PHP 8. Feed content is treated as untrusted — every
// field is validated/escaped here and again at render time.
$xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
if ($xml === false || !isset($xml->entry)) $fail();

$YT_NS    = 'http://www.youtube.com/xml/schemas/2015';
$MEDIA_NS = 'http://search.yahoo.com/mrss/';

$videos = [];
foreach ($xml->entry as $entry) {
    $yt    = $entry->children($YT_NS);
    $media = $entry->children($MEDIA_NS);

    $id = (string) ($yt->videoId ?? '');
    // YouTube ids are 11 chars of [A-Za-z0-9_-]; be lenient on length but strict on charset.
    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) continue;

    $group = $media->group ?? null;
    $thumb = '';
    $views = 0;
    if ($group) {
        if (isset($group->thumbnail)) {
            $thumb = (string) $group->thumbnail->attributes()['url'];
        }
        if (isset($group->community->statistics)) {
            $views = (int) $group->community->statistics->attributes()['views'];
        }
    }
    // Deterministic fallback if the feed omits/points elsewhere for the thumbnail.
    if ($thumb === '' || strpos($thumb, 'ytimg.com') === false) {
        $thumb = 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
    }

    $videos[] = [
        'id'        => $id,
        'title'     => (string) $entry->title,
        'url'       => 'https://www.youtube.com/watch?v=' . $id,
        'thumb'     => $thumb,
        'published' => (string) $entry->published,
        'views'     => $views,
    ];
}

if (!$videos) $fail();

$data = [
    'ok'         => true,
    'channelUrl' => YT_CHANNEL_URL,
    'videos'     => $videos,
    'fetched_at' => time(),
];
writeJsonFile($cacheFile, $data);
echo json_encode($data);
