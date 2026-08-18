// Canaries: mint decoy tokens, trip/delete them, and trace a pasted leak back to its
// source. Talks to canary.php. Deployment suggestions are built from the user's own
// email/username (read off the panel).
(function () {
  var csrf = (document.querySelector('meta[name=osint-csrf]') || {}).content || '';
  var panel = document.querySelector('[data-email][data-user]');
  var email = panel ? panel.getAttribute('data-email') : '';
  var user = panel ? panel.getAttribute('data-user') : '';
  var list = document.getElementById('os-cn-list');
  if (!list) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function post(body) {
    body.csrf = csrf;
    return fetch('/osint/canary.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams(body) }).then(function (r) { return r.json(); });
  }
  function deploy(token) {
    var bits = [];
    if (email && email.indexOf('@') >= 0) { var p = email.split('@'); bits.push('Email <span class="os-code">' + esc(p[0] + '+' + token + '@' + p[1]) + '</span>'); }
    bits.push('Name/field <span class="os-code">' + esc(token.charAt(0).toUpperCase() + token.slice(1)) + '</span>');
    bits.push('Username <span class="os-code">' + esc((user ? user + '.' : '') + token) + '</span>');
    return bits.join(' · ');
  }
  function count() {
    var n = list.querySelectorAll('[data-canary]').length;
    var c = document.getElementById('os-cn-count'); if (c) c.textContent = '(' + n + ')';
    var e = document.getElementById('os-cn-empty'); if (e) e.style.display = n ? 'none' : '';
  }
  function wireRow(row) {
    row.querySelector('.os-cn-trip').addEventListener('click', function () {
      var btn = this, tripping = btn.getAttribute('data-op') === 'trip';
      post({ action: 'update', id: row.getAttribute('data-canary'), op: tripping ? 'trip' : 'untrip' }).then(function () {
        row.classList.toggle('os-cn-tripped', tripping);
        btn.setAttribute('data-op', tripping ? 'untrip' : 'trip'); btn.textContent = tripping ? 'Clear' : 'Mark leaked';
        var t = row.querySelector('.os-row-t'); var tag = t.querySelector('.os-cn-badge');
        if (tripping && !tag) { var s = document.createElement('span'); s.className = 'os-tag os-tag-hi os-cn-badge'; s.textContent = 'tripped'; t.appendChild(document.createTextNode(' ')); t.appendChild(s); }
        else if (!tripping && tag) tag.remove();
      });
    });
    row.querySelector('.os-cn-del').addEventListener('click', function () {
      post({ action: 'update', id: row.getAttribute('data-canary'), op: 'delete' }).then(function () { row.remove(); count(); });
    });
  }
  list.querySelectorAll('[data-canary]').forEach(wireRow);

  var createBtn = document.getElementById('os-cn-create');
  if (createBtn) createBtn.addEventListener('click', function () {
    var label = document.getElementById('os-cn-label').value, note = document.getElementById('os-cn-note').value;
    createBtn.disabled = true;
    post({ action: 'create', label: label, note: note }).then(function (d) {
      createBtn.disabled = false;
      if (!d.ok) { alert(d.error || 'Could not create.'); return; }
      var tok = d.canary.token;
      var row = document.createElement('div');
      row.className = 'os-row'; row.setAttribute('data-canary', d.canary.id);
      row.innerHTML = '<div class="os-row-main"><div class="os-row-t"><span class="os-code">' + esc(tok) + '</span> ' + (label ? '<b>' + esc(label) + '</b>' : '<span class="os-dim">(unlabeled)</span>') + '</div>'
        + '<div class="os-row-d os-cn-deploy">' + deploy(tok) + '</div>'
        + '<div class="os-row-d os-dim">Minted just now' + (note ? ' · ' + esc(note) : '') + '</div></div>'
        + '<div class="os-row-side" style="display:flex;flex-direction:column;gap:6px"><button type="button" class="os-pendbtn os-cn-trip" data-op="trip">Mark leaked</button><button type="button" class="os-pendbtn os-cn-del">Delete</button></div>';
      var empty = document.getElementById('os-cn-empty'); if (empty) empty.style.display = 'none';
      list.insertBefore(row, list.firstChild); wireRow(row); count();
      document.getElementById('os-cn-label').value = ''; document.getElementById('os-cn-note').value = '';
    }).catch(function () { createBtn.disabled = false; });
  });

  var traceBtn = document.getElementById('os-cn-trace');
  if (traceBtn) traceBtn.addEventListener('click', function () {
    var q = document.getElementById('os-cn-match').value, out = document.getElementById('os-cn-traceout');
    if (!q.trim()) { out.innerHTML = ''; return; }
    post({ action: 'match', q: q }).then(function (d) {
      if (!d.hits || !d.hits.length) { out.innerHTML = '<p class="os-dim">No canary of yours appears in that text. Either it wasn\'t one of your decoys, or the leaker stripped the tag.</p>'; return; }
      out.innerHTML = '<div class="os-warn-box" style="margin-top:0"><b>Leak traced.</b> This text contains ' + d.hits.length + ' of your canaries:</div><div class="os-list" style="margin-top:8px">'
        + d.hits.map(function (h) { return '<div class="os-row"><div class="os-row-main"><div class="os-row-t"><span class="os-code">' + esc(h.token) + '</span> → <b>' + esc(h.label || '(unlabeled)') + '</b></div><div class="os-row-d os-dim">Seeded ' + new Date(h.created_at * 1000).toISOString().slice(0, 10) + ' — that\'s who leaked or sold it.</div></div></div>'; }).join('') + '</div>';
    });
  });
})();
