<?php
// Photo EXIF viewer + stripper. 100% client-side (assets/metadata.js): the image is
// read and re-encoded in the browser and never uploaded anywhere.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
osint_head('Photo EXIF · m190 finder', 'metadata', ['narrow' => true]);
?>
  <div class="os-panel">
    <h2>What your photos give away</h2>
    <p>Phone photos often embed the exact <b>GPS coordinates</b>, timestamp, and camera they were taken with. Post one publicly and you may be handing out your home address. Drop a photo below to see its hidden metadata — and download a clean copy with all of it removed.</p>
    <div class="os-drop" id="os-drop" style="margin-top:14px">
      <div style="font-size:1.6rem">📷</div>
      <div style="margin-top:6px"><b>Drop a photo here</b> or click to choose</div>
      <div class="os-dim" style="font-size:.78rem;margin-top:4px">JPEG, PNG, WebP · never leaves your browser</div>
      <input type="file" id="os-mf" accept="image/*" hidden>
    </div>
    <div id="os-mout" hidden style="margin-top:4px"></div>
    <p class="os-note" style="margin-top:16px"><b>Fully private:</b> this reads and cleans the image entirely inside your browser using the File and Canvas APIs. No upload, no server round-trip — you can watch the network tab. The cleaned copy is re-encoded, which discards EXIF, GPS, and every other embedded tag.</p>
  </div>
<?php
osint_foot(['metadata.js']);
