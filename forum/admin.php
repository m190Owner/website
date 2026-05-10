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
    enforceRateLimit('admin_action', 30, 60);
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_invite') { $code = generateInvite(); logModAction('invite', "Generated invite code $code"); $success = "Invite code generated: <strong>$code</strong>"; }
    if ($action === 'delete_invite') { if (deleteInvite($_POST['invite_code'] ?? '')) { logModAction('invite', "Deleted invite code " . ($_POST['invite_code'] ?? '')); $success = "Invite code deleted."; } else $error = "Invite not found."; }
    if ($action === 'toggle_ban') { $t = $_POST['username'] ?? ''; if (toggleBan($t)) { logModAction('ban', "Toggled ban for $t"); $success = "Toggled ban for $t."; } else $error = 'Cannot ban this user.'; }
    if ($action === 'set_role') { $t = $_POST['username'] ?? ''; $r = $_POST['role'] ?? ''; if (setUserRole($t, $r)) { logModAction('role', "Set role for $t to $r"); $success = "Updated role for $t."; } else $error = 'Failed.'; }
    if ($action === 'add_category') { $n = $_POST['cat_name'] ?? ''; $d = $_POST['cat_desc'] ?? ''; $p = $_POST['cat_parent'] ?? ''; if ($n && addCategory($n, $d, $p)) { logModAction('category', "Created category \"$n\""); $success = "Category created."; } else $error = 'Failed (may already exist).'; }
    if ($action === 'delete_category') { $cid = $_POST['cat_id'] ?? ''; logModAction('category', "Deleted category $cid"); deleteCategory($cid); $success = "Category deleted."; }
    if ($action === 'resolve_report') { resolveReport($_POST['report_id'] ?? ''); logModAction('report', "Resolved report " . ($_POST['report_id'] ?? '')); $success = "Report resolved."; }
    if ($action === 'set_title') { $t = $_POST['username'] ?? ''; $title = $_POST['custom_title'] ?? ''; setUserTitle($t, $title); logModAction('title', "Set custom title for $t: \"$title\""); $success = "Updated title for $t."; }
}

$invites = getInvites();
$users = readJsonFile(USERS_FILE, []);
$categories = getCategories();
$reports = getReports(true);
$openReports = getOpenReportCount();
$modLog = getModLog(50);
$allCategories = getCategories();

