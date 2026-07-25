<?php
require __DIR__ . '/lib/bootstrap.php';

// Logout is state-changing; require a POST + CSRF to avoid drive-by logout links.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    logout_user();
    redirect('/videos/');
}

render_header('Log out');
?>
<div class="v-auth">
  <h1>Log out?</h1>
  <form method="post" class="v-form">
    <?= csrf_field() ?>
    <button type="submit" class="v-btn v-btn-accent v-btn-lg">Log out</button>
    <a href="/videos/" class="v-btn v-btn-lg">Cancel</a>
  </form>
</div>
<?php render_footer();
