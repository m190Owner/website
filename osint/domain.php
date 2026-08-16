<?php
// Domain footprint: for each of the user's own domains, show live DNS records, email
// security posture (SPF/DMARC/DNSSEC), and subdomains discovered via certificate
// transparency. Results are cached; the Scan button (domain.js -> domainscan.php)
// refreshes them.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);

/** One posture row: coloured dot + label + value. */
function os_prow(string $cls, string $key, string $val): string {
    return '<div class="os-prow"><span class="os-pdot os-pdot-' . $cls . '"></span><span class="os-pk">' . ose($key) . '</span><span>' . $val . '</span></div>';
}
/** A DNS record block (list of values) as a definition row. */
function os_reclist(string $label, array $vals): string {
    if (!$vals) return '';
    $items = implode('', array_map(fn($v) => '<dd>' . ose($v) . '</dd>', array_slice($vals, 0, 12)));
    return '<dt>' . ose($label) . '</dt>' . $items;
}
function os_domain_render(array $d): void {
    // Email-security posture
    echo '<div class="os-posture">';
    echo os_prow($d['resolves'] ? 'ok' : 'warn', 'Resolves', $d['resolves'] ? 'Yes — has A/AAAA records' : 'No A/AAAA record (parked or internal)');
    echo os_prow($d['has_mail'] ? 'ok' : '', 'Email (MX)', $d['has_mail'] ? ose($d['mx'][0]) : 'No MX record — domain does not receive mail');
    echo os_prow($d['spf'] ? 'ok' : 'warn', 'SPF', $d['spf'] ? 'Present' : 'Missing — senders can be spoofed');
    if (($d['dmarc_policy'] ?? null) === 'reject' || $d['dmarc_policy'] === 'quarantine')
        echo os_prow('ok', 'DMARC', 'Enforced (<span class="os-code">p=' . ose($d['dmarc_policy']) . '</span>)');
    elseif (($d['dmarc_policy'] ?? null) === 'none')
        echo os_prow('warn', 'DMARC', 'Monitoring only (<span class="os-code">p=none</span>) — not enforced');
    else
        echo os_prow('warn', 'DMARC', 'Missing — no anti-spoofing policy');
    echo os_prow($d['dnssec'] ? 'ok' : '', 'DNSSEC', $d['dnssec'] ? 'Signed' : 'Not enabled');
    echo '</div>';

    // Raw DNS records
    $recs = os_reclist('A', $d['a']) . os_reclist('AAAA', $d['aaaa']) . os_reclist('MX', $d['mx'])
          . os_reclist('NS', $d['ns']) . os_reclist('TXT', $d['txt']);
    if ($recs) echo '<div class="os-subhead">DNS records</div><dl class="os-kv">' . $recs . '</dl>';

    // Subdomains via certificate transparency
    echo '<div class="os-subhead">Subdomains <span class="os-dim">(certificate transparency · ' . count($d['subdomains']) . ')</span></div>';
    if ($d['subdomains']) {
        echo '<div class="os-taglist">';
        foreach ($d['subdomains'] as $s) echo '<span class="os-code">' . ose($s) . '</span>';
        echo '</div>';
        echo '<p class="os-fineprint">Every name a public TLS certificate was ever issued for. Retire ones you no longer use — they map your attack surface.</p>';
    } elseif (!$d['crt_ok']) {
        echo '<p class="os-dim">Certificate-transparency lookup was unavailable this run — hit Rescan to retry.</p>';
    } else {
        echo '<p class="os-dim">No subdomains found in certificate transparency logs.</p>';
    }
}

osint_head('Domain footprint · m190 finder', 'domains');
?>
  <div class="os-panel">
    <h2>Domain footprint</h2>
    <p>What your domains expose publicly: where they resolve, whether their email is protected against spoofing, and every subdomain that's ever appeared in a public certificate. All of it is public DNS and certificate-transparency data — this just gathers it in one place.</p>
    <?php if (!$p['domains']): ?>
      <p class="os-dim" style="margin-top:12px">No domains yet. <a href="/osint/profile.php">Add a domain you own</a> to map its footprint.</p>
    <?php endif; ?>
  </div>

  <?php foreach ($p['domains'] as $i => $domain):
    $cached = scan_domain_cache_get((int) $u['id'], $domain); ?>
    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">🌐 <?= ose($domain) ?></h3>
        <button type="button" class="os-btn os-btn-sm" data-scan-domain="<?= ose($domain) ?>" data-idx="<?= $i ?>"><?= $cached ? 'Rescan' : 'Scan' ?></button>
      </div>
      <div id="os-dstatus-<?= $i ?>" class="os-dim" style="font-size:.8rem;margin-top:4px">
        <?= $cached ? 'Last scanned ' . ose(date('Y-m-d H:i', (int) $cached['ts'])) : 'Not scanned yet — hit Scan.' ?>
      </div>
      <?php if ($cached) os_domain_render($cached); ?>
    </div>
  <?php endforeach; ?>
<?php
osint_foot(['domain.js']);
