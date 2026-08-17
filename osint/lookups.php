<?php
// Lookups: investigation tools over public infrastructure data — expand a suspicious
// link, profile an IP, dump a domain's DNS, or decode a certificate. All server-backed
// (keyless) via lookup.php; nothing is stored.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
osint_head('Lookups · m190 finder', 'lookups');
?>
  <div class="os-panel">
    <h2>Investigation lookups</h2>
    <p>Trace where a link really goes, profile an IP address, dump a domain's DNS, or decode a certificate. These work on public network data — pair them with the email-header analyzer to run down a suspicious message.</p>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">URL / link analyzer</h3>
    <p class="os-dim os-mb">Expand a shortened or suspicious link to see its full redirect chain and final destination — without clicking it.</p>
    <div class="os-inrow">
      <input type="url" class="os-input" id="os-url-in" placeholder="https://bit.ly/… or any link" autocomplete="off">
      <button type="button" class="os-btn os-btn-accent" id="os-url-run">Trace</button>
    </div>
    <div id="os-url-out" style="margin-top:12px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">IP address lookup</h3>
    <p class="os-dim os-mb">Geolocation, ISP/ASN, reverse DNS, and threat-feed reputation for any IP (e.g. the origin IP from an email header).</p>
    <div class="os-inrow">
      <input type="text" class="os-input" id="os-ip-in" placeholder="8.8.8.8" autocomplete="off">
      <button type="button" class="os-btn os-btn-accent" id="os-ip-run">Look up</button>
    </div>
    <div id="os-ip-out" style="margin-top:12px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">DNS lookup</h3>
    <p class="os-dim os-mb">Every common record type for a domain — A, AAAA, MX, NS, TXT, CAA, SOA, SRV.</p>
    <div class="os-inrow">
      <input type="text" class="os-input" id="os-dns-in" placeholder="example.com" autocomplete="off">
      <button type="button" class="os-btn os-btn-accent" id="os-dns-run">Look up</button>
    </div>
    <div id="os-dns-out" style="margin-top:12px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Certificate decoder</h3>
    <p class="os-dim os-mb">Paste a PEM certificate to read its subject, issuer, validity, and SANs.</p>
    <textarea class="os-ta" id="os-cert-in" placeholder="-----BEGIN CERTIFICATE-----&#10;…&#10;-----END CERTIFICATE-----" spellcheck="false" style="min-height:110px"></textarea>
    <button type="button" class="os-btn os-btn-accent" id="os-cert-run" style="margin-top:8px">Decode</button>
    <div id="os-cert-out" style="margin-top:12px"></div>
  </div>
<?php
osint_foot(['lookups.js']);
