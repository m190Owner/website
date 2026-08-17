// Breach-monitoring: dashboard toggle, alert dismiss, and a self-driving background
// re-check so monitoring needs no external cron. When the marker element is present
// (server decided a re-check is due), we fire it in the background and, if new breaches
// turned up, surface the alert without a reload.
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  function post(body) {
    body.csrf = csrf;
    return fetch('/osint/monitor.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams(body)
    }).then(function (r) { return r.json(); });
  }
  function esc(s) { return String(s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }

  function wireDismiss(btn, box) {
    if (!btn) return;
    btn.addEventListener('click', function () { post({ action: 'dismiss' }); if (box) box.style.display = 'none'; });
  }
  wireDismiss(document.getElementById('os-mon-dismiss'), document.getElementById('os-mon-alert'));
  var ctDismiss = document.getElementById('os-ct-dismiss');
  if (ctDismiss) ctDismiss.addEventListener('click', function () { post({ action: 'dismiss_ct' }); var b = document.getElementById('os-ct-alert'); if (b) b.style.display = 'none'; });

  function wireToggle(id, action, statusId, onText) {
    var tog = document.getElementById(id);
    if (!tog) return;
    tog.addEventListener('change', function () {
      var status = document.getElementById(statusId);
      tog.disabled = true;
      post({ action: action, on: tog.checked ? '1' : '0' }).then(function () {
        tog.disabled = false;
        if (status) status.textContent = tog.checked ? onText : 'Off.';
      }).catch(function () { tog.checked = !tog.checked; tog.disabled = false; });
    });
  }
  wireToggle('os-mon-toggle', 'toggle', 'os-mon-status', 'On — we’ll re-check automatically when you visit and flag new breaches here.');
  wireToggle('os-ct-toggle', 'toggle_ct', 'os-ct-status', 'On — we’ll watch the certificate-transparency logs for your domains and flag new certificates here.');

  function showAlert(pending) {
    if (document.getElementById('os-mon-alert') || !pending || !pending.length) return;
    var items = pending.slice().reverse().map(function (pi) {
      return '<li><b>' + esc(pi.email) + '</b> in the <b>' + esc(pi.breach) + '</b> breach</li>';
    }).join('');
    var div = document.createElement('div');
    div.className = 'os-panel os-alertbox'; div.id = 'os-mon-alert';
    div.innerHTML = '<h3 class="os-h3">&#9888; New exposure since your last check</h3>'
      + '<p class="os-dim os-mb"><b>' + pending.length + '</b> new breach record(s) appeared for your monitored emails:</p>'
      + '<ul class="os-rlist">' + items + '</ul>'
      + '<div class="os-inrow" style="margin-top:12px"><a class="os-btn os-btn-sm" href="/osint/harden.php">What to do</a>'
      + '<button type="button" class="os-btn os-btn-sm" id="os-mon-dismiss">Got it, dismiss</button></div>';
    var main = document.querySelector('.os-main');
    if (main) main.insertBefore(div, main.firstChild);
    wireDismiss(document.getElementById('os-mon-dismiss'), div);
  }

  function showCtAlert(pending) {
    if (document.getElementById('os-ct-alert') || !pending || !pending.length) return;
    var items = pending.slice().reverse().map(function (ci) {
      return '<li><b>' + esc(ci.name) + '</b> via ' + esc(ci.issuer) + (ci.nb ? ' · ' + esc(ci.nb) : '') + '</li>';
    }).join('');
    var div = document.createElement('div');
    div.className = 'os-panel os-alertbox'; div.id = 'os-ct-alert';
    div.innerHTML = '<h3 class="os-h3">&#9888; New certificate issued for your domain</h3>'
      + '<p class="os-dim os-mb"><b>' + pending.length + '</b> new certificate(s) appeared in the public logs for your monitored domains — verify you issued them:</p>'
      + '<ul class="os-rlist">' + items + '</ul>'
      + '<div class="os-inrow" style="margin-top:12px"><a class="os-btn os-btn-sm" href="/osint/domain.php">Review domains</a>'
      + '<button type="button" class="os-btn os-btn-sm" id="os-ct-dismiss">Got it, dismiss</button></div>';
    var main = document.querySelector('.os-main');
    if (main) main.insertBefore(div, main.firstChild);
    var b = document.getElementById('os-ct-dismiss');
    if (b) b.addEventListener('click', function () { post({ action: 'dismiss_ct' }); div.style.display = 'none'; });
  }

  // Self-driving check: only present when the server says a re-check is due. One sweep
  // covers both breach monitoring and certificate-transparency monitoring.
  if (document.getElementById('os-mon-auto')) {
    post({ action: 'check' }).then(function (r) {
      if (!r || !r.ran) return;
      if (r.new > 0) showAlert(r.pending);
      if (r.ct_new > 0) showCtAlert(r.ct_pending);
    }).catch(function () {});
  }
})();
