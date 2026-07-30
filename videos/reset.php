<?php
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) redirect('/videos/');

$sent = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    enforceRateLimit('videos_pwreset', 5, 3600);
    request_password_reset($_POST['username'] ?? '');
    $sent = true;   // always show the same message (don't reveal whether the account exists)
}

render_header('Reset password');
?>
<div class="v-auth">
  <h1>Reset your password</h1>
  <?php if ($sent): ?>
    <div class="v-flash">Request received. An admin will review it and set you a new temporary password — reach out on Discord to get it.</div>
    <p class="v-dim"><a href="/videos/login.php">Back to log in</a></p>
  <?php else: ?>
    <p class="v-dim">Forgot your password? Send a reset request. Since there's no email, an admin approves it and hands you a new temporary password.</p>
    <form method="post" class="v-form" autocomplete="off">
      <?= csrf_field() ?>
      <label>Username
        <input name="username" maxlength="16" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
      </label>
      <button type="submit" class="v-btn v-btn-accent v-btn-lg">Request reset</button>
    </form>
    <p class="v-dim">Remembered it? <a href="/videos/login.php">Log in</a>.</p>
  <?php endif; ?>
</div>
<?php render_footer();
