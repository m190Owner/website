// Removal verification: after opting out, re-check each broker. The name/city stay in
// this browser (localStorage) and are only used to build per-broker site: search links;
// nothing about the name is sent to our server. The verify STATUS (still listed / removed)
// is saved server-side via checklist.php (list=brokerverify) so it persists across devices.
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  var nameEl = document.getElementById('os-vf-name');
  var cityEl = document.getElementById('os-vf-city');
  if (!nameEl) return;
  nameEl.value = localStorage.getItem('os_vf_name') || '';
  cityEl.value = localStorage.getItem('os_vf_city') || '';

  function setStatus(item, status) {
    return fetch('/osint/checklist.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ csrf: csrf, list: 'brokerverify', item: item, status: status })
    }).then(function (r) { return r.json(); });
  }

  function searchUrl(domain) {
    var name = (nameEl.value || '').trim();
    if (!name) return null;
    var q = 'site:' + domain + ' "' + name + '"';
    if ((cityEl.value || '').trim()) q += ' ' + cityEl.value.trim();
    return 'https://www.google.com/search?q=' + encodeURIComponent(q);
  }

  function badge(el, status) {
    var b = el.querySelector('.os-verify-badge');
    el.classList.remove('os-vf-listed', 'os-vf-removed');
    if (status === 'done') { b.textContent = 'removed ✓'; b.className = 'os-verify-badge os-vf-ok'; el.classList.add('os-vf-removed'); }
    else if (status === 'started') { b.textContent = 'still listed'; b.className = 'os-verify-badge os-vf-bad'; el.classList.add('os-vf-listed'); }
    else { b.textContent = ''; b.className = 'os-verify-badge'; }
  }

  function updateCount() {
    var n = document.querySelectorAll('.os-verify[data-vstatus="done"]').length;
    var lbl = document.getElementById('os-vf-lbl');
    if (lbl) lbl.textContent = n + ' verified removed';
  }

  var rows = Array.prototype.slice.call(document.querySelectorAll('.os-verify'));
  function refreshLinks() {
    rows.forEach(function (el) {
      var link = el.querySelector('.os-verify-link');
      var url = searchUrl(el.getAttribute('data-domain'));
      if (url) { link.href = url; link.classList.remove('os-disabled'); }
      else { link.href = '#'; link.classList.add('os-disabled'); }
    });
  }

  rows.forEach(function (el) {
    badge(el, el.getAttribute('data-vstatus') || '');
    var link = el.querySelector('.os-verify-link');
    link.addEventListener('click', function (e) {
      if (!searchUrl(el.getAttribute('data-domain'))) { e.preventDefault(); nameEl.focus(); nameEl.classList.add('os-input-flash'); setTimeout(function () { nameEl.classList.remove('os-input-flash'); }, 900); }
    });
    el.querySelectorAll('.os-verify-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var cur = el.getAttribute('data-vstatus');
        var want = btn.getAttribute('data-v');
        var next = cur === want ? 'todo' : want;      // click the active one again to clear
        el.setAttribute('data-vstatus', next === 'todo' ? '' : next);
        badge(el, next === 'todo' ? '' : next);
        updateCount();
        setStatus(el.getAttribute('data-vitem'), next).catch(function () {});
      });
    });
  });

  function persist() { localStorage.setItem('os_vf_name', nameEl.value); localStorage.setItem('os_vf_city', cityEl.value); refreshLinks(); }
  nameEl.addEventListener('input', persist);
  cityEl.addEventListener('input', persist);
  refreshLinks();
  updateCount();
})();
