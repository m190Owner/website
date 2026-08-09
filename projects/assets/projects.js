// Renders the /projects/ grid from github_latest.php (the same live public-repo
// feed the homepage announcement banner uses). Repo fields are escaped before
// they touch the DOM. Cards open the repo on GitHub in a new tab.
(function () {
  'use strict';
  var grid = document.getElementById('pj-grid');
  if (!grid) return;

  function escHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function card(r) {
    var desc  = '<div class="pj-desc">' + (r.description ? escHtml(r.description) : '') + '</div>';
    var lang  = r.language ? '<span class="pj-lang"><span class="pj-lang-dot"></span>' + escHtml(r.language) + '</span>' : '';
    var stars = (+r.stars > 0) ? '<span class="pj-stars">★ ' + (+r.stars) + '</span>' : '';
    var footer = (lang || stars) ? '<div class="pj-footer">' + lang + stars + '</div>' : '';
    return '<a class="pj-card" href="' + escHtml(r.url) + '" target="_blank" rel="noopener">'
      + '<div class="pj-name">' + escHtml(r.name) + '</div>' + desc + footer + '</a>';
  }

  function state(html) { grid.innerHTML = '<div class="pj-state">' + html + '</div>'; }

  fetch('/github_latest.php', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var repos = data && data.repos;
      if (!data || !data.ok || !Array.isArray(repos) || !repos.length) {
        state('Couldn’t load projects right now — <a href="https://github.com/m190Owner" target="_blank" rel="noopener">view them on GitHub</a>.');
        return;
      }
      grid.innerHTML = repos.map(card).join('');
    })
    .catch(function () {
      state('Couldn’t load projects right now — <a href="https://github.com/m190Owner" target="_blank" rel="noopener">view them on GitHub</a>.');
    });
})();
