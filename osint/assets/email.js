// Email intel tab — consolidated per-email report. Button → emailintel.php, render
// inline. Also renders any server-cached report embedded on load. Same pattern as the
// domain tab's on-demand sections.
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function prow(cls, k, v) { return '<div class="os-prow"><span class="os-pdot os-pdot-' + cls + '"></span><span class="os-pk">' + esc(k) + '</span><span>' + v + '</span></div>'; }
  function sub(t) { return '<div class="os-subhead">' + esc(t) + '</div>'; }

  function renderEmail(d) {
    if (!d || d.error) return '<p class="os-dim">' + esc(d && d.error ? d.error : 'Lookup failed.') + '</p>';
    var h = '';

    // Address & classification
    h += sub('Address');
    if (d.canonical) h += '<p class="os-dim os-mb">Canonicalizes to <span class="os-code">' + esc(d.canonical) + '</span> — Gmail ignores dots and everything after <span class="os-code">+</span>, so all variants hit the same inbox.</p>';
    var chips = [];
    chips.push('<span class="os-tag">' + (d.disposable ? 'disposable domain' : (d.free ? 'consumer webmail' : 'custom domain')) + '</span>');
    if (d.disposable) chips.push('<span class="os-tag os-tag-hi">throwaway</span>');
    if (d.role) chips.push('<span class="os-tag os-tag-hi">role account</span>');
    h += '<div class="os-taglist" style="margin-bottom:6px">' + chips.join('') + '</div>';

    // Deliverability & mail security
    h += sub('Deliverability & mail security');
    h += '<div class="os-posture">';
    h += prow(d.deliverable ? 'ok' : 'warn', 'Receives mail (MX)', d.deliverable ? esc((d.mx_hosts || []).join(', ')) : 'No MX record — this address cannot receive email (defunct or fabricated)');
    h += prow(d.disposable ? 'bad' : 'ok', 'Provider', d.disposable ? 'Disposable / temporary-mail provider' : (d.free ? 'Consumer webmail provider' : 'Custom / organisation domain'));
    h += prow(d.spf ? 'ok' : 'warn', 'SPF', d.spf ? 'Published' : 'Missing — mail from this domain can be spoofed');
    if (d.dmarc === 'reject' || d.dmarc === 'quarantine') h += prow('ok', 'DMARC', 'Enforced (<span class="os-code">p=' + esc(d.dmarc) + '</span>)');
    else if (d.dmarc === 'none') h += prow('warn', 'DMARC', 'Monitoring only (<span class="os-code">p=none</span>) — not enforced');
    else h += prow('warn', 'DMARC', 'Missing — no anti-spoofing policy on the domain');
    h += '</div>';

    // Breach exposure
    h += sub('Breach exposure');
    var totalBreach = (d.breach_count || 0) + (d.leakcheck ? 1 : 0) + (d.pastes ? 1 : 0);
    if (!totalBreach) {
      h += '<p class="os-dim">No records found in XposedOrNot, LeakCheck, or public pastes. (A clean result isn\'t a guarantee — no single corpus is complete.)</p>';
    } else {
      h += '<p class="os-dim os-mb"><b>' + (d.breach_count || 0) + '</b> breach(es)' + (d.span ? ' spanning ' + esc(d.span) : '') + (d.leakcheck ? ' · <b>' + d.leakcheck.found + '</b> LeakCheck record(s)' : '') + (d.pastes ? ' · in <b>' + d.pastes + '</b> public paste(s)' : '') + '.</p>';
      if (d.pw_exposed) h += '<div class="os-warn-box" style="margin-top:0;margin-bottom:10px"><b>A password was exposed</b> in at least one breach of this address. Rotate it anywhere it was reused and turn on 2FA.</div>';
      if (d.dataclasses && d.dataclasses.length) {
        h += '<div class="os-taglist" style="margin-bottom:10px">';
        d.dataclasses.forEach(function (c) { var hot = /passw|security question|payment|card|social security|bank/i.test(c); h += '<span class="os-tag' + (hot ? ' os-tag-hi' : '') + '">' + esc(c) + '</span>'; });
        h += '</div>';
      }
      if (d.breaches && d.breaches.length) {
        h += '<div class="os-list">';
        d.breaches.forEach(function (b) {
          h += '<div class="os-row"><div class="os-row-main"><div class="os-row-t"><b>' + esc(b.name) + '</b>' + (b.date ? ' <span class="os-dim">' + esc(b.date) + '</span>' : '') + '</div>' + (b.data ? '<div class="os-row-d">' + esc(b.data) + '</div>' : '') + '</div></div>';
        });
        h += '</div>';
      }
      if (d.leakcheck && d.leakcheck.sources && d.leakcheck.sources.length) {
        h += '<p class="os-fineprint" style="margin-top:8px">LeakCheck sources: ' + esc(d.leakcheck.sources.join(', ')) + (d.leakcheck.fields && d.leakcheck.fields.length ? ' · exposes ' + esc(d.leakcheck.fields.join(', ')) : '') + '</p>';
      }
    }

    // Gravatar
    if (d.gravatar) {
      h += sub('Gravatar profile');
      var g = d.gravatar;
      var av = g.avatar ? '<img class="os-av-img" src="' + esc(g.avatar) + '" alt="" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'">' : '';
      h += '<div class="os-scard"><div class="os-scard-h"><span class="os-av">' + av + '</span><div class="os-scard-t">' + (g.name ? '<div class="os-scard-name">' + esc(g.name) + '</div>' : '') + (g.location ? '<div class="os-scard-meta"><span>📍 ' + esc(g.location) + '</span></div>' : '') + '</div></div>';
      if (g.about) h += '<div class="os-scard-bio">' + esc(g.about) + '</div>';
      var linked = (g.accounts || []).concat(g.urls ? g.urls.map(function (u) { return { label: u.title, url: u.url }; }) : []);
      if (linked.length) { h += '<div class="os-scard-linked">'; linked.forEach(function (l) { h += '<a href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow">' + esc(l.label) + '</a>'; }); h += '</div>'; }
      h += '</div><p class="os-fineprint">A Gravatar tied to this email is public to any site that knows the address — it exposes your name, photo, and linked profiles.</p>';
    }

    // Registered accounts
    if (d.accounts && d.accounts.length) {
      h += sub('Registered accounts');
      h += '<div class="os-list">';
      d.accounts.forEach(function (a) { h += '<div class="os-row"><div class="os-row-main"><div class="os-row-t"><a href="' + esc(a.url) + '" target="_blank" rel="noopener nofollow">' + esc(a.label) + '</a></div><div class="os-row-d">This address is registered here.</div></div></div>'; });
      h += '</div>';
    }
    return h;
  }

  document.querySelectorAll('.os-emailout .os-email-data').forEach(function (s) {
    try { s.parentNode.innerHTML = renderEmail(JSON.parse(s.textContent)); } catch (e) {}
  });

  document.querySelectorAll('[data-email]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var email = btn.getAttribute('data-email');
      var out = document.getElementById('os-email-' + btn.getAttribute('data-eidx'));
      var status = document.getElementById('os-estatus-' + btn.getAttribute('data-eidx'));
      btn.disabled = true; var old = btn.textContent;
      btn.innerHTML = '<span class="os-spinner"></span> Analyzing…';
      if (out) { out.hidden = false; out.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Querying breach corpora, Gravatar, and DNS…</p>'; }
      fetch('/osint/emailintel.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ csrf: csrf, email: email })
      }).then(function (r) { return r.json(); }).then(function (j) {
        btn.disabled = false; btn.textContent = 'Re-analyze';
        if (status) status.textContent = 'Analyzed just now';
        if (out) out.innerHTML = renderEmail(j);
      }).catch(function () {
        btn.disabled = false; btn.textContent = old;
        if (out) out.innerHTML = '<p class="os-dim">Analysis failed — try again.</p>';
      });
    });
  });
})();
