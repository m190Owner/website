<?php
require __DIR__ . '/lib/bootstrap.php';

$me = require_admin();
$db = videos_db();
$q = trim($_GET['q'] ?? '');

$sql = "SELECT u.*,
          (SELECT COUNT(*) FROM videos v   WHERE v.user_id = u.id AND v.status = 'live') AS nvids,
          (SELECT COUNT(*) FROM warnings w WHERE w.user_id = u.id) AS nwarn
        FROM users u ";
if ($q !== '') {
    $sql .= "WHERE u.username LIKE :q ESCAPE '\\' ";
    $st = $db->prepare($sql . "ORDER BY u.created_at DESC LIMIT 500");
    $st->execute([':q' => '%' . like_escape($q) . '%']);
} else {
    $st = $db->query($sql . "ORDER BY u.created_at DESC LIMIT 500");
}
$users = $st->fetchAll();

function user_status(array $u): array {
    if (!empty($u['is_admin']))  return ['owner',  'v-tag-owner'];
    if (!empty($u['is_banned'])) return ['banned', 'v-tag-banned'];
    if (!empty($u['is_muted']))  return ['muted',  'v-tag-muted'];
    return ['active', ''];
}

render_header('Manage users');
?>
<div class="v-feed-head">
  <h1>Users <span class="v-dim">(<?= count($users) ?>)</span></h1>
  <a href="/videos/admin.php" class="v-btn">← Reports</a>
</div>

<form class="v-usersearch" method="get" action="/videos/admin_users.php">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by username" maxlength="32" autocomplete="off">
  <button class="v-btn">Search</button>
</form>

<div class="v-usertable">
  <div class="v-userrow v-userhead">
    <span>User</span><span>Joined</span><span>Videos</span><span>Warnings</span><span>Status</span>
  </div>
  <?php foreach ($users as $u): [$label, $cls] = user_status($u); ?>
    <a class="v-userrow" href="/videos/admin_user.php?id=<?= (int) $u['id'] ?>">
      <span class="v-usercell">
        <?= avatar_html($u['username'], $u['avatar'], 'v-chip-av') ?>
        <b><?= e($u['username']) ?></b>
      </span>
      <span class="v-dim"><?= e(time_ago((int) $u['created_at'])) ?></span>
      <span><?= (int) $u['nvids'] ?></span>
      <span class="<?= (int) $u['nwarn'] > 0 ? 'v-warn-count' : 'v-dim' ?>"><?= (int) $u['nwarn'] ?></span>
      <span class="v-tag <?= $cls ?>"><?= $label ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$users): ?><p class="v-dim v-empty">No users found.</p><?php endif; ?>
</div>
<?php render_footer();
