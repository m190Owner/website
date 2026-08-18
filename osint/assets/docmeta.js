// Cross-document metadata correlation: read metadata from MANY documents (via the PDF /
// OOXML parsers exposed by metadata.js), then find the attributes they SHARE — the author,
// software, or company fingerprint that links "anonymous" files back to one person.
// 100% client-side; nothing is uploaded.
(function () {
  var input = document.getElementById('os-df'), drop = document.getElementById('os-ddrop'), out = document.getElementById('os-dout');
  if (!input || typeof window.__osPdf !== 'function' || typeof window.__osOoxml !== 'function') return;
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; }); }

  function readDoc(file) {
    return new Promise(function (resolve) {
      var fr = new FileReader();
      fr.onload = async function () {
        try {
          var u8 = new Uint8Array(fr.result), name = (file.name || '').toLowerCase();
          var isPdf = (u8[0] === 0x25 && u8[1] === 0x50) || /\.pdf$/.test(name);
          var isZip = u8[0] === 0x50 && u8[1] === 0x4B;
          var fields = {};
          if (isPdf) fields = window.__osPdf(u8);
          else if (isZip || /\.(docx|xlsx|pptx)$/.test(name)) fields = await window.__osOoxml(u8);
          resolve({ name: file.name, fields: fields || {} });
        } catch (e) { resolve({ name: file.name, fields: {} }); }
      };
      fr.onerror = function () { resolve({ name: file.name, fields: {} }); };
      fr.readAsArrayBuffer(file);
    });
  }

  function analyze(docs) {
    var links = [];
    // Person: pool the name-bearing fields so the same name links even across Author /
    // Last-modified-by / Creator on different documents.
    var byPerson = {};
    ['Author', 'Last modified by', 'Creator'].forEach(function (k) {
      docs.forEach(function (d, i) { var v = (d.fields[k] || '').trim(); if (v) { (byPerson[v] = byPerson[v] || {})[i] = 1; } });
    });
    Object.keys(byPerson).forEach(function (v) { var idx = Object.keys(byPerson[v]).map(Number); if (idx.length >= 2) links.push({ key: 'Person', value: v, docs: idx }); });
    // Origin fields, per key.
    ['Company', 'Producer', 'Application'].forEach(function (k) {
      var byVal = {};
      docs.forEach(function (d, i) { var v = (d.fields[k] || '').trim(); if (v) (byVal[v] = byVal[v] || []).push(i); });
      Object.keys(byVal).forEach(function (v) { if (byVal[v].length >= 2) links.push({ key: k, value: v, docs: byVal[v] }); });
    });
    var rank = { 'Person': 0, 'Company': 1, 'Producer': 2, 'Application': 3 };
    links.sort(function (a, b) { return (rank[a.key] - rank[b.key]) || (b.docs.length - a.docs.length); });
    return links;
  }

  function render(docs) {
    var links = analyze(docs);
    var people = links.filter(function (l) { return l.key === 'Person' || l.key === 'Company'; });
    var html;
    if (!links.length) html = '<div class="os-warn-box" style="border-color:var(--os-border);background:transparent;color:var(--os-dim)">No shared identifying metadata across these ' + docs.length + ' document(s) — they don\'t obviously link. (They may still share writing style — see the fingerprint tool in the Toolbox.)</div>';
    else {
      html = '<div class="os-warn-box">These ' + docs.length + ' document(s) share <b>' + links.length + '</b> identifying attribute(s)' + (people.length ? ', including a <b>person/company</b> fingerprint' : '') + ' — enough to tie them to the same origin.</div>';
      html += '<div class="os-subhead">Shared fingerprint</div><div class="os-list">';
      links.forEach(function (l) {
        var which = l.docs.map(function (i) { return esc(docs[i].name); }).join(', ');
        var hot = (l.key === 'Person' || l.key === 'Company');
        html += '<div class="os-row"><div class="os-row-main"><div class="os-row-t"><span class="os-tag' + (hot ? ' os-tag-hi' : '') + '">' + esc(l.key) + '</span> <span class="os-code">' + esc(l.value) + '</span></div>'
          + '<div class="os-row-d os-dim">links: ' + which + '</div></div></div>';
      });
      html += '</div>';
    }
    // Per-document metadata
    html += '<div class="os-subhead">Per document</div>';
    docs.forEach(function (d) {
      var keys = Object.keys(d.fields);
      html += '<div class="os-doss" style="border:1px solid var(--os-border);border-radius:8px;margin-bottom:6px"><div class="os-doss-k">' + esc(d.name) + '</div><div class="os-doss-v">'
        + (keys.length ? keys.map(function (k) { return '<span class="os-code">' + esc(k) + ': ' + esc(d.fields[k]) + '</span>'; }).join(' ') : '<span class="os-dim">no metadata (stripped or none)</span>') + '</div></div>';
    });
    html += '<p class="os-fineprint">Defence: before sharing, strip document metadata — Word/Excel/PowerPoint have <b>File → Info → Inspect Document</b>, or export to PDF and clean that. A shared author or company name is all it takes to deanonymise a set of files.</p>';
    out.innerHTML = html; out.hidden = false;
  }

  function handle(files) {
    files = Array.prototype.slice.call(files);
    if (files.length < 1) return;
    out.hidden = false; out.innerHTML = '<p class="os-dim"><span class="os-spinner"></span> Reading ' + files.length + ' document(s) in your browser…</p>';
    Promise.all(files.map(readDoc)).then(render);
  }

  input.addEventListener('change', function () { if (input.files.length) handle(input.files); });
  if (drop) {
    drop.addEventListener('click', function () { input.click(); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) handle(e.dataTransfer.files); });
  }
  window.__osDocLink = analyze;   // exposed for verification
})();
