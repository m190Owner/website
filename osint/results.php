<?php
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    osint_csrf_require();
    enforceRateLimit('osint_clear', 20, 60);
    scan_clear((int) $u['id']);
    header('Location: /osint/'); exit;
}

$scan = isset($_GET['scan']) ? scan_get((int) $u['id'], (int) $_GET['scan']) : scan_latest((int) $u['id']);
$findings = $scan ? scan_findings((int) $u['id'], (int) $scan['id']) : [];
$has = fn($f, $needle) => strpos((string) ($f['exposes'] ?? ''), $needle) !== false;
$accounts = array_values(array_filter($findings, fn($f) => $f['category'] === 'account' && !$has($f, 'email')));
$identity = array_values(array_filter($findings, fn($f) => $f['category'] === 'account' && $has($f, 'email')));
$breaches = array_values(array_filter($findings, fn($f) => $f['category'] === 'breach'));
$phones   = array_values(array_filter($findings, fn($f) => $f['category'] === 'phone'));

$exposure = scan_exposure($findings);
$prev = $scan ? scan_prev_titles((int) $u['id'], (int) $scan['id']) : [];
$newCount = 0;
if ($prev) foreach ($findings as $f) { if (($f['status'] ?? 'new') !== 'false' && !isset($prev[scan_dismiss_key((string) $f['title'])])) $newCount++; }
$history = scan_history((int) $u['id']);
$isNew = fn($f) => $prev && !isset($prev[scan_dismiss_key((string) $f['title'])]);

