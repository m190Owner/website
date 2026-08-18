<?php
// Network footprint: what the user's current connection reveals to every site they
// visit — public IP, geolocation, ISP/ASN, and threat-feed reputation. Server-side,
// keyless (ipwho.is + DShield), scoped to the caller's own connection.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();

$ip = os_client_ip();
$info = scan_ip_footprint($ip);
$priv = !empty($info['private']);

$repLinks = [
    ['AbuseIPDB', 'https://www.abuseipdb.com/check/' . rawurlencode($ip), '🛑'],
    ['DShield / SANS', 'https://isc.sans.edu/ipinfo.html?ip=' . rawurlencode($ip), '🐚'],
    ['VirusTotal', 'https://www.virustotal.com/gui/ip-address/' . rawurlencode($ip), '🧬'],
    ['Cloudflare Radar', 'https://radar.cloudflare.com/ip/' . rawurlencode($ip), '📡'],
    ['Shodan', 'https://www.shodan.io/host/' . rawurlencode($ip), '🛰️'],
];
osint_head('Network footprint · m190 finder', 'network');
?>
  <div class="os-panel">
    <h2>Your network footprint</h2>
    <p>Every website you open sees this. Your IP address ties your activity to a rough location and your internet provider, and its reputation can get you blocked or flagged. Here's what yours looks like right now.</p>
  </div>

  <?php if ($priv): ?>
    <div class="os-panel">
      <div class="os-score">
        <div class="os-gauge" style="--v:0;--c:var(--os-accent)"><div class="os-gauge-in"><b>—</b><span>local</span></div></div>
        <div class="os-score-txt">
          <h2><span class="os-code"><?= ose($ip) ?></span></h2>
          <p class="os-dim">That's a local/loopback address — you're viewing from the same network as the server. <b>Deployed on the live site, this page shows your real public IP, city, ISP, and threat-feed reputation.</b></p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="os-panel">
      <div class="os-score">
        <div class="os-gauge" style="--v:100;--c:var(--os-accent)"><div class="os-gauge-in" style="font-size:1.4rem"><b><?= ose($info['flag'] ?? '🌐') ?></b><span><?= ose($info['cc'] ?? '') ?></span></div></div>
        <div class="os-score-txt">
          <h2 style="font-family:var(--os-mono)"><?= ose($ip) ?></h2>
          <p class="os-dim"><?= ose(trim(($info['city'] ?? '') . ', ' . ($info['region'] ?? '') . ', ' . ($info['country'] ?? ''), ', ')) ?></p>
        </div>
      </div>
      <dl class="os-kv" style="margin-top:14px">
        <dt>ISP</dt><dd><?= ose($info['isp'] ?? '—') ?></dd>
        <?php if (!empty($info['org']) && $info['org'] !== ($info['isp'] ?? '')): ?><dt>Organisation</dt><dd><?= ose($info['org']) ?></dd><?php endif; ?>
        <?php if (!empty($info['asn'])): ?><dt>ASN</dt><dd><a href="https://bgp.he.net/AS<?= ose(preg_replace('/\D/', '', $info['asn'])) ?>" target="_blank" rel="noopener nofollow">AS<?= ose(preg_replace('/\D/', '', $info['asn'])) ?></a></dd><?php endif; ?>
        <dt>Type</dt><dd><?= ose($info['type'] ?? '—') ?></dd>
        <?php if (!empty($info['tz'])): ?><dt>Timezone</dt><dd><?= ose($info['tz']) ?></dd><?php endif; ?>
      </dl>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Threat-feed reputation</h3>
      <div class="os-posture">
        <?php
        if (!empty($info['ds_ok'])) {
            $attacks = (int) ($info['ds_attacks'] ?? 0);
            $cnt = (int) ($info['ds_count'] ?? 0);
            if ($attacks > 0 || $cnt > 0) {
                echo '<div class="os-prow"><span class="os-pdot os-pdot-bad"></span><span class="os-pk">DShield</span><span>Reported as an attack source — <b>' . $attacks . '</b> target(s), <b>' . $cnt . '</b> report(s)'
                    . (!empty($info['ds_maxdate']) ? ', last ' . ose($info['ds_maxdate']) : '') . '. If this is your home IP, scan your devices for malware.</span></div>';
            } else {
                echo '<div class="os-prow"><span class="os-pdot os-pdot-ok"></span><span class="os-pk">DShield</span><span>No attack activity reported for your IP.</span></div>';
            }
            if (!empty($info['ds_feeds'])) {
                echo '<div class="os-prow"><span class="os-pdot"></span><span class="os-pk">Listed on</span><span>' . ose(implode(', ', $info['ds_feeds'])) . ' <span class="os-dim">— informational feeds; inclusion isn\'t necessarily malicious.</span></span></div>';
            }
        } else {
            echo '<div class="os-prow"><span class="os-pdot os-pdot-warn"></span><span class="os-pk">DShield</span><span>Reputation service didn\'t respond this time — try the direct links below.</span></div>';
        }
        ?>
      </div>
      <div class="os-subhead">Check your IP's reputation directly</div>
      <div class="os-srch">
        <?php foreach ($repLinks as [$label, $url, $ic]): ?>
          <a href="<?= ose($url) ?>" target="_blank" rel="noopener nofollow"><span class="os-srch-ic"><?= $ic ?></span><?= ose($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">Exposed services <span class="os-dim">(Shodan InternetDB)</span></h3>
        <button type="button" class="os-btn os-btn-sm" id="os-svc-run">Check my IP</button>
      </div>
      <p class="os-dim os-mb">Open ports and known vulnerabilities Shodan sees on your public IP. On a home connection this should be empty; anything here is reachable from the whole internet — close it or put it behind a firewall/VPN. Runs on demand, keyless.</p>
      <div id="os-svc-out" hidden style="margin-top:4px"></div>
    </div>
  <?php endif; ?>

  <div class="os-panel">
    <h3 class="os-h3">WebRTC leak test</h3>
    <p class="os-dim os-mb">WebRTC can hand your real IP address to any website through a direct browser connection — even when you're behind a VPN or proxy. Run the test to see what your browser exposes.</p>
    <button type="button" class="os-btn os-btn-accent" id="os-leak-run">Run WebRTC leak test</button>
    <div id="os-leak-out" class="os-posture" style="margin-top:12px" hidden></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Browser fingerprint</h3>
    <p class="os-dim os-mb">Even with cookies blocked, the unique combination of your browser, screen, GPU, fonts, and settings forms a fingerprint that tracks you across sites. Here's yours.</p>
    <button type="button" class="os-btn os-btn-accent" id="os-fp-run">Analyze my fingerprint</button>
    <div id="os-fp-out" hidden style="margin-top:12px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">What this reveals — and how to shrink it</h3>
    <p class="os-dim">Your IP alone doesn't give a street address, but combined with your ISP and timezone it narrows you down, and every site logs it. To reduce it: use a reputable <b>VPN</b> (hides your IP and location from sites), a privacy-respecting <b>DNS</b> resolver, and keep your devices clean so your IP never lands on a blocklist. The <a href="/osint/harden.php">hardening checklist</a> covers the device and network steps.</p>
  </div>
<?php
osint_foot(['netleak.js', 'fingerprint.js', 'ipservices.js']);
