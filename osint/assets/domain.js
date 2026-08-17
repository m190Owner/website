// Domain footprint page:
//  - "Scan"/"Rescan" → POST domainscan.php, then reload (server renders cached result).
//  - "Check" look-alikes → POST domaintwist.php, render the typosquat hits inline.
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  function esc(s) { return String(s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }

  // --- DNS / email-security footprint ---
  document.querySelectorAll('[data-scan-domain]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var domain = btn.getAttribute('data-scan-domain');
      var status = document.getElementById('os-dstatus-' + btn.getAttribute('data-idx'));
      btn.disabled = true;
      var old = btn.textContent;
      btn.innerHTML = '<span class="os-spinner"></span> Scanning…';
      if (status) status.textContent = 'Querying DNS and certificate transparency…';
      fetch('/osint/domainscan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ csrf: csrf, domain: domain })
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.ok) { location.reload(); }
        else { btn.disabled = false; btn.textContent = old; if (status) status.textContent = j.error || 'Lookup failed.'; }
      }).catch(function () {
        btn.disabled = false; btn.textContent = old;
        if (status) status.textContent = 'Lookup failed — try again.';
      });
    });
  });

  // --- look-alike / typosquat domains ---
  function renderTwist(d) {
    if (d.error) return '<p class="os-dim">' + esc(d.error) + '</p>';
    if (!d.registered) {
      return '<p class="os-dim">Checked <b>' + (d.generated || 0) + '</b> look-alike variations — none are currently registered. Good.</p>';
    }
    var hi = d.hits.filter(function (h) { return h.mx && !h.same_ip; }).length;
    var head = '<div class="os-warn-box"><b>' + d.registered + '</b> of ' + d.generated + ' look-alike domain(s) are registered'
      + (hi ? ' — <b>' + hi + '</b> can receive email (phishing-capable) and are hosted elsewhere' : '') + '.</div>';
    var rows = d.hits.map(function (h) {
      var tags = '<span class="os-tag">' + esc(h.type) + '</span>';
      if (h.mx) tags += '<span class="os-tag os-tag-hi">✉ mail</span>';
      tags += h.same_ip ? '<span class="os-tag">same host as you</span>' : '<span class="os-tag os-tag-hi">other host</span>';
      var ip = h.a && h.a.length ? '<div class="os-row-d">→ ' + esc(h.a.join(', ')) + '</div>' : '';
      return '<div class="os-row"><div class="os-row-main"><div class="os-row-t">'
        + '<a href="https://' + esc(h.domain) + '" target="_blank" rel="noopener nofollow">' + esc(h.domain) + '</a> ' + tags
        + '</div>' + ip + '</div></div>';
    }).join('');
    return head + '<div class="os-list" style="margin-top:10px">' + rows + '</div>'
      + '<p class="os-fineprint">A registered look-alike isn\'t proof of abuse — but any that can receive mail and resolve to a host that isn\'t yours are worth watching or reporting. Some may be your own defensive registrations.</p>';
  }

  // Render any server-cached result embedded on load.
  document.querySelectorAll('.os-twistout .os-twist-data').forEach(function (s) {
    try { s.parentNode.innerHTML = renderTwist(JSON.parse(s.textContent)); } catch (e) {}
  });

  document.querySelectorAll('[data-twist]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var domain = btn.getAttribute('data-twist');
      var out = document.getElementById('os-twist-' + btn.getAttribute('data-tidx'));
      btn.disabled = true;
      var old = btn.textContent;
      btn.innerHTML = '<span class="os-spinner"></span> Checking…';
      if (out) { out.hidden = false; out.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Generating and resolving look-alike domains… this takes a few seconds.</p>'; }
      fetch('/osint/domaintwist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ csrf: csrf, domain: domain })
      }).then(function (r) { return r.json(); }).then(function (j) {
        btn.disabled = false; btn.textContent = 'Recheck';
        if (out) out.innerHTML = renderTwist(j);
      }).catch(function () {
        btn.disabled = false; btn.textContent = old;
        if (out) out.innerHTML = '<p class="os-dim">Check failed — try again.</p>';
      });
    });
  });
})();