function os_avatar(array $f): string {
    $a = (string) ($f['avatar'] ?? '');
    if ($a !== '' && preg_match('#^https?://#i', $a)) {
        return '<img class="os-av-img" src="' . ose($a) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';this.parentNode.classList.add(\'os-av-none\')">';
    }
    return '';
}
function os_triage(): string {
    return '<div class="os-triage">'
        . '<button type="button" data-set="attention" title="Needs attention">&#9873; attention</button>'
        . '<button type="button" data-set="false" title="Not me (false flag)">&times; not me</button>'
        . '<button type="button" data-set="done" title="Done">&#10003; done</button>'
        . '</div>';
}
/** One triage card. $main is the inner markup of the (optionally linked) main area. */
function os_fcard(array $f, string $main, bool $fresh = false): string {
    $s = ose($f['status'] ?? 'new');
    $badge = $fresh ? '<span class="os-fresh" title="New since your last scan">new</span>' : '';
    return '<div class="os-fcard os-st-' . $s . '" data-fid="' . (int) $f['id'] . '" data-status="' . $s . '">'
        . $badge . $main . os_triage() . '</div>';
}
osint_head('Results · m190 finder', 'results');
?>
  <?php if (!$scan): ?>
    <div class="os-panel"><h2>No scans yet</h2><p>Run one from the <a href="/osint/">dashboard</a>, or explore the removal, self-search, and hardening tools — they work without a scan.</p></div>
  <?php else: ?>
    <div class="os-panel">
      <div class="os-score">
        <?php $gc = $exposure['level'] === 'high' ? 'var(--os-danger)' : ($exposure['level'] === 'mid' ? 'var(--os-warn)' : 'var(--os-accent)'); ?>
        <div class="os-gauge" style="--v:<?= (int) $exposure['score'] ?>;--c:<?= $gc ?>">
          <div class="os-gauge-in"><b><?= (int) $exposure['score'] ?></b><span>exposure</span></div>
        </div>
        <div class="os-score-txt">
          <h2>Exposure snapshot</h2>
          <p class="os-dim"><?= ose(date('Y-m-d H:i', (int) $scan['started_at'])) ?><?= $scan['status'] === 'running' ? ' · incomplete' : '' ?><?php if ($newCount): ?> · <b style="color:var(--os-accent-l)"><?= (int) $newCount ?> new since last scan</b><?php endif; ?></p>
          <div class="os-riskrow">
            <span class="os-pill<?= $exposure['accounts'] ? '' : ' os-pill-good' ?>"><b><?= (int) $exposure['accounts'] ?></b> accounts</span>
            <span class="os-pill<?= $exposure['identity'] ? ' os-pill-warn' : ' os-pill-good' ?>"><b><?= (int) $exposure['identity'] ?></b> email identity</span>
            <span class="os-pill<?= $exposure['breaches'] ? ' os-pill-bad' : ' os-pill-good' ?>"><b><?= (int) $exposure['breaches'] ?></b> breaches<?= $exposure['span'] ? ' (' . ose($exposure['span']) . ')' : '' ?></span>
            <?php if ($exposure['pw']): ?><span class="os-pill os-pill-bad">passwords exposed</span><?php endif; ?>
            <span class="os-pill os-pill-warn"><b><?= (int) $scan['unreachable'] ?></b> couldn't check</span>
          </div>
        </div>
      </div>
      <div class="os-resultbtns" style="margin-top:16px">
        <a class="os-btn os-btn-sm" href="/osint/report.php">Printable report</a>
        <a class="os-btn os-btn-sm" href="/osint/receipt.php">Timestamped receipt</a>
        <a class="os-btn os-btn-sm" href="/osint/export.php?scan=<?= (int) $scan['id'] ?>">Export CSV</a>
        <a class="os-btn os-btn-sm" href="/osint/export.php?format=json">Export JSON</a>
        <a class="os-btn os-btn-sm" href="/osint/harden.php">Fix these &rarr;</a>
        <form method="post" class="os-inline" onsubmit="return confirm('Delete all your scan results? This cannot be undone.')">
          <?= osint_csrf_field() ?><input type="hidden" name="action" value="clear">
          <button class="os-btn os-btn-sm os-btn-danger">Clear results</button>
        </form>
      </div>
      <p class="os-fineprint">Mark each hit: <b>needs attention</b> if it's you and you'll deal with it, <b>not me</b> if it's a false flag (short/common handles collide with other people), <b>done</b> once handled. Avatars come from each site's public page so you can eyeball it.</p>
    </div>

    <div class="os-chips" id="os-chips">
      <button class="os-chip on" data-filter="all">All <span class="n">0</span></button>
      <button class="os-chip os-chip-att" data-filter="attention">Needs attention <span class="n">0</span></button>
      <button class="os-chip" data-filter="new">Unreviewed <span class="n">0</span></button>
      <button class="os-chip" data-filter="false">Not me <span class="n">0</span></button>
      <button class="os-chip" data-filter="done">Done <span class="n">0</span></button>
    </div>

    <div class="os-panel">
      <h3 class="os-h3">Accounts &amp; profiles <span class="os-dim">(<?= count($accounts) ?>)</span></h3>
      <?php if (!$accounts): ?><p class="os-dim">Nothing matched.</p><?php else: ?>
        <div class="os-cardgrid">
          <?php foreach ($accounts as $f):
            $t = ose($f['title']) . ($f['detail'] ? '<br><span class="os-dim">' . ose($f['detail']) . '</span>' : '');
            $main = '<a class="os-fcard-main" href="' . ose($f['url']) . '" target="_blank" rel="noopener nofollow">'
                  . '<span class="os-av">' . os_avatar($f) . '</span>'
                  . '<span class="os-acard-t">' . $t . '</span></a>';
            echo os_fcard($f, $main, $isNew($f));
          endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($identity): ?>
      <div class="os-panel">
        <h3 class="os-h3">Email identity <span class="os-dim">(<?= count($identity) ?>)</span></h3>
        <div class="os-cardgrid">
          <?php foreach ($identity as $f): $isG = $has($f, 'google');
            $av = $isG ? '<span class="os-av os-av-g">G</span>' : '<span class="os-av">' . os_avatar($f) . '</span>';
            $t  = ose($f['title']) . ($f['detail'] ? '<br><span class="os-dim">' . ose($f['detail']) . '</span>' : '');
            $main = '<a class="os-fcard-main" href="' . ose($f['url']) . '" target="_blank" rel="noopener nofollow">'
                  . $av . '<span class="os-acard-t">' . $t . '</span></a>';
            echo os_fcard($f, $main, $isNew($f));
          endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($phones): ?>
      <div class="os-panel">
        <h3 class="os-h3">Phone numbers <span class="os-dim">(<?= count($phones) ?>)</span></h3>
        <p class="os-dim os-mb">What your number reveals offline — country/region and format. Live carrier, linked-account, and breach checks for phones aren&rsquo;t available keyless from a server.</p>
        <div class="os-cardgrid">
          <?php foreach ($phones as $f):
            $main = '<div class="os-fcard-main"><span class="os-av">&#9742;</span><span class="os-acard-t">' . ose($f['title'])
                  . ($f['detail'] ? '<br><span class="os-dim">' . ose($f['detail']) . '</span>' : '') . '</span></div>';
            echo os_fcard($f, $main, $isNew($f));
          endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="os-panel">
      <div class="os-sec-head">
        <h3 class="os-h3">Breach records <span class="os-dim">(<?= count($breaches) ?>)</span></h3>
        <?php if ($exposure['span']): ?><span class="os-dim os-badge"><?= ose($exposure['span']) ?></span><?php endif; ?>
      </div>
      <p class="os-dim os-mb">A breach already happened — change the password anywhere you reused it, then mark it done.</p>
      <?php if ($exposure['dataclasses']): ?>
        <div class="os-subhead" style="margin-top:0">What leaked across your breaches</div>
        <div class="os-taglist" style="margin-bottom:12px">
          <?php foreach (array_slice($exposure['dataclasses'], 0, 16) as $dc): $hot = preg_match('/passw|security question|payment|card|social security|bank/i', $dc); ?>
            <span class="os-tag<?= $hot ? ' os-tag-hi' : '' ?>"><?= ose($dc) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$breaches): ?><p class="os-dim">No breach records reported.</p><?php else: ?>
        <div class="os-breachlist">
          <?php foreach ($breaches as $f):
            $name = preg_replace('/^.* in the (.*) breach$/', '$1', $f['title']);
            $main = '<div class="os-fcard-main"><span class="os-blogo">' . os_avatar($f) . '</span>'
                  . '<span class="os-bcard-t"><b>' . ose($name) . '</b>' . ($f['detail'] ? '<span class="os-bmeta">' . ose($f['detail']) . '</span>' : '') . '</span></div>';
            echo os_fcard($f, $main, $isNew($f));
          endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (count($history) > 1): ?>
      <div class="os-panel">
        <h3 class="os-h3">Scan history</h3>
        <div class="os-list" style="margin-top:8px">
          <?php foreach ($history as $h): $cur = (int) $h['id'] === (int) $scan['id']; ?>
            <a class="os-row" href="/osint/results.php?scan=<?= (int) $h['id'] ?>" style="text-decoration:none">
              <div class="os-row-main">
                <div class="os-row-t"><?= ose(date('Y-m-d H:i', (int) $h['started_at'])) ?><?php if ($cur): ?> <span class="os-tag">viewing</span><?php endif; ?><?php if ($h['status'] === 'running'): ?> <span class="os-tag os-tag-hi">incomplete</span><?php endif; ?></div>
                <div class="os-row-d"><b><?= (int) $h['found'] ?></b> found · <?= (int) $h['unreachable'] ?> couldn't check · <?= (int) $h['total'] ?> checks</div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php
osint_foot(['results.js']);
