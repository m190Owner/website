// Pwned-password check using k-anonymity. The password is SHA-1'd IN THE BROWSER;
// only the first 5 hex chars of the hash are sent to api.pwnedpasswords.com, and the
// match is done locally against the returned suffixes. The password, and its full
// hash, never leave this page — and this site's server never sees any of it.
(function () {
  var inp = document.getElementById('os-pw');
  if (!inp || !window.crypto || !crypto.subtle) return;
  var btn = document.getElementById('os-pw-check');
  var toggle = document.getElementById('os-pw-toggle');
  var out = document.getElementById('os-pw-out');
  var meter = document.getElementById('os-pw-meter');
  var mfill = document.getElementById('os-pw-mfill');
  var mlbl = document.getElementById('os-pw-mlbl');

  toggle.addEventListener('click', function () {
    var toText = inp.type === 'password';
    inp.type = toText ? 'text' : 'password';
    toggle.textContent = toText ? 'Hide' : 'Show';
    inp.focus();
  });

  function strength(pw) {
    if (!pw) return { bits: 0, label: '', pct: 0, cls: '' };
    var pool = 0;
    if (/[a-z]/.test(pw)) pool += 26;
    if (/[A-Z]/.test(pw)) pool += 26;
    if (/[0-9]/.test(pw)) pool += 10;
    if (/[^A-Za-z0-9]/.test(pw)) pool += 33;
    var bits = Math.round(pw.length * Math.log2(pool || 1));
    return {
      bits: bits,
      label: bits < 40 ? 'weak' : bits < 70 ? 'fair' : 'strong',
      pct: Math.min(100, bits / 1.2),
      cls: bits < 40 ? 'bad' : bits < 70 ? 'warn' : 'good'
    };
  }

  inp.addEventListener('input', function () {
    var s = strength(inp.value);
    meter.hidden = !inp.value;
    mfill.style.width = s.pct + '%';
    mfill.className = 'os-mini-fill os-str-' + s.cls;
    mlbl.textContent = inp.value ? (s.label + ' · ~' + s.bits + ' bits entropy') : '';
    out.hidden = true;
  });

  async function sha1(str) {
    var buf = await crypto.subtle.digest('SHA-1', new TextEncoder().encode(str));
    return Array.from(new Uint8Array(buf)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('').toUpperCase();
  }

  async function check() {
    var pw = inp.value;
    if (!pw) { inp.focus(); return; }
    out.hidden = false;
    out.className = 'os-pwres';
    out.innerHTML = '<span class="os-spinner"></span> Checking against the breach corpus…';
    try {
      var h = await sha1(pw), pre = h.slice(0, 5), suf = h.slice(5);
      var res = await fetch('https://api.pwnedpasswords.com/range/' + pre, { headers: { 'Add-Padding': 'true' } });
      var text = await res.text();
      var count = 0;
      text.split('\n').forEach(function (line) {
        var p = line.trim().split(':');
        if (p[0] === suf) count = parseInt(p[1], 10) || 0;
      });
      if (count > 0) {
        out.className = 'os-pwres bad';
        out.innerHTML = '<b>Found in ' + count.toLocaleString() + ' known breaches.</b> This password is public — stop using it anywhere and change it on every account that has it.';
      } else {
        out.className = 'os-pwres good';
        out.innerHTML = '<b>Not found</b> in any known breach corpus. That isn’t proof it’s strong — check the strength meter above and never reuse it.';
      }
    } catch (e) {
      out.className = 'os-pwres';
      out.textContent = 'Couldn’t reach the breach service. Check your connection and try again — nothing was sent anywhere.';
    }
  }

  btn.addEventListener('click', check);
  inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') check(); });
})();
