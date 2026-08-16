// Domain footprint: click "Scan"/"Rescan" → POST to domainscan.php, show a spinner,
// then reload so the page renders the freshly cached, server-side result.
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
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
})();
