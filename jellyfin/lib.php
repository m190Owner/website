<?php
// Jellyfin API client for the owner dashboard. Talks to Jellyfin server-side
// with the API key kept out of the browser (sent as an X-Emby-Token header,
// never a URL param). Read helpers + a few control POSTs. All request PATHS are
// built here from a fixed set — the client never forwards a raw URL from the
// browser, so this can't be turned into an open proxy / SSRF.

/** Load + cache the gitignored config. Returns null if missing or unfilled. */
function jf_config(): ?array {
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) return $cfg = null;
    $c = require $path;
    if (!is_array($c) || empty($c['url']) || empty($c['api_key'])
        || $c['api_key'] === 'YOUR_JELLYFIN_API_KEY') return $cfg = null;
    $c['url'] = rtrim((string) $c['url'], '/');
    return $cfg = $c;
}

function jf_configured(): bool { return jf_config() !== null; }

/**
 * One request to Jellyfin. $raw=true returns the body bytes untouched (images);
 * otherwise the JSON body is decoded. Returns a uniform shape.
 */
function jf_request(string $method, string $path, array $query = [], ?array $body = null, bool $raw = false): array {
    $cfg = jf_config();
    if (!$cfg) return ['ok' => false, 'status' => 0, 'error' => 'not configured', 'data' => null, 'raw' => '', 'contentType' => ''];
    $url = $cfg['url'] . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['X-Emby-Token: ' . $cfg['api_key'], 'Accept: ' . ($raw ? '*/*' : 'application/json')];
    $payload = $body !== null ? json_encode($body) : null;
    if ($payload !== null) $headers[] = 'Content-Type: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        if (!empty($cfg['cainfo']) && is_file($cfg['cainfo'])) curl_setopt($ch, CURLOPT_CAINFO, $cfg['cainfo']);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = $out === false ? curl_error($ch) : null;
        curl_close($ch);
    } else {
        // Fallback for hosts without curl.
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if (!empty($cfg['cainfo']) && is_file($cfg['cainfo'])) $ssl['cafile'] = $cfg['cainfo'];
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $payload, 'timeout' => 12, 'ignore_errors' => true,
        ], 'ssl' => $ssl]);
        $out = @file_get_contents($url, false, $ctx);
        $status = 0; $ctype = ''; $err = $out === false ? 'request failed' : null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int) $m[1];
            if (stripos($h, 'Content-Type:') === 0) $ctype = trim(substr($h, 13));
        }
    }

    $ok = $err === null && $status >= 200 && $status < 300;
    return [
        'ok' => $ok, 'status' => $status, 'error' => $err,
        'data' => ($raw || $out === false) ? null : json_decode((string) $out, true),
        'raw' => $out === false ? '' : (string) $out, 'contentType' => $ctype,
    ];
}

function jf_get(string $path, array $query = []): array { return jf_request('GET', $path, $query); }
function jf_post(string $path, ?array $body = null): array { return jf_request('POST', $path, [], $body); }

// ---- Stack status store (snapshots pushed by the media-server agent) ----
function jf_stack_path(): string { return __DIR__ . '/data/stack.json'; }

/** Persist a validated stack snapshot atomically, stamped with server time. */
function jf_stack_write(array $snapshot): bool {
    $dir = dirname(jf_stack_path());
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $snapshot['storedAt'] = time();
    $tmp = jf_stack_path() . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, json_encode($snapshot)) === false) return false;
    return @rename($tmp, jf_stack_path());
}

/** Read the latest snapshot + how many seconds ago it landed, or null. */
function jf_stack_read(): ?array {
    $raw = @file_get_contents(jf_stack_path());
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    if (!is_array($d)) return null;
    $d['ageSec'] = max(0, time() - (int) ($d['storedAt'] ?? 0));
    return $d;
}

// ---- Trends: a rolling time-series for sparklines + a disk-fill projection ----
function jf_history_path(): string { return __DIR__ . '/data/history.json'; }
const JF_HISTORY_MIN_GAP = 600;    // append at most one point per 10 minutes
const JF_HISTORY_MAX     = 2500;   // ~17 days at that spacing

function jf_history_read(): array {
    $r = @file_get_contents(jf_history_path());
    $d = $r === false ? null : json_decode($r, true);
    return is_array($d) ? $d : [];
}

