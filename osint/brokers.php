<?php
// Removal center: a tracked, curated directory of the data brokers and people-search
// sites that list personal info, each with a direct opt-out link and instructions.
// Per-item progress (to-do / pending / done) persists via osint/checklist.php.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();

$data = json_decode((string) @file_get_contents(__DIR__ . '/assets/brokers.json'), true);
$brokers = $data['brokers'] ?? [];
$state = scan_checklist_get((int) $u['id'], 'brokers');
$verify = scan_checklist_get((int) $u['id'], 'brokerverify');
$doneCount = count(array_filter($state, fn($s) => $s === 'done'));
$verifiedCount = count(array_filter($verify, fn($s) => $s === 'done'));
/** Registrable base domain from a broker URL, for a `site:` listing search. */
$baseDomain = function (string $url): string {
    $h = strtolower((string) parse_url($url, PHP_URL_HOST));
    $h = preg_replace('/^www\./', '', $h);
    $parts = explode('.', $h);
    return count($parts) > 2 ? implode('.', array_slice($parts, -2)) : $h;
};

$tiers = [
    'peoplesearch' => 'People-search sites',
    'background'   => 'Background-check sites',
    'aggregator'   => 'Upstream aggregators',
    'marketing'    => 'Marketing-data brokers',
];
$grouped = [];
foreach ($brokers as $b) $grouped[$b['tier'] ?? 'peoplesearch'][] = $b;

osint_head('Removal center · m190 finder', 'removal');
?>
  <div class="os-panel">
    <h2>Removal center</h2>
    <p>These are the sites that publish your name, address, phone, and relatives — and the direct link to opt out of each. Work top to bottom; the upstream <b>aggregators</b> feed many of the smaller ones, so they're worth doing first. Your progress is saved per account.</p>
    <div class="os-clprog">
      <div class="os-mini"><div class="os-mini-fill" id="os-cl-fill"></div></div>
      <span class="os-clprog-lbl" id="os-cl-lbl"><?= (int) $doneCount ?> of <?= count($brokers) ?> done</span>
    </div>
    <p class="os-note" style="margin-top:14px">In the US, <b>CCPA/CPRA</b> (and similar state laws) give you the legal right to deletion — brokers must honour a request even without a self-serve form. Opting out of the big aggregators (<b>LexisNexis</b>, <b>Acxiom</b>) shrinks many downstream listings at once. Prefer to automate it? Paid services like Optery, DeleteMe, or EasyOptOuts run these continuously — but everything here you can do yourself for free.</p>
    <div class="os-clhead">
      <div class="os-chips" id="os-cl-chips" style="margin:0">
        <button class="os-chip on" data-filter="all">All</button>
        <button class="os-chip" data-filter="todo">To do</button>
        <button class="os-chip os-chip-att" data-filter="pending">Pending</button>
        <button class="os-chip" data-filter="done">Done</button>
      </div>
      <input type="search" class="os-search" id="os-cl-search" placeholder="Filter brokers…" autocomplete="off">
    </div>
  </div>

  <div class="os-panel">
    <div class="os-sec-head">
      <h3 class="os-h3">&#10003; Verify your removals</h3>
      <span class="os-clprog-lbl" id="os-vf-lbl"><?= (int) $verifiedCount ?> verified removed</span>
    </div>
    <p class="os-dim os-mb">Opting out isn't the end — brokers miss requests and silently re-list. After you opt out, come back and <b>re-check</b> each one. Enter the name you're listed under (and city, to narrow it); each broker gets a one-click search of its own site for you. <b>Your name stays in this browser only — it is never sent to our server.</b></p>
    <div class="os-vfform">
      <input type="text" id="os-vf-name" class="os-input" placeholder="Full name (e.g. Jane Smith)" autocomplete="off">
      <input type="text" id="os-vf-city" class="os-input" placeholder="City, State (optional)" autocomplete="off">
    </div>
    <p class="os-fineprint" id="os-vf-hint">Brokers block automated checks, so this opens each search in your own browser — look for your profile, then mark it <b>still listed</b> or <b>removed</b> below.</p>
  </div>

  <div data-checklist="brokers">
  <?php foreach ($tiers as $tk => $tlabel): if (empty($grouped[$tk])) continue; ?>
    <div class="os-panel">
      <h3 class="os-h3"><?= ose($tlabel) ?> <span class="os-dim">(<?= count($grouped[$tk]) ?>)</span></h3>
      <div class="os-list">
        <?php foreach ($grouped[$tk] as $b):
          $st = $state[$b['id']] ?? 'todo';
          $done = $st === 'done';
          $search = strtolower($b['name'] . ' ' . $tlabel . ' ' . ($b['region'] ?? ''));
        ?>
          <div class="os-row<?= $done ? ' done' : '' ?>" data-item="<?= ose($b['id']) ?>" data-status="<?= ose($st) ?>" data-search="<?= ose($search) ?>">
            <input type="checkbox" class="os-check" <?= $done ? 'checked' : '' ?> aria-label="Mark <?= ose($b['name']) ?> done">
            <div class="os-row-main">
              <div class="os-row-t">
                <a href="<?= ose($b['optout']) ?>" target="_blank" rel="noopener nofollow"><?= ose($b['name']) ?></a>
                <span class="os-tag"><?= ose($b['method']) ?></span>
                <span class="os-tag"><?= ose($b['region']) ?></span>
                <?php if (($b['effort'] ?? '') === 'hard'): ?><span class="os-tag os-tag-hi">tougher</span><?php endif; ?>
              </div>
              <div class="os-row-d"><?= ose($b['note']) ?> · <a href="<?= ose($b['site']) ?>" target="_blank" rel="noopener nofollow">site</a></div>
              <?php $vst = $verify[$b['id']] ?? ''; ?>
              <div class="os-verify" data-vitem="<?= ose($b['id']) ?>" data-domain="<?= ose($baseDomain($b['site'] ?? $b['optout'])) ?>" data-vstatus="<?= ose($vst) ?>">
                <a class="os-verify-link" href="#" target="_blank" rel="noopener nofollow">&#128269; Check listing</a>
                <button type="button" class="os-verify-btn" data-v="started">Still listed</button>
                <button type="button" class="os-verify-btn" data-v="done">Removed &#10003;</button>
                <span class="os-verify-badge"></span>
              </div>
            </div>
            <div class="os-row-side">
              <button type="button" class="os-pendbtn<?= $st === 'started' ? ' on' : '' ?>"<?= $done ? ' hidden' : '' ?>>Pending</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <p class="os-fineprint"><?= ose($data['note'] ?? '') ?> Opt-outs can take days to weeks to process, and some brokers re-list after a while — mark those <b>pending</b> and recheck in a few months.</p>
<?php
osint_foot(['checklist.js', 'brokerverify.js']);
