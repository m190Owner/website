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

  loadOverview();
  setInterval(loadOverview, 30000);
  setInterval(loadSessions, 5000);
})();