/** Append a compact metrics point from a snapshot — throttled + pruned. */
function jf_history_append(array $snap): void {
    $hist = jf_history_read();
    $now  = time();
    $last = $hist ? end($hist) : null;
    if ($last && ($now - (int) ($last['t'] ?? 0)) < JF_HISTORY_MIN_GAP) return;   // throttle
    $m = $snap['disk']['media'] ?? [];
    if (empty($m['total'])) return;                                                // no disk reading → skip
    $h = $snap['disk']['host'] ?? [];
    $q = $snap['services']['qbit'] ?? [];
    $hist[] = ['t' => $now,
        'mu' => (int) ($m['used'] ?? 0), 'mt' => (int) ($m['total'] ?? 0), 'mp' => (int) ($m['pct'] ?? 0),
        'hp' => (int) ($h['pct'] ?? 0), 'qd' => (int) ($q['down'] ?? 0), 'qu' => (int) ($q['up'] ?? 0)];
    if (count($hist) > JF_HISTORY_MAX) $hist = array_slice($hist, -JF_HISTORY_MAX);
    $tmp = jf_history_path() . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, json_encode($hist)) !== false) @rename($tmp, jf_history_path());
}

/** Least-squares projection of when the media volume fills, from the recent series. */
function jf_disk_projection(array $series): array {
    $cutoff = time() - 7 * 86400;
    $pts = array_values(array_filter($series, fn($p) => ($p['t'] ?? 0) >= $cutoff && !empty($p['mt'])));
    if (count($pts) < 4) return ['trend' => 'gathering', 'daysToFull' => null, 'ratePerDay' => 0];
    $t0 = (int) $pts[0]['t'];
    if (((int) end($pts)['t'] - $t0) < 6 * 3600) return ['trend' => 'gathering', 'daysToFull' => null, 'ratePerDay' => 0];
    $n = count($pts); $sx = $sy = $sxx = $sxy = 0.0;
    foreach ($pts as $p) { $x = (int) $p['t'] - $t0; $y = (float) $p['mu']; $sx += $x; $sy += $y; $sxx += $x * $x; $sxy += $x * $y; }
    $den = $n * $sxx - $sx * $sx;
    $slope = $den != 0.0 ? ($n * $sxy - $sx * $sy) / $den : 0.0;      // bytes/sec
    $ratePerDay = (int) ($slope * 86400);
    if ($slope <= 0 || $ratePerDay < 1e8) {                          // < ~0.1 GB/day → stable
        return ['trend' => $slope < 0 ? 'shrinking' : 'stable', 'daysToFull' => null, 'ratePerDay' => $ratePerDay];
    }
    $last = end($pts);
    $days = ((float) $last['mt'] - (float) $last['mu']) / $slope / 86400;
    return ['trend' => 'filling', 'daysToFull' => max(0, (int) round($days)), 'ratePerDay' => $ratePerDay];
}

/** Client-facing trends: a capped series (for sparklines) + the disk projection. */
function jf_history_view(): array {
    $hist = jf_history_read();
    $series = array_map(fn($p) => ['t' => (int) ($p['t'] ?? 0), 'mp' => (int) ($p['mp'] ?? 0),
        'hp' => (int) ($p['hp'] ?? 0), 'qd' => (int) ($p['qd'] ?? 0)], array_slice($hist, -300));
    return ['series' => $series, 'projection' => jf_disk_projection($hist)];
}

