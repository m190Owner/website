// Pattern-of-life mapper: read GPS + timestamp EXIF from MANY photos (via window.__osExif
// from metadata.js), cluster the locations, infer likely home/work, and chart the times.
// 100% client-side — the photos are read with FileReader and never leave the browser.
(function () {
  var input = document.getElementById('os-pf');
  var drop = document.getElementById('os-pdrop');
  var out = document.getElementById('os-pout');
  if (!input || typeof window.__osExif !== 'function') return;
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }

  function readExif(file) {
    return new Promise(function (resolve) {
      var fr = new FileReader();
      fr.onload = function () { try { resolve(window.__osExif(fr.result)); } catch (e) { resolve(null); } };
      fr.onerror = function () { resolve(null); };
      fr.readAsArrayBuffer(file);
    });
  }
  // EXIF datetime "YYYY:MM:DD HH:MM:SS" → Date (local, as recorded).
  function exifDate(s) {
    if (!s) return null;
    var m = String(s).match(/(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : null;
  }
  function haversine(a, b) {
    var R = 6371000, toR = Math.PI / 180;
    var dLat = (b.lat - a.lat) * toR, dLng = (b.lng - a.lng) * toR;
    var s = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * toR) * Math.cos(b.lat * toR) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)));
  }

  function analyze(points) {
    // Greedy proximity clustering (~180 m).
    var clusters = [];
    points.forEach(function (p) {
      var hit = null;
      for (var i = 0; i < clusters.length; i++) if (haversine(p, clusters[i].center) < 180) { hit = clusters[i]; break; }
      if (!hit) { hit = { pts: [], center: { lat: p.lat, lng: p.lng } }; clusters.push(hit); }
      hit.pts.push(p);
      // running centroid
      var n = hit.pts.length;
      hit.center.lat += (p.lat - hit.center.lat) / n;
      hit.center.lng += (p.lng - hit.center.lng) / n;
    });
    clusters.forEach(function (c) {
      c.count = c.pts.length;
      c.night = c.pts.filter(function (p) { return p.date && (p.date.getHours() >= 22 || p.date.getHours() < 6); }).length;
      c.workday = c.pts.filter(function (p) { return p.date && p.date.getDay() >= 1 && p.date.getDay() <= 5 && p.date.getHours() >= 9 && p.date.getHours() < 18; }).length;
      var ds = c.pts.filter(function (p) { return p.date; }).map(function (p) { return p.date.getTime(); }).sort();
      c.first = ds.length ? new Date(ds[0]) : null;
      c.last = ds.length ? new Date(ds[ds.length - 1]) : null;
    });
    clusters.sort(function (a, b) { return b.count - a.count; });
    // Infer home = most night-time photos (fallback: biggest cluster); work = most weekday-daytime, not home.
    var home = clusters.slice().sort(function (a, b) { return (b.night - a.night) || (b.count - a.count); })[0];
    if (home && !home.night) home = clusters[0];
    var work = clusters.filter(function (c) { return c !== home; }).sort(function (a, b) { return b.workday - a.workday; })[0];
    if (work && !work.workday) work = null;
    return { clusters: clusters, home: home, work: work };
  }

  function scatter(clusters) {
    var all = [];
    clusters.forEach(function (c, i) { c.pts.forEach(function (p) { all.push({ lat: p.lat, lng: p.lng, c: i }); }); });
    var lats = all.map(function (p) { return p.lat; }), lngs = all.map(function (p) { return p.lng; });
    var minLat = Math.min.apply(null, lats), maxLat = Math.max.apply(null, lats);
    var minLng = Math.min.apply(null, lngs), maxLng = Math.max.apply(null, lngs);
    var W = 640, H = 300, pad = 18;
    var spanLat = (maxLat - minLat) || 1e-4, spanLng = (maxLng - minLng) || 1e-4;
    var palette = ['#37c98b', '#6ea8fe', '#e0a83a', '#c59bf5', '#e5695f', '#5fe0a6', '#8b98a6'];
    var x = function (lng) { return pad + (lng - minLng) / spanLng * (W - 2 * pad); };
    var y = function (lat) { return pad + (maxLat - lat) / spanLat * (H - 2 * pad); };   // north up
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" class="os-pmsvg" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Photo locations">';
    svg += '<text x="' + (W / 2) + '" y="12" class="os-pm-n">N ↑</text>';
    all.forEach(function (p) { svg += '<circle cx="' + x(p.lng).toFixed(1) + '" cy="' + y(p.lat).toFixed(1) + '" r="5" fill="' + palette[p.c % palette.length] + '" opacity="0.8"/>'; });
    return svg + '</svg>';
  }

  function fmtDate(d) { return d ? d.toISOString().slice(0, 10) : ''; }
  function osm(c) { var la = c.center.lat.toFixed(6), lo = c.center.lng.toFixed(6); return 'https://www.openstreetmap.org/?mlat=' + la + '&mlon=' + lo + '#map=15/' + la + '/' + lo; }

  function render(result, total, withGps, withTime) {
    var A = analyze(result);
    var clusters = A.clusters, palette = ['#37c98b', '#6ea8fe', '#e0a83a', '#c59bf5', '#e5695f', '#5fe0a6', '#8b98a6'];
    var html = '<div class="os-warn-box"><b>' + withGps + ' of ' + total + ' photo(s) exposed a GPS location</b>, resolving to <b>' + clusters.length + '</b> distinct place(s). This is the pattern someone can build from photos you post publicly.</div>';

    // Inferred pattern-of-life
    var bits = [];
    if (A.home) bits.push('<li><b>Likely home</b> — ' + A.home.count + ' photo(s)' + (A.home.night ? ', ' + A.home.night + ' at night' : '') + ' near <a href="' + osm(A.home) + '" target="_blank" rel="noopener nofollow">' + A.home.center.lat.toFixed(4) + ', ' + A.home.center.lng.toFixed(4) + '</a></li>');
    if (A.work) bits.push('<li><b>Likely workplace / weekday spot</b> — ' + A.work.workday + ' weekday-daytime photo(s) near <a href="' + osm(A.work) + '" target="_blank" rel="noopener nofollow">' + A.work.center.lat.toFixed(4) + ', ' + A.work.center.lng.toFixed(4) + '</a></li>');
    if (bits.length) html += '<div class="os-subhead">What it reveals</div><ul class="os-rlist">' + bits.join('') + '</ul>';

    // Map scatter
    if (withGps > 0) html += '<div class="os-subhead">Where <span class="os-dim">(relative positions, north up — click a place below for the real map)</span></div><div class="os-tlwrap">' + scatter(clusters) + '</div>';

    // Cluster list
    html += '<div class="os-subhead">Places <span class="os-dim">(' + clusters.length + ')</span></div><div class="os-list">';
    clusters.forEach(function (c, i) {
      var when = (c.first ? fmtDate(c.first) + (c.last && fmtDate(c.last) !== fmtDate(c.first) ? ' → ' + fmtDate(c.last) : '') : 'no timestamps');
      var tags = '';
      if (c === A.home) tags += '<span class="os-tag os-tag-hi">home?</span>';
      if (c === A.work) tags += '<span class="os-tag os-tag-hi">work?</span>';
      html += '<div class="os-row"><div class="os-row-main"><div class="os-row-t"><span class="os-pm-dot" style="background:' + palette[i % palette.length] + '"></span> '
        + '<a href="' + osm(c) + '" target="_blank" rel="noopener nofollow">' + c.center.lat.toFixed(5) + ', ' + c.center.lng.toFixed(5) + '</a> ' + tags + '</div>'
        + '<div class="os-row-d">' + c.count + ' photo(s) · ' + esc(when) + (c.night ? ' · ' + c.night + ' at night' : '') + '</div></div></div>';
    });
    html += '</div>';

    // Time-of-day histogram
    if (withTime > 0) {
      var buckets = [0, 0, 0, 0, 0, 0];   // 4-hour buckets
      result.forEach(function (p) { if (p.date) buckets[Math.floor(p.date.getHours() / 4)]++; });
      var mx = Math.max.apply(null, buckets) || 1;
      var labels = ['0–4', '4–8', '8–12', '12–16', '16–20', '20–24'];
      html += '<div class="os-subhead">When <span class="os-dim">(photos by time of day)</span></div><div class="os-pmhist">';
      buckets.forEach(function (v, i) { html += '<div class="os-pmbar"><div class="os-pmbar-fill" style="height:' + Math.round(v / mx * 100) + '%"></div><span class="os-pmbar-n">' + v + '</span><span class="os-pmbar-l">' + labels[i] + '</span></div>'; });
      html += '</div>';
    }

    html += '<p class="os-fineprint">Defence: strip GPS before posting (use the cleaner above), turn off location tags in your camera app, and avoid posting photos taken at home in real time.</p>';
    out.innerHTML = html; out.hidden = false;
  }

  function handle(files) {
    files = Array.prototype.slice.call(files).filter(function (f) { return /^image\//.test(f.type); });
    if (!files.length) return;
    out.hidden = false; out.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Reading ' + files.length + ' photo(s) in your browser…</p>';
    Promise.all(files.map(readExif)).then(function (metas) {
      var points = [], withGps = 0, withTime = 0;
      metas.forEach(function (m) {
        if (!m) return;
        var d = exifDate(m.taken || m.datetime || m.gpsdate);
        if (typeof m.lat === 'number' && typeof m.lng === 'number') { points.push({ lat: m.lat, lng: m.lng, date: d }); withGps++; if (d) withTime++; }
      });
      if (!withGps) { out.innerHTML = '<div class="os-warn-box" style="border-color:var(--os-border);background:transparent;color:var(--os-dim)">None of the ' + files.length + ' photo(s) carried a GPS location — either already stripped, or the camera didn\'t tag them. That\'s the safe state.</div>'; return; }
      render(points, files.length, withGps, withTime);
    });
  }

  input.addEventListener('change', function () { if (input.files.length) handle(input.files); });
  if (drop) {
    drop.addEventListener('click', function () { input.click(); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) handle(e.dataTransfer.files); });
  }
  window.__osPatternOfLife = function (pts) { return analyze(pts); };   // exposed for verification
  window.__osPatternRender = render;                                    // exposed for verification
})();
