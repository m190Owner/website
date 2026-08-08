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
        'queue'      => max(0, (int) ($x['queue'] ?? 0)),
        'indexers'   => max(0, (int) ($x['indexers'] ?? 0)),
        'health'     => max(0, (int) ($x['health'] ?? 0)),
    ];
}

jf_stack_write([
    'containers' => $containers,
    'vpn'        => $vpn,
    'services'   => $services,
    'host'       => $s($in['host'] ?? '', 40),
    'agentTime'  => (int) ($in['agentTime'] ?? 0),
]);

echo 'ok';
