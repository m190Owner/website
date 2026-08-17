// Breach-monitoring toggle + alert dismiss on the dashboard. Optimistic with revert.
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

  var tog = document.getElementById('os-mon-toggle');
  if (tog) tog.addEventListener('change', function () {
    var status = document.getElementById('os-mon-status');
    tog.disabled = true;
    post({ action: 'toggle', on: tog.checked ? '1' : '0' }).then(function () {
      tog.disabled = false;
      if (status) status.textContent = tog.checked ? 'On — we’ll flag new breaches here.' : 'Off.';
    }).catch(function () { tog.checked = !tog.checked; tog.disabled = false; });
  });

  var dis = document.getElementById('os-mon-dismiss');
  if (dis) dis.addEventListener('click', function () {
    post({ action: 'dismiss' });
    var a = document.getElementById('os-mon-alert');
    if (a) a.style.display = 'none';
  });
})();
