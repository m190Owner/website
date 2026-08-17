// Analysis tab — Maltego-style entity graph on a canvas with a small force-directed
// layout. Reads nodes/edges from #os-graph-data. No external libraries.
(function () {
  var host = document.getElementById('os-graph-data');
  var canvas = document.getElementById('os-graph');
  if (!host || !canvas) return;
  var data;
  try { data = JSON.parse(host.textContent); } catch (e) { return; }
  var nodes = data.nodes || [], edges = data.edges || [];
  if (nodes.length < 2) return;

  var COLORS = { email: '#37c98b', username: '#6ea8fe', domain: '#c59bf5', phone: '#e0a83a', account: '#8b98a6', breach: '#e5695f' };
  var ANCHOR = { email: 1, username: 1, domain: 1, phone: 1 };
  var byId = {}; nodes.forEach(function (n) { byId[n.id] = n; });

  // Adjacency (for focus highlighting + degree-based sizing).
  var deg = {}, adj = {};
  nodes.forEach(function (n) { deg[n.id] = 0; adj[n.id] = {}; });
  edges = edges.filter(function (e) { return byId[e.from] && byId[e.to]; });
  edges.forEach(function (e) { deg[e.from]++; deg[e.to]++; adj[e.from][e.to] = 1; adj[e.to][e.from] = 1; });

  var ctx = canvas.getContext('2d');
  var W = 0, H = 0, dpr = Math.min(window.devicePixelRatio || 1, 2);
  var focus = null, hover = null, drag = null, downAt = null, moved = false;

  function radius(n) { return (ANCHOR[n.type] ? 8 : 5) + Math.min(6, deg[n.id]); }

  function resize() {
    var wrap = canvas.parentNode;
    W = wrap.clientWidth; H = Math.max(360, Math.min(560, Math.round(W * 0.6)));
    canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
    canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function seed() {
    // Anchors near centre, leaves on a ring — gives the sim a sane starting point.
    var cx = W / 2, cy = H / 2, i = 0, a = 0, l = 0;
    var na = nodes.filter(function (n) { return ANCHOR[n.type]; }).length || 1;
    var nl = nodes.length - na || 1;
    nodes.forEach(function (n) {
      if (ANCHOR[n.type]) { var t = (a++ / na) * Math.PI * 2; n.x = cx + Math.cos(t) * 60; n.y = cy + Math.sin(t) * 60; }
      else { var u = (l++ / nl) * Math.PI * 2; n.x = cx + Math.cos(u) * Math.min(cx, cy) * 0.8; n.y = cy + Math.sin(u) * Math.min(cx, cy) * 0.8; }
      n.vx = 0; n.vy = 0; i++;
    });
    alpha = 1;
  }

  var alpha = 1;
  function tick() {
    var REP = 2600, LEN = 74, SPRING = 0.03, CENTER = 0.02, DAMP = 0.85;
    for (var i = 0; i < nodes.length; i++) {
      var a = nodes[i];
      for (var j = i + 1; j < nodes.length; j++) {
        var b = nodes[j];
        var dx = a.x - b.x, dy = a.y - b.y, d2 = dx * dx + dy * dy + 0.01;
        var f = REP / d2, d = Math.sqrt(d2);
        var fx = (dx / d) * f, fy = (dy / d) * f;
        a.vx += fx; a.vy += fy; b.vx -= fx; b.vy -= fy;
      }
    }
    edges.forEach(function (e) {
      var a = byId[e.from], b = byId[e.to];
      var dx = b.x - a.x, dy = b.y - a.y, d = Math.sqrt(dx * dx + dy * dy) + 0.01;
      var f = (d - LEN) * SPRING;
      var fx = (dx / d) * f, fy = (dy / d) * f;
      a.vx += fx; a.vy += fy; b.vx -= fx; b.vy -= fy;
    });
    var cx = W / 2, cy = H / 2;
    nodes.forEach(function (n) {
      n.vx += (cx - n.x) * CENTER; n.vy += (cy - n.y) * CENTER;
      if (n === drag) return;
      n.vx *= DAMP; n.vy *= DAMP;
      n.x += n.vx * alpha; n.y += n.vy * alpha;
      var r = radius(n) + 2;
      n.x = Math.max(r, Math.min(W - r, n.x)); n.y = Math.max(r, Math.min(H - r, n.y));
    });
    alpha *= 0.992; if (alpha < 0.02) alpha = 0.02;
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    // edges
    edges.forEach(function (e) {
      var a = byId[e.from], b = byId[e.to];
      var dim = focus && !(e.from === focus || e.to === focus);
      ctx.strokeStyle = dim ? 'rgba(148,163,184,0.10)' : 'rgba(148,163,184,0.35)';
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
    });
    // nodes
    nodes.forEach(function (n) {
      var dim = focus && n.id !== focus && !adj[focus][n.id];
      var r = radius(n);
      ctx.globalAlpha = dim ? 0.28 : 1;
      ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2);
      ctx.fillStyle = COLORS[n.type] || '#93a4bd'; ctx.fill();
      if (n.id === hover || n.id === focus) { ctx.lineWidth = 2; ctx.strokeStyle = '#e2e8f0'; ctx.stroke(); }
      // label for anchors always; leaves only when not dimmed and graph is small-ish
      if (ANCHOR[n.type] || !dim) {
        ctx.globalAlpha = dim ? 0.35 : 0.92;
        ctx.fillStyle = '#cbd5e1';
        ctx.font = (ANCHOR[n.type] ? '600 ' : '') + '11px system-ui, sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        var lbl = n.label.length > 22 ? n.label.slice(0, 21) + '…' : n.label;
        ctx.fillText(lbl, n.x, n.y + r + 2);
      }
      ctx.globalAlpha = 1;
    });
  }

  function frame() { tick(); draw(); requestAnimationFrame(frame); }

  function at(mx, my) {
    var best = null, bd = 1e9;
    nodes.forEach(function (n) {
      var dx = n.x - mx, dy = n.y - my, d = dx * dx + dy * dy, r = radius(n) + 6;
      if (d < r * r && d < bd) { bd = d; best = n; }
    });
    return best;
  }
  function pos(ev) {
    var rect = canvas.getBoundingClientRect();
    var t = ev.touches ? ev.touches[0] : ev;
    return { x: t.clientX - rect.left, y: t.clientY - rect.top };
  }

  var tip = document.getElementById('os-graph-tip');
  function showTip(n, p) {
    if (!tip) return;
    if (!n) { tip.hidden = true; return; }
    tip.innerHTML = '<b>' + n.label.replace(/[<>&]/g, '') + '</b><span>' + n.type + (n.sub ? ' · ' + n.sub.replace(/[<>&]/g, '') : '') + '</span>';
    tip.style.left = Math.min(W - 160, p.x + 12) + 'px'; tip.style.top = (p.y + 12) + 'px'; tip.hidden = false;
  }

  canvas.addEventListener('mousemove', function (ev) {
    var p = pos(ev);
    if (drag) { drag.x = p.x; drag.y = p.y; drag.vx = 0; drag.vy = 0; moved = true; alpha = Math.max(alpha, 0.5); showTip(null); return; }
    var n = at(p.x, p.y); hover = n ? n.id : null;
    canvas.style.cursor = n ? 'pointer' : 'default';
    showTip(n, p);
  });
  canvas.addEventListener('mousedown', function (ev) { var p = pos(ev); drag = at(p.x, p.y); downAt = p; moved = false; });
  window.addEventListener('mouseup', function (ev) {
    if (drag && !moved) { if (drag.url) window.open(drag.url, '_blank', 'noopener'); else focus = (focus === drag.id ? null : drag.id); }
    drag = null;
  });
  // touch
  canvas.addEventListener('touchstart', function (ev) { var p = pos(ev); drag = at(p.x, p.y); moved = false; if (drag) ev.preventDefault(); }, { passive: false });
  canvas.addEventListener('touchmove', function (ev) { if (!drag) return; var p = pos(ev); drag.x = p.x; drag.y = p.y; drag.vx = 0; drag.vy = 0; moved = true; alpha = Math.max(alpha, 0.5); ev.preventDefault(); }, { passive: false });
  window.addEventListener('touchend', function () { if (drag && !moved) { if (drag.url) window.open(drag.url, '_blank', 'noopener'); else focus = (focus === drag.id ? null : drag.id); } drag = null; });

  var resetBtn = document.getElementById('os-graph-reset');
  if (resetBtn) resetBtn.addEventListener('click', function () { focus = null; resize(); seed(); });

  window.addEventListener('resize', function () { resize(); alpha = Math.max(alpha, 0.3); });
  resize(); seed(); frame();
})();
