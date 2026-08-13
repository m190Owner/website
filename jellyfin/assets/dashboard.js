// Jellyfin dashboard client. Polls the owner-only proxy and renders live state.
// Sends only intent (action names + a session id); the proxy holds the API key.
(function () {
  const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  const $ = (id) => document.getElementById(id);
  const conn = $('jf-conn'), ctlMsg = $('jf-ctl-msg');

  async function api(action, body) {
    const data = new URLSearchParams(Object.assign({ csrf, action }, body || {}));
    try {
      const r = await fetch('/jellyfin/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: data,
      });
      return await r.json();
    } catch (e) { return { ok: false, error: 'network error' }; }
  }

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function fmtTicks(t) {
    if (!t) return '0:00';
    let s = Math.floor(t / 1e7), h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); s = s % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
  }
  function ago(iso) {
    if (!iso) return '';
    const d = (Date.now() - new Date(iso).getTime()) / 1000;
    if (d < 45) return 'just now';
    if (d < 3600) return Math.round(d / 60) + 'm ago';
    if (d < 86400) return Math.round(d / 3600) + 'h ago';
    return Math.round(d / 86400) + 'd ago';
  }
  function setConn(ok, text) { conn.className = 'jf-status ' + (ok ? 'ok' : 'err'); conn.innerHTML = '<span class="jf-dot"></span> ' + esc(text); }
  function flash(t, bad) { ctlMsg.textContent = t; ctlMsg.style.color = bad ? 'var(--red)' : 'var(--green)'; }

  // ---- now playing ----
  function nowPlayingCard(s) {
    const np = s.nowPlaying;
    const img = np.imageItemId
      ? '/jellyfin/api.php?action=image&item=' + encodeURIComponent(np.imageItemId) + (np.imageTag ? '&tag=' + encodeURIComponent(np.imageTag) : '')
      : '';
    const pct = np.runTimeTicks ? Math.min(100, np.positionTicks / np.runTimeTicks * 100) : 0;
    const isTc = np.playMethod === 'Transcode';
    const badge = np.paused ? '<span class="jf-badge paused">paused</span>'
      : isTc ? '<span class="jf-badge transcode" title="' + esc((np.transcode && np.transcode.reasons || []).join(', ')) + '">transcode</span>'
             : '<span class="jf-badge direct">direct play</span>';
    const ctl = s.canControl ? (
      '<button class="jf-btn" data-act="' + (np.paused ? 'unpause' : 'pause') + '" data-s="' + esc(s.id) + '">' + (np.paused ? '▶ Resume' : '⏸ Pause') + '</button>' +
      '<button class="jf-btn jf-btn-danger" data-act="stop" data-s="' + esc(s.id) + '">⏹ Stop</button>' +
      '<button class="jf-btn" data-act="message" data-s="' + esc(s.id) + '">✉ Message</button>'
    ) : '<span class="jf-dim">no remote control</span>';
    return '<div class="jf-np">' +
      (img ? '<img class="jf-np-poster" src="' + img + '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">' : '<div class="jf-np-poster"></div>') +
      '<div class="jf-np-mid">' +
        '<div class="jf-np-title">' + esc(np.title) + '</div>' +
        '<div class="jf-np-sub">' + badge + '<span>👤 ' + esc(s.user) + '</span><span>' + esc(s.client || s.device) + '</span>' + (s.remote ? '<span>' + esc(s.remote) + '</span>' : '') + '</div>' +
        '<div class="jf-prog"><div class="jf-prog-bar" style="width:' + pct.toFixed(1) + '%"></div></div>' +
        '<div class="jf-np-time">' + fmtTicks(np.positionTicks) + ' / ' + fmtTicks(np.runTimeTicks) + '</div>' +
      '</div>' +
      '<div class="jf-np-ctl">' + ctl + '</div>' +
    '</div>';
  }

  function renderSessions(sessions) {
    const playing = (sessions || []).filter((s) => s.nowPlaying);
    $('np-count').textContent = playing.length ? playing.length + ' streaming' : '';
    const wrap = $('jf-nowplaying');
    wrap.innerHTML = playing.length ? playing.map(nowPlayingCard).join('') : '<p class="jf-empty">Nothing playing right now.</p>';
  }

  function renderOverview(o) {
    $('s-movies').textContent = o.counts.movies;
    $('s-series').textContent = o.counts.series;
    $('s-episodes').textContent = o.counts.episodes;
    $('s-users').textContent = o.userCount;
    $('s-server').textContent = o.server.name.trim();
    $('s-version').textContent = 'Jellyfin ' + o.server.version + (o.server.pendingRestart ? ' · restart pending' : '');

    $('jf-activity').innerHTML = (o.activity || []).map((a) => {
      const cls = a.severity === 'Error' ? 'jf-act-err' : (a.severity === 'Warning' ? 'jf-act-warn' : '');
      return '<li class="' + cls + '"><span class="jf-act-name">' + esc(a.name) + '</span>' +
        (a.overview ? ' <span class="jf-act-meta">— ' + esc(a.overview) + '</span>' : '') +
        '<div class="jf-act-meta">' + ago(a.date) + '</div></li>';
    }).join('') || '<li class="jf-empty">No recent activity.</li>';

    $('jf-users').innerHTML = (o.users || []).map((u) =>
      '<li><span class="jf-u-name">' + esc(u.name) + (u.admin ? '<span class="jf-tag">admin</span>' : '') + (u.disabled ? '<span class="jf-tag off">disabled</span>' : '') + '</span>' +
      '<span class="jf-dim">' + (u.lastActive ? ago(u.lastActive) : 'never') + '</span></li>'
    ).join('') || '<li class="jf-empty">No users.</li>';

    renderSessions(o.sessions);
  }

  async function loadOverview() {
    const o = await api('overview');
    if (!o || !o.ok) { setConn(false, (o && o.error) || 'cannot reach Jellyfin'); return; }
    setConn(true, o.server.name.trim() + ' · Jellyfin ' + o.server.version);
    renderOverview(o);
  }
  async function loadSessions() {
    const r = await api('sessions');
    if (r && r.ok) renderSessions(r.sessions);
  }

  // ---- controls ----
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const act = btn.dataset.act, sid = btn.dataset.s;
    if (act === 'stop' && !confirm('Stop this stream?')) return;
    let body = { session: sid };
    if (act === 'message') { const t = prompt('Message to show on their screen:'); if (t == null || !t.trim()) return; body.text = t; }
    btn.disabled = true;
    const r = await api(act, body);
    if (!r || !r.ok) flash((r && r.error) || 'failed', true); else flash(act + ' sent');
    setTimeout(loadSessions, 700);
  });

  $('jf-scan') && $('jf-scan').addEventListener('click', async () => {
    const b = $('jf-scan'); b.disabled = true;
    const r = await api('scan');
    flash(r && r.ok ? 'Library scan started.' : ((r && r.error) || 'failed'), !(r && r.ok));
    setTimeout(() => b.disabled = false, 3000);
  });
  $('jf-restart') && $('jf-restart').addEventListener('click', async () => {
    if (!confirm('Restart the Jellyfin server now? Active streams will drop for ~15–30s.')) return;
    const b = $('jf-restart'); b.disabled = true;
    const r = await api('restart');
    flash(r && r.ok ? 'Restart triggered — reconnecting…' : ((r && r.error) || 'failed'), !(r && r.ok));
    setConn(false, 'restarting…');
    setTimeout(loadOverview, 20000);
    setTimeout(() => b.disabled = false, 20000);
  });

  // ---- media server stack ----
  function ctClass(c) {
    var st = (c.state || '').toLowerCase(), h = (c.health || '').toLowerCase();
    if (h === 'unhealthy' || h === 'starting' || st === 'restarting' || st === 'paused') return 'warn';
    if (st === 'running') return 'up';
    return 'down';   // exited / created / dead / removing
  }
  function fmtSpeed(bps) {
    bps = bps || 0;
    if (bps >= 1e6) return (bps / 1e6).toFixed(1) + ' MB/s';
    if (bps >= 1e3) return Math.round(bps / 1e3) + ' KB/s';
    return bps + ' B/s';
  }
  var SVC_OF = { qbittorrent: 'qbit', sonarr: 'sonarr', radarr: 'radarr', lidarr: 'lidarr', prowlarr: 'prowlarr' };
  var torrentsOpen = false;
  function fmtSize(b) { b = b || 0; if (b >= 1e12) return (b / 1e12).toFixed(2) + ' TB'; if (b >= 1e9) return (b / 1e9).toFixed(1) + ' GB'; if (b >= 1e6) return (b / 1e6).toFixed(0) + ' MB'; return (b / 1e3).toFixed(0) + ' KB'; }
  function fmtEta(s) { if (!s || s >= 8640000) return ''; if (s >= 3600) return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm'; if (s >= 60) return Math.floor(s / 60) + 'm'; return s + 's'; }
  function torrentRow(t) {
    var pct = Math.round((t.progress || 0) * 100);
    var speed = t.dl > 0 ? '↓ ' + fmtSpeed(t.dl) : (t.up > 0 ? '↑ ' + fmtSpeed(t.up) : '');
    var eta = t.dl > 0 ? fmtEta(t.eta) : '';
    return '<div class="jf-tor"><div class="jf-tor-main"><div class="jf-tor-name">' + esc(t.name) + '</div>' +
      '<div class="jf-tor-bar"><div class="jf-tor-fill' + (t.dl > 0 ? ' dl' : '') + '" style="width:' + pct + '%"></div></div></div>' +
      '<div class="jf-tor-meta"><b>' + pct + '%</b>' + (speed ? '<span>' + speed + '</span>' : '') +
      '<span class="jf-dim">' + fmtSize(t.size) + (t.cat ? ' · ' + esc(t.cat) : '') + (eta ? ' · ' + eta : '') + '</span></div></div>';
  }
  function diskBar(label, d) {
    if (!d || !d.total) return '';
    var pct = d.pct || Math.round(d.used / d.total * 100);
    var cls = pct >= 90 ? 'crit' : pct >= 78 ? 'warn' : '';
    return '<div class="jf-diskrow"><div class="jf-diskrow-top"><span>' + label + '</span>' +
      '<span class="jf-dim">' + fmtSize(d.used) + ' / ' + fmtSize(d.total) + ' · ' + fmtSize(d.free) + ' free</span></div>' +
      '<div class="jf-diskbar"><div class="jf-diskfill ' + cls + '" style="width:' + pct + '%"></div></div></div>';
  }
  function renderDisk(disk) { $('jf-disk').innerHTML = disk ? (diskBar('Media volume', disk.media) + diskBar('Host drive (C:)', disk.host)) : ''; }
  // ---- trends: media-disk sparkline + fill projection ----
  function sparkline(vals, w, h, color) {
    if (!vals.length) return '';
    var min = Math.min.apply(null, vals), max = Math.max.apply(null, vals), rng = (max - min) || 1;
    var pts = vals.map(function (v, i) { var x = (i / ((vals.length - 1) || 1)) * w; var y = h - ((v - min) / rng) * (h - 4) - 2; return x.toFixed(1) + ',' + y.toFixed(1); }).join(' ');
    return '<svg class="jf-spark" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none"><polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="1.5"/></svg>';
  }
  function renderTrends(r) {
    var el = $('jf-trends'); if (!el) return;
    var s = (r && r.series) || [], p = (r && r.projection) || {};
    if (s.length < 2) { el.innerHTML = ''; return; }
    var mp = s.map(function (x) { return x.mp; }), lastPct = mp[mp.length - 1];
    var color = lastPct >= 90 ? 'var(--red)' : lastPct >= 78 ? 'var(--amber)' : 'var(--accent2)';
    var proj = (p.trend === 'filling' && p.daysToFull != null)
        ? 'Filling · ~' + fmtSize(p.ratePerDay) + '/day · <b>full in ~' + p.daysToFull + ' day' + (p.daysToFull === 1 ? '' : 's') + '</b>'
      : p.trend === 'stable' ? 'Media disk usage is stable'
      : p.trend === 'shrinking' ? 'Media disk usage is shrinking'
      : 'Gathering trend data…';
    el.innerHTML = '<span class="jf-dim jf-trend-lbl">Media disk trend</span>' + sparkline(mp, 180, 32, color) +
      '<span class="jf-trend-proj">' + proj + '</span>';
  }
  async function loadHistory() { var r = await api('history'); if (r && r.ok) renderTrends(r); }
  function renderGrabs(hist) {
    var sec = $('jf-grabs-sec'), ul = $('jf-grabs');
    if (!hist || !hist.length) { sec.style.display = 'none'; return; }
    sec.style.display = '';
    var ICON = { sonarr: '📺', radarr: '🎬', lidarr: '🎵' };
    ul.innerHTML = hist.map(function (h) {
      return '<li><span class="jf-act-name">' + (ICON[h.svc] || '') + ' ' + esc(h.event) + '</span> ' +
        '<span class="jf-act-meta">' + esc(h.title) + '</span><div class="jf-act-meta">' + ago(h.date) + '</div></li>';
    }).join('');
  }
  // ---- Jellyseerr requests (read-only monitor) ----
  function reqStatusLabel(ms) {
    return ms >= 5 ? ['available', 'ok'] : ms === 4 ? ['partial', 'warn'] : ms === 3 ? ['processing', 'proc'] : ms === 2 ? ['pending', 'pend'] : ['requested', 'pend'];
  }
  function renderRequests(js) {
    var sec = $('jf-req-sec'), wrap = $('jf-requests'), cnt = $('jf-req-counts');
    if (!js || !js.ok || !(js.requests && js.requests.length)) { sec.style.display = 'none'; return; }
    sec.style.display = '';
    var c = js.counts || {};
    cnt.textContent = (c.total || 0) + ' total' + (c.processing ? ' · ' + c.processing + ' processing' : '') + (c.pending ? ' · ' + c.pending + ' pending approval' : '') + (c.available ? ' · ' + c.available + ' available' : '');
    wrap.innerHTML = js.requests.map(function (r) {
      var st = reqStatusLabel(r.mediaStatus);
      var poster = r.poster ? 'https://image.tmdb.org/t/p/w92' + r.poster : '';
      return '<div class="jf-req">' +
        (poster ? '<img class="jf-req-poster" src="' + esc(poster) + '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">' : '<div class="jf-req-poster"></div>') +
        '<div class="jf-req-main"><div class="jf-req-title">' + (r.type === 'tv' ? '📺' : '🎬') + ' ' + esc(r.title) + '</div>' +
        '<div class="jf-req-sub"><span class="jf-req-badge ' + st[1] + '">' + st[0] + '</span>' +
        (r.reqStatus === 1 ? '<span class="jf-req-badge pend">needs approval</span>' : '') +
        '<span class="jf-dim">👤 ' + esc(r.user) + ' · ' + ago(r.createdAt) + '</span></div></div></div>';
    }).join('');
  }
  function renderStack(r) {
    var fresh = $('jf-stack-fresh'), vpnEl = $('jf-vpn'), qbitEl = $('jf-qbit'), grid = $('jf-stack');
    var st = r && r.stack;
    if (!st) {
      fresh.textContent = 'waiting for agent…'; fresh.className = 'jf-dim';
      vpnEl.innerHTML = ''; vpnEl.className = ''; qbitEl.innerHTML = ''; qbitEl.className = '';
      grid.innerHTML = '<p class="jf-empty">No report yet — once the status agent on the media-server box is running, its containers show up here.</p>';
      return;
    }
    var age = st.ageSec || 0, stale = age > 120;
    fresh.textContent = 'updated ' + (age < 60 ? age + 's' : Math.round(age / 60) + 'm') + ' ago' + (stale ? ' · stale' : '');
    fresh.className = 'jf-dim jf-stack-fresh' + (stale ? ' stale' : '');
    var svcs = st.services || {};

    var v = st.vpn || {}, vok = v.ok && v.ip, leak = !!v.leak;
    vpnEl.className = 'jf-vpn' + ((leak || !vok) ? ' bad' : '');
    vpnEl.innerHTML = '<span class="jf-vpn-ico">' + (leak ? '🚨' : vok ? '🔒' : '⚠️') + '</span><div class="jf-vpn-main">' +
      '<div class="jf-vpn-title">' + (leak ? 'VPN LEAK' : 'VPN ' + (vok ? 'connected' : 'not confirmed')) + '</div>' +
      '<div class="jf-vpn-sub">' + (leak
        ? 'egress not tunneled' + (v.killed ? ' · qBittorrent auto-paused' : ' · qBittorrent NOT paused — act now')
        : vok
          ? 'torrent egress <span class="jf-vpn-ip">' + esc(v.ip) + '</span>' + (v.country ? ' · ' + esc(v.country) : '')
          : 'gluetun egress IP could not be read') + '</div></div>';

    var qb = svcs.qbit, tl = $('jf-torrents');
    if (qb && qb.ok) {
      var conn = (qb.connection || '') === 'connected';
      var hasList = qb.list && qb.list.length;
      qbitEl.className = 'jf-vpn' + (conn ? '' : ' bad') + (hasList ? ' jf-clickable' : '');
      qbitEl.innerHTML = '<span class="jf-vpn-ico">🌊</span><div class="jf-vpn-main">' +
        '<div class="jf-vpn-title">qBittorrent · ' + esc(qb.connection || 'unknown') + (hasList ? ' <span class="jf-caret">' + (torrentsOpen ? '▾' : '▸') + '</span>' : '') + '</div>' +
        '<div class="jf-vpn-sub">↓ <span class="jf-vpn-ip">' + fmtSpeed(qb.down) + '</span> · ↑ ' + fmtSpeed(qb.up) +
        ' · ' + (qb.torrents || 0) + ' torrents' + (qb.dl ? ' · ' + qb.dl + ' downloading' : '') + (qb.ul ? ' · ' + qb.ul + ' seeding' : '') + '</div></div>';
      tl.innerHTML = hasList ? qb.list.map(torrentRow).join('') : '';
      tl.style.display = (torrentsOpen && hasList) ? 'block' : 'none';
    } else { qbitEl.className = ''; qbitEl.innerHTML = ''; tl.innerHTML = ''; tl.style.display = 'none'; }

    renderDisk(st.disk);
    var cs = st.containers || [];
    grid.innerHTML = cs.length ? cs.map(function (c) {
      var sub = (c.uptime || c.state || '') + (c.health && c.health !== 'none' ? ' · ' + c.health : '');
      var svc = svcs[SVC_OF[c.name]];
      if (svc && svc.ok) {
        if (c.name === 'sonarr' || c.name === 'radarr' || c.name === 'lidarr') sub += ' · queue ' + svc.queue;
        else if (c.name === 'prowlarr') sub += ' · ' + svc.indexers + ' indexers';
        else if (c.name === 'qbittorrent') sub += ' · ↓ ' + fmtSpeed(svc.down);
        if (svc.health > 0) sub += ' · ⚠' + svc.health;
      }
      return '<div class="jf-ct ' + ctClass(c) + '"><span class="jf-ct-dot"></span><div class="jf-ct-body">' +
        '<div class="jf-ct-name">' + esc(c.name) + '</div><div class="jf-ct-sub">' + esc(sub) + '</div></div></div>';
    }).join('') : '<p class="jf-empty">No containers reported.</p>';

    renderGrabs(st.history);
    renderRequests(st.jellyseerr);
  }
  async function loadStack() { var r = await api('stack'); if (r && r.ok) renderStack(r); }

  // qBittorrent card → expand/collapse the per-torrent list
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#jf-qbit')) return;
    var tl = $('jf-torrents'); if (!tl || !tl.innerHTML) return;
    torrentsOpen = !torrentsOpen;
    tl.style.display = torrentsOpen ? 'block' : 'none';
    var car = document.querySelector('#jf-qbit .jf-caret'); if (car) car.textContent = torrentsOpen ? '▾' : '▸';
  });

  loadOverview();
  loadStack();
  loadHistory();
  setInterval(loadOverview, 30000);
  setInterval(loadSessions, 5000);
  setInterval(loadStack, 20000);
  setInterval(loadHistory, 300000);
})();
