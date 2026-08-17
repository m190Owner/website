// WebRTC leak test: asks the browser to gather ICE candidates via a public STUN server
// and reports which of your IPs are exposed. All client-side; the only outbound contact
// is the STUN handshake your browser would make for any WebRTC call anyway.
(function () {
  var btn = document.getElementById('os-leak-run');
  if (!btn) return;
  var out = document.getElementById('os-leak-out');

  function row(cls, k, v) {
    return '<div class="os-prow"><span class="os-pdot os-pdot-' + cls + '"></span><span class="os-pk">' + k + '</span><span>' + v + '</span></div>';
  }

  btn.addEventListener('click', function () {
    out.hidden = false;
    if (!window.RTCPeerConnection) {
      out.innerHTML = row('ok', 'WebRTC', 'Your browser has WebRTC disabled — there is nothing to leak. That is the safest setting.');
      return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="os-spinner"></span> Testing…';
    out.innerHTML = '<div class="os-prow"><span class="os-pdot"></span><span>Gathering ICE candidates…</span></div>';

    var pub = {}, loc = {}, pc, done = false;
    try { pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] }); }
    catch (e) { finish(); return; }
    pc.createDataChannel('probe');
    pc.onicecandidate = function (e) {
      if (!e.candidate) { finish(); return; }
      var c = e.candidate.candidate || '';
      var ip4 = c.match(/\b(\d{1,3}(?:\.\d{1,3}){3})\b/);
      if (ip4) {
        var ip = ip4[1];
        if (/^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|0\.)/.test(ip)) loc[ip] = 1;
        else pub[ip] = 1;
      } else if (/\.local/.test(c)) loc['(mDNS-obfuscated)'] = 1;
    };
    pc.createOffer().then(function (o) { return pc.setLocalDescription(o); }).catch(finish);
    var timer = setTimeout(finish, 3500);

    function finish() {
      if (done) return; done = true;
      clearTimeout(timer);
      try { pc && pc.close(); } catch (e) {}
      btn.disabled = false; btn.textContent = 'Run again';

      var pubIps = Object.keys(pub), locIps = Object.keys(loc), html = '';
      if (pubIps.length) {
        pubIps.forEach(function (ip) {
          html += row('bad', 'Public IP', '<b>' + ip + '</b> — exposed to any site via WebRTC. If you are on a VPN and this is not your VPN\'s exit IP, your real IP is leaking.');
        });
      } else {
        html += row('ok', 'Public IP', 'No public IP leaked via WebRTC — good.');
      }
      if (locIps.length) {
        var obf = locIps.every(function (x) { return x === '(mDNS-obfuscated)'; });
        html += row(obf ? 'ok' : 'warn', 'Local network', obf ? 'Obfuscated by your browser (good).' : locIps.join(', ') + ' — your local network address is visible.');
      } else {
        html += row('ok', 'Local network', 'Not exposed.');
      }
      html += row('', 'Fix', 'If your real IP leaked, enable your VPN\'s WebRTC protection, or use uBlock Origin (Settings → “Prevent WebRTC from leaking local IP addresses”).');
      out.innerHTML = html;
    }
  });
})();
