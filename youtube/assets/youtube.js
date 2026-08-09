// Renders the /youtube/ grid from youtube_latest.php (the RSS mirror endpoint).
// Every field from the feed is untrusted, so titles/urls are escaped before they
// touch the DOM. Cards open the video on YouTube in a new tab.
(function () {
  'use strict';
  var grid = document.getElementById('yt-grid');
  if (!grid) return;

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
    return '<a class="yt-card" href="' + escHtml(v.url) + '" target="_blank" rel="noopener">'
      + '<div class="yt-thumb"><img loading="lazy" src="' + escHtml(v.thumb) + '" alt="">'
      + '<span class="yt-play" aria-hidden="true"></span></div>'
      + '<div class="yt-title">' + escHtml(v.title) + '</div>'
      + '<div class="yt-meta">' + escHtml(meta.join(' · ')) + '</div></a>';
  }

  function state(html) { grid.innerHTML = '<div class="yt-state">' + html + '</div>'; }

  fetch('/youtube_latest.php', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data || !data.ok || !Array.isArray(data.videos) || !data.videos.length) {
        state('Couldn’t load videos right now — <a href="https://www.youtube.com/@LoganSandivar" target="_blank" rel="noopener">visit the channel on YouTube</a>.');
        return;
      }
      if (data.channelUrl) {
        var link = document.getElementById('yt-channel-link');
        if (link) link.href = data.channelUrl;
      }
      grid.innerHTML = data.videos.map(card).join('');
    })
    .catch(function () {
      state('Couldn’t load videos right now — <a href="https://www.youtube.com/@LoganSandivar" target="_blank" rel="noopener">visit the channel on YouTube</a>.');
    });
})();
