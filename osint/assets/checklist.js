// Shared behaviour for the removal + hardening checklists: a done checkbox and an
// optional "pending" toggle per row, a live progress bar, filter chips, and search.
// State persists to osint/checklist.php. Optimistic UI with revert on failure.
(function () {
  var root = document.querySelector('[data-checklist]');
  if (!root) return;
  var list = root.getAttribute('data-checklist');
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';

  function post(item, status) {
    return fetch('/osint/checklist.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ csrf: csrf, list: list, item: item, status: status })
    }).then(function (r) { return r.json(); });
  }

  function progress() {
    var rows = root.querySelectorAll('.os-row');
    var done = root.querySelectorAll('.os-row.done').length;
    var fill = document.getElementById('os-cl-fill');
    var lbl = document.getElementById('os-cl-lbl');
    if (fill) fill.style.width = (rows.length ? done / rows.length * 100 : 0) + '%';
    if (lbl) lbl.textContent = done + ' of ' + rows.length + ' done';
  }

  var filter = 'all', term = '';
  function applyFilter() {
    root.querySelectorAll('.os-row').forEach(function (row) {
      var st = row.getAttribute('data-status') || 'todo';
      var okF = filter === 'all'
        || (filter === 'done' && st === 'done')
        || (filter === 'todo' && st !== 'done')
        || (filter === 'pending' && st === 'started');
      var okT = !term || (row.getAttribute('data-search') || '').indexOf(term) >= 0;
      row.style.display = (okF && okT) ? '' : 'none';
    });
  }

  root.addEventListener('change', function (e) {
    var cb = e.target.closest('.os-check');
    if (!cb) return;
    var row = cb.closest('.os-row');
    var item = row.getAttribute('data-item');
    var status = cb.checked ? 'done' : 'todo';
    row.classList.toggle('done', cb.checked);
    row.setAttribute('data-status', status);
    var pend = row.querySelector('.os-pendbtn');
    if (pend) { pend.hidden = cb.checked; if (cb.checked) pend.classList.remove('on'); }
    progress(); applyFilter();
    post(item, status).catch(function () {  // revert
      cb.checked = !cb.checked;
      row.classList.toggle('done', cb.checked);
      row.setAttribute('data-status', cb.checked ? 'done' : 'todo');
      if (pend) pend.hidden = cb.checked;
      progress(); applyFilter();
    });
  });

  root.addEventListener('click', function (e) {
    var pb = e.target.closest('.os-pendbtn');
    if (!pb) return;
    var row = pb.closest('.os-row');
    var item = row.getAttribute('data-item');
    var on = !pb.classList.contains('on');
    pb.classList.toggle('on', on);
    row.setAttribute('data-status', on ? 'started' : 'todo');
    applyFilter();
    post(item, on ? 'started' : 'todo');
  });

  var chips = document.querySelectorAll('#os-cl-chips .os-chip');
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      chips.forEach(function (x) { x.classList.remove('on'); });
      c.classList.add('on');
      filter = c.getAttribute('data-filter');
      applyFilter();
    });
  });

  var q = document.getElementById('os-cl-search');
  if (q) q.addEventListener('input', function () { term = q.value.toLowerCase(); applyFilter(); });

  progress();
})();
