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
<?php
osint_foot(['metadata.js']);
