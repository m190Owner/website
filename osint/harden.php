<?php
// Hardening checklist: a tracked, step-by-step plan to lock down accounts and shrink
// exposure. Same generic checklist backend as the removal center (list = "harden").
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();

$data = json_decode((string) @file_get_contents(__DIR__ . '/assets/harden.json'), true);
$groups = $data['groups'] ?? [];
$state = scan_checklist_get((int) $u['id'], 'harden');
$total = 0; foreach ($groups as $g) $total += count($g['items'] ?? []);
$doneCount = count(array_filter($state, fn($s) => $s === 'done'));

$plinks = json_decode((string) @file_get_contents(__DIR__ . '/assets/privacy-links.json'), true);
$platforms = $plinks['platforms'] ?? [];

osint_head('Hardening · m190 finder', 'harden');
?>
  <div class="os-panel">
    <h2>Hardening checklist</h2>
    <p>Finding your exposure is half the job — this is the other half. A prioritised, do-once plan to close the gaps attackers actually use. Tick items off as you go; your progress is saved per account.</p>
    <div class="os-clprog">
      <div class="os-mini"><div class="os-mini-fill" id="os-cl-fill"></div></div>
      <span class="os-clprog-lbl" id="os-cl-lbl"><?= (int) $doneCount ?> of <?= (int) $total ?> done</span>
    </div>
    <div class="os-clhead">
      <div class="os-chips" id="os-cl-chips" style="margin:0">
        <button class="os-chip on" data-filter="all">All</button>
        <button class="os-chip" data-filter="todo">To do</button>
        <button class="os-chip" data-filter="done">Done</button>
      </div>
      <input type="search" class="os-search" id="os-cl-search" placeholder="Filter steps…" autocomplete="off">
    </div>
  </div>

  <?php if ($platforms): ?>
    <div class="os-panel">
      <h3 class="os-h3">Your privacy settings — direct links</h3>
      <p class="os-dim os-mb">Jump straight to each platform's privacy, security, and &ldquo;download your data&rdquo; pages — lock down who can find you by email/phone, review active sessions, turn off ad tracking, or delete the account entirely. (You'll need to be signed in to each.)</p>
      <?php foreach ($platforms as $pf): ?>
        <div class="os-subhead"><?= $pf['icon'] ? ose($pf['icon']) . ' ' : '' ?><?= ose($pf['name']) ?></div>
        <div class="os-srch">
          <?php foreach ($pf['links'] as $l): ?>
            <a href="<?= ose($l['url']) ?>" target="_blank" rel="noopener nofollow"><span class="os-srch-ic">&#8599;</span><?= ose($l['label']) ?></a>
          <?php endforeach; ?>
          <?php if (!empty($pf['delete'])): ?>
            <a class="os-srch-del" href="<?= ose($pf['delete']) ?>" target="_blank" rel="noopener nofollow"><span class="os-srch-ic">&#128465;</span>Delete / deactivate</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div data-checklist="harden">
  <?php foreach ($groups as $g): ?>
    <div class="os-panel">
      <h3 class="os-h3"><?= ose($g['title']) ?> <span class="os-dim">(<?= count($g['items']) ?>)</span></h3>
      <div class="os-list">
        <?php foreach ($g['items'] as $it):
          $st = $state[$it['id']] ?? 'todo';
          $done = $st === 'done';
          $link = $it['link'] ?? '';
          $ext = $link !== '' && strpos($link, 'http') === 0;
          $search = strtolower($it['title'] . ' ' . $g['title'] . ' ' . ($it['detail'] ?? ''));
        ?>
          <div class="os-row<?= $done ? ' done' : '' ?>" data-item="<?= ose($it['id']) ?>" data-status="<?= ose($st) ?>" data-search="<?= ose($search) ?>">
            <input type="checkbox" class="os-check" <?= $done ? 'checked' : '' ?> aria-label="Mark done">
            <div class="os-row-main">
              <div class="os-row-t">
                <?php if ($link !== ''): ?>
                  <a href="<?= ose($link) ?>"<?= $ext ? ' target="_blank" rel="noopener nofollow"' : '' ?>><?= ose($it['title']) ?></a>
                <?php else: ?>
                  <?= ose($it['title']) ?>
                <?php endif; ?>
              </div>
              <div class="os-row-d"><?= ose($it['detail'] ?? '') ?><?php if (!empty($it['linklabel']) && $link !== ''): ?> · <a href="<?= ose($link) ?>"<?= $ext ? ' target="_blank" rel="noopener nofollow"' : '' ?>><?= ose($it['linklabel']) ?></a><?php endif; ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <p class="os-fineprint">Order matters: a password manager + 2FA on your email closes the widest attack path, credit freezes stop new-account fraud, and the removal opt-outs shrink what a stranger can find. Everything here is free.</p>
<?php
osint_foot(['checklist.js']);