/** Build the weekly-digest embed from a snapshot (pure — testable). */
function jf_digest_build(array $st): array {
    $cs = $st['containers'] ?? [];
    $up  = count(array_filter($cs, fn($c) => ($c['state'] ?? '') === 'running' && ($c['health'] ?? '') !== 'unhealthy'));
    $tot = count($cs);
    $v = $st['vpn'] ?? [];
    $vpnStr = !empty($v['leak']) ? '🚨 LEAK — torrents ' . (!empty($v['killed']) ? 'auto-paused' : 'NOT paused')
            : (!empty($v['ok']) ? 'connected' . (!empty($v['country']) ? ' (' . $v['country'] . ')' : '') : 'not confirmed');
    $proj = jf_disk_projection(jf_history_read());
    $md = $st['disk']['media'] ?? []; $hd = $st['disk']['host'] ?? [];
    $diskLine = 'Media **' . (int) ($md['pct'] ?? 0) . '%**';
    if ($proj['trend'] === 'filling' && $proj['daysToFull'] !== null) $diskLine .= ' · filling ~' . round(($proj['ratePerDay'] ?? 0) / 1e9, 1) . ' GB/day · full in **~' . $proj['daysToFull'] . 'd**';
    elseif ($proj['trend'] === 'stable')    $diskLine .= ' · stable';
    elseif ($proj['trend'] === 'shrinking') $diskLine .= ' · shrinking';
    $diskLine .= ' · Host ' . (int) ($hd['pct'] ?? 0) . '%';
    $js = $st['jellyseerr'] ?? null;
    $reqLine = ($js && !empty($js['ok'])) ? (($js['counts']['total'] ?? 0) . ' requests (' . ($js['counts']['pending'] ?? 0) . ' pending, ' . ($js['counts']['processing'] ?? 0) . ' processing)') : '—';
    $qerr = (int) ($st['services']['qbit']['err'] ?? 0);
    $age = (int) ($st['ageSec'] ?? 0);
    $desc = "**Containers:** {$up}/{$tot} up\n**VPN:** {$vpnStr}\n**Disk:** {$diskLine}\n**Requests:** {$reqLine}\n"
          . ($qerr > 0 ? "**qBittorrent:** ⚠ {$qerr} errored torrent(s)\n" : '')
          . '**Last agent report:** ' . ($age < 120 ? 'live' : round($age / 60) . 'm ago');
    return ['color' => 0x5B8CFF, 'title' => '📊 Weekly media-server digest', 'desc' => $desc];
}

// Weekly-digest cadence, driven by the agent's ingest heartbeat — no host cron.
const JF_DIGEST_INTERVAL = 7 * 86400;
function jf_digest_state_path(): string { return __DIR__ . '/data/digest-state.json'; }

/** Send the digest if a week has elapsed since the last one; records the time.
 *  Called from ingest.php on every agent report, so it fires ~once per week
 *  without any external scheduler. (First report after deploy sends one.) */
function jf_digest_maybe_send(string $webhook, array $st): void {
    if ($webhook === '') return;
    $path = jf_digest_state_path();
    $raw  = @file_get_contents($path);
    $last = $raw !== false ? (int) (json_decode($raw, true)['lastSent'] ?? 0) : 0;
    if (time() - $last < JF_DIGEST_INTERVAL) return;               // not due yet
    jf_discord_alert($webhook, jf_digest_build($st));             // domain-guarded internally
    @file_put_contents($path, json_encode(['lastSent' => time()]));
}

// ---- Jellyfin access → owner security audit log (throttled, ingest-driven) ----
function jf_access_state_path(): string { return __DIR__ . '/data/access-sync.json'; }
const JF_ACCESS_SYNC_GAP = 300;   // poll Jellyfin's activity log at most every 5 min

/** Pull new Jellyfin session / failed-auth events and record them in the owner
 *  audit log — with new-device detection. Best-effort; never breaks the caller.
 *  Called from ingest.php off the agent heartbeat, so no host cron is needed. */
function jf_sync_access(): void {
    try {
        if (!function_exists('audit_log') || !jf_configured()) return;
        $path = jf_access_state_path();
        $raw = @file_get_contents($path);
        $state = ($raw !== false && is_array($d = json_decode($raw, true))) ? $d : [];
        if (time() - (int) ($state['at'] ?? 0) < JF_ACCESS_SYNC_GAP) return;

        $lastId   = (int) ($state['lastId'] ?? 0);
        $known    = is_array($state['devices'] ?? null) ? $state['devices'] : [];
        $firstRun = ($raw === false);                        // no state file yet → seed only

        $items = jf_get('/System/ActivityLog/Entries', ['limit' => 40])['data']['Items'] ?? [];
        if (!is_array($items)) return;
        usort($items, fn($a, $b) => ((int) ($a['Id'] ?? 0)) <=> ((int) ($b['Id'] ?? 0)));  // oldest first
        $maxId = $lastId;
        foreach ($items as $e) {
            $id = (int) ($e['Id'] ?? 0);
            if ($id <= $lastId) continue;
            $maxId = max($maxId, $id);
            $type = (string) ($e['Type'] ?? '');
            $name = (string) ($e['Name'] ?? '');
            if ($type === 'SessionStarted' || $type === 'AuthenticationSucceeded') {
                $user = $name; $device = '';
                if (preg_match('/^(.+?) is online from (.+)$/', $name, $m)) { $user = $m[1]; $device = $m[2]; }
                $devKey = strtolower($user . '|' . $device);
                $isNew = $device !== '' && !isset($known[$devKey]);
                if ($device !== '') $known[$devKey] = 1;
                if ($firstRun) continue;                     // seed known devices, don't backfill old sessions
                audit_log($isNew ? 'jellyfin_new_device' : 'jellyfin_session', $isNew ? 'warn' : 'info', [
                    'actor' => mb_substr($user, 0, 60), 'target' => mb_substr($device, 0, 80), 'ip' => '',
                    'detail' => ($isNew ? '🆕 NEW device — ' : '') . 'Jellyfin session' . ($device !== '' ? ' from ' . $device : ''),
                    'push' => $isNew,
                ]);
            } elseif ($type === 'AuthenticationFailed') {
                if ($firstRun) continue;
                audit_log('jellyfin_login_fail', 'warn', ['ip' => '', 'detail' => 'Jellyfin: ' . mb_substr($name, 0, 200), 'push' => true]);
            }
        }
        @file_put_contents($path, json_encode(['at' => time(), 'lastId' => $maxId, 'devices' => $known]));
    } catch (\Throwable $e) {
        // best-effort — must never break ingest
    }
}

