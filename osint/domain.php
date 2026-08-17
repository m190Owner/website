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

    // Web security headers
    $sec = $d['security'] ?? [];
    if (!empty($sec['reachable'])) {
        echo '<div class="os-subhead">Web security headers</div><div class="os-posture">';
        $hdr = fn($ok, $name, $desc) => os_prow($ok ? 'ok' : 'warn', $name, $ok ? 'Present' : ('Missing — ' . $desc));
        echo $hdr($sec['hsts'], 'HSTS', 'HTTPS is not enforced against downgrade');
        echo $hdr($sec['csp'], 'CSP', 'no content-injection defence');
        echo $hdr($sec['xfo'], 'X-Frame-Options', 'clickjacking not blocked');
        echo $hdr($sec['xcto'], 'X-Content-Type', 'MIME-sniffing not blocked');
        echo $hdr($sec['refpol'], 'Referrer-Policy', 'referrers may leak');
        echo $hdr($sec['perms'], 'Permissions-Policy', 'browser features unrestricted');
        if (!empty($sec['server'])) echo os_prow('', 'Server', ose($sec['server']) . ' <span class="os-dim">— a version banner helps attackers target you</span>');
        echo '</div>';
    } elseif (isset($d['security'])) {
        echo '<div class="os-subhead">Web security headers</div><p class="os-dim">The homepage didn\'t respond over HTTPS, so headers couldn\'t be read.</p>';
    }

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

    // Wayback Machine — pages the internet still remembers
    $wb = $d['wayback'] ?? [];
    echo '<div class="os-subhead">Wayback Machine <span class="os-dim">(archived pages)</span></div>';
    if (!empty($wb['ok']) && (int) $wb['count'] > 0) {
        echo '<p class="os-dim os-mb"><b>' . (int) $wb['count'] . '</b> archived page(s)'
           . ($wb['first'] ? ', ' . ose($wb['first']) . ' to ' . ose($wb['last']) : '')
           . '. These snapshots persist even after you delete the live pages — check them for info you meant to remove.</p><div class="os-taglist">';
        foreach ($wb['urls'] as $wu) echo '<a class="os-code" href="https://web.archive.org/web/*/' . ose($wu) . '" target="_blank" rel="noopener nofollow">' . ose(mb_substr($wu, 0, 64)) . '</a>';
        echo '</div>';
    } elseif (!empty($wb['ok'])) {
        echo '<p class="os-dim">No archived captures found.</p>';
    } else {
        echo '<p class="os-dim">Wayback lookup was unavailable this run — hit Rescan to retry.</p>';
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
