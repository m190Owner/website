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

// ---- password / passphrase generator (cryptographic RNG, entirely client-side) ----
(function () {
  var out = document.getElementById('os-gen-out');
  if (!out || !window.crypto || !crypto.getRandomValues) return;
  var lenEl = document.getElementById('os-gen-len'), lenLbl = document.getElementById('os-gen-lenlbl');
  var meta = document.getElementById('os-gen-meta'), opts = document.getElementById('os-gen-opts');
  var again = document.getElementById('os-gen-again'), copy = document.getElementById('os-gen-copy');
  var modeBtns = document.querySelectorAll('#os-gen-mode button');
  var mode = 'random';
  var WORDS = ('apple river cloud stone tiger maple ocean ember frost lunar cabin delta eagle flint grove harbor ivory jolly koala lemon '
    + 'mango north olive piano quilt raven solar timber umbra vivid wheat xenon yacht zebra amber brass coral dune echo fable '
    + 'glade hazel iris jade kite lily moss nova onyx pearl quartz reed sage teak urban vale willow aqua birch cedar '
    + 'drift elm fern gale heron inlet jasper kelp larch marsh nectar otter pine quail ridge spruce thorn vapor walnut yarn '
    + 'zephyr anchor badge cliff dawn ferry glow hollow island jungle kettle ladder meadow needle orchard pebble quiver ranch summit tunnel '
    + 'velvet wander yonder acorn beacon canyon dapper eager fossil garden hammer igloo jigsaw kindle lantern mellow nugget oxygen puzzle quartzite '
    + 'rocket saddle turtle unicorn violet wizard yellow zigzag bamboo comet dolphin engine falcon galaxy hazelnut icicle jackal koala lobster '
    + 'magnet nimbus ocelot penguin quokka rooster salmon toffee ukulele vulture walrus yak zeppelin almond bison castle donkey emerald flamingo '
    + 'granite harvest ironwood juniper kayak lagoon mammoth notebook obsidian pyramid quilted redwood sapphire tornado umbrella volcano waffle yogurt '
    + 'abbey brook cactus daisy eclipse feather glacier hornet indigo jasmine kernel lilac marble nutmeg opal parrot quince ribbon sunset').split(' ');

  function rand(max) {
    var a = new Uint32Array(1), limit = Math.floor(0x100000000 / max) * max;
    do { crypto.getRandomValues(a); } while (a[0] >= limit);
    return a[0] % max;
  }
  function genRandom(len) {
    var pool = '';
    if (document.getElementById('os-gen-upper').checked) pool += 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    if (document.getElementById('os-gen-lower').checked) pool += 'abcdefghijkmnpqrstuvwxyz';
    if (document.getElementById('os-gen-digit').checked) pool += '23456789';
    if (document.getElementById('os-gen-sym').checked) pool += '!@#$%^&*-_=+?';
    if (!pool) pool = 'abcdefghijkmnpqrstuvwxyz';
    var s = ''; for (var i = 0; i < len; i++) s += pool[rand(pool.length)];
    return { value: s, bits: Math.round(len * Math.log2(pool.length)) };
  }
  function genPhrase(n) {
    var w = [];
    for (var i = 0; i < n; i++) { var x = WORDS[rand(WORDS.length)]; w.push(x.charAt(0).toUpperCase() + x.slice(1)); }
    var s = w.join('-') + '-' + rand(100);
    return { value: s, bits: Math.round(n * Math.log2(WORDS.length) + Math.log2(100)) };
  }
  function generate() {
    var n = parseInt(lenEl.value, 10);
    lenLbl.textContent = (mode === 'phrase' ? 'Words: ' : 'Length: ') + n;
    var r = mode === 'phrase' ? genPhrase(n) : genRandom(n);
    out.value = r.value;
    var label = r.bits < 60 ? 'fair' : r.bits < 90 ? 'strong' : 'very strong';
    meta.textContent = '~' + r.bits + ' bits of entropy · ' + label;
  }
  function setMode(m) {
    mode = m;
    modeBtns.forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-mode') === m); });
    if (m === 'phrase') { opts.style.display = 'none'; lenEl.min = 4; lenEl.max = 10; lenEl.value = 7; }
    else { opts.style.display = ''; lenEl.min = 8; lenEl.max = 40; lenEl.value = 20; }
    generate();
  }
  modeBtns.forEach(function (b) { b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); }); });
  lenEl.addEventListener('input', generate);
  opts.addEventListener('change', generate);
  again.addEventListener('click', generate);
  copy.addEventListener('click', function () {
    var t = copy.textContent;
    var done = function () { copy.textContent = 'Copied ✓'; setTimeout(function () { copy.textContent = t; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(out.value).then(done, function () { out.select(); document.execCommand('copy'); done(); });
    else { out.select(); document.execCommand('copy'); done(); }
  });
  generate();
})();
