// Spot-the-phish training quiz. Reads generated items from #os-quiz-data, lets the user
// judge each message, reveals the tells, and scores them. Pure client-side.
(function () {
  var host = document.getElementById('os-quiz-data'), mount = document.getElementById('os-quiz');
  if (!host || !mount) return;
  var data; try { data = JSON.parse(host.textContent); } catch (e) { return; }
  var items = data.items || []; if (!items.length) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }

  var answered = 0, correct = 0;
  var html = '';
  items.forEach(function (it, i) {
    html += '<div class="os-quiz-item" data-i="' + i + '">'
      + '<div class="os-phish"><div class="os-phish-hdr"><div><span class="os-phish-k">From</span> ' + esc(it.from) + '</div>'
      + '<div><span class="os-phish-k">Subject</span> <b>' + esc(it.subject) + '</b></div></div>'
      + '<pre class="os-phish-body">' + esc(it.body) + '</pre></div>'
      + '<div class="os-quiz-actions"><button type="button" class="os-btn os-btn-sm os-q-btn" data-guess="1">&#127907; Phishing</button>'
      + '<button type="button" class="os-btn os-btn-sm os-q-btn" data-guess="0">&#10003; Legit</button></div>'
      + '<div class="os-quiz-reveal" hidden></div></div>';
  });
  html += '<div class="os-quiz-score os-corr" id="os-quiz-score" hidden></div>';
  mount.innerHTML = html;

  function reveal(itemEl, i, guessPhish) {
    if (itemEl.__done) return; itemEl.__done = true;
    var it = items[i], isPhish = !!it.phish, right = (guessPhish === isPhish);
    answered++; if (right) correct++;
    itemEl.querySelectorAll('.os-q-btn').forEach(function (b) {
      b.disabled = true;
      if ((b.getAttribute('data-guess') === '1') === isPhish) b.classList.add('os-q-answer');
    });
    var box = itemEl.querySelector('.os-quiz-reveal');
    box.innerHTML = '<div class="os-corr ' + (right ? 'os-corr-low' : 'os-corr-high') + '"><div class="os-corr-h"><span class="os-corr-sev">' + (right ? 'Correct' : 'Missed') + '</span><b>' + (isPhish ? 'This one is phishing' : 'This one is legitimate') + '</b></div>'
      + '<ul class="os-rlist" style="margin-top:6px">' + (it.reasons || []).map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></div>';
    box.hidden = false;
    if (answered === items.length) {
      var s = document.getElementById('os-quiz-score');
      var pct = Math.round(correct / items.length * 100);
      s.className = 'os-quiz-score os-corr ' + (pct >= 80 ? 'os-corr-low' : (pct >= 50 ? 'os-corr-med' : 'os-corr-high'));
      s.innerHTML = '<div class="os-corr-h"><span class="os-corr-sev">' + correct + '/' + items.length + '</span><b>You caught ' + correct + ' of ' + items.length + ' correctly (' + pct + '%).</b></div>'
        + '<p class="os-corr-d">' + (pct >= 80 ? 'Sharp eye. The real defence: when a message pressures you to act, stop and reach the service directly instead of using its links.' : 'A targeted phish using your real data is convincing — never act on links/urgency in a message; open the service yourself and check.') + '</p>';
      s.hidden = false;
      s.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  mount.querySelectorAll('.os-quiz-item').forEach(function (itemEl) {
    var i = +itemEl.getAttribute('data-i');
    itemEl.querySelectorAll('.os-q-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { reveal(itemEl, i, btn.getAttribute('data-guess') === '1'); });
    });
  });
})();
