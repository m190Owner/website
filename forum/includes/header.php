<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Forum') ?> | Logan Sandivar</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0b0b0f;
            color: #c8ccd4;
            min-height: 100vh;
        }

        /* ---- NAV ---- */
        .forum-nav {
            position: sticky; top: 0; z-index: 100;
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 30px;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(122,162,255,0.12);
        }
        .forum-nav-left { display: flex; align-items: center; gap: 20px; }
        .forum-nav-brand {
            color: #7aa2ff; font-weight: 800; font-size: 0.9rem;
            letter-spacing: 2.5px; text-transform: uppercase;
            text-decoration: none;
            text-shadow: 0 0 20px rgba(122,162,255,0.25);
        }
        .forum-nav-links { display: flex; gap: 6px; }
        .forum-nav-links a {
            color: #8a96b8; text-decoration: none; font-size: 0.78rem;
            padding: 5px 12px; border-radius: 6px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .forum-nav-links a:hover, .forum-nav-links a.active {
            color: #7aa2ff;
            background: rgba(122,162,255,0.08);
            border-color: rgba(122,162,255,0.15);
        }
        .forum-nav-right { display: flex; align-items: center; gap: 12px; }
        .forum-nav-user {
            color: #e5e5e5; font-size: 0.8rem; font-weight: 600;
        }
        .nav-btn {
            background: rgba(122,162,255,0.1); color: #7aa2ff;
            border: 1px solid rgba(122,162,255,0.2);
            padding: 5px 14px; border-radius: 6px;
            font-size: 0.75rem; cursor: pointer;
            text-decoration: none; transition: all 0.2s;
        }
        .nav-btn:hover {
            background: rgba(122,162,255,0.2);
            border-color: rgba(122,162,255,0.35);
        }
        .nav-btn-primary {
            background: linear-gradient(135deg, #7aa2ff, #5a80cc);
            color: #fff; border: none;
        }
        .nav-btn-primary:hover { opacity: 0.9; }

        /* ---- LAYOUT ---- */
        .forum-wrap {
            max-width: 960px; margin: 0 auto;
            padding: 25px 20px 60px;
        }

        /* ---- BREADCRUMBS ---- */
        .breadcrumbs {
            display: flex; align-items: center; gap: 8px;
            font-size: 0.75rem; color: #5a6480;
            margin-bottom: 20px;
        }
        .breadcrumbs a { color: #7aa2ff; text-decoration: none; }
        .breadcrumbs a:hover { text-decoration: underline; }
        .breadcrumbs .sep { color: #3a4060; }

        /* ---- CARDS ---- */
        .card {
            background: rgba(17,17,24,0.75);
            border: 1px solid rgba(122,162,255,0.08);
            border-radius: 10px;
            backdrop-filter: blur(6px);
            overflow: hidden;
        }
        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid rgba(122,162,255,0.06);
            display: flex; justify-content: space-between; align-items: center;
        }
        .card-header h2 {
            font-size: 0.85rem; font-weight: 700; color: #7aa2ff;
            letter-spacing: 1.5px; text-transform: uppercase;
        }
        .card-body { padding: 0; }

        /* ---- CATEGORY ROW ---- */
        .cat-row {
            display: flex; align-items: center; gap: 16px;
            padding: 16px 20px;
            border-bottom: 1px solid rgba(122,162,255,0.04);
            transition: background 0.15s;
        }
        .cat-row:last-child { border-bottom: none; }
        .cat-row:hover { background: rgba(122,162,255,0.03); }
        .cat-icon {
            width: 40px; height: 40px; border-radius: 8px;
            background: rgba(122,162,255,0.08);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .cat-info { flex: 1; min-width: 0; }
        .cat-name {
            color: #e5e5e5; font-weight: 600; font-size: 0.9rem;
            text-decoration: none; display: block;
        }
        .cat-name:hover { color: #7aa2ff; }
        .cat-desc { color: #5a6480; font-size: 0.75rem; margin-top: 2px; }
        .cat-stats {
            display: flex; gap: 20px; flex-shrink: 0;
            text-align: center;
        }
        .cat-stat-num { color: #7aa2ff; font-weight: 700; font-size: 0.9rem; display: block; }
        .cat-stat-label { color: #5a6480; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .cat-last-post {
            width: 180px; flex-shrink: 0;
            font-size: 0.72rem; color: #5a6480;
        }
        .cat-last-post a { color: #8a96b8; text-decoration: none; }
        .cat-last-post a:hover { color: #7aa2ff; }

        /* ---- THREAD ROW ---- */
        .thread-row {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 20px;
            border-bottom: 1px solid rgba(122,162,255,0.04);
            transition: background 0.15s;
        }
        .thread-row:last-child { border-bottom: none; }
        .thread-row:hover { background: rgba(122,162,255,0.03); }
        .thread-info { flex: 1; min-width: 0; }
        .thread-title-row { display: flex; align-items: center; gap: 8px; }
        .thread-title {
            color: #e5e5e5; font-weight: 600; font-size: 0.85rem;
            text-decoration: none; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .thread-title:hover { color: #7aa2ff; }
        .thread-meta { color: #5a6480; font-size: 0.7rem; margin-top: 3px; }
        .thread-meta a { color: #7aa2ff; text-decoration: none; }
        .thread-meta a:hover { text-decoration: underline; }
        .thread-stats { display: flex; gap: 16px; flex-shrink: 0; text-align: center; }
        .thread-last {
            width: 150px; flex-shrink: 0;
            font-size: 0.7rem; color: #5a6480;
        }
        .thread-last a { color: #8a96b8; text-decoration: none; }
        .thread-last a:hover { color: #7aa2ff; }
        .pin-tag, .lock-tag {
            font-size: 0.6rem; padding: 2px 6px; border-radius: 4px;
            font-weight: 700; letter-spacing: 0.5px; flex-shrink: 0;
        }
        .pin-tag { background: rgba(122,162,255,0.12); color: #7aa2ff; }
        .lock-tag { background: rgba(255,107,107,0.12); color: #ff6b6b; }

        /* ---- POSTS ---- */
        .post {
            display: flex; gap: 16px;
            padding: 20px;
            border-bottom: 1px solid rgba(122,162,255,0.05);
        }
        .post:last-child { border-bottom: none; }
        .post-sidebar { flex-shrink: 0; text-align: center; width: 80px; }
        .post-author-link {
            color: #e5e5e5; font-size: 0.78rem; font-weight: 600;
            text-decoration: none; display: block; margin-top: 6px;
            word-break: break-all;
        }
        .post-author-link:hover { color: #7aa2ff; }
        .post-role { font-size: 0.6rem; color: #5a6480; margin-top: 2px; }
        .post-body { flex: 1; min-width: 0; }
        .post-content {
            font-size: 0.85rem; line-height: 1.65; color: #d0d4dc;
            word-break: break-word;
        }
        .post-content pre.code-block {
            background: rgba(0,0,0,0.4); padding: 12px 14px;
            border-radius: 6px; font-family: 'Consolas', 'Courier New', monospace;
            font-size: 0.8rem; overflow-x: auto; margin: 8px 0;
            border: 1px solid rgba(122,162,255,0.08);
        }
        .post-content code {
            background: rgba(122,162,255,0.08); padding: 2px 6px;
            border-radius: 3px; font-family: 'Consolas', monospace;
            font-size: 0.82em;
        }
        .post-content a { color: #7aa2ff; }
        .post-footer {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 12px; padding-top: 10px;
            border-top: 1px solid rgba(122,162,255,0.04);
            font-size: 0.7rem; color: #5a6480;
        }
        .post-actions { display: flex; gap: 8px; }
        .post-action-btn {
            background: none; border: none; color: #5a6480;
            font-size: 0.68rem; cursor: pointer; padding: 2px 6px;
            border-radius: 4px; transition: all 0.15s;
        }
        .post-action-btn:hover { color: #ff6b6b; background: rgba(255,107,107,0.08); }

        /* ---- AVATAR ---- */
        .avatar {
            border-radius: 8px; text-align: center;
            font-weight: 800; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
        }

        /* ---- BADGE ---- */
        .badge {
            font-size: 0.58rem; padding: 2px 7px; border-radius: 4px;
            font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;
            vertical-align: middle;
        }
        .badge-admin { background: rgba(255,107,107,0.12); color: #ff6b6b; }
        .badge-mod { background: rgba(107,255,184,0.12); color: #6bffb8; }

        /* ---- FORMS ---- */
        .form-group { margin-bottom: 16px; }
        .form-label {
            display: block; font-size: 0.75rem; font-weight: 600;
            color: #8a96b8; margin-bottom: 6px;
            letter-spacing: 0.5px; text-transform: uppercase;
        }
        .form-input, .form-textarea, .form-select {
            width: 100%; padding: 10px 14px;
            background: rgba(10,10,18,0.8);
            border: 1px solid rgba(122,162,255,0.12);
            border-radius: 7px; color: #e5e5e5;
            font-size: 0.85rem; font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: rgba(122,162,255,0.4);
            box-shadow: 0 0 12px rgba(122,162,255,0.12);
        }
        .form-textarea { resize: vertical; min-height: 120px; line-height: 1.55; }
        .form-hint { font-size: 0.68rem; color: #5a6480; margin-top: 4px; }

        /* ---- BUTTONS ---- */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 20px; border: none; border-radius: 7px;
            font-size: 0.8rem; font-weight: 600; cursor: pointer;
            text-decoration: none; transition: all 0.2s;
            font-family: inherit;
        }
        .btn-primary {
            background: linear-gradient(135deg, #7aa2ff, #5a80cc);
            color: #fff;
        }
        .btn-primary:hover { opacity: 0.88; transform: translateY(-1px); }
        .btn-secondary {
            background: rgba(122,162,255,0.08);
            color: #7aa2ff;
            border: 1px solid rgba(122,162,255,0.18);
        }
        .btn-secondary:hover {
            background: rgba(122,162,255,0.15);
            border-color: rgba(122,162,255,0.3);
        }
        .btn-danger {
            background: rgba(255,107,107,0.1);
            color: #ff6b6b;
            border: 1px solid rgba(255,107,107,0.2);
        }
        .btn-danger:hover { background: rgba(255,107,107,0.2); }
        .btn-sm { padding: 5px 12px; font-size: 0.72rem; }

        /* ---- ALERT ---- */
        .alert {
            padding: 12px 16px; border-radius: 8px;
            font-size: 0.8rem; margin-bottom: 16px;
        }
        .alert-error {
            background: rgba(255,107,107,0.1);
            border: 1px solid rgba(255,107,107,0.2);
            color: #ff6b6b;
        }
        .alert-success {
            background: rgba(107,255,184,0.1);
            border: 1px solid rgba(107,255,184,0.2);
            color: #6bffb8;
        }

        /* ---- PAGINATION ---- */
        .pagination {
            display: flex; gap: 4px; justify-content: center;
            padding: 16px;
        }
        .pagination a, .pagination span {
            padding: 6px 12px; border-radius: 5px;
            font-size: 0.78rem; text-decoration: none;
            border: 1px solid rgba(122,162,255,0.1);
            color: #8a96b8; transition: all 0.15s;
        }
        .pagination a:hover {
            background: rgba(122,162,255,0.1);
            border-color: rgba(122,162,255,0.25);
            color: #7aa2ff;
        }
        .pagination .active {
            background: rgba(122,162,255,0.15);
            border-color: rgba(122,162,255,0.3);
            color: #7aa2ff; font-weight: 700;
        }

        /* ---- EMPTY STATE ---- */
        .empty-state {
            text-align: center; padding: 40px 20px;
            color: #5a6480; font-size: 0.85rem;
        }
        .empty-state p { margin-bottom: 14px; }

        /* ---- TABS ---- */
        .tabs { display: flex; gap: 2px; margin-bottom: 20px; }
        .tab {
            padding: 9px 18px; border-radius: 7px 7px 0 0;
            background: rgba(17,17,24,0.5);
            color: #5a6480; font-size: 0.78rem; font-weight: 600;
            cursor: pointer; border: 1px solid transparent;
            border-bottom: none; transition: all 0.15s;
        }
        .tab:hover { color: #8a96b8; }
        .tab.active {
            background: rgba(17,17,24,0.75);
            color: #7aa2ff;
            border-color: rgba(122,162,255,0.08);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ---- TABLE ---- */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            text-align: left; padding: 10px 16px;
            font-size: 0.68rem; font-weight: 700;
            color: #5a6480; text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(122,162,255,0.08);
        }
        .data-table td {
            padding: 10px 16px; font-size: 0.8rem;
            border-bottom: 1px solid rgba(122,162,255,0.04);
        }
        .data-table tr:hover td { background: rgba(122,162,255,0.02); }

        /* ---- PROFILE ---- */
        .profile-header {
            display: flex; align-items: center; gap: 20px;
            padding: 24px;
        }
        .profile-info h1 { font-size: 1.2rem; color: #e5e5e5; font-weight: 700; }
        .profile-stats {
            display: flex; gap: 24px; margin-top: 8px;
        }
        .profile-stat-val { color: #7aa2ff; font-weight: 700; }

        /* ---- AUTH PAGE ---- */
        .auth-wrap {
            max-width: 400px; margin: 60px auto;
            padding: 0 20px;
        }
        .auth-card { padding: 30px; }
        .auth-card h1 {
            font-size: 1.1rem; color: #e5e5e5; font-weight: 700;
            margin-bottom: 4px;
        }
        .auth-card .subtitle {
            color: #5a6480; font-size: 0.78rem; margin-bottom: 24px;
        }
        .auth-footer {
            text-align: center; margin-top: 16px;
            font-size: 0.78rem; color: #5a6480;
        }
        .auth-footer a { color: #7aa2ff; text-decoration: none; }
        .auth-footer a:hover { text-decoration: underline; }

        /* ---- MISC ---- */
        .text-muted { color: #5a6480; }
        .text-sm { font-size: 0.75rem; }
        .mt-2 { margin-top: 8px; }
        .mt-4 { margin-top: 16px; }
        .mb-4 { margin-bottom: 16px; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        .inline-form { display: inline; }

        /* ---- COPY BUTTON ---- */
        .copy-btn {
            background: rgba(122,162,255,0.1); border: 1px solid rgba(122,162,255,0.2);
            color: #7aa2ff; padding: 3px 8px; border-radius: 4px;
            font-size: 0.68rem; cursor: pointer; transition: all 0.15s;
        }
        .copy-btn:hover { background: rgba(122,162,255,0.2); }

        /* ---- RESPONSIVE ---- */
        @media (max-width: 700px) {
            .forum-nav { padding: 10px 14px; flex-wrap: wrap; gap: 8px; }
            .forum-wrap { padding: 14px 10px 40px; }
            .cat-stats, .cat-last-post, .thread-stats, .thread-last { display: none; }
            .post { flex-direction: column; gap: 8px; }
            .post-sidebar { width: auto; display: flex; align-items: center; gap: 10px; text-align: left; }
            .profile-header { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <nav class="forum-nav">
        <div class="forum-nav-left">
            <a href="/forum/" class="forum-nav-brand">Forum</a>
            <div class="forum-nav-links">
                <a href="/" class="<?= ($navActive ?? '') === 'home' ? 'active' : '' ?>">Main Site</a>
                <a href="/forum/" class="<?= ($navActive ?? '') === 'forum' ? 'active' : '' ?>">Categories</a>
                <?php if (isLoggedIn()): ?>
                    <a href="/forum/profile.php?user=<?= e(currentUser()) ?>" class="<?= ($navActive ?? '') === 'profile' ? 'active' : '' ?>">Profile</a>
                    <?php if (isAdmin()): ?>
                        <a href="/forum/admin.php" class="<?= ($navActive ?? '') === 'admin' ? 'active' : '' ?>">Admin</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="forum-nav-right">
            <?php if (isLoggedIn()): ?>
                <span class="forum-nav-user"><?= e(currentUser()) ?></span>
                <a href="/forum/logout.php" class="nav-btn">Logout</a>
            <?php else: ?>
                <a href="/forum/login.php" class="nav-btn">Login</a>
                <a href="/forum/register.php" class="nav-btn nav-btn-primary">Register</a>
            <?php endif; ?>
        </div>
    </nav>