// ---- Alerting (container down / disk threshold -> Discord) ----
const JF_DISK_RECOVER_MARGIN = 5;   // a volume "recovers" once it drops this many % below the alert line

/** Diff the previous snapshot against the new one; return alert embeds for the
 *  transitions worth notifying about. Pure + testable — edge-triggered, so it
 *  only fires on the change, never repeatedly while something stays down. */
function jf_compute_alerts(?array $old, array $new, int $diskPct): array {
    if ($old === null) return [];                 // no baseline yet
    $alerts = [];
    $recover = max(1, $diskPct - JF_DISK_RECOVER_MARGIN);
    $up = fn($c) => ($c['state'] ?? '') === 'running' && ($c['health'] ?? '') !== 'unhealthy';

    $oldByName = [];
    foreach ($old['containers'] ?? [] as $c) $oldByName[$c['name']] = $c;
    foreach ($new['containers'] ?? [] as $c) {
        $o = $oldByName[$c['name']] ?? null;
        if ($o === null) continue;                // a container appearing is not an alert
        if ($up($o) && !$up($c)) {
            $why = ($c['state'] ?? '') !== 'running' ? (($c['state'] ?? '') ?: 'stopped') : 'unhealthy';
            $alerts[] = ['color' => 0xE5555F, 'title' => '🔴 ' . $c['name'] . ' is down',
                         'desc' => '**' . $c['name'] . '** is now `' . $why . '` (was running).'];
        } elseif (!$up($o) && $up($c)) {
            $alerts[] = ['color' => 0x43D17A, 'title' => '🟢 ' . $c['name'] . ' recovered',
                         'desc' => '**' . $c['name'] . '** is running again.'];
        }
    }

    foreach (['media' => 'Media volume', 'host' => 'Host drive (C:)'] as $k => $label) {
        $on = $old['disk'][$k]['pct'] ?? null;
        $nn = $new['disk'][$k]['pct'] ?? null;
        if ($on === null || $nn === null) continue;
        $free = round(($new['disk'][$k]['free'] ?? 0) / 1e9) . ' GB free';
        if ($on < $diskPct && $nn >= $diskPct) {
            $alerts[] = ['color' => 0xE8B53F, 'title' => '🟠 ' . $label . ' at ' . $nn . '%',
                         'desc' => $label . ' crossed **' . $diskPct . '%** — ' . $free . '.'];
        } elseif ($on >= $recover && $nn < $recover) {
            $alerts[] = ['color' => 0x43D17A, 'title' => '🟢 ' . $label . ' back to ' . $nn . '%',
                         'desc' => $label . ' dropped below ' . $recover . '% — ' . $free . '.'];
        }
    }

    // VPN leak — torrent egress is not going through the tunnel (agent kill-switch).
    $ol = !empty($old['vpn']['leak']);
    $nl = !empty($new['vpn']['leak']);
    if (!$ol && $nl) {
        $killed = !empty($new['vpn']['killed']);
        $alerts[] = ['color' => 0xE5555F, 'title' => '🔴 VPN leak detected',
                     'desc' => 'Torrent egress IP matches the host — traffic is **not** going through the VPN.'
                             . ($killed ? ' qBittorrent was **auto-paused** (kill-switch).' : ' qBittorrent could **not** be paused — check it now.')];
    } elseif ($ol && !$nl) {
        $alerts[] = ['color' => 0x43D17A, 'title' => '🟢 VPN leak cleared',
                     'desc' => 'Torrent egress is back on the tunnel. Torrents stay paused until you resume them.'];
    }

    // qBittorrent errored torrents (error / missing-files), edge-triggered on the count.
    $oe = (int) ($old['services']['qbit']['err'] ?? 0);
    $ne = (int) ($new['services']['qbit']['err'] ?? 0);
    if ($oe === 0 && $ne > 0) {
        $alerts[] = ['color' => 0xE8B53F, 'title' => '🟠 ' . $ne . ' torrent' . ($ne === 1 ? '' : 's') . ' errored',
                     'desc' => 'qBittorrent reports **' . $ne . '** torrent(s) in an error / missing-files state.'];
    } elseif ($oe > 0 && $ne === 0) {
        $alerts[] = ['color' => 0x43D17A, 'title' => '🟢 Torrent errors cleared', 'desc' => 'No more errored torrents in qBittorrent.'];
    }

    // New failed grabs/imports in the *arrs — one alert per newly-seen failure.
    $failEv = ['failed', 'import failed'];
    $oldFails = [];
    foreach ($old['history'] ?? [] as $h) {
        if (in_array($h['event'] ?? '', $failEv, true)) $oldFails[($h['svc'] ?? '') . '|' . ($h['title'] ?? '') . '|' . ($h['date'] ?? '')] = true;
    }
    foreach ($new['history'] ?? [] as $h) {
        if (!in_array($h['event'] ?? '', $failEv, true)) continue;
        $sig = ($h['svc'] ?? '') . '|' . ($h['title'] ?? '') . '|' . ($h['date'] ?? '');
        if (isset($oldFails[$sig])) continue;
        $alerts[] = ['color' => 0xE5555F, 'title' => '🔴 ' . ($h['svc'] ?? 'arr') . ': ' . ($h['event'] ?? 'failed'),
                     'desc' => '**' . ($h['title'] ?: 'a release') . '** — ' . ($h['event'] ?? 'failed') . ' in ' . ($h['svc'] ?? 'the *arr') . '.'];
    }
    return $alerts;
}

