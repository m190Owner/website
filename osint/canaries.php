<?php
// Canaries: mint unique decoy identifiers, seed each into ONE specific site/broker, and
// when a leak or spam later surfaces, reverse-look-it-up to learn exactly who sold or
// leaked your data. The registry (which token → which site) lives in the user's own store.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);
$canaries = scan_canary_list((int) $u['id']);
$emailBase = $p['emails'][0] ?? '';
$userBase  = $p['usernames'][0] ?? '';

/** Deployment suggestions for a token, from the user's own email/username. */
function os_canary_deploy(string $token, string $email, string $user): string {
    $bits = [];
    if ($email !== '' && strpos($email, '@') !== false) { [$l, $d] = explode('@', $email, 2); $bits[] = 'Email <span class="os-code">' . ose($l . '+' . $token . '@' . $d) . '</span>'; }
    $bits[] = 'Name/field <span class="os-code">' . ose(ucfirst($token)) . '</span>';
    $bits[] = 'Username <span class="os-code">' . ose(($user !== '' ? $user . '.' : '') . $token) . '</span>';
    return implode(' · ', $bits);
}

osint_head('Canaries · m190 finder', 'canaries');
?>
  <div class="os-panel" data-email="<?= ose($emailBase) ?>" data-user="<?= ose($userBase) ?>">
    <h2>Canary identifiers</h2>
    <p>A canary is a <b>unique decoy</b> you give to exactly one place. Sign up for a newsletter with <span class="os-code">you+m190x…@gmail.com</span>, give a broker a one-of-a-kind middle name, use a throwaway handle on a sketchy site — each tied to a token only you know. If that exact token later turns up in <b>spam, a breach, or a broker listing</b>, you know precisely who leaked or sold it. It turns "someone leaked my data" into "<i>this</i> company did."</p>
    <p class="os-note" style="margin-top:12px">Email canaries work with Gmail/most providers via <b>+tag addressing</b> (mail to <span class="os-code">you+anything@gmail.com</span> still reaches you), or a catch-all on your own domain. The token is what matters — deploy it however a given site allows.</p>
  </div>

  <div class="os-grid2">
    <div class="os-panel">
      <h3 class="os-h3">Mint a canary</h3>
      <p class="os-dim os-mb">Name where you'll use it, so a future hit points straight at the source.</p>
      <input type="text" id="os-cn-label" class="os-input" placeholder="Where will you use it? (e.g. Spokeo signup)" autocomplete="off" style="min-width:0">
      <input type="text" id="os-cn-note" class="os-input" placeholder="Note (optional)" autocomplete="off" style="min-width:0;margin-top:8px">
      <button type="button" class="os-btn os-btn-accent" id="os-cn-create" style="margin-top:10px">Generate canary</button>
    </div>
    <div class="os-panel">
      <h3 class="os-h3">Trace a leak</h3>
      <p class="os-dim os-mb">Got spam or found yourself in a listing? Paste it — if it contains one of your canaries, you'll see who you gave it to.</p>
      <textarea id="os-cn-match" class="os-ta" placeholder="Paste a spam email, a listing, or a leaked record…" spellcheck="false" style="min-height:80px"></textarea>
      <button type="button" class="os-btn os-btn-accent" id="os-cn-trace" style="margin-top:10px">Trace it</button>
      <div id="os-cn-traceout" style="margin-top:10px"></div>
    </div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Your canaries <span class="os-dim" id="os-cn-count">(<?= count($canaries) ?>)</span></h3>
    <div id="os-cn-list" class="os-list" style="margin-top:8px">
      <?php if (!$canaries): ?>
        <p class="os-dim" id="os-cn-empty">No canaries yet. Mint one above and start seeding it wherever you hand over your details.</p>
      <?php endif; ?>
      <?php foreach ($canaries as $c): ?>
        <div class="os-row<?= $c['tripped'] ? ' os-cn-tripped' : '' ?>" data-canary="<?= (int) $c['id'] ?>">
          <div class="os-row-main">
            <div class="os-row-t"><span class="os-code"><?= ose($c['token']) ?></span> <?= $c['label'] ? '<b>' . ose($c['label']) . '</b>' : '<span class="os-dim">(unlabeled)</span>' ?>
              <?php if ($c['tripped']): ?><span class="os-tag os-tag-hi">tripped <?= $c['tripped_at'] ? ose(date('Y-m-d', (int) $c['tripped_at'])) : '' ?></span><?php endif; ?></div>
            <div class="os-row-d os-cn-deploy"><?= os_canary_deploy($c['token'], $emailBase, $userBase) ?></div>
            <div class="os-row-d os-dim">Minted <?= ose(date('Y-m-d', (int) $c['created_at'])) ?><?= $c['note'] ? ' · ' . ose($c['note']) : '' ?><?= $c['tripped'] && $c['tripped_note'] ? ' · leaked: ' . ose($c['tripped_note']) : '' ?></div>
          </div>
          <div class="os-row-side" style="display:flex;flex-direction:column;gap:6px">
            <button type="button" class="os-pendbtn os-cn-trip" data-op="<?= $c['tripped'] ? 'untrip' : 'trip' ?>"><?= $c['tripped'] ? 'Clear' : 'Mark leaked' ?></button>
            <button type="button" class="os-pendbtn os-cn-del">Delete</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php
osint_foot(['canary.js']);
