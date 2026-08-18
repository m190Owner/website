<?php
// Social tab: the detailed public profile behind each of your handles (aggregated across
// keyless-API platforms + Keybase cross-platform proofs), an impersonation finder over
// your handle-variations, a Fediverse resolver, and a profile/post preview extractor.
// Profile + impersonation are scoped to your own usernames; the resolvers take any input.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();
$p = scan_profile_get((int) $u['id']);
osint_head('Social · m190 finder', 'social');
?>
  <div class="os-panel">
    <h2>Your social footprint</h2>
    <p>The detailed public profile behind each of your handles — display name, bio, location, join date, follower counts, and (via <b>Keybase</b>) the other accounts they're cryptographically linked to. This is what anyone can pull up about your username.</p>
    <?php if (!$p['usernames']): ?>
      <p class="os-dim" style="margin-top:12px">No usernames yet. <a href="/osint/profile.php">Add some to your profile</a> to map your social footprint.</p>
    <?php endif; ?>
  </div>

  <?php foreach ($p['usernames'] as $un): ?>
    <div class="os-panel" data-social="<?= ose($un) ?>">
      <div class="os-sec-head">
        <h3 class="os-h3">@<?= ose($un) ?></h3>
        <div class="os-inrow" style="gap:8px">
          <button type="button" class="os-btn os-btn-sm" data-ghsecrets="<?= ose($un) ?>">Scan GitHub for secrets</button>
          <button type="button" class="os-btn os-btn-sm" data-impersonate="<?= ose($un) ?>">Find impersonators</button>
        </div>
      </div>
      <div class="os-social-cards"><p class="os-dim"><span class="os-spinner"></span> Looking up profiles…</p></div>
      <div class="os-gh-out" hidden style="margin-top:12px"></div>
      <div class="os-imp-out" hidden style="margin-top:12px"></div>
    </div>
  <?php endforeach; ?>

  <div class="os-panel">
    <h3 class="os-h3">Fediverse lookup</h3>
    <p class="os-dim os-mb">Resolve a Mastodon / Fediverse handle to its instance and profile via WebFinger.</p>
    <div class="os-inrow">
      <input type="text" class="os-input" id="os-fedi-in" placeholder="user@mastodon.social" autocomplete="off">
      <button type="button" class="os-btn os-btn-accent" id="os-fedi-run">Resolve</button>
    </div>
    <div id="os-fedi-out" style="margin-top:12px"></div>
  </div>

  <div class="os-panel">
    <h3 class="os-h3">Profile / post preview</h3>
    <p class="os-dim os-mb">Paste any social profile or post URL to pull its public preview — title, description, image — even for platforms whose API is locked down (Instagram, TikTok, X, Facebook).</p>
    <div class="os-inrow">
      <input type="url" class="os-input" id="os-og-in" placeholder="https://…" autocomplete="off">
      <button type="button" class="os-btn os-btn-accent" id="os-og-run">Preview</button>
    </div>
    <div id="os-og-out" style="margin-top:12px"></div>
  </div>
<?php
osint_foot(['social.js']);