// Small persisted flag so the staleness check alerts once per transition.
function jf_alert_state_path(): string { return __DIR__ . '/data/alert-state.json'; }
function jf_alert_state_read(): array { $r = @file_get_contents(jf_alert_state_path()); $d = $r === false ? null : json_decode($r, true); return is_array($d) ? $d : []; }
function jf_alert_state_write(array $st): void { @file_put_contents(jf_alert_state_path(), json_encode($st)); }

/** Edge decision for the "server offline" alert. Pure/testable. */
function jf_stale_decision(int $ageSec, int $staleSec, bool $wasOffline): string {
    $stale = $ageSec > $staleSec;
    if ($stale && !$wasOffline) return 'offline';
    if (!$stale && $wasOffline) return 'online';
    return 'none';
}

/** Best-effort post of one alert embed to a Discord webhook. Only ever posts to
 *  a real Discord webhook URL (guards against a misconfigured target). */
function jf_discord_alert(string $webhook, array $a): void {
    if (!preg_match('#^https://(canary\.|ptb\.)?discord(app)?\.com/api/webhooks/#', $webhook)) return;
    $cfg = jf_config();
    $body = json_encode(['username' => 'media-server', 'embeds' => [[
        'title' => mb_substr($a['title'], 0, 250),
        'description' => mb_substr($a['desc'], 0, 1500),
        'color' => (int) ($a['color'] ?? 0),
    ]]]);
    if (function_exists('curl_init')) {
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (is_array($cfg) && !empty($cfg['cainfo']) && is_file($cfg['cainfo'])) curl_setopt($ch, CURLOPT_CAINFO, $cfg['cainfo']);
        curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 6, 'ignore_errors' => true]]);
        @file_get_contents($webhook, false, $ctx);
    }
}

const JF_TICKS_PER_SEC = 10000000; // Jellyfin RunTime/Position ticks are 100-ns units

