// Social tab: per-username profile aggregation (auto), impersonation finder (on demand),
// Fediverse resolver, and profile/post preview. Talks to sociallookup.php.
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  function esc(s) { return String(s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function post(action, q) {
    return fetch('/osint/sociallookup.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ csrf: csrf, action: action, q: q }) }).then(function (r) { return r.json(); });
  }

  function scard(c) {
    var av = c.avatar ? '<span class="os-av"><img class="os-av-img" src="' + esc(c.avatar) + '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'"></span>' : '<span class="os-av"></span>';
    var h = '<div class="os-scard"><div class="os-scard-h">' + av + '<div class="os-scard-t"><div class="os-scard-plat"><a href="' + esc(c.url) + '" target="_blank" rel="noopener nofollow">' + esc(c.platform) + '</a></div>' + (c.name ? '<div class="os-scard-name">' + esc(c.name) + '</div>' : '') + '</div></div>';
    if (c.bio) h += '<div class="os-scard-bio">' + esc(c.bio) + '</div>';
    var meta = [];
    if (c.location) meta.push('📍 ' + esc(c.location));
    if (c.joined) meta.push('joined ' + esc(c.joined));
    if (c.stats) meta.push(esc(c.stats));
    if (meta.length) h += '<div class="os-scard-meta">' + meta.map(function (m) { return '<span>' + m + '</span>'; }).join('') + '</div>';
    if (c.linked && c.linked.length) {
      h += '<div class="os-scard-linked">';
      c.linked.forEach(function (l) { h += '<a href="' + esc(l.url || '#') + '" target="_blank" rel="noopener nofollow">' + esc(l.service) + ': ' + esc(l.name) + '</a>'; });
      h += '</div>';
    }
    return h + '</div>';
  }

  // per-username profile aggregation (auto)
  document.querySelectorAll('[data-social]').forEach(function (panel) {
    var un = panel.getAttribute('data-social'), out = panel.querySelector('.os-social-cards');
    post('profile', un).then(function (d) {
      if (d.error) { out.innerHTML = '<p class="os-dim">' + esc(d.error) + '</p>'; return; }
      var found = d.cards.filter(function (c) { return c.exists === true; });
      var none = d.cards.filter(function (c) { return c.exists === false; }).map(function (c) { return c.platform; });
      var unk = d.cards.filter(function (c) { return c.exists === null; }).map(function (c) { return c.platform; });
      var html = found.length ? '<div class="os-scardgrid">' + found.map(scard).join('') + '</div>' : '<p class="os-dim">No public profiles found for this handle on the checked platforms.</p>';
      var notes = [];
      if (none.length) notes.push('Not found on: ' + none.join(', '));
      if (unk.length) notes.push('Couldn\'t check: ' + unk.join(', '));
      if (notes.length) html += '<p class="os-fineprint">' + esc(notes.join(' · ')) + '</p>';
      out.innerHTML = html;
    }).catch(function () { out.innerHTML = '<p class="os-dim">Lookup failed.</p>'; });
  });

  // impersonation finder (on demand)
  document.querySelectorAll('[data-impersonate]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var un = btn.getAttribute('data-impersonate'), out = btn.closest('[data-social]').querySelector('.os-imp-out');
      out.hidden = false; btn.disabled = true; var t = btn.textContent; btn.innerHTML = '<span class="os-spinner"></span> Checking…';
      out.innerHTML = '<p class="os-dim">Checking handle variations across GitHub, Keybase, Chess.com, Lichess, HN…</p>';
      post('impersonate', un).then(function (d) {
        btn.disabled = false; btn.textContent = t;
        if (d.error) { out.innerHTML = '<p class="os-dim">' + esc(d.error) + '</p>'; return; }
        var others = d.rows.filter(function (r) { return !r.is_you; });
        if (!others.length) { out.innerHTML = '<p class="os-dim">Checked ' + d.checked + ' variations — no look-alike accounts found on the checked platforms. Good.</p>'; return; }
        var html = '<div class="os-warn-box"><b>' + others.length + ' look-alike handle(s) exist</b> — verify these aren\'t you and aren\'t impersonating you:</div><div class="os-list" style="margin-top:10px">';
        others.forEach(function (r) { html += '<div class="os-row"><div class="os-row-main"><div class="os-row-t"><span class="os-code">' + esc(r.variant) + '</span></div><div class="os-row-d">exists on ' + esc(r.hits.join(', ')) + '</div></div></div>'; });
        out.innerHTML = html + '</div>';
      }).catch(function () { btn.disabled = false; btn.textContent = t; out.innerHTML = '<p class="os-dim">Check failed.</p>'; });
    });
  });

  // Fediverse resolver
  function wire(btnId, inId, outId, action, render) {
    var btn = document.getElementById(btnId), inp = document.getElementById(inId), out = document.getElementById(outId);
    if (!btn) return;
    var go = function () {
      var q = inp.value.trim(); if (!q) return;
      out.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Looking up…</p>';
      post(action, q).then(function (d) { out.innerHTML = d.error ? '<p class="os-dim">' + esc(d.error) + '</p>' : render(d); }).catch(function () { out.innerHTML = '<p class="os-dim">Lookup failed.</p>'; });
    };
    btn.addEventListener('click', go);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
  }
  wire('os-fedi-run', 'os-fedi-in', 'os-fedi-out', 'fediverse', function (d) {
    if (!d.exists) return '<p class="os-dim">No account found for <b>' + esc(d.handle) + '</b> on ' + esc(d.instance) + '.</p>';
    return '<dl class="os-kv"><dt>Handle</dt><dd>' + esc(d.handle) + '</dd><dt>Instance</dt><dd>' + esc(d.instance) + '</dd>'
      + (d.profile ? '<dt>Profile</dt><dd><a href="' + esc(d.profile) + '" target="_blank" rel="noopener nofollow">' + esc(d.profile) + '</a></dd>' : '') + '</dl>';
  });
  wire('os-og-run', 'os-og-in', 'os-og-out', 'ogmeta', function (d) {
    if (!d.title && !d.description && !d.image) return '<p class="os-dim">No preview metadata found (the page may block bots or require login).</p>';
    var img = d.image ? '<img src="' + esc(d.image) + '" alt="" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'">' : '';
    return '<div class="os-ogcard">' + img + '<div class="os-ogcard-b"><h4>' + esc(d.title || '(no title)') + '</h4>'
      + (d.description ? '<p>' + esc(d.description) + '</p>' : '') + (d.site ? '<p class="os-fineprint">' + esc(d.site) + '</p>' : '') + '</div></div>';
  });
})();