$navActive = 'admin';
$pageTitle = 'Admin Panel';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700; margin-bottom:16px;">Admin Panel</h1>

    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="tabs">
        <div class="tab active" onclick="switchTab('invites')">Invites</div>
        <div class="tab" onclick="switchTab('users')">Users</div>
        <div class="tab" onclick="switchTab('categories')">Categories</div>
        <div class="tab" onclick="switchTab('reports')">Reports<?php if ($openReports): ?> <span style="background:#ff6b6b;color:#fff;font-size:0.55rem;padding:1px 5px;border-radius:8px;font-weight:700;"><?= $openReports ?></span><?php endif; ?></div>
        <div class="tab" onclick="switchTab('modlog')">Mod Log</div>
    </div>

    <!-- INVITES -->
    <div class="tab-content active" id="tab-invites">
        <div class="card mb-4">
            <div class="card-header">
                <h2>Generate Invite</h2>
                <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="generate_invite"><button class="btn btn-primary btn-sm">+ Generate Code</button></form>
            </div>
            <div class="card-body">
                <?php if (empty($invites)): ?>
                    <div class="empty-state"><p>No invite codes yet.</p></div>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>Code</th><th>Created By</th><th>Created</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach (array_reverse($invites) as $inv): ?>
                        <tr>
                            <td><code style="color:#7aa2ff; letter-spacing:1px;"><?= e($inv['code']) ?></code>
                                <?php if (!$inv['used']): ?><button class="copy-btn" onclick="navigator.clipboard.writeText('<?= e($inv['code']) ?>')">copy</button>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this invite code?')" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="delete_invite"><input type="hidden" name="invite_code" value="<?= e($inv['code']) ?>"><button class="copy-btn" style="color:#ff6b6b;border-color:rgba(255,107,107,0.2);">delete</button></form>
                                <?php endif; ?></td>
                            <td><?= e($inv['created_by']) ?></td>
                            <td class="text-muted"><?= timeAgo($inv['created']) ?></td>
                            <td><?php if ($inv['used']): ?><span style="color:#6bffb8;font-size:0.75rem;">Used by <?= e($inv['used_by']) ?></span>
                                <?php else: ?><span style="color:#ffb86b;font-size:0.75rem;">Available</span><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- USERS -->
    <div class="tab-content" id="tab-users">
        <div class="card">
            <div class="card-header"><h2>Users (<?= count($users) ?>)</h2></div>
            <div class="card-body">
                <table class="data-table">
                    <thead><tr><th>User</th><th>Role</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($users as $key => $user): ?>
                        <tr>
                            <td><div style="display:flex;align-items:center;gap:8px;">
                                <?= avatarHtml($user['username'], 28) ?>
                                <a href="/forum/profile.php?user=<?= e($user['username']) ?>" style="color:#e5e5e5;text-decoration:none;font-weight:600;"><?= e($user['username']) ?></a>
                                <span class="online-dot <?= isUserOnline($user['username']) ? 'on' : 'off' ?>"></span>
                            </div></td>
                            <td><?= roleBadge($user['role']) ?: '<span class="text-muted text-sm">member</span>' ?></td>
                            <td class="text-muted text-sm"><?= date('M j, Y', strtotime($user['created'])) ?></td>
                            <td><?php if ($user['banned'] ?? false): ?><span style="color:#ff6b6b;font-size:0.75rem;">Banned</span>
                                <?php else: ?><span style="color:#6bffb8;font-size:0.75rem;">Active</span><?php endif; ?></td>
                            <td><?php if ($user['role'] !== 'admin'): ?>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                    <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="toggle_ban"><input type="hidden" name="username" value="<?= e($user['username']) ?>">
                                        <button class="btn btn-danger btn-sm" style="padding:3px 8px;font-size:0.68rem;"><?= ($user['banned'] ?? false) ? 'Unban' : 'Ban' ?></button></form>
                                    <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="username" value="<?= e($user['username']) ?>">
                                        <select name="role" onchange="this.form.submit()" style="background:rgba(10,10,18,0.8);border:1px solid rgba(122,162,255,0.12);color:#8a96b8;padding:3px 6px;border-radius:4px;font-size:0.68rem;">
                                            <option value="member" <?= $user['role']==='member'?'selected':'' ?>>Member</option>
                                            <option value="moderator" <?= $user['role']==='moderator'?'selected':'' ?>>Mod</option>
                                            <option value="admin" <?= $user['role']==='admin'?'selected':'' ?>>Admin</option>
                                        </select></form>
                                    <form method="POST" class="inline-form" style="display:flex;gap:3px;"><?= csrfField() ?><input type="hidden" name="action" value="set_title"><input type="hidden" name="username" value="<?= e($user['username']) ?>">
                                        <input type="text" name="custom_title" value="<?= e($user['custom_title'] ?? '') ?>" placeholder="Title..." style="background:rgba(10,10,18,0.8);border:1px solid rgba(122,162,255,0.12);color:#8a96b8;padding:3px 6px;border-radius:4px;font-size:0.68rem;width:90px;">
                                        <button class="btn btn-secondary btn-sm" style="padding:3px 6px;font-size:0.62rem;">Set</button></form>
                                </div>
                            <?php else: ?><span class="text-muted text-sm">—</span><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- CATEGORIES -->
    <div class="tab-content" id="tab-categories">
        <div class="card mb-4">
            <div class="card-header"><h2>Add Category</h2></div>
            <div style="padding:16px 20px;">
                <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                    <?= csrfField() ?><input type="hidden" name="action" value="add_category">
                    <div class="form-group" style="margin:0;flex:1;min-width:150px;"><label class="form-label">Name</label><input class="form-input" type="text" name="cat_name" required placeholder="Category name"></div>
                    <div class="form-group" style="margin:0;flex:2;min-width:200px;"><label class="form-label">Description</label><input class="form-input" type="text" name="cat_desc" required placeholder="Short description"></div>
                    <div class="form-group" style="margin:0;flex:1;min-width:120px;">
                        <label class="form-label">Parent (optional)</label>
                        <select class="form-select" name="cat_parent" style="padding:8px 10px;">
                            <option value="">None (top-level)</option>
                            <?php foreach ($allCategories as $pc): if (empty($pc['parent'])): ?>
                                <option value="<?= e($pc['id']) ?>"><?= e($pc['name']) ?></option>
                            <?php endif; endforeach; ?>
                        </select>
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
                        <?php if (!empty($cat['parent'])): ?><span class="text-muted text-sm" style="margin-right:4px;">&#8627;</span><?php endif; ?>
                        <strong style="color:#e5e5e5;"><?= e($cat['name']) ?></strong>
                        <span class="text-muted text-sm" style="margin-left:8px;"><?= e($cat['description']) ?></span>
                        <?php if (!empty($cat['parent'])): ?><span class="text-muted" style="font-size:0.65rem;margin-left:6px;">(sub of <?= e($cat['parent']) ?>)</span><?php endif; ?>
                    </div>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete category and all its threads?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="cat_id" value="<?= e($cat['id']) ?>"><button class="btn btn-danger btn-sm">Delete</button></form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- REPORTS -->
    <div class="tab-content" id="tab-reports">
        <div class="card">
            <div class="card-header"><h2>Reports</h2></div>
            <div class="card-body">
                <?php if (empty($reports)): ?>
                    <div class="empty-state"><p>No reports.</p></div>
                <?php else: ?>
                <table class="data-table">
                    <thead><tr><th>Post</th><th>Reported By</th><th>Reason</th><th>When</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($reports as $r):
                            $rThread = getThread($r['thread_id']);
                        ?>
                        <tr>
                            <td><?php if ($rThread): ?><a href="/forum/thread.php?id=<?= e($r['thread_id']) ?>#post-<?= e($r['post_id']) ?>" style="color:#7aa2ff;text-decoration:none;"><?= e(mb_strimwidth($rThread['title'], 0, 30, '...')) ?></a><?php else: ?><span class="text-muted">Deleted</span><?php endif; ?></td>
                            <td><?= e($r['reported_by']) ?></td>
                            <td style="max-width:200px;"><?= e(mb_strimwidth($r['reason'], 0, 80, '...')) ?></td>
                            <td class="text-muted text-sm"><?= timeAgo($r['created']) ?></td>
                            <td>
                                <?php if ($r['resolved']): ?>
                                    <span style="color:#6bffb8;font-size:0.75rem;">Resolved by <?= e($r['resolved_by']) ?></span>
                                <?php else: ?>
                                    <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="resolve_report"><input type="hidden" name="report_id" value="<?= e($r['id']) ?>"><button class="btn btn-secondary btn-sm" style="padding:3px 8px;font-size:0.68rem;">Resolve</button></form>
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

    <!-- MOD LOG -->
    <div class="tab-content" id="tab-modlog">
        <div class="card">
            <div class="card-header"><h2>Moderation Log</h2></div>
            <div class="card-body">
                <?php if (empty($modLog)): ?>
                    <div class="empty-state"><p>No moderation actions yet.</p></div>
                <?php else: ?>
                    <?php foreach ($modLog as $entry): ?>
                    <div class="modlog-entry">
                        <span class="modlog-action"><?= e($entry['action']) ?></span>
                        <div class="modlog-details"><?= e($entry['details']) ?></div>
                        <div class="modlog-meta">by <?= e($entry['actor']) ?><?php if ($entry['ip'] ?? ''): ?> <span style="color:#3a4060;">(<?= e($entry['ip']) ?>)</span><?php endif; ?> &middot; <?= timeAgo($entry['created']) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('active')});
    document.querySelectorAll('.tab-content').forEach(function(t){t.classList.remove('active')});
    event.target.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