/** Reduce a raw Jellyfin session to just what the dashboard shows. */
function jf_shape_session(array $s): array {
    $np = $s['NowPlayingItem'] ?? null;
    $ps = $s['PlayState'] ?? [];
    $tc = $s['TranscodingInfo'] ?? null;
    $playing = null;
    if ($np) {
        $title = $np['Name'] ?? 'Unknown';
        if (($np['Type'] ?? '') === 'Episode') {
            $se = isset($np['ParentIndexNumber']) ? 'S' . $np['ParentIndexNumber'] : '';
            $ep = isset($np['IndexNumber']) ? 'E' . $np['IndexNumber'] : '';
            $title = trim(($np['SeriesName'] ?? '') . ' · ' . trim($se . $ep) . ' · ' . $title, ' ·');
        }
        $playing = [
            'title'        => $title,
            'type'         => $np['Type'] ?? '',
            'itemId'       => $np['Id'] ?? '',
            'imageTag'     => $np['ImageTags']['Primary'] ?? ($np['SeriesPrimaryImageTag'] ?? ''),
            'imageItemId'  => (($np['Type'] ?? '') === 'Episode' && !isset($np['ImageTags']['Primary']) && isset($np['SeriesId'])) ? $np['SeriesId'] : ($np['Id'] ?? ''),
            'runTimeTicks' => (int) ($np['RunTimeTicks'] ?? 0),
            'positionTicks'=> (int) ($ps['PositionTicks'] ?? 0),
            'paused'       => (bool) ($ps['IsPaused'] ?? false),
            'playMethod'   => $ps['PlayMethod'] ?? 'DirectPlay',
            'transcode'    => $tc ? [
                'reasons' => $tc['TranscodeReasons'] ?? [],
                'video'   => $tc['VideoCodec'] ?? '', 'audio' => $tc['AudioCodec'] ?? '',
                'bitrate' => (int) ($tc['Bitrate'] ?? 0),
            ] : null,
        ];
    }
    return [
        'id'         => $s['Id'] ?? '',
        'user'       => $s['UserName'] ?? '(none)',
        'client'     => $s['Client'] ?? '',
        'device'     => $s['DeviceName'] ?? '',
        'remote'     => $s['RemoteEndPoint'] ?? '',
        'canControl' => (bool) ($s['SupportsRemoteControl'] ?? false),
        'lastActive' => $s['LastActivityDate'] ?? '',
        'nowPlaying' => $playing,
    ];
}

/** Active sessions that have a user, playing ones first. */
function jf_sessions(): array {
    $r = jf_get('/Sessions');
    if (!$r['ok'] || !is_array($r['data'])) return [];
    $out = [];
    foreach ($r['data'] as $s) {
        if (empty($s['UserName'])) continue;               // skip anonymous/service sessions
        $out[] = jf_shape_session($s);
    }
    usort($out, fn($a, $b) => ($b['nowPlaying'] ? 1 : 0) <=> ($a['nowPlaying'] ? 1 : 0));
    return $out;
}

/** Everything the dashboard needs in one call (used as the connection test too). */
// ---- Playback stats: recently watched + most-watched, aggregated across users ----
function jf_playback(): array {
    $users = jf_get('/Users')['data'] ?? [];
    if (!is_array($users)) return ['recent' => [], 'top' => []];
    $recent = []; $agg = [];
    foreach ($users as $u) {
        $uid = $u['Id'] ?? ''; $uname = $u['Name'] ?? '?';
        if ($uid === '') continue;
        $r = jf_get("/Users/$uid/Items", ['SortBy' => 'PlayCount,DatePlayed', 'SortOrder' => 'Descending',
            'Filters' => 'IsPlayed', 'Recursive' => 'true', 'IncludeItemTypes' => 'Movie,Episode',
            'Limit' => 15, 'Fields' => 'UserData,SeriesName']);
        foreach ($r['data']['Items'] ?? [] as $it) {
            $ud = $it['UserData'] ?? [];
            $isEp = ($it['Type'] ?? '') === 'Episode';
            $series = (string) ($it['SeriesName'] ?? '');
            $title = $isEp ? trim($series . ' · ' . ($it['Name'] ?? ''), ' ·') : (string) ($it['Name'] ?? '');
            $recent[] = ['title' => mb_substr($title, 0, 100), 'user' => mb_substr((string) $uname, 0, 40),
                'when' => (string) ($ud['LastPlayedDate'] ?? ''), 'type' => $isEp ? 'tv' : 'movie',
                'id' => (string) ($it['Id'] ?? ''), 'tag' => (string) ($it['ImageTags']['Primary'] ?? '')];
            $key = $isEp ? 's:' . $series : 'm:' . ($it['Name'] ?? '');
            if (!isset($agg[$key])) $agg[$key] = ['title' => $isEp ? $series : (string) ($it['Name'] ?? ''), 'type' => $isEp ? 'tv' : 'movie', 'plays' => 0];
            $agg[$key]['plays'] += (int) ($ud['PlayCount'] ?? 0);
        }
    }
    usort($recent, fn($a, $b) => strcmp($b['when'], $a['when']));
    $seen = []; $dedup = [];                                   // one row per (user,title) — keep newest
    foreach ($recent as $r0) { $sig = $r0['user'] . '|' . $r0['title']; if (isset($seen[$sig])) continue; $seen[$sig] = 1; $dedup[] = $r0; }
    $top = array_values(array_filter($agg, fn($p) => $p['plays'] > 0));
    usort($top, fn($a, $b) => $b['plays'] <=> $a['plays']);
    return ['recent' => array_slice($dedup, 0, 12), 'top' => array_slice($top, 0, 8)];
}

