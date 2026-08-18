<?php
// Photo EXIF viewer + stripper. 100% client-side (assets/metadata.js): the image is
// read and re-encoded in the browser and never uploaded anywhere.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
osint_head('File metadata · m190 finder', 'metadata', ['narrow' => true]);
?>
  <div class="os-panel">
    <h2>What your files give away</h2>
    <p>Photos embed the exact <b>GPS coordinates</b>, timestamp, and camera. Documents embed the <b>author's real name</b>, their organisation, and the software used. Share one publicly and you may be handing out more than you meant to. Drop a file below to see its hidden metadata — for images, download a cleaned copy too.</p>
    <div class="os-drop" id="os-drop" style="margin-top:14px">
      <div style="font-size:1.6rem">📄</div>
      <div style="margin-top:6px"><b>Drop a file here</b> or click to choose</div>
      <div class="os-dim" style="font-size:.78rem;margin-top:4px">Images (JPEG/PNG/WebP), PDF, Word/Excel/PowerPoint · never leaves your browser</div>
      <input type="file" id="os-mf" accept="image/*,.pdf,.docx,.xlsx,.pptx,application/pdf" hidden>
    </div>
    <div id="os-mout" hidden style="margin-top:4px"></div>
    <p class="os-note" style="margin-top:16px"><b>Fully private:</b> files are read entirely inside your browser (File, Canvas, and the built-in decompression API). No upload, no server round-trip — watch the network tab. The cleaned image copy is re-encoded, which discards EXIF, GPS, and every embedded tag.</p>
  </div>

  <div class="os-panel">
    <h2>🗺️ Pattern of life</h2>
    <p>Drop in <b>several</b> photos at once. Where they carry GPS, this maps <b>where and when</b> you were — the home, workplace, and daily routine a stalker or investigator can reconstruct from a handful of your public photos. It clusters the locations, guesses your likely home and work, and charts the times of day. Everything is computed in your browser.</p>
    <div class="os-drop" id="os-pdrop" style="margin-top:14px">
      <div style="font-size:1.6rem">📍</div>
      <div style="margin-top:6px"><b>Drop multiple photos</b> or click to choose</div>
      <div class="os-dim" style="font-size:.78rem;margin-top:4px">JPEG with GPS works best · analyzed entirely in your browser, never uploaded</div>
      <input type="file" id="os-pf" accept="image/*" multiple hidden>
    </div>
    <div id="os-pout" hidden style="margin-top:4px"></div>
  </div>

  <div class="os-panel">
    <h2>🔗 Link documents</h2>
    <p>Drop <b>several</b> documents (PDF / Word / Excel / PowerPoint). Even with your name stripped from the visible text, files carry an <b>author</b>, the software and company that made them, and template fingerprints. This finds the metadata your documents <b>share</b> — the thread that ties "anonymous" files back to the same person. All read in your browser.</p>
    <div class="os-drop" id="os-ddrop" style="margin-top:14px">
      <div style="font-size:1.6rem">📎</div>
      <div style="margin-top:6px"><b>Drop multiple documents</b> or click to choose</div>
      <div class="os-dim" style="font-size:.78rem;margin-top:4px">PDF, docx, xlsx, pptx · correlated entirely in your browser</div>
      <input type="file" id="os-df" accept=".pdf,.docx,.xlsx,.pptx,application/pdf" multiple hidden>
    </div>
    <div id="os-dout" hidden style="margin-top:4px"></div>
  </div>
<?php
osint_foot(['metadata.js', 'photomap.js', 'docmeta.js']);
