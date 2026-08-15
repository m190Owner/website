<?php
// Ingest endpoint for the media-server status agent. Machine-to-machine: authed
// by a shared secret header (NOT the admin session), rate-limited, size-capped.
// It only STORES a defensively-reshaped snapshot — it never executes anything
// from the payload.
require __DIR__ . '/../videos/lib/bootstrap.php';
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('POST only'); }
enforceRateLimit('jf_ingest', 120, 60);

$cfg = jf_config();
$secret = is_array($cfg) ? (string) ($cfg['ingest_secret'] ?? '') : '';
$sent = (string) ($_SERVER['HTTP_X_AGENT_SECRET'] ?? '');
if ($secret === '' || strlen($sent) < 16 || !hash_equals($secret, $sent)) {
    http_response_code(403); exit('forbidden');
}

$raw = file_get_contents('php://input', false, null, 0, 262144);   // cap 256 KB
$in = json_decode((string) $raw, true);
if (!is_array($in) || !isset($in['containers']) || !is_array($in['containers'])) {
    http_response_code(400); exit('bad payload');
}

$s = fn($v, int $max) => mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);

$containers = [];
foreach (array_slice($in['containers'], 0, 60) as $c) {
    if (!is_array($c)) continue;
    $containers[] = [
        'name'   => $s($c['name']   ?? '', 64),
        'state'  => $s($c['state']  ?? '', 20),
        'health' => $s($c['health'] ?? '', 20),
        'uptime' => $s($c['uptime'] ?? '', 60),
        'image'  => $s($c['image']  ?? '', 120),
    ];
}

$vpnIn = is_array($in['vpn'] ?? null) ? $in['vpn'] : [];
$vpn = [
    'ok'      => (bool) ($vpnIn['ok'] ?? false),
    'ip'      => $s($vpnIn['ip'] ?? '', 45),
    'country' => $s($vpnIn['country'] ?? '', 8),
    'city'    => $s($vpnIn['city'] ?? '', 64),
    'leak'    => (bool) ($vpnIn['leak'] ?? false),   // torrent egress not tunneled
    'killed'  => (bool) ($vpnIn['killed'] ?? false), // agent auto-paused qBittorrent
];

// Per-service detail — whitelist a fixed set of numeric/string fields per service.
$svcIn = is_array($in['services'] ?? null) ? $in['services'] : [];
$services = [];
foreach (['qbit', 'sonarr', 'radarr', 'lidarr', 'prowlarr'] as $name) {
    $x = is_array($svcIn[$name] ?? null) ? $svcIn[$name] : null;
    if ($x === null) continue;
    $services[$name] = [
        'ok'         => (bool) ($x['ok'] ?? false),
        'connection' => $s($x['connection'] ?? '', 20),
        'down'       => max(0, (int) ($x['down'] ?? 0)),
        'up'         => max(0, (int) ($x['up'] ?? 0)),
        'torrents'   => max(0, (int) ($x['torrents'] ?? 0)),
        'dl'         => max(0, (int) ($x['dl'] ?? 0)),
        'ul'         => max(0, (int) ($x['ul'] ?? 0)),
        'err'        => max(0, (int) ($x['err'] ?? 0)),
        'queue'      => max(0, (int) ($x['queue'] ?? 0)),
        'indexers'   => max(0, (int) ($x['indexers'] ?? 0)),
        'health'     => max(0, (int) ($x['health'] ?? 0)),
    ];
}

// qBittorrent per-torrent detail (for the expand-on-click list).
if (isset($services['qbit']) && is_array($svcIn['qbit']['list'] ?? null)) {
    $list = [];
    foreach (array_slice($svcIn['qbit']['list'], 0, 50) as $t) {
        if (!is_array($t)) continue;
        $list[] = [
            'name'     => $s($t['name'] ?? '', 120),
            'progress' => max(0.0, min(1.0, (float) ($t['progress'] ?? 0))),
            'dl'       => max(0, (int) ($t['dl'] ?? 0)),
            'up'       => max(0, (int) ($t['up'] ?? 0)),
            'state'    => $s($t['state'] ?? '', 20),
            'size'     => max(0, (int) ($t['size'] ?? 0)),
            'eta'      => max(0, (int) ($t['eta'] ?? 0)),
            'cat'      => $s($t['cat'] ?? '', 16),
            'hash'     => preg_match('/^[a-f0-9]{40}$/i', (string) ($t['hash'] ?? '')) ? strtolower($t['hash']) : '',
        ];
    }
    $services['qbit']['list'] = $list;
}

