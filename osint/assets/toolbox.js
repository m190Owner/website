// Investigator toolbox — all client-side except the email-deliverability check.
(function () {
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }

  // ---------- encode / decode ----------
  function b64e(s) { return btoa(unescape(encodeURIComponent(s))); }
  function b64d(s) { try { return decodeURIComponent(escape(atob(s.replace(/\s/g, '')))); } catch (e) { return '[invalid base64]'; } }
  function hexe(s) { return Array.from(new TextEncoder().encode(s)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join(''); }
  function hexd(s) { s = s.replace(/[^0-9a-fA-F]/g, ''); var b = []; for (var i = 0; i < s.length; i += 2) b.push(parseInt(s.substr(i, 2), 16)); try { return new TextDecoder().decode(new Uint8Array(b)); } catch (e) { return '[invalid]'; } }
  function rot13(s) { return s.replace(/[a-zA-Z]/g, function (c) { var b = c <= 'Z' ? 65 : 97; return String.fromCharCode((c.charCodeAt(0) - b + 13) % 26 + b); }); }
  function bine(s) { return Array.from(new TextEncoder().encode(s)).map(function (b) { return b.toString(2).padStart(8, '0'); }).join(' '); }
  function bind(s) { return (s.trim().match(/[01]{8}/g) || []).map(function (x) { return String.fromCharCode(parseInt(x, 2)); }).join(''); }
  var OPS = {
    b64e: b64e, b64d: b64d, hexe: hexe, hexd: hexd,
    urle: encodeURIComponent, urld: function (s) { try { return decodeURIComponent(s); } catch (e) { return '[invalid]'; } },
    htmle: esc, htmld: function (s) { return s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&'); },
    rot13: rot13, bine: bine, bind: bind, rev: function (s) { return s.split('').reverse().join(''); }
  };
  var encIn = $('os-enc-in'), encOut = $('os-enc-out'), encOp = $('os-enc-op');
  if (encIn) {
    var runEnc = function () { encOut.value = encIn.value ? (OPS[encOp.value] || function (x) { return x; })(encIn.value) : ''; };
    encIn.addEventListener('input', runEnc); encOp.addEventListener('change', runEnc);
  }

  // ---------- MD5 (compact, public-domain core) ----------
  function md5(str) {
    function sa(x, y) { var l = (x & 0xFFFF) + (y & 0xFFFF); return (((x >> 16) + (y >> 16) + (l >> 16)) << 16) | (l & 0xFFFF); }
    function rl(n, c) { return (n << c) | (n >>> (32 - c)); }
    function cmn(q, a, b, x, s, t) { return sa(rl(sa(sa(a, q), sa(x, t)), s), b); }
    function ff(a, b, c, d, x, s, t) { return cmn((b & c) | (~b & d), a, b, x, s, t); }
    function gg(a, b, c, d, x, s, t) { return cmn((b & d) | (c & ~d), a, b, x, s, t); }
    function hh(a, b, c, d, x, s, t) { return cmn(b ^ c ^ d, a, b, x, s, t); }
    function ii(a, b, c, d, x, s, t) { return cmn(c ^ (b | ~d), a, b, x, s, t); }
    var utf8 = unescape(encodeURIComponent(str)), n = utf8.length, x = [], i;
    for (i = 0; i < n; i++) x[i >> 2] |= (utf8.charCodeAt(i) & 0xFF) << ((i % 4) * 8);
    x[n >> 2] |= 0x80 << ((n % 4) * 8);
    x[(((n + 8) >> 6) * 16) + 14] = n * 8;
    var a = 1732584193, b = -271733879, c = -1732584194, d = 271733878;
    for (i = 0; i < x.length; i += 16) {
      var oa = a, ob = b, oc = c, od = d;
      a = ff(a, b, c, d, x[i] | 0, 7, -680876936); d = ff(d, a, b, c, x[i + 1] | 0, 12, -389564586); c = ff(c, d, a, b, x[i + 2] | 0, 17, 606105819); b = ff(b, c, d, a, x[i + 3] | 0, 22, -1044525330);
      a = ff(a, b, c, d, x[i + 4] | 0, 7, -176418897); d = ff(d, a, b, c, x[i + 5] | 0, 12, 1200080426); c = ff(c, d, a, b, x[i + 6] | 0, 17, -1473231341); b = ff(b, c, d, a, x[i + 7] | 0, 22, -45705983);
      a = ff(a, b, c, d, x[i + 8] | 0, 7, 1770035416); d = ff(d, a, b, c, x[i + 9] | 0, 12, -1958414417); c = ff(c, d, a, b, x[i + 10] | 0, 17, -42063); b = ff(b, c, d, a, x[i + 11] | 0, 22, -1990404162);
      a = ff(a, b, c, d, x[i + 12] | 0, 7, 1804603682); d = ff(d, a, b, c, x[i + 13] | 0, 12, -40341101); c = ff(c, d, a, b, x[i + 14] | 0, 17, -1502002290); b = ff(b, c, d, a, x[i + 15] | 0, 22, 1236535329);
      a = gg(a, b, c, d, x[i + 1] | 0, 5, -165796510); d = gg(d, a, b, c, x[i + 6] | 0, 9, -1069501632); c = gg(c, d, a, b, x[i + 11] | 0, 14, 643717713); b = gg(b, c, d, a, x[i] | 0, 20, -373897302);
      a = gg(a, b, c, d, x[i + 5] | 0, 5, -701558691); d = gg(d, a, b, c, x[i + 10] | 0, 9, 38016083); c = gg(c, d, a, b, x[i + 15] | 0, 14, -660478335); b = gg(b, c, d, a, x[i + 4] | 0, 20, -405537848);
      a = gg(a, b, c, d, x[i + 9] | 0, 5, 568446438); d = gg(d, a, b, c, x[i + 14] | 0, 9, -1019803690); c = gg(c, d, a, b, x[i + 3] | 0, 14, -187363961); b = gg(b, c, d, a, x[i + 8] | 0, 20, 1163531501);
      a = gg(a, b, c, d, x[i + 13] | 0, 5, -1444681467); d = gg(d, a, b, c, x[i + 2] | 0, 9, -51403784); c = gg(c, d, a, b, x[i + 7] | 0, 14, 1735328473); b = gg(b, c, d, a, x[i + 12] | 0, 20, -1926607734);
      a = hh(a, b, c, d, x[i + 5] | 0, 4, -378558); d = hh(d, a, b, c, x[i + 8] | 0, 11, -2022574463); c = hh(c, d, a, b, x[i + 11] | 0, 16, 1839030562); b = hh(b, c, d, a, x[i + 14] | 0, 23, -35309556);
      a = hh(a, b, c, d, x[i + 1] | 0, 4, -1530992060); d = hh(d, a, b, c, x[i + 4] | 0, 11, 1272893353); c = hh(c, d, a, b, x[i + 7] | 0, 16, -155497632); b = hh(b, c, d, a, x[i + 10] | 0, 23, -1094730640);
      a = hh(a, b, c, d, x[i + 13] | 0, 4, 681279174); d = hh(d, a, b, c, x[i] | 0, 11, -358537222); c = hh(c, d, a, b, x[i + 3] | 0, 16, -722521979); b = hh(b, c, d, a, x[i + 6] | 0, 23, 76029189);
      a = hh(a, b, c, d, x[i + 9] | 0, 4, -640364487); d = hh(d, a, b, c, x[i + 12] | 0, 11, -421815835); c = hh(c, d, a, b, x[i + 15] | 0, 16, 530742520); b = hh(b, c, d, a, x[i + 2] | 0, 23, -995338651);
      a = ii(a, b, c, d, x[i] | 0, 6, -198630844); d = ii(d, a, b, c, x[i + 7] | 0, 10, 1126891415); c = ii(c, d, a, b, x[i + 14] | 0, 15, -1416354905); b = ii(b, c, d, a, x[i + 5] | 0, 21, -57434055);
      a = ii(a, b, c, d, x[i + 12] | 0, 6, 1700485571); d = ii(d, a, b, c, x[i + 3] | 0, 10, -1894986606); c = ii(c, d, a, b, x[i + 10] | 0, 15, -1051523); b = ii(b, c, d, a, x[i + 1] | 0, 21, -2054922799);
      a = ii(a, b, c, d, x[i + 8] | 0, 6, 1873313359); d = ii(d, a, b, c, x[i + 15] | 0, 10, -30611744); c = ii(c, d, a, b, x[i + 6] | 0, 15, -1560198380); b = ii(b, c, d, a, x[i + 13] | 0, 21, 1309151649);
      a = ii(a, b, c, d, x[i + 4] | 0, 6, -145523070); d = ii(d, a, b, c, x[i + 11] | 0, 10, -1120210379); c = ii(c, d, a, b, x[i + 2] | 0, 15, 718787259); b = ii(b, c, d, a, x[i + 9] | 0, 21, -343485551);
      a = sa(a, oa); b = sa(b, ob); c = sa(c, oc); d = sa(d, od);
    }
    function hex(w) { var s = ''; for (var j = 0; j < 4; j++) s += ((w >> (j * 8 + 4)) & 0xF).toString(16) + ((w >> (j * 8)) & 0xF).toString(16); return s; }
    return hex(a) + hex(b) + hex(c) + hex(d);
  }
  window.__osMd5 = md5;

  async function sha(algo, str) { var b = await crypto.subtle.digest(algo, new TextEncoder().encode(str)); return Array.from(new Uint8Array(b)).map(function (x) { return x.toString(16).padStart(2, '0'); }).join(''); }
  var hashIn = $('os-hash-in'), hashOut = $('os-hash-out');
  if (hashIn) hashIn.addEventListener('input', async function () {
    var v = hashIn.value;
    if (!v) { hashOut.innerHTML = ''; return; }
    var rows = [['MD5', md5(v)], ['SHA-1', await sha('SHA-1', v)], ['SHA-256', await sha('SHA-256', v)], ['SHA-512', await sha('SHA-512', v)]];
    hashOut.innerHTML = rows.map(function (r) { return '<dt>' + r[0] + '</dt><dd>' + r[1] + '</dd>'; }).join('');
  });

  // ---------- hash identifier ----------
  function idHash(h) {
    h = h.trim(); var o = [];
    if (/^[a-f0-9]{32}$/i.test(h)) o.push('MD5', 'NTLM', 'MD4');
    if (/^[a-f0-9]{40}$/i.test(h)) o.push('SHA-1', 'RIPEMD-160');
    if (/^[a-f0-9]{56}$/i.test(h)) o.push('SHA-224');
    if (/^[a-f0-9]{64}$/i.test(h)) o.push('SHA-256');
    if (/^[a-f0-9]{96}$/i.test(h)) o.push('SHA-384');
    if (/^[a-f0-9]{128}$/i.test(h)) o.push('SHA-512');
    if (/^\$2[aby]\$/.test(h)) o.push('bcrypt');
    if (/^\$1\$/.test(h)) o.push('md5crypt');
    if (/^\$5\$/.test(h)) o.push('sha256crypt');
    if (/^\$6\$/.test(h)) o.push('sha512crypt');
    if (/^\$argon2/i.test(h)) o.push('Argon2');
    if (/^\*[A-F0-9]{40}$/i.test(h)) o.push('MySQL 4.1+');
    if (/^[a-f0-9]{16}$/i.test(h)) o.push('MySQL<4.1 / CRC64');
    if (/^[A-Za-z0-9+/]{20,}={0,2}$/.test(h) && h.indexOf('=') >= 0) o.push('Base64?');
    return o.length ? o : ['Unrecognised'];
  }
  var hidIn = $('os-hid-in'), hidOut = $('os-hid-out');
  if (hidIn) hidIn.addEventListener('input', function () {
    hidOut.innerHTML = hidIn.value.trim() ? idHash(hidIn.value).map(function (t) { return '<span class="os-tag">' + esc(t) + '</span>'; }).join('') : '';
  });

  // ---------- JWT ----------
  function b64urlDec(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; try { return decodeURIComponent(escape(atob(s))); } catch (e) { return null; } }
  var jwtIn = $('os-jwt-in'), jwtOut = $('os-jwt-out');
  if (jwtIn) jwtIn.addEventListener('input', function () {
    var t = jwtIn.value.trim(); if (!t) { jwtOut.innerHTML = ''; return; }
    var parts = t.split('.'); if (parts.length < 2) { jwtOut.innerHTML = '<p class="os-dim">Not a JWT (need header.payload).</p>'; return; }
    var h = b64urlDec(parts[0]), p = b64urlDec(parts[1]);
    try { h = JSON.parse(h); p = JSON.parse(p); } catch (e) { jwtOut.innerHTML = '<p class="os-dim">Could not decode.</p>'; return; }
    var dates = ['exp', 'iat', 'nbf'].filter(function (k) { return p[k]; }).map(function (k) { return k + ' = ' + new Date(p[k] * 1000).toUTCString(); });
    jwtOut.innerHTML = '<div class="os-subhead" style="margin-top:0">Header</div><pre class="os-pre">' + esc(JSON.stringify(h, null, 2)) + '</pre>'
      + '<div class="os-subhead">Payload</div><pre class="os-pre">' + esc(JSON.stringify(p, null, 2)) + '</pre>'
      + (dates.length ? '<p class="os-dim" style="margin-top:8px">' + esc(dates.join(' · ')) + '</p>' : '');
  });

  // ---------- epoch ----------
  var epIn = $('os-epoch-in'), epOut = $('os-epoch-out'), epNow = $('os-epoch-now');
  function runEpoch() {
    var v = epIn.value.trim(); if (!v) { epOut.innerHTML = ''; return; }
    var digits = v.replace(/\D/g, ''); var n = Number(digits); if (!isFinite(n) || !digits) { epOut.textContent = 'Enter a number.'; return; }
    var ms = digits.length >= 12 ? n : n * 1000; var d = new Date(ms);
    if (isNaN(d.getTime())) { epOut.textContent = 'Out of range.'; return; }
    epOut.innerHTML = 'UTC: ' + esc(d.toUTCString()) + '<br>Local: ' + esc(d.toString()) + '<br>ISO: ' + esc(d.toISOString());
  }
  if (epIn) { epIn.addEventListener('input', runEpoch); epNow.addEventListener('click', function () { epIn.value = String(Math.floor(Date.now() / 1000)); runEpoch(); }); }

  // ---------- email header analyzer ----------
  function prow(cls, k, v) { return '<div class="os-prow"><span class="os-pdot os-pdot-' + cls + '"></span><span class="os-pk">' + k + '</span><span>' + v + '</span></div>'; }
  var hdrIn = $('os-hdr-in'), hdrOut = $('os-hdr-out'), hdrRun = $('os-hdr-run');
  if (hdrRun) hdrRun.addEventListener('click', function () {
    var raw = hdrIn.value; if (!raw.trim()) return;
    var unfolded = raw.replace(/\r?\n[ \t]+/g, ' '), H = [];
    unfolded.split(/\r?\n/).forEach(function (l) { var m = l.match(/^([A-Za-z-]+):\s?(.*)$/); if (m) H.push([m[1].toLowerCase(), m[2]]); });
    var all = function (n) { return H.filter(function (h) { return h[0] === n; }).map(function (h) { return h[1]; }); };
    var one = function (n) { return all(n)[0] || ''; };
    var hops = all('received').map(function (r) {
      return { from: (r.match(/from\s+([^\s;(]+)/i) || [])[1] || '', ip: (r.match(/\[?(\d{1,3}(?:\.\d{1,3}){3})\]?/) || [])[1] || '', date: (r.split(';').pop() || '').trim() };
    }).reverse();
    var auth = one('authentication-results');
    var spf = (auth.match(/spf=(\w+)/i) || [])[1] || ((one('received-spf').match(/^(\w+)/) || [])[1] || '');
    var dkim = (auth.match(/dkim=(\w+)/i) || [])[1] || '';
    var dmarc = (auth.match(/dmarc=(\w+)/i) || [])[1] || '';
    var from = one('from'), rp = one('return-path');
    var fromDom = (from.match(/@([^\s>]+)/) || [])[1] || '', rpDom = (rp.match(/@([^\s>]+)/) || [])[1] || '';
    var mismatch = fromDom && rpDom && fromDom.toLowerCase() !== rpDom.toLowerCase();
    var st = function (v) { return /pass/i.test(v) ? 'ok' : (/fail|softfail|none/i.test(v) ? 'bad' : ''); };
    var html = '<div class="os-posture">';
    html += prow(hops.length && hops[0].ip ? 'warn' : '', 'Origin IP', hops.length && hops[0].ip ? esc(hops[0].ip) + ' — the first relay that handled it (look this up in Network)' : 'not found');
    html += prow('', 'Relay hops', hops.length + ' — ' + esc(hops.map(function (h) { return h.from || h.ip; }).filter(Boolean).slice(0, 6).join(' → ')));
    html += prow(spf ? st(spf) : '', 'SPF', spf ? esc(spf) : 'not stated');
    html += prow(dkim ? st(dkim) : '', 'DKIM', dkim ? esc(dkim) : 'not stated');
    html += prow(dmarc ? st(dmarc) : '', 'DMARC', dmarc ? esc(dmarc) : 'not stated');
    if (from) html += prow(mismatch ? 'bad' : '', 'From', esc(from) + (mismatch ? ' — Return-Path domain (' + esc(rpDom) + ') differs; possible spoofing' : ''));
    html += '</div>';
    hdrOut.innerHTML = html;
  });

  // ---------- QR decoder ----------
  var qrDrop = $('os-qr-drop'), qrFile = $('os-qr-file'), qrOut = $('os-qr-out');
  async function decodeQr(file) {
    qrOut.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Decoding…</p>';
    try {
      var bmp = await createImageBitmap(file), val = null;
      if (typeof jsQR === 'function') {
        var cv = document.createElement('canvas'); cv.width = bmp.width; cv.height = bmp.height;
        var ctx = cv.getContext('2d'); ctx.drawImage(bmp, 0, 0);
        var img = ctx.getImageData(0, 0, cv.width, cv.height);
        var res = jsQR(img.data, img.width, img.height);
        if (res) val = res.data;
      }
      if (val === null && 'BarcodeDetector' in window) {
        var codes = await new BarcodeDetector({ formats: ['qr_code'] }).detect(bmp);
        if (codes.length) val = codes[0].rawValue;
      }
      if (val === null) { qrOut.innerHTML = '<p class="os-dim">No QR code found in that image.</p>'; return; }
      var isUrl = /^https?:\/\//i.test(val);
      qrOut.innerHTML = '<div class="os-idbox"><span class="os-dim">Decoded content</span><div class="os-code os-idhash">' + esc(val) + '</div>'
        + (isUrl ? '<span class="os-dim" style="font-size:.78rem">It\'s a link. Don\'t open it unless you trust the source — check the domain first.</span>' : '') + '</div>';
    } catch (e) { qrOut.innerHTML = '<p class="os-dim">Could not decode that image.</p>'; }
  }
  if (qrDrop) {
    qrDrop.addEventListener('click', function () { qrFile.click(); });
    qrFile.addEventListener('change', function () { if (qrFile.files[0]) decodeQr(qrFile.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { qrDrop.addEventListener(ev, function (e) { e.preventDefault(); qrDrop.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { qrDrop.addEventListener(ev, function (e) { e.preventDefault(); qrDrop.classList.remove('drag'); }); });
    qrDrop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) decodeQr(e.dataTransfer.files[0]); });
  }

  // ---------- email deliverability (server) ----------
  var emlIn = $('os-eml-in'), emlRun = $('os-eml-run'), emlOut = $('os-eml-out');
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  if (emlRun) emlRun.addEventListener('click', function () {
    var v = emlIn.value.trim(); if (!v) return;
    emlOut.hidden = false; emlOut.innerHTML = '<div class="os-prow"><span class="os-pdot"></span><span><span class="os-spinner"></span> Checking…</span></div>';
    fetch('/osint/emailcheck.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ csrf: csrf, email: v }) })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) { emlOut.innerHTML = prow('bad', 'Error', esc(d.error || 'check failed')); return; }
        var html = '';
        html += prow('', 'Domain', esc(d.domain));
        html += prow(d.mx ? 'ok' : 'bad', 'Receives mail', d.mx ? 'Yes — MX: ' + esc((d.mx_hosts || []).slice(0, 2).join(', ')) : 'No MX record — this address can\'t receive email');
        html += prow(d.disposable ? 'bad' : 'ok', 'Disposable', d.disposable ? 'Yes — a throwaway / temp-mail provider' : 'Not a known throwaway provider');
        html += prow(d.role ? 'warn' : '', 'Role address', d.role ? 'Yes — a shared/role mailbox (info@, admin@, …)' : 'No — looks like a personal address');
        emlOut.innerHTML = html;
      }).catch(function () { emlOut.innerHTML = prow('bad', 'Error', 'Request failed.'); });
  });

  // ---------- punycode / IDN homograph ----------
  function punyDecode(input) {
    var base = 36, tmin = 1, tmax = 26, skew = 38, damp = 700, out = [], i = 0, n = 128, bias = 72, b = input.lastIndexOf('-');
    if (b < 0) b = 0;
    for (var j = 0; j < b; j++) { if (input.charCodeAt(j) >= 0x80) return null; out.push(input.charCodeAt(j)); }
    function adapt(d, np, first) { d = first ? Math.floor(d / damp) : d >> 1; d += Math.floor(d / np); var k = 0; for (; d > ((base - tmin) * tmax) >> 1; k += base) d = Math.floor(d / (base - tmin)); return Math.floor(k + (base - tmin + 1) * d / (d + skew)); }
    for (var idx = b > 0 ? b + 1 : 0; idx < input.length;) {
      var oldi = i, w = 1;
      for (var k = base; ; k += base) {
        if (idx >= input.length) return null;
        var c = input.charCodeAt(idx++), digit = c - 48 < 10 ? c - 22 : (c - 65 < 26 ? c - 65 : (c - 97 < 26 ? c - 97 : base));
        if (digit >= base) return null;
        i += digit * w;
        var t = k <= bias ? tmin : (k >= bias + tmax ? tmax : k - bias);
        if (digit < t) break;
        w *= base - t;
      }
      var outLen = out.length + 1;
      bias = adapt(i - oldi, outLen, oldi === 0);
      n += Math.floor(i / outLen); i %= outLen;
      out.splice(i++, 0, n);
    }
    try { return String.fromCodePoint.apply(null, out); } catch (e) { return null; }
  }
  window.__osPuny = punyDecode;
  function idnDecode(d) { return d.split('.').map(function (l) { return l.toLowerCase().indexOf('xn--') === 0 ? (punyDecode(l.slice(4)) || l) : l; }).join('.'); }
  function idnEncode(d) { try { return new URL('http://' + d).hostname; } catch (e) { return d; } }
  function homograph(u) {
    if (!/[^\x00-\x7f]/.test(u)) return null;
    var latin = /[a-z]/i.test(u), cyr = /[Ѐ-ӿ]/.test(u), grk = /[Ͱ-Ͽ]/.test(u);
    if ((cyr || grk) && latin) return 'Mixes Latin with ' + (cyr ? 'Cyrillic' : 'Greek') + ' letters — a classic look-alike (homograph) domain.';
    if (cyr || grk) return 'Uses ' + (cyr ? 'Cyrillic' : 'Greek') + ' letters that can mimic a Latin brand.';
    return 'Contains non-ASCII characters — verify it is the genuine domain.';
  }
  var idnIn = $('os-idn-in'), idnOut = $('os-idn-out');
  if (idnIn) idnIn.addEventListener('input', function () {
    var v = idnIn.value.trim(); if (!v) { idnOut.innerHTML = ''; return; }
    var uni = /xn--/i.test(v) ? idnDecode(v) : v, ascii = idnEncode(v), risk = homograph(uni);
    idnOut.innerHTML = '<dl class="os-kv"><dt>Displays as</dt><dd>' + esc(uni) + '</dd><dt>ASCII (punycode)</dt><dd>' + esc(ascii) + '</dd></dl>'
      + (risk ? '<div class="os-warn-box" style="margin-top:10px"><b>⚠ ' + esc(risk) + '</b></div>' : '<p class="os-dim" style="margin-top:8px">Plain ASCII — no homograph risk.</p>');
  });

  // ---------- user-agent parser ----------
  function parseUA(ua) {
    var o = {};
    if (/windows nt 10/i.test(ua)) o.OS = 'Windows 10/11';
    else if (/windows nt 6\.3/i.test(ua)) o.OS = 'Windows 8.1';
    else if (/windows nt 6\.1/i.test(ua)) o.OS = 'Windows 7';
    else if (/windows/i.test(ua)) o.OS = 'Windows';
    else if (/mac os x ([0-9_]+)/i.test(ua)) o.OS = 'macOS ' + RegExp.$1.replace(/_/g, '.');
    else if (/android ([0-9.]+)/i.test(ua)) o.OS = 'Android ' + RegExp.$1;
    else if (/iphone os ([0-9_]+)/i.test(ua)) o.OS = 'iOS ' + RegExp.$1.replace(/_/g, '.');
    else if (/ipad/i.test(ua)) o.OS = 'iPadOS';
    else if (/cros/i.test(ua)) o.OS = 'ChromeOS';
    else if (/linux/i.test(ua)) o.OS = 'Linux';
    if (/edg\/([0-9.]+)/i.test(ua)) o.Browser = 'Edge ' + RegExp.$1;
    else if (/opr\/([0-9.]+)/i.test(ua)) o.Browser = 'Opera ' + RegExp.$1;
    else if (/firefox\/([0-9.]+)/i.test(ua)) o.Browser = 'Firefox ' + RegExp.$1;
    else if (/chrome\/([0-9.]+)/i.test(ua)) o.Browser = 'Chrome ' + RegExp.$1;
    else if (/version\/([0-9.]+).*safari/i.test(ua)) o.Browser = 'Safari ' + RegExp.$1;
    else if (/safari/i.test(ua)) o.Browser = 'Safari';
    o.Device = /mobile|iphone|android/i.test(ua) ? 'Mobile' : (/ipad|tablet/i.test(ua) ? 'Tablet' : 'Desktop');
    if (/bot|crawl|spider|slurp|curl|wget|python|go-http|headless|monitor/i.test(ua)) o.Note = 'Looks like a bot / automated client';
    return o;
  }
  var uaIn = $('os-ua-in'), uaOut = $('os-ua-out');
  if (uaIn) uaIn.addEventListener('input', function () {
    if (!uaIn.value.trim()) { uaOut.innerHTML = ''; return; }
    var o = parseUA(uaIn.value);
    uaOut.innerHTML = Object.keys(o).map(function (k) { return '<dt>' + k + '</dt><dd>' + esc(o[k]) + '</dd>'; }).join('') || '<dt>—</dt><dd>Unrecognised</dd>';
  });

  // ---------- CIDR calculator ----------
  function cidrCalc(input) {
    var m = input.trim().match(/^(\d{1,3}(?:\.\d{1,3}){3})(?:\/(\d{1,2}))?$/); if (!m) return null;
    var ip = m[1].split('.').map(Number); if (ip.some(function (o) { return o > 255; })) return null;
    var bits = m[2] !== undefined ? +m[2] : 32; if (bits > 32) return null;
    var ipn = ((ip[0] << 24) | (ip[1] << 16) | (ip[2] << 8) | ip[3]) >>> 0;
    var mask = bits === 0 ? 0 : (0xFFFFFFFF << (32 - bits)) >>> 0;
    var net = (ipn & mask) >>> 0, bc = (net | (~mask >>> 0)) >>> 0;
    var toIp = function (n) { return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join('.'); };
    var hosts = bits >= 31 ? (bits === 32 ? 1 : 2) : (bc - net - 1);
    return { Network: toIp(net), Broadcast: toIp(bc), Netmask: toIp(mask), 'Usable range': toIp(bits >= 31 ? net : net + 1) + ' – ' + toIp(bits >= 31 ? bc : bc - 1), 'Usable hosts': String(hosts < 0 ? 0 : hosts) };
  }
  var cidrIn = $('os-cidr-in'), cidrOut = $('os-cidr-out');
  if (cidrIn) cidrIn.addEventListener('input', function () {
    if (!cidrIn.value.trim()) { cidrOut.innerHTML = ''; return; }
    var r = cidrCalc(cidrIn.value);
    cidrOut.innerHTML = r ? Object.keys(r).map(function (k) { return '<dt>' + k + '</dt><dd>' + esc(r[k]) + '</dd>'; }).join('') : '<dt>—</dt><dd>Enter an IPv4 address or CIDR (e.g. 10.0.0.0/24).</dd>';
  });

  // ---------- UUID ----------
  var uuidGen = $('os-uuid-gen'), uuidOut = $('os-uuid-out');
  if (uuidGen) uuidGen.addEventListener('click', function () {
    var id = (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = crypto.getRandomValues(new Uint8Array(1))[0] % 16, v = c === 'x' ? r : (r & 0x3 | 0x8); return v.toString(16); }));
    var chip = document.createElement('span'); chip.className = 'os-code'; chip.textContent = id;
    uuidOut.insertBefore(chip, uuidOut.firstChild);
    while (uuidOut.children.length > 6) uuidOut.removeChild(uuidOut.lastChild);
  });

  // ---------- writing fingerprint (stylometry) ----------
  var STY_FUNC = ('the of and to a in that it is was for on are as with his they i at be this have from or one had by word but not what all were we when your can said there use an each which she do how their if will up other about out many then them these so some her would make like him into time has look two more write go see number no way could people my than first been call who its now find long down day did get come made may part over new sound take only little work know place year live me back give most very after thing our just name good sentence man think say great where help through much before line right too mean old any same tell boy follow came want show also around form three small set put end does another well large must big even such because turn here why ask went men read need land different home us move try kind hand picture again change off play spell air away animal house point page letter mother answer found study still learn should america world').split(' ');
  function styTokens(t) { return (t.toLowerCase().match(/[a-z']+/g) || []); }
  function styFeatures(t) {
    var words = styTokens(t), n = words.length || 1;
    var fw = {}; STY_FUNC.forEach(function (w) { fw[w] = 0; });
    var letters = 0, uniq = {};
    words.forEach(function (w) { if (fw[w] !== undefined) fw[w]++; letters += w.length; uniq[w] = 1; });
    var vec = STY_FUNC.map(function (w) { return fw[w] / n; });
    var sentences = (t.match(/[.!?]+/g) || []).length || 1;
    return {
      vec: vec, words: n,
      avgWord: letters / n,
      avgSent: n / sentences,
      commaRate: (t.match(/,/g) || []).length / n * 100,
      ttr: Object.keys(uniq).length / n,
      exclQ: ((t.match(/[!?]/g) || []).length) / sentences
    };
  }
  function styBray(a, b) { var num = 0, den = 0; for (var i = 0; i < a.length; i++) { num += Math.abs(a[i] - b[i]); den += a[i] + b[i]; } return den ? 1 - num / den : 0; }
  function styClose(a, b) { return 1 - Math.abs(a - b) / (Math.abs(a) + Math.abs(b) + 1e-9); }

  var styRun = $('os-sty-run'), styOut = $('os-sty-out');
  if (styRun) styRun.addEventListener('click', function () {
    var A = styFeatures($('os-sty-a').value), B = styFeatures($('os-sty-b').value);
    styOut.hidden = false;
    if (A.words < 40 || B.words < 40) {
      styOut.innerHTML = '<div class="os-warn-box" style="margin-top:0">Each sample needs at least ~40 words to be meaningful (A: ' + A.words + ', B: ' + B.words + '). Paste more text.</div>';
      return;
    }
    var fwSim = styBray(A.vec, B.vec);
    var structSim = (styClose(A.avgWord, B.avgWord) + styClose(A.avgSent, B.avgSent) + styClose(A.commaRate, B.commaRate) + styClose(A.ttr, B.ttr)) / 4;
    var score = Math.round((0.7 * fwSim + 0.3 * structSim) * 100);
    var verdict, cls;
    if (score >= 85) { verdict = 'Very similar — consistent with the same author.'; cls = 'os-corr-high'; }
    else if (score >= 70) { verdict = 'Similar — plausibly the same author; worth a closer look.'; cls = 'os-corr-med'; }
    else if (score >= 55) { verdict = 'Some overlap — inconclusive from these samples.'; cls = 'os-corr-med'; }
    else { verdict = 'Distinct — the two samples show different writing habits.'; cls = 'os-corr-low'; }
    var row = function (label, a, b, unit) { return '<dt>' + label + '</dt><dd>' + a.toFixed(2) + unit + ' <span class="os-dim">vs</span> ' + b.toFixed(2) + unit + '</dd>'; };
    styOut.innerHTML = '<div class="os-corr ' + cls + '"><div class="os-corr-h"><span class="os-corr-sev">' + score + '%</span><b>' + verdict + '</b></div>'
      + '<p class="os-corr-d">A blend of function-word rhythm (70%) and structural habits (30%). Higher means more alike.</p></div>'
      + '<div class="os-subhead">Side by side <span class="os-dim">(A vs B)</span></div>'
      + '<dl class="os-kv">'
      + row('Avg word length', A.avgWord, B.avgWord, ' ch')
      + row('Avg sentence length', A.avgSent, B.avgSent, ' wd')
      + row('Commas per 100 words', A.commaRate, B.commaRate, '')
      + row('Vocabulary richness (TTR)', A.ttr, B.ttr, '')
      + '<dt>Sample size</dt><dd>' + A.words + ' vs ' + B.words + ' words</dd>'
      + '</dl>'
      + '<p class="os-fineprint">Stylometry is a lead, not proof: it needs long samples, deliberate obfuscation can defeat it, and topic overlap can inflate the score. Use it to prioritise, then corroborate.</p>';
  });
})();
