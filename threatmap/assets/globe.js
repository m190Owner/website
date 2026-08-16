(function () {
  'use strict';
  var cvs = document.getElementById('globe'), ctx = cvs.getContext('2d');
  var DPR = Math.min(2, window.devicePixelRatio || 1);
  var W = 0, H = 0, cx = 0, cy = 0, R = 0;

  var yaw = 0.3, pitch = 0.35, drag = null;
  var land = [], threats = [], nodes = [], arcs = [], feedRows = [];
  var hovered = null, pinned = null;

  var COL = { c2: '#ff4d5e', attacker: '#ffb020', tgt: '#38e1c2', land: '#3a7f9c' };
  var TARGETS = [
    ['US', 38, -97], ['DE', 51, 10], ['NL', 52.2, 5.3], ['GB', 54, -2], ['FR', 46, 2],
    ['JP', 36, 138], ['SG', 1.35, 103.8], ['KR', 36.5, 128], ['IN', 22, 79],
    ['BR', -10, -52], ['AU', -25, 133], ['CA', 56, -106]
  ].map(function (t) { return { cc: t[0], v: vec(t[1], t[2]), flash: 0 }; });

  function vec(lat, lon) { var a = lat * Math.PI / 180, b = lon * Math.PI / 180, c = Math.cos(a); return [c * Math.sin(b), Math.sin(a), c * Math.cos(b)]; }
  function rot(v) {
    var x = v[0], y = v[1], z = v[2], cw = Math.cos(yaw), sw = Math.sin(yaw);
    var x1 = x * cw + z * sw, z1 = -x * sw + z * cw;
    var cp = Math.cos(pitch), sp = Math.sin(pitch);
    return [x1, y * cp - z1 * sp, y * sp + z1 * cp];
  }
  function proj(v) { var r = rot(v); return { x: cx + r[0] * R, y: cy - r[1] * R, z: r[2] }; }

  function resize() {
    W = window.innerWidth; H = window.innerHeight;
    cvs.width = W * DPR; cvs.height = H * DPR; ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    cx = W / 2; cy = H / 2; R = Math.min(W, H) * 0.42;
  }
  window.addEventListener('resize', resize); resize();

  function greatArc(a, b) {
    var d = Math.max(-1, Math.min(1, a[0] * b[0] + a[1] * b[1] + a[2] * b[2])), om = Math.acos(d), so = Math.sin(om), pts = [], N = 32;
    for (var i = 0; i <= N; i++) {
      var t = i / N, x, y, z;
      if (so < 1e-4) { x = a[0]; y = a[1]; z = a[2]; }
      else { var s1 = Math.sin((1 - t) * om) / so, s2 = Math.sin(t * om) / so; x = s1 * a[0] + s2 * b[0]; y = s1 * a[1] + s2 * b[1]; z = s1 * a[2] + s2 * b[2]; }
      var lift = 1 + 0.23 * Math.sin(Math.PI * t);
      pts.push([x * lift, y * lift, z * lift]);
    }
    return pts;
  }

  function drawSphere() {
    var g = ctx.createRadialGradient(cx - R * 0.3, cy - R * 0.35, R * 0.1, cx, cy, R);
    g.addColorStop(0, '#0e2036'); g.addColorStop(1, '#060b16');
    ctx.beginPath(); ctx.arc(cx, cy, R, 0, 6.283); ctx.fillStyle = g; ctx.fill();
    ctx.lineWidth = 1; ctx.strokeStyle = 'rgba(56,225,194,.22)'; ctx.stroke();
  }

  function drawGraticule() {
    ctx.strokeStyle = 'rgba(122,162,255,.10)'; ctx.lineWidth = 0.7;
    var lat, lon, first, p;
    for (lon = -150; lon <= 180; lon += 30) {
      ctx.beginPath(); first = true;
      for (lat = -90; lat <= 90; lat += 6) { p = proj(vec(lat, lon)); if (p.z <= 0) { first = true; continue; } if (first) { ctx.moveTo(p.x, p.y); first = false; } else ctx.lineTo(p.x, p.y); }
      ctx.stroke();
    }
    for (lat = -60; lat <= 60; lat += 30) {
      ctx.beginPath(); first = true;
      for (lon = -180; lon <= 180; lon += 6) { p = proj(vec(lat, lon)); if (p.z <= 0) { first = true; continue; } if (first) { ctx.moveTo(p.x, p.y); first = false; } else ctx.lineTo(p.x, p.y); }
      ctx.stroke();
    }
  }

  function drawLand() {
    ctx.strokeStyle = COL.land; ctx.lineWidth = 0.9; ctx.globalAlpha = 0.85;
    for (var r = 0; r < land.length; r++) {
      var ring = land[r], first = true;
      ctx.beginPath();
      for (var i = 0; i < ring.length; i++) {
        var p = proj(vec(ring[i][1], ring[i][0]));
        if (p.z <= 0.02) { first = true; continue; }
        if (first) { ctx.moveTo(p.x, p.y); first = false; } else ctx.lineTo(p.x, p.y);
      }
      ctx.stroke();
    }
    ctx.globalAlpha = 1;
  }

  function drawTargets() {
    for (var i = 0; i < TARGETS.length; i++) {
      var t = TARGETS[i], p = proj(t.v); if (p.z <= 0) continue;
      ctx.beginPath(); ctx.arc(p.x, p.y, 2.4, 0, 6.283); ctx.fillStyle = COL.tgt; ctx.fill();
      if (t.flash > 0.02) {
        ctx.beginPath(); ctx.arc(p.x, p.y, 3 + (1 - t.flash) * 16, 0, 6.283);
        ctx.strokeStyle = COL.tgt; ctx.globalAlpha = t.flash * 0.8; ctx.lineWidth = 1.4; ctx.stroke(); ctx.globalAlpha = 1;
        t.flash *= 0.92;
      }
    }
  }

  function drawNodes(now) {
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i], p = proj(n.v); n._sx = p.x; n._sy = p.y; n._front = p.z > 0;
      if (p.z <= 0) continue;
      var col = n.cat === 'c2' ? COL.c2 : COL.attacker;
      var pulse = 0.5 + 0.5 * Math.sin(now / 600 + i);
      ctx.beginPath(); ctx.arc(p.x, p.y, 6 + pulse * 3, 0, 6.283);
      ctx.fillStyle = col; ctx.globalAlpha = 0.10 + pulse * 0.08; ctx.fill(); ctx.globalAlpha = 1;
      var big = (n === hovered || n === pinned);
      ctx.beginPath(); ctx.arc(p.x, p.y, big ? 3.6 : 2, 0, 6.283); ctx.fillStyle = col; ctx.fill();
      if (big) { ctx.beginPath(); ctx.arc(p.x, p.y, 7, 0, 6.283); ctx.strokeStyle = col; ctx.lineWidth = 1.4; ctx.stroke(); }
    }
  }

  function drawArcs() {
    for (var a = arcs.length - 1; a >= 0; a--) {
      var arc = arcs[a], pts = arc.pts, n = pts.length, lead = Math.floor(arc.t * (n - 1));
      ctx.strokeStyle = arc.col; ctx.shadowColor = arc.col;
      for (var s = 0; s < n - 1 && s < lead; s++) {
        var p1 = proj(pts[s]), p2 = proj(pts[s + 1]), front = (p1.z + p2.z) > 0;
        ctx.globalAlpha = front ? 0.9 : 0.1; ctx.lineWidth = front ? 1.7 : 0.8; ctx.shadowBlur = front ? 6 : 0;
        ctx.beginPath(); ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y); ctx.stroke();
      }
      ctx.shadowBlur = 0; ctx.globalAlpha = 1;
      var hp = proj(pts[Math.min(lead, n - 1)]);
      if (hp.z > -0.1) { ctx.beginPath(); ctx.arc(hp.x, hp.y, 2.6, 0, 6.283); ctx.fillStyle = '#fff'; ctx.shadowColor = arc.col; ctx.shadowBlur = 8; ctx.fill(); ctx.shadowBlur = 0; }
      arc.t += arc.sp;
      if (arc.t >= 1 && !arc.done) { arc.done = true; arc.tgt.flash = 1; }
      if (arc.t >= 1.12) arcs.splice(a, 1);
    }
  }

  function frame(now) {
    ctx.clearRect(0, 0, W, H);
    drawSphere(); drawGraticule(); drawLand(); drawArcs(); drawTargets(); drawNodes(now || 0);
    requestAnimationFrame(frame);
  }

  function spawnArc() {
    if (nodes.length === 0 || arcs.length > 18) return;
    var n = nodes[(Math.random() * nodes.length) | 0], tg = TARGETS[(Math.random() * TARGETS.length) | 0];
    arcs.push({ pts: greatArc(n.v, tg.v), t: 0, sp: 0.008 + Math.random() * 0.006, col: n.cat === 'c2' ? COL.c2 : COL.attacker, tgt: tg, done: false });
    pushFeed(n, tg);
  }

  function pushFeed(n, tg) {
    feedRows.unshift({ kind: n.kind, from: n.cc, to: tg.cc, cat: n.cat, rep: n.reports });
    if (feedRows.length > 6) feedRows.pop();
    var el = document.getElementById('tm-feed'); if (!el) return;
    el.innerHTML = '';
    feedRows.forEach(function (f) {
      var d = document.createElement('div');
      d.className = 'tm-feed-row' + (f.cat === 'attacker' ? ' atk' : '');
      d.innerHTML = '<span>' + esc(f.kind) + '</span><span class="k">' + esc(f.from) + ' &rarr; ' + esc(f.to) + '</span>';
      el.appendChild(d);
    });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function ago(ts) { var d = Date.now() / 1000 - ts; if (d < 90) return 'moments ago'; if (d < 3600) return Math.floor(d / 60) + 'm ago'; if (d < 86400) return Math.floor(d / 3600) + 'h ago'; return Math.floor(d / 86400) + 'd ago'; }

  function buildNodes() { nodes = threats.map(function (t) { var o = Object.assign({}, t); o.v = vec(t.lat, t.lon); return o; }); }

  function setStats(view) {
    document.getElementById('tm-total').textContent = view.count || 0;
    document.getElementById('tm-c2').textContent = (view.cats && view.cats.c2) || 0;
    document.getElementById('tm-atk').textContent = (view.cats && view.cats.attacker) || 0;
    var u = document.getElementById('tm-updated');
    u.textContent = view.count ? ('● live · ' + view.count + ' sources · updated ' + (view.updated ? ago(view.updated) : 'now')) : 'awaiting feed data…';
  }

  function showPanel(n) {
    var p = document.getElementById('tm-panel');
    if (!n) { p.hidden = true; return; }
    document.getElementById('tm-panel-kind').textContent = n.kind;
    var rows = [
      ['source ip', n.ip], ['country', (n.land || n.cc) + ' (' + n.cc + ')'],
      ['network', n.asname || '—'], ['type', n.cat === 'c2' ? 'botnet C2' : 'attacker'],
    ];
    if (n.port) rows.push(['port', n.port]);
    if (n.reports) rows.push(['reports', Number(n.reports).toLocaleString()]);
    rows.push(['feed', n.src]);
    if (n.seen) rows.push(['last seen', ago(n.seen)]);
    document.getElementById('tm-panel-tbl').innerHTML = rows.map(function (r) { return '<tr><td>' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>'; }).join('');
    p.hidden = false;
  }

  function pick(mx, my) {
    var best = null, bd = 13 * 13;
    for (var i = 0; i < nodes.length; i++) { var n = nodes[i]; if (!n._front) continue; var dx = n._sx - mx, dy = n._sy - my, d = dx * dx + dy * dy; if (d < bd) { bd = d; best = n; } }
    return best;
  }

  cvs.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY, yaw: yaw, pitch: pitch, moved: 0 }; cvs.setPointerCapture(e.pointerId); });
  cvs.addEventListener('pointermove', function (e) {
    if (drag) {
      drag.moved += Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y);
      yaw = drag.yaw + (e.clientX - drag.x) * 0.005;
      pitch = Math.max(-1.2, Math.min(1.2, drag.pitch + (e.clientY - drag.y) * 0.005));
    } else {
      var n = pick(e.clientX, e.clientY); hovered = n; cvs.style.cursor = n ? 'pointer' : 'grab';
      if (n && !pinned) showPanel(n); else if (!n && !pinned) showPanel(null);
    }
  });
  cvs.addEventListener('pointerup', function (e) {
    if (drag && drag.moved < 5) { var n = pick(e.clientX, e.clientY); pinned = n; showPanel(n); }
    drag = null;
  });
  cvs.addEventListener('pointerleave', function () { if (!pinned) { hovered = null; showPanel(null); } });
  document.getElementById('tm-panel-x').addEventListener('click', function () { pinned = null; showPanel(null); });

  function loadFeed() {
    fetch('/threatmap/feed.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (v) { threats = v.threats || []; buildNodes(); setStats(v); })
      .catch(function () { });
  }

  fetch('/threatmap/assets/world-land.json?v=1')
    .then(function (r) { return r.json(); })
    .then(function (rings) { land = rings || []; })
    .catch(function () { })
    .finally(function () { requestAnimationFrame(frame); });

  loadFeed();
  setInterval(loadFeed, 60000);
  setInterval(spawnArc, 1100);
})();
