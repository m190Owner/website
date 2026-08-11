<?php
require __DIR__ . '/lib/bootstrap.php';

$u = require_login();
$error = ''; $ok = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    enforceRateLimit('videos_pwchange', 10, 600);
    $error = change_password((int) $u['id'], $_POST['current'] ?? '', $_POST['new'] ?? '', $_POST['confirm'] ?? '') ?? '';
    if ($error === '') {
        videos_session_start();
        session_regenerate_id(true);   // rotate the session after a credential change
        audit_log('pw_change', 'info', [
            'actor' => $u['username'], 'actor_uid' => (int) $u['id'],
            'detail' => 'User changed their own password',
        ]);
        $ok = true;
    }
}

render_header('Change password');
?>
<div class="v-auth">
  <h1>Change password</h1>
  <?php if ($ok): ?>
    <div class="v-flash">✅ Your password has been changed.</div>
    <p class="v-dim"><a href="/videos/channel.php?u=<?= urlencode($u['username']) ?>">Back to your channel</a>.</p>
  <?php else: ?>
    <p class="v-dim">Set a new password for <b><?= e($u['username']) ?></b>.</p>
    <?php if ($error): ?><div class="v-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="v-form" autocomplete="off">
      <?= csrf_field() ?>
      <label>Current password
        <input name="current" type="password" maxlength="200" required autofocus autocomplete="current-password">
      </label>
      <label>New password
        <input name="new" type="password" minlength="8" maxlength="200" required autocomplete="new-password">
      </label>
      <label>Confirm new password
        <input name="confirm" type="password" minlength="8" maxlength="200" required autocomplete="new-password">
      </label>
      <div class="v-form-row">
        <button type="submit" class="v-btn v-btn-accent v-btn-lg">Change password</button>
        <a href="/videos/settings.php" class="v-btn v-btn-lg">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php render_footer();