// Recent grabs/imports across the *arrs (nested under services on the wire).
$history = [];
foreach (array_slice(is_array($svcIn['history'] ?? null) ? $svcIn['history'] : [], 0, 15) as $h) {
    if (!is_array($h)) continue;
    $history[] = [
        'svc'   => $s($h['svc'] ?? '', 12),
        'event' => $s($h['event'] ?? '', 24),
        'title' => $s($h['title'] ?? '', 120),
        'date'  => $s($h['date'] ?? '', 30),
    ];
}

// Disk usage (media volume + host drive).
$diskOne = function ($d) {
    if (!is_array($d)) return null;
    return ['total' => max(0, (int) ($d['total'] ?? 0)), 'used' => max(0, (int) ($d['used'] ?? 0)),
            'free' => max(0, (int) ($d['free'] ?? 0)), 'pct' => max(0, min(100, (int) ($d['pct'] ?? 0)))];
};
$diskIn = is_array($in['disk'] ?? null) ? $in['disk'] : [];
$disk = ['media' => $diskOne($diskIn['media'] ?? null), 'host' => $diskOne($diskIn['host'] ?? null)];

// Jellyseerr request overview (read-only monitor). Nested under services on the
// wire (like history). Whitelist counts + a capped list of {title,type,status,
// user,poster}. Only tmdb poster PATHS are stored.
$jsIn = is_array($svcIn['jellyseerr'] ?? null) ? $svcIn['jellyseerr'] : null;
$jellyseerr = null;
if ($jsIn !== null) {
    $cIn = is_array($jsIn['counts'] ?? null) ? $jsIn['counts'] : [];
    $reqs = [];
    foreach (array_slice(is_array($jsIn['requests'] ?? null) ? $jsIn['requests'] : [], 0, 15) as $r) {
        if (!is_array($r)) continue;
        $reqs[] = [
            'id'          => max(0, (int) ($r['id'] ?? 0)),
            'title'       => $s($r['title'] ?? '', 120),
            'type'        => ($r['type'] ?? '') === 'tv' ? 'tv' : 'movie',
            'mediaStatus' => max(0, min(5, (int) ($r['mediaStatus'] ?? 0))),
            'reqStatus'   => max(0, min(5, (int) ($r['reqStatus'] ?? 0))),
            'user'        => $s($r['user'] ?? '', 40),
            'poster'      => $s($r['poster'] ?? '', 80),
            'createdAt'   => $s($r['createdAt'] ?? '', 30),
        ];
    }
    $jellyseerr = [
        'ok'     => (bool) ($jsIn['ok'] ?? false),
        'counts' => [
            'total'      => max(0, (int) ($cIn['total'] ?? 0)),
            'pending'    => max(0, (int) ($cIn['pending'] ?? 0)),
            'processing' => max(0, (int) ($cIn['processing'] ?? 0)),
            'available'  => max(0, (int) ($cIn['available'] ?? 0)),
            'approved'   => max(0, (int) ($cIn['approved'] ?? 0)),
            'declined'   => max(0, (int) ($cIn['declined'] ?? 0)),
        ],
        'requests' => $reqs,
    ];
}

$snapshot = [
    'containers' => $containers,
    'vpn'        => $vpn,
    'services'   => $services,
    'history'    => $history,
    'disk'       => $disk,
    'jellyseerr' => $jellyseerr,
    'host'       => $s($in['host'] ?? '', 40),
    'agentTime'  => (int) ($in['agentTime'] ?? 0),
];

// Alerting: diff against the previous snapshot, notify Discord on transitions.
$webhook = (string) ($cfg['alert_webhook'] ?? '');
if ($webhook !== '') {
    $diskPct = (int) (($cfg['disk_alert_pct'] ?? 0) ?: 90);
    foreach (jf_compute_alerts(jf_stack_read(), $snapshot, $diskPct) as $a) jf_discord_alert($webhook, $a);
}

jf_stack_write($snapshot);
jf_history_append($snapshot);              // rolling trend series (throttled to ~10 min)
jf_digest_maybe_send($webhook, $snapshot); // weekly digest, ingest-driven (no host cron)
jf_sync_access();                          // Jellyfin logins/new devices → owner audit log
echo 'ok';
