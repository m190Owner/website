<?php
require __DIR__ . '/lib/bootstrap.php';

if (current_user()) redirect('/videos/');

$error = '';
$next = $_GET['next'] ?? ($_POST['next'] ?? '/videos/');
// Same-origin only: must start with a single "/" (blocks "//evil.com" open redirects).
if (!preg_match('#^/[^/]#', (string) $next)) $next = '/videos/';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    enforceRateLimit('videos_register', 8, 3600);
    [$uid, $error] = register_user($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($uid) {
        login_user($uid);
        redirect($next);
    }
}

render_header('Sign up');
?>
<div class="v-auth">
  <h1>Create an account</h1>
  <p class="v-dim">Pick a handle and a password to start uploading.</p>
  <?php if ($error): ?><div class="v-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="v-form" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Username
      <input name="username" maxlength="16" required autofocus
             value="<?= e($_POST['username'] ?? '') ?>"
             pattern="[A-Za-z0-9_]{3,16}" title="3-16 letters, numbers, or _">
    </label>
    <label>Password
      <input name="password" type="password" minlength="8" maxlength="200" required>
    </label>
    <button type="submit" class="v-btn v-btn-accent v-btn-lg">Sign up</button>
  </form>
  <p class="v-dim">Already have an account? <a href="/videos/login.php">Log in</a>.</p>
</div>
<?php render_footer();
