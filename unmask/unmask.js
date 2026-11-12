// Unmask — collect what the browser leaks (all client-side, zero permissions), resolve
// geo server-side, and reveal it as a staged theater. Every signal is read defensively:
// a hardened browser that blocks a probe is shown as "blocked (good)", never an error.
(function () {
  'use strict';
  // The optional Signal trail is a clue-only visit: do not run privacy probes.
  if (new URLSearchParams(location.search).get('signal') === '01') return;
  var $ = function (s) { return document.querySelector(s); };
  var stage = $('#stage'), sub = $('#sub');
  var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }

  // ---- defensive signal reader ----
  function g(fn, dflt) { try { var v = fn(); return (v === undefined || v === null || v === '') ? dflt : v; } catch (e) { return dflt; } }

  // ---- reveal primitives ----
  function addLine(html) {
    var d = document.createElement('div'); d.className = 'line'; d.innerHTML = html; stage.appendChild(d);
    requestAnimationFrame(function () { d.classList.add('show'); });
    return d;
  }
  function addCard(title, innerHTML) {
    var c = document.createElement('div'); c.className = 'card';
    c.innerHTML = (title ? '<h2>' + esc(title) + '</h2>' : '') + innerHTML;
    stage.appendChild(c);
    requestAnimationFrame(function () { c.classList.add('show'); });
    return c;
  }
  function kv(rows) {
    return '<dl class="kv">' + rows.map(function (r) {
      return '<dt>' + esc(r[0]) + '</dt><dd class="' + (r[2] || '') + '">' + (r[3] ? r[1] : esc(r[1])) + '</dd>';
    }).join('') + '</dl>';
  }

  // ---- signal collectors ----
  function canvasFp() {
    try {
      var c = document.createElement('canvas'); c.width = 240; c.height = 60;
      var ctx = c.getContext('2d'); if (!ctx) return '';
      ctx.textBaseline = 'top'; ctx.font = "16px 'Arial'";
      ctx.fillStyle = '#f60'; ctx.fillRect(0, 0, 240, 60);
      ctx.fillStyle = '#069'; ctx.fillText('m190 unmask ✨', 4, 4);
      ctx.strokeStyle = 'rgba(0,140,90,.7)'; ctx.beginPath(); ctx.arc(70, 32, 18, 0, 6.283); ctx.stroke();
      return c.toDataURL();
    } catch (e) { return ''; }
  }
  function webgl() {
    try {
      var c = document.createElement('canvas');
      var gl = c.getContext('webgl') || c.getContext('experimental-webgl'); if (!gl) return {};
      var dbg = gl.getExtension('WEBGL_debug_renderer_info');
      return { vendor: dbg ? gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) : '', renderer: dbg ? gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) : '' };
    } catch (e) { return {}; }
  }
  function audioFp() {
    return new Promise(function (res) {
      try {
        var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        if (!Ctx) return res('');
        var ctx = new Ctx(1, 4410, 44100);
        var osc = ctx.createOscillator(); osc.type = 'triangle'; osc.frequency.value = 10000;
        var comp = ctx.createDynamicsCompressor();
        osc.connect(comp); comp.connect(ctx.destination); osc.start(0);
        ctx.startRendering();
        ctx.oncomplete = function (e) {
          var d = e.renderedBuffer.getChannelData(0), sum = 0;
          for (var i = 2000; i < 3000; i++) sum += Math.abs(d[i]);
          res(sum.toString());
        };
        setTimeout(function () { res(''); }, 1200);
      } catch (e) { res(''); }
    });
  }
  function detectFonts() {
    try {
      var base = ['monospace', 'sans-serif', 'serif'];
      var test = ['Arial', 'Arial Black', 'Calibri', 'Cambria', 'Comic Sans MS', 'Consolas', 'Courier New',
        'Georgia', 'Helvetica', 'Impact', 'Lucida Console', 'Palatino Linotype', 'Segoe UI', 'Tahoma',
        'Times New Roman', 'Trebuchet MS', 'Verdana', 'Menlo', 'Monaco', 'Roboto', 'Ubuntu', 'Cantarell',
        'DejaVu Sans', 'Liberation Sans', 'Noto Sans', 'San Francisco', 'SF Pro', 'Gill Sans'];
      var span = document.createElement('span');
      span.style.cssText = 'position:absolute;left:-9999px;font-size:72px'; span.textContent = 'mmmmmmmmmwwwwwww';
      document.body.appendChild(span);
      var baseW = {};
      base.forEach(function (b) { span.style.fontFamily = b; baseW[b] = { w: span.offsetWidth, h: span.offsetHeight }; });
      var found = [];
      test.forEach(function (f) {
        for (var i = 0; i < base.length; i++) {
          span.style.fontFamily = "'" + f + "'," + base[i];
          if (span.offsetWidth !== baseW[base[i]].w || span.offsetHeight !== baseW[base[i]].h) { found.push(f); break; }
        }
      });
      document.body.removeChild(span);
      return found;
    } catch (e) { return []; }
  }
  function localIPs() {
    return new Promise(function (res) {
      var out = { lan: [], masked: false };
      try {
        var RTC = window.RTCPeerConnection || window.webkitRTCPeerConnection;
        if (!RTC) return res(out);
        var pc = new RTC({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
        pc.createDataChannel('x');
        pc.onicecandidate = function (e) {
          if (!e || !e.candidate) { try { pc.close(); } catch (_) {} return res(out); }
          var cand = e.candidate.candidate || '';
          if (/\.local/i.test(cand)) out.masked = true;
          var m = /([0-9]{1,3}(?:\.[0-9]{1,3}){3})/.exec(cand);
          if (m) { var ip = m[1]; if (/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)/.test(ip) && out.lan.indexOf(ip) < 0) out.lan.push(ip); }
        };
        pc.createOffer().then(function (o) { return pc.setLocalDescription(o); }).catch(function () {});
        setTimeout(function () { try { pc.close(); } catch (_) {} res(out); }, 2600);
      } catch (e) { res(out); }
    });
  }
  function battery() {
    return new Promise(function (res) {
      try {
        if (!navigator.getBattery) return res(null);
        navigator.getBattery().then(function (b) { res({ level: Math.round(b.level * 100), charging: b.charging }); }).catch(function () { res(null); });
        setTimeout(function () { res(null); }, 1000);
      } catch (e) { res(null); }
    });
  }
  async function sha(str) {
    try {
      var b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
      return Array.from(new Uint8Array(b)).map(function (x) { return x.toString(16).padStart(2, '0'); }).join('');
    } catch (e) {
      var h = 0; for (var i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0; return ('0000000' + h.toString(16)).slice(-8);
    }
  }
  function parseUA(ua) {
    var os = 'unknown', br = 'unknown';
    if (/Windows NT 10/.test(ua)) os = 'Windows 10/11'; else if (/Windows/.test(ua)) os = 'Windows';
    else if (/Mac OS X/.test(ua)) os = 'macOS'; else if (/Android/.test(ua)) os = 'Android';
    else if (/(iPhone|iPad|iPod)/.test(ua)) os = 'iOS'; else if (/Linux/.test(ua)) os = 'Linux';
    if (/Edg\//.test(ua)) br = 'Edge'; else if (/OPR\//.test(ua)) br = 'Opera';
    else if (/Chrome\//.test(ua)) br = 'Chrome'; else if (/Firefox\//.test(ua)) br = 'Firefox';
    else if (/Safari\//.test(ua)) br = 'Safari';
    var m = ua.match(/(?:Chrome|Firefox|Version|Edg)\/(\d+)/); if (m) br += ' ' + m[1];
    return { os: os, br: br };
  }

  // ---- compact globe (reuses threat-map land data + projection math) ----
  var Globe = (function () {
    var cvs = $('#globe'), ctx = cvs.getContext('2d'), DPR = Math.min(2, window.devicePixelRatio || 1);
    var W, H, cx, cy, R, land = [], raf = 0;
    var yaw = 0.3, pitch = 0.3, spin = true, target = null, pin = null, pinPulse = 0;
    function vec(lat, lon) { var a = lat * Math.PI / 180, b = lon * Math.PI / 180, c = Math.cos(a); return [c * Math.sin(b), Math.sin(a), c * Math.cos(b)]; }
    function rot(v) { var x = v[0], y = v[1], z = v[2], cw = Math.cos(yaw), sw = Math.sin(yaw), x1 = x * cw + z * sw, z1 = -x * sw + z * cw, cp = Math.cos(pitch), sp = Math.sin(pitch); return [x1, y * cp - z1 * sp, y * sp + z1 * cp]; }
    function proj(v) { var r = rot(v); return { x: cx + r[0] * R, y: cy - r[1] * R, z: r[2] }; }
    function resize() { W = innerWidth; H = innerHeight; cvs.width = W * DPR; cvs.height = H * DPR; ctx.setTransform(DPR, 0, 0, DPR, 0, 0); cx = W / 2; cy = H * 0.42; R = Math.min(W, H) * 0.4; }
    function frame(now) {
      raf = requestAnimationFrame(frame);
      if (spin) yaw += 0.0016;
      if (target) { yaw += (target.yaw - yaw) * 0.05; pitch += (target.pitch - pitch) * 0.05; }
      ctx.clearRect(0, 0, W, H);
      var gr = ctx.createRadialGradient(cx - R * 0.3, cy - R * 0.35, R * 0.1, cx, cy, R);
      gr.addColorStop(0, '#0c1a2e'); gr.addColorStop(1, '#04070f');
      ctx.beginPath(); ctx.arc(cx, cy, R, 0, 6.283); ctx.fillStyle = gr; ctx.fill();
      ctx.lineWidth = 1; ctx.strokeStyle = 'rgba(56,225,194,.22)'; ctx.stroke();
      // graticule
      ctx.strokeStyle = 'rgba(122,162,255,.09)'; ctx.lineWidth = 0.6;
      var lat, lon, first, p;
      for (lon = -150; lon <= 180; lon += 30) { ctx.beginPath(); first = true; for (lat = -90; lat <= 90; lat += 6) { p = proj(vec(lat, lon)); if (p.z <= 0) { first = true; continue; } if (first) { ctx.moveTo(p.x, p.y); first = false; } else ctx.lineTo(p.x, p.y); } ctx.stroke(); }
      for (lat = -60; lat <= 60; lat += 30) { ctx.beginPath(); first = true; for (lon = -180; lon <= 180; lon += 6) { p = proj(vec(lat, lon)); if (p.z <= 0) { first = true; continue; } if (first) { ctx.moveTo(p.x, p.y); first = false; } else ctx.lineTo(p.x, p.y); } ctx.stroke(); }
      // land
      ctx.strokeStyle = '#2f7f9c'; ctx.lineWidth = 0.8; ctx.globalAlpha = 0.85;
      for (var r = 0; r < land.length; r++) { var ring = land[r]; first = true; ctx.beginPath(); for (var i = 0; i < ring.length; i++) { p = proj(vec(ring[i][1], ring[i][0])); if (p.z <= 0.02) { first = true; continue; } if (first) { ctx.moveTo(p.x, p.y); first = false; } else ctx.lineTo(p.x, p.y); } ctx.stroke(); }
      ctx.globalAlpha = 1;
      // pin
      if (pin) { var pp = proj(vec(pin.lat, pin.lon)); if (pp.z > 0) { pinPulse = (pinPulse + 0.03) % 1; ctx.beginPath(); ctx.arc(pp.x, pp.y, 3 + pinPulse * 22, 0, 6.283); ctx.strokeStyle = 'rgba(255,77,94,' + (0.8 * (1 - pinPulse)) + ')'; ctx.lineWidth = 2; ctx.stroke(); ctx.beginPath(); ctx.arc(pp.x, pp.y, 4, 0, 6.283); ctx.fillStyle = '#ff4d5e'; ctx.shadowColor = '#ff4d5e'; ctx.shadowBlur = 14; ctx.fill(); ctx.shadowBlur = 0; } }
    }
    return {
      start: function () { resize(); addEventListener('resize', resize); cancelAnimationFrame(raf); raf = requestAnimationFrame(frame); cvs.classList.add('on'); },
      loadLand: function () { return fetch('/threatmap/assets/world-land.json?v=1').then(function (r) { return r.json(); }).then(function (d) { land = d || []; }).catch(function () {}); },
      dropPin: function (lat, lon) { spin = false; pin = { lat: lat, lon: lon }; target = { yaw: -lon * Math.PI / 180, pitch: lat * Math.PI / 180 }; }
    };
  })();

  // ---- the sequence ----
  async function run() {
    sub.innerHTML = '<span class="cursor">establishing connection…</span>';
    Globe.start(); Globe.loadLand();

    // signals in parallel (each degrades gracefully)
    var ua = g(function () { return navigator.userAgent; }, '?');
    var uap = parseUA(ua);
    var gl = webgl();
    var geoP = fetch('/unmask/geo.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.json(); }).catch(function () { return null; });
    var audioP = audioFp(), lanP = localIPs(), batP = battery();
    var canvas = canvasFp(), fonts = detectFonts();

    await sleep(700);

    // --- STAGE 1: location ---
    var geo = await geoP;
    if (geo && geo.geo && geo.geo.lat != null) {
      var G = geo.geo;
      Globe.dropPin(G.lat, G.lon);
      await sleep(600);
      var locRows = [
        ['public IP', esc(geo.ip), 'hot'],
        ['location', esc([G.city, G.region, G.country].filter(Boolean).join(', ')) + (G.flag ? ' ' + G.flag : ''), 'hot', true],
        ['network', esc(G.isp || G.org || '—')],
        ['ASN', esc(G.asn || '—')]
      ];
      if (G.hosting) locRows.push(['note', 'looks like a VPN / hosting network — nice try 😉', 'good']);
      addCard('📍 where you are', kv(locRows));
      sub.textContent = 'I resolved that from your IP alone — before you clicked anything.';
    } else if (geo && geo.local) {
      addCard('📍 where you are', '<p class="line show" style="color:var(--cyn)">You’re on a local/private address here, so there’s no public IP to geolocate. On the live site this pins your city.</p>');
      sub.textContent = 'Running locally — on the live site I pin your city from your IP alone.';
    } else {
      addCard('📍 where you are', '<p class="line show good">Your IP didn’t resolve to a location — you may be on Tor or a hardened VPN. Genuinely well done.</p>');
      sub.textContent = 'Your IP is hidden — but your device still gives you away. Keep reading.';
    }

    await sleep(700);

    // --- STAGE 2: device ---
    var bat = await batP, lan = await lanP;
    var conn = g(function () { return navigator.connection || navigator.webkitConnection; }, null);
    // Firefox / privacy tools hand out a DECOY GPU (a common card + ", or similar"), or a
    // software renderer — don't present that as the real card; flag it as browser masking.
    var gpuRaw = gl.renderer || '';
    var gpuMasked = !gpuRaw || /,\s*or similar/i.test(gpuRaw) || /SwiftShader|llvmpipe|software|Microsoft Basic/i.test(gpuRaw);
    var gpuCard = (gpuRaw.match(/(GeForce[^,)]*|Radeon[^,)]*|Apple M\d[^,)]*|Arc[^,)]*|Iris[^,)]*|UHD[^,)]*)/i) || [])[1];
    if (gpuCard) gpuCard = gpuCard.replace(/\s*(Direct3D|vs_\d|OpenGL|Metal|D3D).*$/i, '').trim();
    var devRows = [
      ['operating system', uap.os],
      ['browser', uap.br],
      ['GPU', gpuMasked ? (gpuCard ? 'decoy — your browser fakes this (reports “' + gpuCard.trim() + '”) to resist fingerprinting. Nice.' : 'hidden by your browser (good)') : gpuRaw, gpuMasked ? 'good' : 'hot'],
      ['screen', g(function () { return screen.width + '×' + screen.height + ' @' + (window.devicePixelRatio || 1) + 'x, ' + screen.colorDepth + '-bit'; }, '?')],
      ['CPU cores', g(function () { return navigator.hardwareConcurrency; }, '?')],
      ['device memory', g(function () { return navigator.deviceMemory ? navigator.deviceMemory + ' GB' : 'hidden'; }, '?')],
      ['timezone', g(function () { return Intl.DateTimeFormat().resolvedOptions().timeZone; }, '?')],
      ['languages', g(function () { return (navigator.languages || [navigator.language]).join(', '); }, '?')],
      ['touch points', g(function () { return navigator.maxTouchPoints; }, 0)]
    ];
    if (bat) devRows.push(['battery', bat.level + '% ' + (bat.charging ? '⚡ charging' : 'on battery'), 'hot']);
    if (conn) devRows.push(['network type', esc((conn.effectiveType || '?') + (conn.downlink ? ' · ~' + conn.downlink + ' Mbps' : ''))]);
    if (lan && lan.lan.length) devRows.push(['local network IP', esc(lan.lan.join(', ')), 'hot']);
    else if (lan && lan.masked) devRows.push(['local network IP', 'masked by your browser (good)', 'good']);
    devRows.push(['fonts detected', fonts.length ? (fonts.length + ' installed — ' + esc(fonts.slice(0, 8).join(', ')) + (fonts.length > 8 ? '…' : '')) : 'blocked (good)', fonts.length ? '' : 'good']);
    addCard('🖥️ your device & network', kv(devRows));

    await sleep(700);

    // --- STAGE 3: fingerprint ---
    var audio = await audioP;
    var stable = [ua, g(function () { return navigator.platform; }, ''), devRows[7][1], devRows[3][1], devRows[4][1],
      gl.renderer || '', gl.vendor || '', canvas, audio, fonts.join(',')].join('|');
    var fp = (await sha(stable)).slice(0, 32);
    // rough entropy estimate
    var bits = 0;
    if (gl.renderer) bits += 5; if (canvas) bits += 6; if (audio) bits += 3;
    bits += Math.min(6, fonts.length * 0.4); bits += 4; // screen/tz/lang/cores baseline
    var oneIn = Math.round(Math.pow(2, Math.min(bits, 33)));
    addCard('🧬 your fingerprint', '<div class="hash">' + esc(fp) + '</div>'
      + '<p class="consent" style="margin-top:10px">A stable ID built from the values above — no cookie needed. Roughly <b style="color:var(--amb)">' + bits.toFixed(0) + ' bits</b> of entropy → about <b style="color:var(--amb)">1 in ' + oneIn.toLocaleString() + '</b> browsers look like this.</p>');

    await sleep(600);

    // --- STAGE 4: uniqueness (real data) ---
    var uCard = addCard('👥 among everyone who’s tried this', '<p class="line show"><span class="spinner"></span> comparing you against every other visitor…</p>');
    var stat = await fetch('/unmask/reveal.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ fp: fp, gpu: gl.renderer || '' })
    }).then(function (r) { return r.json(); }).catch(function () { return null; });
    if (stat && stat.ok) {
      try { localStorage.setItem('unmask_token', stat.token); } catch (e) {}
      var uniqLine = stat.twins === 0
        ? '<div class="unqnote">Nobody else who came here looks exactly like you.</div>'
        : '<div>' + stat.twins + ' other visitor' + (stat.twins > 1 ? 's' : '') + ' share your exact fingerprint.</div>';
      uCard.innerHTML = '<h2>👥 among everyone who’s tried this</h2>'
        + '<div class="big">visitor <span class="u">#' + stat.visitor_no.toLocaleString() + '</span></div>'
        + '<div class="kv"><dt>unique fingerprints</dt><dd>' + stat.unique.toLocaleString() + ' of ' + stat.total.toLocaleString() + '</dd></div>'
        + '<div style="margin-top:10px">' + uniqLine + '</div>';
    } else {
      uCard.innerHTML = '<h2>👥 among everyone who’s tried this</h2><p class="line show good">Couldn’t reach the counter — but your fingerprint above is still yours alone.</p>';
    }

    await sleep(600);

    // --- STAGE 5: the punch ---
    var token = '';
    try { token = localStorage.getItem('unmask_token') || ''; } catch (e) {}
    var punch = addCard('', '');
    punch.className = 'card punch show';
    punch.innerHTML = '<h2>⚠️ the point</h2>'
      + '<p>I just stored everything above — exactly like every site you visit does, silently, right now. The difference is I’m telling you, and I’ll delete it if you ask.</p>'
      + '<div class="btns">'
      + (token ? '<button class="btn btn-del" id="del">🗑️ delete what I stored on you</button>' : '')
      + '<a class="btn btn-ghost" href="/osint/">see your full footprint →</a>'
      + '<a class="btn btn-ghost" href="/osint/harden.php">lock your browser down →</a>'
      + '</div>'
      + '<p class="consent">By opening this page you let it read these values (no permission prompt is required for any of them — that’s the lesson). Stored data is a fingerprint hash + your city/ISP/GPU, never your raw IP, and auto-expires in 30 days. <a href="/">back to the site</a>.</p>';

    var del = $('#del');
    if (del) del.addEventListener('click', function () {
      del.disabled = true; del.innerHTML = '<span class="spinner"></span> deleting…';
      fetch('/unmask/forget.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ token: token }) })
        .then(function (r) { return r.json(); }).then(function (j) {
          try { localStorage.removeItem('unmask_token'); } catch (e) {}
          del.outerHTML = '<span class="deleted">✓ deleted. Your record is gone — if only every site made that so easy.</span>';
        }).catch(function () { del.disabled = false; del.textContent = 'delete failed — retry'; });
    });
  }

  run();
})();
