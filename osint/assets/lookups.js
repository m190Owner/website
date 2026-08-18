// Drives the investigation lookups (URL / IP / DNS / cert) against lookup.php.
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  function esc(s) { return String(s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }
  function prow(cls, k, v) { return '<div class="os-prow"><span class="os-pdot os-pdot-' + cls + '"></span><span class="os-pk">' + k + '</span><span>' + v + '</span></div>'; }
  var PORTS = { 21: 'FTP', 22: 'SSH', 23: 'Telnet', 25: 'SMTP', 53: 'DNS', 80: 'HTTP', 110: 'POP3', 135: 'MSRPC', 139: 'NetBIOS', 143: 'IMAP', 161: 'SNMP', 389: 'LDAP', 443: 'HTTPS', 445: 'SMB', 465: 'SMTPS', 587: 'SMTP', 993: 'IMAPS', 995: 'POP3S', 1433: 'MSSQL', 2082: 'cPanel', 2083: 'cPanel', 3306: 'MySQL', 3389: 'RDP', 5432: 'PostgreSQL', 5900: 'VNC', 5985: 'WinRM', 6379: 'Redis', 8080: 'HTTP-alt', 8443: 'HTTPS-alt', 9200: 'Elasticsearch', 11211: 'Memcached', 27017: 'MongoDB' };
  function renderServices(s) {
    if (!s) return '';   // unreachable / not applicable
    if (!s.found) return '<div class="os-subhead">Exposed services <span class="os-dim">(Shodan InternetDB)</span></div><p class="os-dim">No internet-facing services recorded for this IP — the good state.</p>';
    var html = '<div class="os-subhead">Exposed services <span class="os-dim">(Shodan InternetDB)</span></div>';
    if (s.ports && s.ports.length) {
      html += '<div class="os-taglist" style="margin-bottom:8px">';
      s.ports.forEach(function (p) { var risky = [23, 3389, 445, 3306, 5432, 6379, 27017, 9200, 11211, 5900, 135, 139].indexOf(p) >= 0; html += '<span class="os-tag' + (risky ? ' os-tag-hi' : '') + '">' + p + (PORTS[p] ? ' ' + PORTS[p] : '') + '</span>'; });
      html += '</div>';
    }
    if (s.vulns && s.vulns.length) {
      html += '<div class="os-warn-box" style="margin-top:0"><b>' + s.vulns.length + ' known CVE(s)</b> associated with services on this host — patch or restrict them:</div><div class="os-taglist" style="margin-top:8px">';
      s.vulns.slice(0, 24).forEach(function (v) { html += '<a class="os-tag os-tag-hi" href="https://nvd.nist.gov/vuln/detail/' + encodeURIComponent(v) + '" target="_blank" rel="noopener nofollow">' + esc(v) + '</a>'; });
      html += '</div>';
    }
    if (s.tags && s.tags.length) { html += '<div class="os-taglist" style="margin-top:8px">'; s.tags.forEach(function (t) { html += '<span class="os-tag">' + esc(t) + '</span>'; }); html += '</div>'; }
    return html;
  }
  function post(action, q) {
    return fetch('/osint/lookup.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ csrf: csrf, action: action, q: q }) }).then(function (r) { return r.json(); });
  }
  function busy(el) { el.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Looking up…</p>'; }
  function wire(btnId, inId, outId, action, render) {
    var btn = document.getElementById(btnId), inp = document.getElementById(inId), out = document.getElementById(outId);
    if (!btn) return;
    var go = function () {
      var q = inp.value.trim(); if (!q) return;
      busy(out);
      post(action, q).then(function (d) { out.innerHTML = d.error ? '<p class="os-dim">' + esc(d.error) + '</p>' : render(d); }).catch(function () { out.innerHTML = '<p class="os-dim">Lookup failed.</p>'; });
    };
    btn.addEventListener('click', go);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter' && inp.tagName !== 'TEXTAREA') { e.preventDefault(); go(); } });
  }

  // URL / link analyzer
  wire('os-url-run', 'os-url-in', 'os-url-out', 'url', function (d) {
    var html = '<div class="os-subhead" style="margin-top:0">Redirect chain</div><div class="os-list">';
    d.chain.forEach(function (h) {
      var cls = h.code >= 200 && h.code < 300 ? 'ok' : (h.code >= 300 && h.code < 400 ? 'warn' : 'bad');
      html += '<div class="os-prow"><span class="os-pdot os-pdot-' + cls + '"></span><span class="os-pk">' + (h.code || '—') + '</span><span class="os-code">' + esc(h.url) + '</span></div>';
    });
    html += '</div>';
    html += '<div class="os-subhead">Final destination</div><p class="os-code" style="display:block">' + esc(d.final) + '</p>';
    if (d.flags && d.flags.length) {
      html += '<div class="os-subhead">Flags</div><div class="os-posture">';
      d.flags.forEach(function (f) { html += prow(f[0], f[0] === 'bad' ? 'Risk' : 'Note', esc(f[1])); });
      html += '</div>';
    } else html += '<p class="os-dim" style="margin-top:8px">No obvious red flags — still verify the destination before trusting it.</p>';
    return html;
  });

  // IP lookup
  wire('os-ip-run', 'os-ip-in', 'os-ip-out', 'ip', function (d) {
    if (d.private) return '<p class="os-dim">' + esc(d.ip) + ' is a private/reserved address — no public data.</p>';
    var loc = [d.city, d.region, d.country].filter(Boolean).join(', ');
    var html = '<dl class="os-kv"><dt>IP</dt><dd>' + esc(d.ip) + '</dd>';
    if (d.ptr) html += '<dt>Reverse DNS</dt><dd>' + esc(d.ptr) + '</dd>';
    if (loc) html += '<dt>Location</dt><dd>' + esc(loc) + (d.flag ? ' ' + d.flag : '') + '</dd>';
    if (d.isp) html += '<dt>ISP</dt><dd>' + esc(d.isp) + '</dd>';
    if (d.asn) html += '<dt>ASN</dt><dd>AS' + esc(String(d.asn).replace(/\D/g, '')) + '</dd>';
    if (d.type) html += '<dt>Type</dt><dd>' + esc(d.type) + '</dd>';
    if (d.tz) html += '<dt>Timezone</dt><dd>' + esc(d.tz) + '</dd>';
    html += '</dl>';
    html += '<div class="os-subhead">Reputation</div><div class="os-posture">';
    if (d.ds_ok) {
      var atk = (d.ds_attacks | 0) > 0 || (d.ds_count | 0) > 0;
      html += prow(atk ? 'bad' : 'ok', 'DShield', atk ? 'Reported as an attack source (' + (d.ds_attacks | 0) + ' targets)' : 'No attack activity reported');
    } else html += prow('warn', 'DShield', 'No response');
    html += '</div>';
    html += renderServices(d.services);
    var e = encodeURIComponent(d.ip);
    html += '<div class="os-subhead">Check further</div><div class="os-srch">'
      + '<a href="https://www.abuseipdb.com/check/' + e + '" target="_blank" rel="noopener nofollow">AbuseIPDB</a>'
      + '<a href="https://www.virustotal.com/gui/ip-address/' + e + '" target="_blank" rel="noopener nofollow">VirusTotal</a>'
      + '<a href="https://www.shodan.io/host/' + e + '" target="_blank" rel="noopener nofollow">Shodan</a></div>';
    return html;
  });

  // DNS lookup
  wire('os-dns-run', 'os-dns-in', 'os-dns-out', 'dns', function (d) {
    var keys = Object.keys(d.records || {});
    if (!keys.length) return '<p class="os-dim">No records found for ' + esc(d.domain) + '.</p>';
    var html = '';
    keys.forEach(function (t) {
      html += '<div class="os-subhead">' + esc(t) + '</div><div class="os-taglist">';
      d.records[t].forEach(function (v) { html += '<span class="os-code">' + esc(v) + '</span>'; });
      html += '</div>';
    });
    return html;
  });

  // Certificate decoder
  wire('os-cert-run', 'os-cert-in', 'os-cert-out', 'cert', function (d) {
    var html = '<dl class="os-kv">';
    if (d.subject) html += '<dt>Subject</dt><dd>' + esc(d.subject) + '</dd>';
    if (d.issuer) html += '<dt>Issuer</dt><dd>' + esc(d.issuer) + '</dd>';
    if (d.valid_from) html += '<dt>Valid from</dt><dd>' + new Date(d.valid_from * 1000).toISOString().slice(0, 10) + '</dd>';
    if (d.valid_to) html += '<dt>Valid until</dt><dd>' + new Date(d.valid_to * 1000).toISOString().slice(0, 10) + '</dd>';
    if (d.sigalg) html += '<dt>Signature</dt><dd>' + esc(d.sigalg) + '</dd>';
    if (d.serial) html += '<dt>Serial</dt><dd>' + esc(d.serial) + '</dd>';
    html += '</dl>';
    if (d.sans && d.sans.length) {
      html += '<div class="os-subhead">SANs (' + d.sans.length + ')</div><div class="os-taglist">';
      d.sans.forEach(function (s) { html += '<span class="os-code">' + esc(s) + '</span>'; });
      html += '</div>';
    }
    return html;
  });
})();
