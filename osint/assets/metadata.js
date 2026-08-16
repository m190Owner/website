// Photo metadata (EXIF) viewer + stripper. Everything runs IN THE BROWSER — the image
// is read with FileReader, parsed locally, and the "cleaned" copy is re-encoded via a
// canvas (which drops all metadata). Nothing is ever uploaded to any server.
(function () {
  var input = document.getElementById('os-mf');
  var drop = document.getElementById('os-drop');
  var out = document.getElementById('os-mout');
  if (!input) return;

  var SIZES = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8 };

  function readVal(view, off, type, count, little) {
    var i, a = [];
    if (type === 2) { var s = ''; for (i = 0; i < count; i++) { var c = view.getUint8(off + i); if (c === 0) break; s += String.fromCharCode(c); } return s.trim(); }
    if (type === 3) { for (i = 0; i < count; i++) a.push(view.getUint16(off + i * 2, little)); return count === 1 ? a[0] : a; }
    if (type === 4) { for (i = 0; i < count; i++) a.push(view.getUint32(off + i * 4, little)); return count === 1 ? a[0] : a; }
    if (type === 5) { for (i = 0; i < count; i++) { var n = view.getUint32(off + i * 8, little), d = view.getUint32(off + i * 8 + 4, little); a.push(d ? n / d : 0); } return count === 1 ? a[0] : a; }
    return null;
  }
  function readTags(view, tiff, ifdOff, little) {
    var tags = {};
    try {
      var n = view.getUint16(tiff + ifdOff, little);
      for (var i = 0; i < n; i++) {
        var e = tiff + ifdOff + 2 + i * 12;
        var tag = view.getUint16(e, little), type = view.getUint16(e + 2, little), count = view.getUint32(e + 4, little);
        var sz = (SIZES[type] || 1) * count;
        var vOff = sz <= 4 ? e + 8 : tiff + view.getUint32(e + 8, little);
        tags[tag] = readVal(view, vOff, type, count, little);
      }
    } catch (err) {}
    return tags;
  }
  function dms(v, ref) {
    if (!Array.isArray(v) || v.length < 3) return null;
    var d = v[0] + v[1] / 60 + v[2] / 3600;
    if (ref === 'S' || ref === 'W') d = -d;
    return d;
  }
  function parseExif(buf) {
    var view = new DataView(buf);
    if (view.byteLength < 4 || view.getUint16(0) !== 0xFFD8) return { jpeg: false };
    var off = 2, len = view.byteLength;
    while (off + 4 < len) {
      var marker = view.getUint16(off);
      if ((marker & 0xFF00) !== 0xFF00) break;
      var segLen = view.getUint16(off + 2);
      if (marker === 0xFFE1 && view.getUint32(off + 4) === 0x45786966) {   // "Exif"
        var tiff = off + 10;
        var little = view.getUint16(tiff) === 0x4949;
        var t0 = readTags(view, tiff, view.getUint32(tiff + 4, little), little);
        var res = { jpeg: true, make: t0[0x010F], model: t0[0x0110], software: t0[0x0131], datetime: t0[0x0132], orientation: t0[0x0112] };
        if (t0[0x8769]) { var ex = readTags(view, tiff, t0[0x8769], little); res.taken = ex[0x9003]; res.lens = ex[0xA434]; res.iso = ex[0x8827]; }
        if (t0[0x8825]) { var g = readTags(view, tiff, t0[0x8825], little); if (g[2] && g[4]) { res.lat = dms(g[2], g[1]); res.lng = dms(g[4], g[3]); res.alt = g[6]; res.gpsdate = g[0x1D]; } }
        return res;
      }
      if (marker === 0xFFDA) break;   // start of scan — no more metadata
      off += 2 + segLen;
    }
    return { jpeg: true };
  }
  window.__osExif = parseExif;   // exposed for verification

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function render(meta, file, img) {
    var html = '';
    var hasGps = typeof meta.lat === 'number' && typeof meta.lng === 'number';
    if (hasGps) {
      var lat = meta.lat.toFixed(6), lng = meta.lng.toFixed(6);
      html += '<div class="os-warn-box"><b>⚠ This photo contains your GPS location.</b> Anyone you send it to can see exactly where it was taken: '
        + '<b>' + lat + ', ' + lng + '</b>' + (meta.alt ? ' (~' + Math.round(meta.alt) + ' m)' : '')
        + ' — <a href="https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lng + '#map=16/' + lat + '/' + lng + '" target="_blank" rel="noopener nofollow">view on map</a>. Strip it before sharing.</div>';
    }
    var rows = [
      ['Location (GPS)', hasGps ? meta.lat.toFixed(6) + ', ' + meta.lng.toFixed(6) : null],
      ['Date taken', meta.taken || meta.datetime || (meta.gpsdate || null)],
      ['Camera', [meta.make, meta.model].filter(Boolean).join(' ') || null],
      ['Lens', meta.lens || null],
      ['Software', meta.software || null],
      ['ISO', meta.iso || null],
      ['Dimensions', img.naturalWidth ? img.naturalWidth + ' × ' + img.naturalHeight + ' px' : null],
      ['File', file.name + ' · ' + Math.round(file.size / 1024) + ' KB · ' + (file.type || 'unknown')],
    ].filter(function (r) { return r[1]; });

    html += '<dl class="os-kv" style="margin-top:14px">';
    rows.forEach(function (r) { html += '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; });
    html += '</dl>';

    if (!meta.jpeg) html += '<p class="os-dim" style="margin-top:10px">EXIF parsing here covers JPEG. This file isn\'t a JPEG — it may carry other metadata, but the cleaner below still removes everything by re-encoding.</p>';
    else if (!hasGps && !meta.make && !meta.taken) html += '<p class="os-dim" style="margin-top:10px">No camera/GPS EXIF found — either it was already stripped, or this image never had it. Good sign.</p>';

    html += '<div class="os-inrow" style="margin-top:14px"><button type="button" class="os-btn os-btn-accent" id="os-strip">Download cleaned copy (no metadata)</button></div>';
    html += '<div class="os-mprev"><img src="' + img.src + '" alt="preview"></div>';
    out.innerHTML = html;
    out.hidden = false;

    document.getElementById('os-strip').addEventListener('click', function () {
      var canvas = document.createElement('canvas');
      canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
      canvas.getContext('2d').drawImage(img, 0, 0);
      var png = file.type === 'image/png';
      canvas.toBlob(function (blob) {
        if (!blob) return;
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'cleaned-' + (file.name.replace(/\.[^.]+$/, '') || 'image') + (png ? '.png' : '.jpg');
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
      }, png ? 'image/png' : 'image/jpeg', 0.92);
    });
  }

  function handleFile(file) {
    if (!file || !/^image\//.test(file.type)) { out.innerHTML = '<p class="os-dim">Please choose an image file.</p>'; out.hidden = false; return; }
    var img = new Image();
    var url = URL.createObjectURL(file);
    img.onload = function () {
      var fr = new FileReader();
      fr.onload = function () { try { render(parseExif(fr.result), file, img); } catch (e) { out.innerHTML = '<p class="os-dim">Could not read this image.</p>'; out.hidden = false; } };
      fr.readAsArrayBuffer(file);
    };
    img.src = url;
  }

  input.addEventListener('change', function () { if (input.files[0]) handleFile(input.files[0]); });
  if (drop) {
    drop.addEventListener('click', function () { input.click(); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]); });
  }
})();
