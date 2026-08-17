// Reverse-image search: turn a pasted image URL into ready-made lookups. Client-side.
(function () {
  var form = document.getElementById('os-rev');
  if (!form) return;
  var inp = document.getElementById('os-revurl');
  var out = document.getElementById('os-revout');

  function build() {
    var u = inp.value.trim();
    if (!/^https?:\/\//i.test(u)) { out.innerHTML = '<span class="os-dim" style="font-size:.82rem">Paste a direct image URL starting with http.</span>'; return; }
    var e = encodeURIComponent(u);
    var links = [
      ['Google Lens', 'https://lens.google.com/uploadbyurl?url=' + e, '🔍'],
      ['Yandex Images', 'https://yandex.com/images/search?rpt=imageview&url=' + e, 'Я'],
      ['TinEye', 'https://tineye.com/search?url=' + e, '👁️'],
      ['Bing Visual', 'https://www.bing.com/images/search?view=detailv2&iss=sbi&q=imgurl:' + e, 'Ⓑ']
    ];
    out.innerHTML = links.map(function (l) {
      return '<a href="' + l[1] + '" target="_blank" rel="noopener nofollow"><span class="os-srch-ic">' + l[2] + '</span>' + l[0] + '</a>';
    }).join('');
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); build(); });
  inp.addEventListener('input', function () { if (inp.value.trim()) build(); });
})();

// AI exposure: build "ask an assistant about you" links from the (browser-only) name +
// saved usernames. Shares the removal-center name via localStorage.
(function () {
  var panel = document.querySelector('[data-usernames]');
  var nameEl = document.getElementById('os-ai-name');
  var linksEl = document.getElementById('os-ai-links');
  if (!panel || !nameEl || !linksEl) return;
  var usernames = [];
  try { usernames = JSON.parse(panel.getAttribute('data-usernames')) || []; } catch (e) {}
  nameEl.value = localStorage.getItem('os_vf_name') || '';

  function prompt(subject) {
    return 'What publicly available information can you find about ' + subject + '? '
      + 'List any social-media profiles, location, employer, and notable mentions, with sources.';
  }
  function esc(s) { return String(s).replace(/[<>&"]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]; }); }
  function subjectPrompt() {
    var name = (nameEl.value || '').trim();
    return name ? prompt('the person named "' + name + '"') : (usernames[0] ? prompt('the online username "' + usernames[0] + '"') : prompt('me'));
  }

  function render() {
    var name = (nameEl.value || '').trim();
    var subjects = [];
    if (name) subjects.push({ label: '', q: prompt('the person named "' + name + '"') });
    usernames.slice(0, 2).forEach(function (u) { subjects.push({ label: ' (@' + u + ')', q: prompt('the online username "' + u + '"') }); });
    if (!subjects.length) { linksEl.innerHTML = '<span class="os-dim" style="font-size:.82rem">Add your name above (or a username to your profile) to build the queries.</span>'; return; }
    var engines = [['Perplexity', 'https://www.perplexity.ai/search?q=', '🔮'], ['ChatGPT', 'https://chatgpt.com/?q=', '💬']];
    var html = '';
    subjects.forEach(function (s) {
      engines.forEach(function (e) {
        html += '<a href="' + e[1] + encodeURIComponent(s.q) + '" target="_blank" rel="noopener nofollow"><span class="os-srch-ic">' + e[2] + '</span>' + e[0] + esc(s.label) + '</a>';
      });
    });
    linksEl.innerHTML = html;
  }

  nameEl.addEventListener('input', function () { localStorage.setItem('os_vf_name', nameEl.value); render(); });
  var copyBtn = document.getElementById('os-ai-copy');
  var copied = document.getElementById('os-ai-copied');
  if (copyBtn) copyBtn.addEventListener('click', function () {
    var text = subjectPrompt();
    var done = function () { if (copied) { copied.textContent = 'Copied — paste it into Gemini, Claude, or Copilot.'; setTimeout(function () { copied.textContent = ''; }, 4000); } };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, done); else done();
  });
  render();
})();
