<?php
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) redirect('/videos/');

$error = '';
$next = $_GET['next'] ?? ($_POST['next'] ?? '/videos/');
// Same-origin only: must start with a single "/" (blocks "//evil.com" open redirects).
if (!preg_match('#^/[^/]#', (string) $next)) $next = '/videos/';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    enforceRateLimit('videos_login', 10, 600);
    [$uid, $error] = authenticate($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($uid) {
        login_user($uid);
        redirect($next);
    }
}

render_header('Log in');
?>
<div class="v-auth">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="v-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="v-form" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Username
      <input name="username" maxlength="16" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
    </label>
    <label>Password
      <input name="password" type="password" maxlength="200" required>
    </label>
    <button type="submit" class="v-btn v-btn-accent v-btn-lg">Log in</button>
  </form>
  <p class="v-dim">No account yet? <a href="/videos/register.php">Sign up</a>.</p>
  <p class="v-dim"><a href="/videos/reset.php">Forgot password?</a></p>
</div>
<?php render_footer();
