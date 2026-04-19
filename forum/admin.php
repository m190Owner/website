<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!isAdmin()) {
    http_response_code(403);
    $pageTitle = 'Forbidden';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="forum-wrap"><div class="alert alert-error">Admin access required.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_invite') {
        $code = generateInvite();
        $success = "Invite code generated: <strong>$code</strong>";
    }

    if ($action === 'toggle_ban') {
        $target = $_POST['username'] ?? '';
        if (toggleBan($target)) {
            $success = "Toggled ban status for $target.";
        } else {
            $error = 'Cannot ban this user.';
        }
    }

    if ($action === 'set_role') {
        $target = $_POST['username'] ?? '';
        $role = $_POST['role'] ?? '';
        if (setUserRole($target, $role)) {
            $success = "Updated role for $target to $role.";
        } else {
            $error = 'Failed to update role.';
        }
    }

    if ($action === 'add_category') {
        $name = $_POST['cat_name'] ?? '';
        $desc = $_POST['cat_desc'] ?? '';
        if ($name && addCategory($name, $desc)) {
            $success = "Category '$name' created.";
        } else {
            $error = 'Failed to create category (may already exist).';
        }
    }

    if ($action === 'delete_category') {
        $catId = $_POST['cat_id'] ?? '';
        deleteCategory($catId);
        $success = "Category deleted.";
    }
}

$invites = getInvites();
$users = readJsonFile(USERS_FILE, []);
$categories = getCategories();

$navActive = 'admin';
$pageTitle = 'Admin Panel';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700; margin-bottom:16px;">Admin Panel</h1>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="tabs">
        <div class="tab active" onclick="switchTab('invites')">Invites</div>
        <div class="tab" onclick="switchTab('users')">Users</div>
        <div class="tab" onclick="switchTab('categories')">Categories</div>
    </div>

    <!-- INVITES TAB -->
    <div class="tab-content active" id="tab-invites">
        <div class="card mb-4">
            <div class="card-header">
                <h2>Generate Invite</h2>
                <form method="POST" class="inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="generate_invite">
                    <button class="btn btn-primary btn-sm">+ Generate Code</button>
                </form>
            </div>
            <div class="card-body">
                <?php if (empty($invites)): ?>
                    <div class="empty-state"><p>No invite codes generated yet.</p></div>
                <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr><th>Code</th><th>Created By</th><th>Created</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($invites) as $inv): ?>
                        <tr>
                            <td>
                                <code style="color:#7aa2ff; letter-spacing:1px;"><?= e($inv['code']) ?></code>
                                <?php if (!$inv['used']): ?>
                                    <button class="copy-btn" onclick="navigator.clipboard.writeText('<?= e($inv['code']) ?>')">copy</button>
                                <?php endif; ?>
                            </td>
                            <td><?= e($inv['created_by']) ?></td>
                            <td class="text-muted"><?= timeAgo($inv['created']) ?></td>
                            <td>
                                <?php if ($inv['used']): ?>
                                    <span style="color:#6bffb8; font-size:0.75rem;">Used by <?= e($inv['used_by']) ?></span>
                                <?php else: ?>
                                    <span style="color:#ffb86b; font-size:0.75rem;">Available</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- USERS TAB -->
    <div class="tab-content" id="tab-users">
        <div class="card">
            <div class="card-header">
                <h2>Users (<?= count($users) ?>)</h2>
            </div>
            <div class="card-body">
                <table class="data-table">
                    <thead>
                        <tr><th>User</th><th>Role</th><th>Joined</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $key => $user): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <?= avatarHtml($user['username'], 28) ?>
                                    <a href="/forum/profile.php?user=<?= e($user['username']) ?>" style="color:#e5e5e5; text-decoration:none; font-weight:600;"><?= e($user['username']) ?></a>
                                </div>
                            </td>
                            <td><?= roleBadge($user['role']) ?: '<span class="text-muted text-sm">member</span>' ?></td>
                            <td class="text-muted text-sm"><?= date('M j, Y', strtotime($user['created'])) ?></td>
                            <td>
                                <?php if ($user['banned'] ?? false): ?>
                                    <span style="color:#ff6b6b; font-size:0.75rem;">Banned</span>
                                <?php else: ?>
                                    <span style="color:#6bffb8; font-size:0.75rem;">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['role'] !== 'admin'): ?>
                                <div style="display:flex; gap:4px;">
                                    <form method="POST" class="inline-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_ban">
                                        <input type="hidden" name="username" value="<?= e($user['username']) ?>">
                                        <button class="btn btn-danger btn-sm" style="padding:3px 8px; font-size:0.68rem;">
                                            <?= ($user['banned'] ?? false) ? 'Unban' : 'Ban' ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="set_role">
                                        <input type="hidden" name="username" value="<?= e($user['username']) ?>">
                                        <select name="role" onchange="this.form.submit()" style="background:rgba(10,10,18,0.8); border:1px solid rgba(122,162,255,0.12); color:#8a96b8; padding:3px 6px; border-radius:4px; font-size:0.68rem;">
                                            <option value="member" <?= $user['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                            <option value="moderator" <?= $user['role'] === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                    </form>
                                </div>
                                <?php else: ?>
                                    <span class="text-muted text-sm">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- CATEGORIES TAB -->
    <div class="tab-content" id="tab-categories">
        <div class="card mb-4">
            <div class="card-header">
                <h2>Add Category</h2>
            </div>
            <div style="padding:16px 20px;">
                <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_category">
                    <div class="form-group" style="margin:0; flex:1; min-width:150px;">
                        <label class="form-label" for="cat_name">Name</label>
                        <input class="form-input" type="text" id="cat_name" name="cat_name" required placeholder="Category name">
                    </div>
                    <div class="form-group" style="margin:0; flex:2; min-width:200px;">
                        <label class="form-label" for="cat_desc">Description</label>
                        <input class="form-input" type="text" id="cat_desc" name="cat_desc" required placeholder="Short description">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Add</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Existing Categories</h2></div>
            <div class="card-body">
                <?php foreach ($categories as $cat): ?>
                <div class="thread-row" style="justify-content:space-between;">
                    <div>
                        <strong style="color:#e5e5e5;"><?= e($cat['name']) ?></strong>
                        <span class="text-muted text-sm" style="margin-left:8px;"><?= e($cat['description']) ?></span>
                        <span class="text-muted text-sm" style="margin-left:8px;">(<?= e($cat['id']) ?>)</span>
                    </div>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete category and all its threads?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="cat_id" value="<?= e($cat['id']) ?>">
                        <button class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
