// Homepage teaser for /unmask/ — a subtle, personalized line that makes visitors do a
// double-take ("wait, how does it know that?") and pulls them into the full reveal.
// Nothing is stored here; just a client GPU read + one server-side geo lookup.
(function () {
  'use strict';
  // Respect a prior dismissal so repeat visitors aren't nagged.
  try { if (localStorage.getItem('unmask_teaser_x') === '1') return; } catch (e) {}

  function gpuName() {
    try {
      var gl = document.createElement('canvas').getContext('webgl'); if (!gl) return '';
      var dbg = gl.getExtension('WEBGL_debug_renderer_info'); if (!dbg) return '';
      var r = gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) || '';
      r = r.replace(/^ANGLE \(/, '').replace(/\)$/, '');       // unwrap ANGLE(...)
      r = r.replace(/\s*\(0x[0-9a-f]+\)/i, '');                // drop device id
      r = r.replace(/\s*(Direct3D|vs_\d|OpenGL|Metal|D3D).*$/i, ''); // drop driver suffix
      r = r.replace(/^[^,]*,\s*/, '');                          // drop leading vendor "NVIDIA, "
      r = r.replace(/^(NVIDIA|AMD|Intel\(R\)|Intel)\s+/i, '').trim();
      return r.slice(0, 40);
    } catch (e) { return ''; }
  }

  function inject() {
    if (document.getElementById('unmask-teaser')) return;
    var css = document.createElement('style');
    css.textContent =
      '#unmask-teaser{position:fixed;left:50%;bottom:96px;transform:translate(-50%,20px);z-index:8;' +
      'display:flex;align-items:center;gap:10px;max-width:min(92vw,560px);padding:10px 14px;' +
      'background:rgba(8,12,24,.82);border:1px solid rgba(255,77,94,.4);border-radius:999px;' +
      'backdrop-filter:blur(6px);font:0.82rem/1.35 ui-monospace,Consolas,monospace;color:#cdd6e6;' +
      'opacity:0;transition:opacity .5s ease,transform .5s ease;box-shadow:0 8px 30px rgba(0,0,0,.5)}' +
      '#unmask-teaser.show{opacity:1;transform:translate(-50%,0)}' +
      '#unmask-teaser .eye{filter:drop-shadow(0 0 6px rgba(255,77,94,.7))}' +
      '#unmask-teaser a{color:#ff6b78;text-decoration:none;font-weight:700;white-space:nowrap}' +
      '#unmask-teaser a:hover{text-decoration:underline}' +
      '#unmask-teaser .x{cursor:pointer;color:#6b7690;padding:0 2px}#unmask-teaser .x:hover{color:#fff}' +
      '@media(max-width:600px){#unmask-teaser{bottom:80px;font-size:.76rem}}';
    document.head.appendChild(css);

    var el = document.createElement('div'); el.id = 'unmask-teaser';
    el.innerHTML = '<span class="eye">👁</span><span class="msg">reading your browser…</span>' +
      '<a href="/unmask/">show me everything →</a><span class="x" title="dismiss">✕</span>';
    document.body.appendChild(el);
    el.querySelector('.x').addEventListener('click', function () {
      try { localStorage.setItem('unmask_teaser_x', '1'); } catch (e) {}
      el.classList.remove('show'); setTimeout(function () { el.remove(); }, 500);
    });
    return el;
  }

  function start() {
    var el = inject(); if (!el) return;
    var gpu = gpuName();
    fetch('/unmask/geo.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).catch(function () { return null; })
      .then(function (d) {
        var msg = el.querySelector('.msg');
        var city = d && d.geo && d.geo.city ? d.geo.city.replace(/[<>&]/g, '') : '';
        var cityH = city ? "you're in <b style=\"color:#fff\">" + city + '</b>' : '';
        var gpuH = gpu ? '<b style="color:#fff">' + gpu.replace(/[<>&]/g, '') + '</b>' : '';
        var phrase = (cityH && gpuH) ? (cityH + ', on a ' + gpuH) : cityH || (gpuH ? "you're using a " + gpuH : '');
        msg.innerHTML = phrase ? ('I can see ' + phrase + ' — and a lot more.')
          : 'I can already read your device fingerprint — no permission needed.';
        el.classList.add('show');
      });
  }

  // Let the homepage settle first, then slide in.
  setTimeout(start, 4000);
})();
