// Browser-fingerprint / trackability test. Collects the same signals a tracker would —
// user agent, screen, timezone, GPU, canvas rendering, hardware — hashes them into a
// stable ID, and shows what makes the browser identifiable. All client-side.
(function () {
  var btn = document.getElementById('os-fp-run');
  if (!btn) return;
  var out = document.getElementById('os-fp-out');

  function esc(s) { return String(s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }

  function canvasFp() {
    try {
      var c = document.createElement('canvas'); c.width = 240; c.height = 60;
      var ctx = c.getContext('2d');
      ctx.textBaseline = 'top'; ctx.font = "16px 'Arial'";
      ctx.fillStyle = '#f60'; ctx.fillRect(0, 0, 240, 60);
      ctx.fillStyle = '#069'; ctx.fillText('m190 finder ✨ fingerprint', 4, 4);
      ctx.strokeStyle = 'rgba(0,140,90,.7)'; ctx.beginPath(); ctx.arc(70, 32, 18, 0, Math.PI * 2); ctx.stroke();
      return c.toDataURL();
    } catch (e) { return ''; }
  }
  function webgl() {
    try {
      var c = document.createElement('canvas');
      var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
      if (!gl) return {};
      var dbg = gl.getExtension('WEBGL_debug_renderer_info');
      return { vendor: dbg ? gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) : '', renderer: dbg ? gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) : '' };
    } catch (e) { return {}; }
  }
  async function hash(str) {
    try {
      var b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
      return Array.from(new Uint8Array(b)).map(function (x) { return x.toString(16).padStart(2, '0'); }).join('').slice(0, 32);
    } catch (e) {
      var h = 0; for (var i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0;
      return h.toString(16);
    }
  }

  btn.addEventListener('click', async function () {
    btn.disabled = true; btn.innerHTML = '<span class="os-spinner"></span> Analyzing…';
    out.hidden = false; out.innerHTML = '';
    var gl = webgl(), tz = '';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
    var dnt = (navigator.doNotTrack === '1' || window.doNotTrack === '1') ? 'on' : 'off';
    var gpc = navigator.globalPrivacyControl ? 'on' : 'off';
    var sig = {
      'User agent': navigator.userAgent,
      'Platform': navigator.platform || '',
      'Languages': (navigator.languages || [navigator.language]).join(', '),
      'Screen': screen.width + '×' + screen.height + ' @' + (window.devicePixelRatio || 1) + 'x, ' + screen.colorDepth + '-bit',
      'Timezone': tz + ' (UTC' + (-new Date().getTimezoneOffset() / 60) + ')',
      'CPU cores': navigator.hardwareConcurrency || '?',
      'Device memory': navigator.deviceMemory ? navigator.deviceMemory + ' GB' : '?',
      'Touch points': navigator.maxTouchPoints || 0,
      'GPU': gl.renderer || 'hidden',
      'Cookies': navigator.cookieEnabled ? 'enabled' : 'disabled',
      'Do Not Track': dnt,
      'Global Privacy Control': gpc
    };
    var canvas = canvasFp();
    var id = await hash(Object.keys(sig).map(function (k) { return sig[k]; }).join('|') + '|' + canvas + '|' + (gl.vendor || ''));

    var html = '<div class="os-idbox"><span class="os-dim">Your fingerprint ID</span><div class="os-code os-idhash">' + id + '</div>'
      + '<span class="os-dim" style="font-size:.78rem">A stable ID built from the values below — sites can use it to recognise you across visits without any cookie.</span></div>';
    html += '<dl class="os-kv" style="margin-top:12px">';
    Object.keys(sig).forEach(function (k) { html += '<dt>' + k + '</dt><dd>' + esc(String(sig[k]).slice(0, 140)) + '</dd>'; });
    html += '<dt>Canvas</dt><dd>' + (canvas ? 'unique rendering (' + canvas.length + ' bytes) — a strong tracking signal' : 'blocked (good)') + '</dd>';
    html += '</dl>';
    var priv = dnt === 'on' || gpc === 'on';
    html += '<p class="os-note" style="margin-top:12px">' + (priv ? 'You have a privacy signal enabled — but most trackers ignore it. ' : 'No Do-Not-Track / GPC signal is set. ')
      + 'To resist fingerprinting: use a browser with anti-fingerprinting (Firefox on strict, Brave, or Tor) plus uBlock Origin. The <a href="/osint/harden.php">hardening checklist</a> covers browser lockdown.</p>';
    out.innerHTML = html;
    btn.disabled = false; btn.textContent = 'Re-analyze';
  });
})();
