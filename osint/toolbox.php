<?php
// Investigator toolbox: client-side utilities (encode/decode, hashes, hash ID, JWT,
// timestamps, email-header analysis, QR decoding) plus a server-side email deliverability
// check. Scoped to whatever the user pastes in — nothing is stored.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
osint_head('Toolbox · m190 finder', 'toolbox');
?>
  <div class="os-panel">
    <h2>Investigator toolbox</h2>
    <p>Quick utilities for OSINT and security work — decode, hash, inspect, and trace. Everything below runs in your browser; only the email-deliverability check talks to the server (to do a DNS lookup).</p>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Encode / decode</h3>
    <div class="os-inrow">
      <select class="os-select" id="os-enc-op">
        <option value="b64e">Base64 encode</option>
        <option value="b64d">Base64 decode</option>
        <option value="hexe">Hex encode</option>
        <option value="hexd">Hex decode</option>
        <option value="urle">URL encode</option>
        <option value="urld">URL decode</option>
        <option value="htmle">HTML encode</option>
        <option value="htmld">HTML decode</option>
        <option value="rot13">ROT13</option>
        <option value="bine">Binary encode</option>
        <option value="bind">Binary decode</option>
        <option value="rev">Reverse</option>
      </select>
    </div>
    <textarea class="os-ta" id="os-enc-in" placeholder="Input…" spellcheck="false" style="min-height:80px;margin-top:8px"></textarea>
    <textarea class="os-ta" id="os-enc-out" placeholder="Output…" readonly style="min-height:80px;margin-top:8px"></textarea>
  </div>

  <div class="os-grid2">
    <div class="os-panel">
      <h3 class="os-h3">Hash generator</h3>
      <input class="os-input" id="os-hash-in" placeholder="Text to hash…" autocomplete="off" style="min-width:0">
      <dl class="os-kv" id="os-hash-out" style="margin-top:10px"></dl>
    </div>
    <div class="os-panel">
      <h3 class="os-h3">Identify a hash</h3>
      <input class="os-input" id="os-hid-in" placeholder="Paste a hash…" autocomplete="off" style="min-width:0">
      <div id="os-hid-out" class="os-taglist" style="margin-top:12px"></div>
    </div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">JWT decoder <span class="os-dim">(decode only — no signature check)</span></h3>
    <textarea class="os-ta" id="os-jwt-in" placeholder="Paste a JWT: header.payload.signature" spellcheck="false" style="min-height:64px"></textarea>
    <div id="os-jwt-out" style="margin-top:10px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Timestamp converter</h3>
    <div class="os-inrow">
      <input class="os-input" id="os-epoch-in" placeholder="Unix epoch (seconds or milliseconds)…" autocomplete="off">
      <button type="button" class="os-btn os-btn-sm" id="os-epoch-now">Now</button>
    </div>
    <div id="os-epoch-out" class="os-clprog-lbl" style="margin-top:10px;line-height:1.7"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Email header analyzer</h3>
    <p class="os-dim os-mb">Paste a suspicious email's raw headers (in Gmail: &ldquo;Show original&rdquo;) to trace its true origin and check the anti-spoofing results.</p>
    <textarea class="os-ta" id="os-hdr-in" placeholder="Paste raw email headers…" spellcheck="false" style="min-height:120px"></textarea>
    <button type="button" class="os-btn os-btn-accent" id="os-hdr-run" style="margin-top:8px">Analyze</button>
    <div id="os-hdr-out" style="margin-top:12px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">QR code decoder</h3>
    <p class="os-dim os-mb">Decode a QR image to see where it really points — before scanning it with your phone.</p>
    <div class="os-drop" id="os-qr-drop">
      <div style="font-size:1.4rem">🔳</div>
      <div style="margin-top:4px"><b>Drop a QR image</b> or click to choose</div>
      <input type="file" id="os-qr-file" accept="image/*" hidden>
    </div>
    <div id="os-qr-out" style="margin-top:12px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Email deliverability &amp; disposable check</h3>
    <p class="os-dim os-mb">Check whether an email's domain can actually receive mail (has MX), and whether it's a throwaway/temp-mail provider or a role address.</p>
    <div class="os-inrow">
      <input type="email" class="os-input" id="os-eml-in" placeholder="name@example.com" autocomplete="off">
      <button type="button" class="os-btn os-btn-accent" id="os-eml-run">Check</button>
    </div>
    <div id="os-eml-out" class="os-posture" style="margin-top:12px" hidden></div>
  </div>

  <div class="os-grid2">
    <div class="os-panel">
      <h3 class="os-h3">Punycode / IDN homograph</h3>
      <p class="os-dim os-mb">Reveal what a fancy or <span class="os-code">xn--</span> domain really displays as — and catch look-alike (homograph) phishing domains.</p>
      <input class="os-input" id="os-idn-in" placeholder="paypal.com or xn--pypl-53d.com" autocomplete="off" style="min-width:0">
      <div id="os-idn-out" style="margin-top:10px"></div>
    </div>
    <div class="os-panel">
      <h3 class="os-h3">User-agent parser</h3>
      <p class="os-dim os-mb">Break down a User-Agent string into OS, browser, and device.</p>
      <input class="os-input" id="os-ua-in" placeholder="Paste a User-Agent string…" autocomplete="off" style="min-width:0">
      <dl class="os-kv" id="os-ua-out" style="margin-top:10px"></dl>
    </div>
  </div>

  <div class="os-grid2">
    <div class="os-panel">
      <h3 class="os-h3">CIDR / subnet calculator</h3>
      <p class="os-dim os-mb">Network, broadcast, usable range, and host count for an IPv4 block.</p>
      <input class="os-input" id="os-cidr-in" placeholder="192.168.1.0/24" autocomplete="off" style="min-width:0">
      <dl class="os-kv" id="os-cidr-out" style="margin-top:10px"></dl>
    </div>
    <div class="os-panel">
      <h3 class="os-h3">UUID generator</h3>
      <p class="os-dim os-mb">Random v4 UUIDs from a cryptographic RNG.</p>
      <button type="button" class="os-btn os-btn-accent" id="os-uuid-gen">Generate</button>
      <div id="os-uuid-out" class="os-taglist" style="margin-top:12px"></div>
    </div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Writing fingerprint <span class="os-dim">(stylometry)</span></h3>
    <p class="os-dim os-mb">Compare two writing samples — say your known/public writing against an &ldquo;anonymous&rdquo; post — to gauge how likely the <b>same person</b> wrote both. Style habits (function-word rhythm, sentence length, punctuation) are far harder to disguise than a username. Paste a few paragraphs in each. Runs entirely in your browser.</p>
    <div class="os-grid2">
      <textarea class="os-ta" id="os-sty-a" placeholder="Sample A — your known / public writing…" spellcheck="false" style="min-height:130px"></textarea>
      <textarea class="os-ta" id="os-sty-b" placeholder="Sample B — the text to check…" spellcheck="false" style="min-height:130px"></textarea>
    </div>
    <div class="os-inrow" style="margin-top:10px"><button type="button" class="os-btn os-btn-accent" id="os-sty-run">Compare writing</button></div>
    <div id="os-sty-out" hidden style="margin-top:14px"></div>
  </div>
<?php
osint_foot(['jsqr.min.js', 'toolbox.js']);
