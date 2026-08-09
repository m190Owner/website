// Homepage "Latest Videos" strip — shows the newest few uploads from the YouTube
// channel via youtube_latest.php and links through to the full /youtube/ page.
// The section starts hidden and is only revealed if the feed actually loads, so a
// YouTube/network hiccup leaves no empty block on the page. Mirrors how ui.js
// renders the live GitHub projects grid. Feed fields are untrusted → escaped.
(function () {
  'use strict';
  var STRIP_COUNT = 4;
  var section = document.getElementById('youtube');
  var strip   = document.getElementById('youtube-strip');
  if (!section || !strip) return;

  function escHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function fmtViews(n) {
    n = +n || 0;
    if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(n >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
    return '' + n;
  }

  function ago(iso) {
    var then = Date.parse(iso);
    if (!then) return '';
    var s = Math.max(1, Math.floor((Date.now() - then) / 1000));
    var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (var i = 0; i < units.length; i++) {
      var n = Math.floor(s / units[i][1]);
      if (n >= 1) return n + ' ' + units[i][0] + (n > 1 ? 's' : '') + ' ago';
    }
    return 'just now';
  }

  function card(v) {
    var meta = [];
    if (+v.views > 0) meta.push(fmtViews(v.views) + ' views');
    var when = ago(v.published);
    if (when) meta.push(when);
    return '<a class="youtube-card" href="' + escHtml(v.url) + '" target="_blank" rel="noopener">'
      + '<div class="youtube-thumb"><img loading="lazy" src="' + escHtml(v.thumb) + '" alt="">'
      + '<span class="youtube-play" aria-hidden="true"></span></div>'
      + '<div class="youtube-title">' + escHtml(v.title) + '</div>'
      + '<div class="youtube-meta">' + escHtml(meta.join(' · ')) + '</div></a>';
  }

  fetch('/youtube_latest.php', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data || !data.ok || !Array.isArray(data.videos) || !data.videos.length) return;
      strip.innerHTML = data.videos.slice(0, STRIP_COUNT).map(card).join('');
      section.hidden = false;
    })
    .catch(function () { /* leave the section hidden */ });
})();
