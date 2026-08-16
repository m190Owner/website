<?php
// The signed-in user's own identifiers — the only things the tools ever act on.
require __DIR__ . '/lib/scan.php';
require __DIR__ . '/lib/osint_ui.php';
osint_require();
$u = osint_current_user();

$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    osint_csrf_require();
    enforceRateLimit('osint_profile', 30, 60);
    $p = scan_profile_set(
        (int) $u['id'],
        (array) ($_POST['username'] ?? []),
        (array) ($_POST['email'] ?? []),
        (array) ($_POST['phone'] ?? []),
        (array) ($_POST['domain'] ?? [])
    );
    $saved = true;
} else {
    $p = scan_profile_get((int) $u['id']);
}
$usernames = array_pad($p['usernames'], OSINT_MAX_USERNAMES, '');
$emails    = array_pad($p['emails'], OSINT_MAX_EMAILS, '');
$phones    = array_pad($p['phones'], OSINT_MAX_PHONES, '');
$domains   = array_pad($p['domains'], OSINT_MAX_DOMAINS, '');
osint_head('Your profile · m190 finder', 'profile', ['narrow' => true]);
?>
  <?php if ($saved): ?><div class="os-ok">Profile saved. You can run a scan or open any tool from the dashboard.</div><?php endif; ?>
  <div class="os-panel">
    <h2>Your identifiers</h2>
    <p>Every tool here only ever acts on what you put on this page — your own usernames, email addresses, phone numbers, and domains. Nothing else, and never someone else's.</p>
    <form method="post" class="os-form" autocomplete="off" style="margin-top:16px">
      <?= osint_csrf_field() ?>
      <div class="os-fieldgroup">
        <span class="os-grouplabel">Usernames <span class="os-dim">(up to <?= OSINT_MAX_USERNAMES ?>)</span></span>
        <?php foreach ($usernames as $v): ?>
          <input type="text" name="username[]" maxlength="40" value="<?= ose($v) ?>" placeholder="e.g. yourhandle">
        <?php endforeach; ?>
      </div>
      <div class="os-fieldgroup">
        <span class="os-grouplabel">Email addresses <span class="os-dim">(up to <?= OSINT_MAX_EMAILS ?>)</span></span>
        <?php foreach ($emails as $v): ?>
          <input type="email" name="email[]" maxlength="120" value="<?= ose($v) ?>" placeholder="you@example.com">
        <?php endforeach; ?>
      </div>
      <div class="os-fieldgroup">
        <span class="os-grouplabel">Phone numbers <span class="os-dim">(up to <?= OSINT_MAX_PHONES ?>, with country code)</span></span>
        <?php foreach ($phones as $v): ?>
          <input type="tel" name="phone[]" maxlength="24" value="<?= ose($v) ?>" placeholder="+1 415 555 2671">
        <?php endforeach; ?>
      </div>
      <div class="os-fieldgroup">
        <span class="os-grouplabel">Domains <span class="os-dim">(up to <?= OSINT_MAX_DOMAINS ?>, ones you own)</span></span>
        <?php foreach ($domains as $v): ?>
          <input type="text" name="domain[]" maxlength="253" value="<?= ose($v) ?>" placeholder="example.com">
        <?php endforeach; ?>
      </div>
      <button type="submit" class="os-btn os-btn-accent">Save profile</button>
    </form>
  </div>
  <p class="os-fineprint">Stored only for your account, on this server, in a store that is never exposed to the web. Delete a value and save to remove it.</p>
<?php
osint_foot();
