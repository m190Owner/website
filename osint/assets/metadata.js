// File metadata viewer. Everything runs IN THE BROWSER — nothing is uploaded.
//  - Images: parse EXIF (GPS/camera/timestamp) and offer a re-encoded, cleaned copy.
//  - PDF: read the /Info dictionary (author, producer, dates).
//  - Office (docx/xlsx/pptx): unzip docProps/core.xml via the built-in DecompressionStream
//    and read the author / last-modified-by / dates.
(function () {
  var input = document.getElementById('os-mf');
  var drop = document.getElementById('os-drop');
  var out = document.getElementById('os-mout');
  if (!input) return;

  function esc(s) { return String(s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }
  function bytesToStr(u8) { var CH = 0x8000, s = ''; for (var i = 0; i < u8.length; i += CH) s += String.fromCharCode.apply(null, u8.subarray(i, i + CH)); return s; }

  // ---------- images: EXIF ----------
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
        var sz = (SIZES[type] || 1) * count, vOff = sz <= 4 ? e + 8 : tiff + view.getUint32(e + 8, little);
        tags[tag] = readVal(view, vOff, type, count, little);
      }
    } catch (err) {}
    return tags;
  }
  function dms(v, ref) { if (!Array.isArray(v) || v.length < 3) return null; var d = v[0] + v[1] / 60 + v[2] / 3600; if (ref === 'S' || ref === 'W') d = -d; return d; }
  function parseExif(buf) {
    var view = new DataView(buf);
    if (view.byteLength < 4 || view.getUint16(0) !== 0xFFD8) return { jpeg: false };
    var off = 2, len = view.byteLength;
    while (off + 4 < len) {
      var marker = view.getUint16(off);
      if ((marker & 0xFF00) !== 0xFF00) break;
      var segLen = view.getUint16(off + 2);
      if (marker === 0xFFE1 && view.getUint32(off + 4) === 0x45786966) {
        var tiff = off + 10, little = view.getUint16(tiff) === 0x4949;
        var t0 = readTags(view, tiff, view.getUint32(tiff + 4, little), little);
        var res = { jpeg: true, make: t0[0x010F], model: t0[0x0110], software: t0[0x0131], datetime: t0[0x0132], orientation: t0[0x0112] };
        if (t0[0x8769]) { var ex = readTags(view, tiff, t0[0x8769], little); res.taken = ex[0x9003]; res.lens = ex[0xA434]; res.iso = ex[0x8827]; }
        if (t0[0x8825]) { var g = readTags(view, tiff, t0[0x8825], little); if (g[2] && g[4]) { res.lat = dms(g[2], g[1]); res.lng = dms(g[4], g[3]); res.alt = g[6]; res.gpsdate = g[0x1D]; } }
        return res;
      }
      if (marker === 0xFFDA) break;
      off += 2 + segLen;
    }
    return { jpeg: true };
  }
  window.__osExif = parseExif;

  function renderImage(meta, file, img) {
    var html = '', hasGps = typeof meta.lat === 'number' && typeof meta.lng === 'number';
    if (hasGps) {
      var lat = meta.lat.toFixed(6), lng = meta.lng.toFixed(6);
      html += '<div class="os-warn-box"><b>⚠ This photo contains your GPS location.</b> Anyone you send it to can see exactly where it was taken: <b>' + lat + ', ' + lng + '</b>'
        + (meta.alt ? ' (~' + Math.round(meta.alt) + ' m)' : '') + ' — <a href="https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lng + '#map=16/' + lat + '/' + lng + '" target="_blank" rel="noopener nofollow">view on map</a>. Strip it before sharing.</div>';
    }
    var rows = [
      ['Location (GPS)', hasGps ? meta.lat.toFixed(6) + ', ' + meta.lng.toFixed(6) : null],
      ['Date taken', meta.taken || meta.datetime || meta.gpsdate || null],
      ['Camera', [meta.make, meta.model].filter(Boolean).join(' ') || null],
      ['Lens', meta.lens || null], ['Software', meta.software || null], ['ISO', meta.iso || null],
      ['Dimensions', img.naturalWidth ? img.naturalWidth + ' × ' + img.naturalHeight + ' px' : null],
      ['File', file.name + ' · ' + Math.round(file.size / 1024) + ' KB · ' + (file.type || 'unknown')]
    ].filter(function (r) { return r[1]; });
    html += '<dl class="os-kv" style="margin-top:14px">';
    rows.forEach(function (r) { html += '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; });
    html += '</dl>';
    if (!meta.jpeg) html += '<p class="os-dim" style="margin-top:10px">EXIF parsing here covers JPEG; this image isn\'t JPEG, but the cleaner below still removes everything by re-encoding.</p>';
    else if (!hasGps && !meta.make && !meta.taken) html += '<p class="os-dim" style="margin-top:10px">No camera/GPS EXIF found — already stripped, or never had it. Good sign.</p>';
    html += '<div class="os-inrow" style="margin-top:14px"><button type="button" class="os-btn os-btn-accent" id="os-strip">Download cleaned copy (no metadata)</button></div>';
    html += '<div class="os-mprev"><img src="' + img.src + '" alt="preview"></div>';
    out.innerHTML = html; out.hidden = false;
    document.getElementById('os-strip').addEventListener('click', function () {
      var canvas = document.createElement('canvas'); canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
      canvas.getContext('2d').drawImage(img, 0, 0);
      var png = file.type === 'image/png';
      canvas.toBlob(function (blob) {
        if (!blob) return;
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = 'cleaned-' + (file.name.replace(/\.[^.]+$/, '') || 'image') + (png ? '.png' : '.jpg');
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
      }, png ? 'image/png' : 'image/jpeg', 0.92);
    });
  }

  // ---------- PDF ----------
  function pdfDecode(raw) {
    if (raw[0] === '(') {
      return raw.slice(1, -1).replace(/\\([nrtbf()\\]|[0-7]{1,3})/g, function (m, g) {
        var map = { n: '\n', r: '\r', t: '\t', b: '\b', f: '\f', '(': '(', ')': ')', '\\': '\\' };
        return map[g] !== undefined ? map[g] : String.fromCharCode(parseInt(g, 8));
      });
    }
    if (raw[0] === '<') {
      var hex = raw.slice(1, -1).replace(/\s/g, ''), b = [];
      for (var i = 0; i < hex.length; i += 2) b.push(parseInt(hex.substr(i, 2), 16));
      if (b[0] === 0xFE && b[1] === 0xFF) { var o = ''; for (var j = 2; j < b.length; j += 2) o += String.fromCharCode((b[j] << 8) | b[j + 1]); return o; }
      return b.map(function (x) { return String.fromCharCode(x); }).join('');
    }
    return raw;
  }
  function pdfDate(d) { var m = d.match(/D:(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?/); return m ? m[1] + '-' + (m[2] || '01') + '-' + (m[3] || '01') + (m[4] ? ' ' + m[4] + ':' + (m[5] || '00') : '') : d; }
  function parsePdf(u8) {
    var s = bytesToStr(u8), f = {};
    [['Author', 'Author'], ['Producer', 'Producer'], ['Creator', 'Creator'], ['Title', 'Title'], ['CreationDate', 'Created'], ['ModDate', 'Modified']].forEach(function (p) {
      var m = s.match(new RegExp('/' + p[0] + '\\s*(\\([^]*?\\)|<[0-9A-Fa-f\\s]+>)'));
      if (m) { var v = pdfDecode(m[1]); if (/Date/.test(p[0])) v = pdfDate(v); if (v.trim()) f[p[1]] = v.trim(); }
    });
    return f;
  }

  // ---------- Office (OOXML zip) ----------
  function zipFind(u8, name) {
    var dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
    var eocd = -1, lim = Math.max(0, u8.length - 22 - 65536);
    for (var i = u8.length - 22; i >= lim; i--) { if (dv.getUint32(i, true) === 0x06054b50) { eocd = i; break; } }
    if (eocd < 0) return null;
    var cd = dv.getUint32(eocd + 16, true), entries = dv.getUint16(eocd + 10, true), p = cd;
    for (var e = 0; e < entries; e++) {
      if (dv.getUint32(p, true) !== 0x02014b50) break;
      var method = dv.getUint16(p + 10, true), comp = dv.getUint32(p + 20, true),
        fnlen = dv.getUint16(p + 28, true), ex = dv.getUint16(p + 30, true), cm = dv.getUint16(p + 32, true), lho = dv.getUint32(p + 42, true);
      var fn = bytesToStr(u8.subarray(p + 46, p + 46 + fnlen));
      if (fn === name) {
        var lfn = dv.getUint16(lho + 26, true), lex = dv.getUint16(lho + 28, true), ds = lho + 30 + lfn + lex;
        return { method: method, data: u8.subarray(ds, ds + comp) };
      }
      p += 46 + fnlen + ex + cm;
    }
    return null;
  }
  async function zipRead(u8, name) {
    var f = zipFind(u8, name); if (!f) return null;
    if (f.method === 0) return f.data;
    if (f.method === 8 && 'DecompressionStream' in window) {
      var ds = new DecompressionStream('deflate-raw');
      var ab = await new Response(new Blob([f.data]).stream().pipeThrough(ds)).arrayBuffer();
      return new Uint8Array(ab);
    }
    return null;
  }
  function xmlTag(xml, tag) { var m = xml.match(new RegExp('<' + tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[^>]*>([^<]*)</' + tag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '>')); return m ? m[1].trim() : ''; }
  async function parseOoxml(u8) {
    var f = {}, core = await zipRead(u8, 'docProps/core.xml');
    if (core) {
      var xml = new TextDecoder().decode(core);
      [['dc:creator', 'Author'], ['cp:lastModifiedBy', 'Last modified by'], ['dc:title', 'Title'], ['dcterms:created', 'Created'], ['dcterms:modified', 'Modified'], ['cp:revision', 'Revision']].forEach(function (p) {
        var v = xmlTag(xml, p[0]); if (v) f[p[1]] = v.replace('T', ' ').replace('Z', '');
      });
    }
    var app = await zipRead(u8, 'docProps/app.xml');
    if (app) { var ax = new TextDecoder().decode(app); ['Application', 'Company'].forEach(function (t) { var v = xmlTag(ax, t); if (v) f[t] = v; }); }
    return f;
  }

  function renderDoc(kind, fields, file) {
    var keys = Object.keys(fields), html = '';
    var who = fields.Author || fields['Last modified by'];
    if (who) html += '<div class="os-warn-box"><b>⚠ This file names a person:</b> ' + esc(who) + '. Files shared publicly commonly carry the author\'s real name, organisation, and software.</div>';
    html += '<dl class="os-kv" style="margin-top:14px"><dt>Type</dt><dd>' + esc(kind) + '</dd>';
    keys.forEach(function (k) { html += '<dt>' + esc(k) + '</dt><dd>' + esc(fields[k]) + '</dd>'; });
    html += '<dt>File</dt><dd>' + esc(file.name) + ' · ' + Math.round(file.size / 1024) + ' KB</dd></dl>';
    if (!keys.length) html += '<p class="os-dim" style="margin-top:10px">No author/metadata found — already stripped, or this file doesn\'t carry it.</p>';
    html += '<p class="os-note" style="margin-top:12px">To remove document metadata: in Word/Excel/PowerPoint use <b>File → Info → Check for Issues → Inspect Document</b>, or export to PDF and clean that. (In-browser stripping is offered for images.)</p>';
    out.innerHTML = html; out.hidden = false;
  }

  // ---------- dispatch ----------
  function handleImage(file) {
    var img = new Image(), url = URL.createObjectURL(file);
    img.onload = function () {
      var fr = new FileReader();
      fr.onload = function () { try { renderImage(parseExif(fr.result), file, img); } catch (e) { out.innerHTML = '<p class="os-dim">Could not read this image.</p>'; out.hidden = false; } };
      fr.readAsArrayBuffer(file);
    };
    img.src = url;
  }
  function handleFile(file) {
    if (!file) return;
    var name = (file.name || '').toLowerCase();
    if (/^image\//.test(file.type)) return handleImage(file);
    var fr = new FileReader();
    fr.onload = async function () {
      var u8 = new Uint8Array(fr.result);
      try {
        var isZip = u8[0] === 0x50 && u8[1] === 0x4B, isPdf = (u8[0] === 0x25 && u8[1] === 0x50) || file.type === 'application/pdf' || /\.pdf$/.test(name);
        if (isPdf) renderDoc('PDF', parsePdf(u8), file);
        else if (isZip || /\.(docx|xlsx|pptx)$/.test(name)) renderDoc((name.match(/\.(\w+)$/) || [, 'Office'])[1].toUpperCase(), await parseOoxml(u8), file);
        else { out.innerHTML = '<p class="os-dim">Unsupported file — try an image, PDF, or Office document (docx/xlsx/pptx).</p>'; out.hidden = false; }
      } catch (e) { out.innerHTML = '<p class="os-dim">Could not read this file\'s metadata.</p>'; out.hidden = false; }
    };
    fr.readAsArrayBuffer(file);
  }

  window.__osPdf = parsePdf; window.__osOoxml = parseOoxml;   // exposed for verification

  input.addEventListener('change', function () { if (input.files[0]) handleFile(input.files[0]); });
  if (drop) {
    drop.addEventListener('click', function () { input.click(); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]); });
  }
})();
