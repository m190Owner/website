(function () {
  'use strict';
  var runBtn = document.getElementById('os-run');
  if (!runBtn) return;
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  var progress = document.getElementById('os-progress');
  var fill = document.getElementById('os-progfill');
  var ptext = document.getElementById('os-progtext');
  var pcount = document.getElementById('os-progcount');
  var live = document.getElementById('os-live');
  var list = document.getElementById('os-findlist');
  var livecount = document.getElementById('os-livecount');
  var viewBtn = document.getElementById('os-viewresults');
  var foundTotal = 0;

  function post(body) {
    body.csrf = csrf;
    return fetch('/osint/scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams(body)
    }).then(function (r) { return r.json(); });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function addFindings(arr) {
    arr.forEach(function (f) {
      foundTotal++;
      var li = document.createElement('li');
      li.className = 'os-live-row';
      var av = (f.avatar && /^https?:/.test(f.avatar))
        ? '<img class="os-live-av" src="' + esc(f.avatar) + '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">'
        : '';
      var body = f.category === 'breach'
        ? esc(f.title)
        : '<a href="' + esc(f.url) + '" target="_blank" rel="noopener nofollow">' + esc(f.title) + ' ↗</a>';
      li.innerHTML = av + '<span>' + body + '</span>';
      list.appendChild(li);
    });
    livecount.textContent = foundTotal;
  }
  function setProgress(done, total) {
    fill.style.width = (total ? Math.round(done / total * 100) : 0) + '%';
    pcount.textContent = done + ' / ' + total;
  }

  runBtn.addEventListener('click', function () {
    runBtn.disabled = true; runBtn.textContent = 'Scanning…';
    progress.hidden = false; live.hidden = false; ptext.textContent = 'Starting…';
    foundTotal = 0; list.innerHTML = ''; livecount.textContent = '0';
    var deep = (document.getElementById('os-deep') || {}).checked ? '1' : '0';
    var probe = (document.getElementById('os-probe') || {}).checked ? '1' : '0';
    post({ action: 'start', deep: deep, probe: probe }).then(function (r) {
      if (!r.ok) { ptext.textContent = r.error || 'Could not start.'; return reset(); }
      ptext.textContent = 'Scanning…';
      loop(r.id, r.total, 0);
    }).catch(function () { ptext.textContent = 'Network error.'; reset(); });
  });

  function loop(scanId, total, retries) {
    post({ action: 'chunk', scan_id: scanId }).then(function (r) {
      if (!r.ok) { ptext.textContent = r.error || 'Scan error.'; return finish(scanId); }
      setProgress(r.done, r.total);
      if (r.new && r.new.length) addFindings(r.new);
      if (r.status === 'done') { doneMsg(r); return; }
      loop(scanId, r.total, 0);
    }).catch(function () {
      if (retries < 3) { setTimeout(function () { loop(scanId, total, retries + 1); }, 1200); }
      else { ptext.textContent = 'Network dropped — partial results were saved.'; finish(scanId); }
    });
  }
  function doneMsg(r) {
    ptext.textContent = 'Done — ' + r.found + ' found, ' + r.unreachable + " couldn't be checked.";
    fill.style.width = '100%';
    finish(r.scan_id);
  }
  function reset() { runBtn.disabled = false; runBtn.textContent = 'Start scan'; }
  function finish(scanId) {
    runBtn.disabled = false; runBtn.textContent = 'Scan again';
    if (viewBtn) { viewBtn.hidden = false; viewBtn.href = '/osint/results.php?scan=' + scanId; }
  }
})();
