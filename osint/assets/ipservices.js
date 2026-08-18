// On-demand exposed-services check for your own public IP (Shodan InternetDB). Kept off
// the page-load path so network.php stays fast. Guarded so the button can never stick.
(function () {
  var btn = document.getElementById('os-svc-run'), out = document.getElementById('os-svc-out');
  if (!btn) return;
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }
  var PORTS = { 21: 'FTP', 22: 'SSH', 23: 'Telnet', 25: 'SMTP', 53: 'DNS', 80: 'HTTP', 110: 'POP3', 135: 'MSRPC', 139: 'NetBIOS', 143: 'IMAP', 161: 'SNMP', 389: 'LDAP', 443: 'HTTPS', 445: 'SMB', 465: 'SMTPS', 587: 'SMTP', 993: 'IMAPS', 995: 'POP3S', 1433: 'MSSQL', 2082: 'cPanel', 2083: 'cPanel', 3306: 'MySQL', 3389: 'RDP', 5432: 'PostgreSQL', 5900: 'VNC', 5985: 'WinRM', 6379: 'Redis', 8080: 'HTTP-alt', 8443: 'HTTPS-alt', 9200: 'Elasticsearch', 11211: 'Memcached', 27017: 'MongoDB' };
  var RISKY = [23, 135, 139, 445, 3389, 3306, 5432, 6379, 27017, 9200, 11211, 5900];

  function render(d) {
    if (!d || !d.ok) return '<p class="os-dim">Couldn\'t run the check — try again.</p>';
    if (d.private) return '<p class="os-dim">You\'re on a local/private address here (' + esc(d.ip) + '), so there\'s nothing internet-facing to check. On the live site this reads your real public IP.</p>';
    var s = d.services;
    if (!s) return '<p class="os-dim">Shodan InternetDB didn\'t respond for your IP this time — that\'s a "couldn\'t check", not a clean result. Try again shortly.</p>';
    if (!s.found) return '<p class="os-dim">No internet-facing services are recorded for your IP (' + esc(d.ip) + ') — nothing is listening publicly, which is the safe state for a home connection.</p>';
    var html = '';
    if (s.ports && s.ports.length) {
      html += '<div class="os-subhead" style="margin-top:0">Open ports</div><div class="os-taglist">';
      s.ports.forEach(function (p) { html += '<span class="os-tag' + (RISKY.indexOf(p) >= 0 ? ' os-tag-hi' : '') + '">' + p + (PORTS[p] ? ' ' + PORTS[p] : '') + '</span>'; });
      html += '</div>';
    }
    if (s.vulns && s.vulns.length) {
      html += '<div class="os-warn-box"><b>' + s.vulns.length + ' known CVE(s)</b> on services exposed by your IP — patch the affected software or take it off the public internet.</div><div class="os-taglist" style="margin-top:8px">';
      s.vulns.slice(0, 24).forEach(function (v) { html += '<a class="os-vuln" href="https://nvd.nist.gov/vuln/detail/' + encodeURIComponent(v) + '" target="_blank" rel="noopener nofollow">' + esc(v) + '</a>'; });
      html += '</div>';
    }
    if (s.tags && s.tags.length) { html += '<div class="os-taglist" style="margin-top:8px">'; s.tags.forEach(function (t) { html += '<span class="os-tag">' + esc(t) + '</span>'; }); html += '</div>'; }
    return html || '<p class="os-dim">Your IP is listed but with no open ports or CVEs recorded — good.</p>';
  }

  btn.addEventListener('click', function () {
    btn.disabled = true; var old = btn.textContent;
    btn.innerHTML = '<span class="os-spinner"></span> Checking…';
    out.hidden = false; out.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Querying Shodan InternetDB for your public IP…</p>';
    fetch('/osint/ipservices.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ csrf: csrf })
    }).then(function (r) { return r.json(); }).then(function (d) {
      out.innerHTML = render(d);
    }).catch(function () {
      out.innerHTML = '<p class="os-dim">Check failed — try again.</p>';
    }).then(function () {
      btn.disabled = false; btn.textContent = 'Re-check';
    });
  });
})();