/** Cached playback stats (several API calls → cache ~10 min). */
function jf_playback_cached(int $ttl = 600): array {
    $path = __DIR__ . '/data/playback-cache.json';
    $raw = @file_get_contents($path);
    if ($raw !== false) { $c = json_decode($raw, true); if (is_array($c) && isset($c['data']) && (time() - (int) ($c['at'] ?? 0)) < $ttl) return $c['data']; }
    $data = jf_playback();
    @file_put_contents($path, json_encode(['at' => time(), 'data' => $data]));
    return $data;
}

function jf_overview(): array {
    $info = jf_get('/System/Info');
    if (!$info['ok']) {
        return ['ok' => false, 'error' => $info['error'] ?: ('Jellyfin returned HTTP ' . $info['status'])];
    }
    $i = $info['data'] ?? [];
    $counts = jf_get('/Items/Counts')['data'] ?? [];
    $usersRaw = jf_get('/Users')['data'] ?? [];
    $act = jf_get('/System/ActivityLog/Entries', ['limit' => 15])['data'] ?? [];
    $folders = jf_get('/Library/VirtualFolders')['data'] ?? [];

    $users = array_map(fn($u) => [
        'name' => $u['Name'] ?? '?', 'lastActive' => $u['LastActivityDate'] ?? '',
        'admin' => (bool) ($u['Policy']['IsAdministrator'] ?? false),
        'disabled' => (bool) ($u['Policy']['IsDisabled'] ?? false),
    ], is_array($usersRaw) ? $usersRaw : []);
    usort($users, fn($a, $b) => strcmp($b['lastActive'], $a['lastActive']));

    $activity = array_map(fn($e) => [
        'name' => $e['Name'] ?? '', 'overview' => $e['ShortOverview'] ?? '',
        'type' => $e['Type'] ?? '', 'date' => $e['Date'] ?? '', 'severity' => $e['Severity'] ?? 'Information',
    ], $act['Items'] ?? []);

    $libraries = array_map(fn($f) => [
        'name' => $f['Name'] ?? '', 'id' => $f['ItemId'] ?? '', 'type' => $f['CollectionType'] ?? '',
    ], is_array($folders) ? $folders : []);

    return [
        'ok' => true,
        'server' => [
            'name'    => $i['ServerName'] ?? 'Jellyfin',
            'version' => $i['Version'] ?? '?',
            'os'      => $i['OperatingSystemDisplayName'] ?? '',
            'pendingRestart' => (bool) ($i['HasPendingRestart'] ?? false),
            'shuttingDown'   => (bool) ($i['IsShuttingDown'] ?? false),
        ],
        'counts' => [
            'movies'   => (int) ($counts['MovieCount'] ?? 0),
            'series'   => (int) ($counts['SeriesCount'] ?? 0),
            'episodes' => (int) ($counts['EpisodeCount'] ?? 0),
            'songs'    => (int) ($counts['SongCount'] ?? 0),
        ],
        'userCount' => count($users),
        'sessions'  => jf_sessions(),
        'users'     => $users,
        'activity'  => $activity,
        'libraries' => $libraries,
    ];
}
