<?php
require __DIR__ . '/lib/bootstrap.php';

$u = require_login();
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    $about = trim($_POST['about'] ?? '');
    if (mb_strlen($about) > ABOUT_MAX) {
        $error = 'About is too long (max ' . ABOUT_MAX . ' characters).';
    } elseif ($about !== '' && containsProfanity($about)) {
        $error = 'Please remove profanity from your About section.';
    }

    $avatarName = $u['avatar'];
    if (!$error && !empty($_FILES['avatar']['name'])) {
        $stored = store_uploaded_image($_FILES['avatar'], VIDEOS_AVATAR_DIR, 'u' . $u['id'] . '_' . random_slug(6), AVATAR_MAX_BYTES);
        if ($stored === null) {
            $error = 'Profile picture must be a JPG, PNG, or WebP image under ' . human_size(AVATAR_MAX_BYTES) . '.';
        } else {
            if ($u['avatar'] !== '') @unlink(VIDEOS_AVATAR_DIR . '/' . basename($u['avatar']));
            $avatarName = $stored;
        }
    } elseif (!$error && !empty($_POST['remove_avatar'])) {
        if ($u['avatar'] !== '') @unlink(VIDEOS_AVATAR_DIR . '/' . basename($u['avatar']));
        $avatarName = '';
    }

    if (!$error) {
        videos_db()->prepare("UPDATE users SET about = ?, avatar = ? WHERE id = ?")
                   ->execute([$about, $avatarName, $u['id']]);
        redirect('/videos/channel.php?u=' . urlencode($u['username']));
    }
    // Reflect the just-submitted values back into the form on error.
    $u['about'] = $about;
}

render_header('Edit profile');
?>
<div class="v-upload">
  <h1>Edit profile</h1>
  <?php if ($error): ?><div class="v-error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="v-form">
    <?= csrf_field() ?>

    <div class="v-settings-avatar">
      <?= avatar_html($u['username'], $u['avatar'], 'v-avatar') ?>
      <div>
        <label>Profile picture
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
        </label>
        <div class="v-dim">JPG, PNG, or WebP · up to <?= human_size(AVATAR_MAX_BYTES) ?></div>
        <?php if ($u['avatar'] !== ''): ?>
          <label class="v-checkline"><input type="checkbox" name="remove_avatar" value="1"> Remove current picture</label>
        <?php endif; ?>
      </div>
    </div>

    <label>About me
      <textarea name="about" rows="6" maxlength="<?= ABOUT_MAX ?>"
                placeholder="Tell people about yourself and your channel…"><?= e($u['about']) ?></textarea>
    </label>

    <div class="v-form-row">
      <button type="submit" class="v-btn v-btn-accent v-btn-lg">Save</button>
      <a href="/videos/channel.php?u=<?= urlencode($u['username']) ?>" class="v-btn v-btn-lg">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer();
