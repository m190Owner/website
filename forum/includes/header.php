<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'm190') ?> | m190</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0b0b0f; color: #c8ccd4; min-height: 100vh;
        }
        /* NAV */
        .forum-nav {
            position: sticky; top: 0; z-index: 100;
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 30px;
            background: rgba(0,0,0,0.65); backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(122,162,255,0.12);
        }
        .forum-nav-left { display: flex; align-items: center; gap: 20px; }
        .forum-nav-brand {
            color: #7aa2ff; font-weight: 800; font-size: 0.9rem;
            letter-spacing: 2.5px; text-transform: uppercase;
            text-decoration: none; text-shadow: 0 0 20px rgba(122,162,255,0.25);
        }
        .forum-nav-links { display: flex; gap: 6px; }
        .forum-nav-links a {
            color: #8a96b8; text-decoration: none; font-size: 0.78rem;
            padding: 5px 12px; border-radius: 6px; transition: all 0.2s;
            border: 1px solid transparent; position: relative;
        }
        .forum-nav-links a:hover, .forum-nav-links a.active {
            color: #7aa2ff; background: rgba(122,162,255,0.08);
            border-color: rgba(122,162,255,0.15);
        }
        .nav-badge {
            position: absolute; top: -4px; right: -4px;
            background: #ff6b6b; color: #fff; font-size: 0.55rem;
            padding: 1px 5px; border-radius: 8px; font-weight: 700;
        }
        .forum-nav-right { display: flex; align-items: center; gap: 12px; }
        .forum-nav-user { color: #e5e5e5; font-size: 0.8rem; font-weight: 600; }
        .nav-btn {
            background: rgba(122,162,255,0.1); color: #7aa2ff;
            border: 1px solid rgba(122,162,255,0.2);
            padding: 5px 14px; border-radius: 6px;
            font-size: 0.75rem; cursor: pointer; text-decoration: none; transition: all 0.2s;
        }
        .nav-btn:hover { background: rgba(122,162,255,0.2); border-color: rgba(122,162,255,0.35); }
        .nav-btn-primary { background: linear-gradient(135deg, #7aa2ff, #5a80cc); color: #fff; border: none; }
        .nav-btn-primary:hover { opacity: 0.9; }
        /* LAYOUT */
        .forum-wrap { max-width: 960px; margin: 0 auto; padding: 25px 20px 60px; }
        /* BREADCRUMBS */
        .breadcrumbs { display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: #5a6480; margin-bottom: 20px; flex-wrap: wrap; }
        .breadcrumbs a { color: #7aa2ff; text-decoration: none; }
        .breadcrumbs a:hover { text-decoration: underline; }
        .breadcrumbs .sep { color: #3a4060; }
        /* CARDS */
        .card { background: rgba(17,17,24,0.75); border: 1px solid rgba(122,162,255,0.08); border-radius: 10px; backdrop-filter: blur(6px); overflow: hidden; }
        .card-header { padding: 14px 20px; border-bottom: 1px solid rgba(122,162,255,0.06); display: flex; justify-content: space-between; align-items: center; }
        .card-header h2 { font-size: 0.85rem; font-weight: 700; color: #7aa2ff; letter-spacing: 1.5px; text-transform: uppercase; }
        .card-body { padding: 0; }
        /* CATEGORY ROW */
        .cat-row { display: flex; align-items: center; gap: 16px; padding: 16px 20px; border-bottom: 1px solid rgba(122,162,255,0.04); transition: background 0.15s; }
        .cat-row:last-child { border-bottom: none; }
        .cat-row:hover { background: rgba(122,162,255,0.03); }
        .cat-icon { width: 40px; height: 40px; border-radius: 8px; background: rgba(122,162,255,0.08); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .cat-info { flex: 1; min-width: 0; }
        .cat-name { color: #e5e5e5; font-weight: 600; font-size: 0.9rem; text-decoration: none; display: block; }
        .cat-name:hover { color: #7aa2ff; }
        .cat-desc { color: #5a6480; font-size: 0.75rem; margin-top: 2px; }
        .cat-stats { display: flex; gap: 20px; flex-shrink: 0; text-align: center; }
        .cat-stat-num { color: #7aa2ff; font-weight: 700; font-size: 0.9rem; display: block; }
        .cat-stat-label { color: #5a6480; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .cat-last-post { width: 180px; flex-shrink: 0; font-size: 0.72rem; color: #5a6480; }
        .cat-last-post a { color: #8a96b8; text-decoration: none; }
        .cat-last-post a:hover { color: #7aa2ff; }
        /* THREAD ROW */
        .thread-row { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-bottom: 1px solid rgba(122,162,255,0.04); transition: background 0.15s; }
        .thread-row:last-child { border-bottom: none; }
        .thread-row:hover { background: rgba(122,162,255,0.03); }
        .thread-info { flex: 1; min-width: 0; }
        .thread-title-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .thread-title { color: #e5e5e5; font-weight: 600; font-size: 0.85rem; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .thread-title:hover { color: #7aa2ff; }
        .thread-meta { color: #5a6480; font-size: 0.7rem; margin-top: 3px; }
        .thread-meta a { color: #7aa2ff; text-decoration: none; }
        .thread-meta a:hover { text-decoration: underline; }
        .thread-stats { display: flex; gap: 16px; flex-shrink: 0; text-align: center; }
        .thread-last { width: 150px; flex-shrink: 0; font-size: 0.7rem; color: #5a6480; }
        .thread-last a { color: #8a96b8; text-decoration: none; }
        .thread-last a:hover { color: #7aa2ff; }
        .pin-tag, .lock-tag { font-size: 0.6rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; letter-spacing: 0.5px; flex-shrink: 0; }
        .pin-tag { background: rgba(122,162,255,0.12); color: #7aa2ff; }
        .lock-tag { background: rgba(255,107,107,0.12); color: #ff6b6b; }
        /* THREAD TAGS */
        .thread-tag { font-size: 0.6rem; padding: 2px 7px; border-radius: 4px; font-weight: 600; border: 1px solid; flex-shrink: 0; }
        /* POSTS */
        .post { display: flex; gap: 16px; padding: 20px; border-bottom: 1px solid rgba(122,162,255,0.05); }
        .post:last-child { border-bottom: none; }
        .post:target { background: rgba(122,162,255,0.04); }
        .post-sidebar { flex-shrink: 0; text-align: center; width: 80px; }
        .post-author-link { color: #e5e5e5; font-size: 0.78rem; font-weight: 600; text-decoration: none; display: block; margin-top: 6px; word-break: break-all; }
        .post-author-link:hover { color: #7aa2ff; }
        .post-role { font-size: 0.6rem; color: #5a6480; margin-top: 2px; }
        .post-rank { font-size: 0.58rem; margin-top: 1px; font-weight: 600; }
        .post-body { flex: 1; min-width: 0; }
        .post-content { font-size: 0.85rem; line-height: 1.65; color: #d0d4dc; word-break: break-word; }
        .post-content pre.code-block { background: rgba(0,0,0,0.4); padding: 12px 14px; border-radius: 6px; font-family: 'Consolas','Courier New',monospace; font-size: 0.8rem; overflow-x: auto; margin: 8px 0; border: 1px solid rgba(122,162,255,0.08); }
        .post-content code { background: rgba(122,162,255,0.08); padding: 2px 6px; border-radius: 3px; font-family: 'Consolas',monospace; font-size: 0.82em; }
        .post-content a { color: #7aa2ff; }
        .post-content .post-image { max-width: 100%; max-height: 400px; border-radius: 8px; margin: 8px 0; cursor: pointer; border: 1px solid rgba(122,162,255,0.1); }
        .post-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 10px; border-top: 1px solid rgba(122,162,255,0.04); font-size: 0.7rem; color: #5a6480; flex-wrap: wrap; gap: 8px; }
        .post-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .post-action-btn { background: none; border: none; color: #5a6480; font-size: 0.68rem; cursor: pointer; padding: 2px 6px; border-radius: 4px; transition: all 0.15s; text-decoration: none; }
        .post-action-btn:hover { color: #7aa2ff; background: rgba(122,162,255,0.08); }
        .post-action-btn.danger:hover { color: #ff6b6b; background: rgba(255,107,107,0.08); }
        .post-edited { font-style: italic; color: #4a5470; font-size: 0.65rem; }
        /* REACTIONS */
        .reactions { display: flex; gap: 4px; margin-top: 8px; flex-wrap: wrap; }
        .reaction-btn { background: rgba(122,162,255,0.06); border: 1px solid rgba(122,162,255,0.1); border-radius: 6px; padding: 3px 8px; font-size: 0.75rem; cursor: pointer; transition: all 0.15s; color: #8a96b8; display: inline-flex; align-items: center; gap: 4px; }
        .reaction-btn:hover { background: rgba(122,162,255,0.12); border-color: rgba(122,162,255,0.25); }
        .reaction-btn.active { background: rgba(122,162,255,0.15); border-color: rgba(122,162,255,0.3); color: #7aa2ff; }
        .reaction-btn .r-count { font-size: 0.68rem; font-weight: 600; }
        .reaction-add { background: none; border: 1px dashed rgba(122,162,255,0.15); border-radius: 6px; padding: 3px 8px; font-size: 0.7rem; cursor: pointer; color: #5a6480; transition: all 0.15s; position: relative; }
        .reaction-add:hover { border-color: rgba(122,162,255,0.3); color: #7aa2ff; }
        .reaction-picker { display: none; position: absolute; bottom: 100%; left: 0; background: rgba(17,17,24,0.95); border: 1px solid rgba(122,162,255,0.15); border-radius: 8px; padding: 6px; gap: 4px; z-index: 50; flex-wrap: wrap; width: 180px; backdrop-filter: blur(8px); }
        .reaction-picker.show { display: flex; }
        .reaction-picker button { background: none; border: none; font-size: 1.1rem; cursor: pointer; padding: 4px 6px; border-radius: 4px; transition: background 0.15s; }
        .reaction-picker button:hover { background: rgba(122,162,255,0.12); }
        /* SPOILER */
        .spoiler { background: #2a2a3a; color: transparent; padding: 1px 4px; border-radius: 3px; cursor: pointer; transition: color 0.2s; user-select: none; }
        .spoiler.revealed { color: #d0d4dc; background: rgba(122,162,255,0.08); }
        /* YOUTUBE EMBED */
        .yt-embed { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; margin: 10px 0; max-width: 560px; border: 1px solid rgba(122,162,255,0.1); }
        .yt-embed iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        /* QUOTE BLOCK */
        .quote-block { border-left: 3px solid rgba(122,162,255,0.3); padding: 8px 14px; margin: 8px 0; background: rgba(122,162,255,0.04); border-radius: 0 6px 6px 0; color: #8a96b8; font-size: 0.83rem; }
        /* POLL */
        .poll-card { padding: 16px 20px; border-bottom: 1px solid rgba(122,162,255,0.06); }
        .poll-question { font-weight: 700; color: #e5e5e5; font-size: 0.9rem; margin-bottom: 12px; }
        .poll-option { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; position: relative; }
        .poll-option-bar { position: absolute; left: 0; top: 0; height: 100%; background: rgba(122,162,255,0.08); border-radius: 6px; transition: width 0.3s; z-index: 0; }
        .poll-option label, .poll-option span { position: relative; z-index: 1; }
        .poll-option label { flex: 1; padding: 8px 12px; border: 1px solid rgba(122,162,255,0.1); border-radius: 6px; cursor: pointer; font-size: 0.82rem; display: flex; justify-content: space-between; transition: border-color 0.15s; }
        .poll-option label:hover { border-color: rgba(122,162,255,0.25); }
        .poll-option input[type="radio"] { display: none; }
        .poll-votes { font-size: 0.7rem; color: #5a6480; }
        .poll-total { font-size: 0.72rem; color: #5a6480; margin-top: 8px; }
        /* AVATAR */
        .avatar { border-radius: 8px; text-align: center; font-weight: 800; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; }
        /* ONLINE DOT */
        .online-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 4px; }
        .online-dot.on { background: #6bffb8; box-shadow: 0 0 6px rgba(107,255,184,0.4); }
        .online-dot.off { background: #3a4060; }
        /* BADGE */
        .badge { font-size: 0.58rem; padding: 2px 7px; border-radius: 4px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; vertical-align: middle; }
        .badge-admin { background: rgba(255,107,107,0.12); color: #ff6b6b; }
        .badge-mod { background: rgba(107,255,184,0.12); color: #6bffb8; }
        /* FORMS */
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 0.75rem; font-weight: 600; color: #8a96b8; margin-bottom: 6px; letter-spacing: 0.5px; text-transform: uppercase; }
        .form-input, .form-textarea, .form-select { width: 100%; padding: 10px 14px; background: rgba(10,10,18,0.8); border: 1px solid rgba(122,162,255,0.12); border-radius: 7px; color: #e5e5e5; font-size: 0.85rem; font-family: inherit; transition: border-color 0.2s, box-shadow 0.2s; }
        .form-input:focus, .form-textarea:focus, .form-select:focus { outline: none; border-color: rgba(122,162,255,0.4); box-shadow: 0 0 12px rgba(122,162,255,0.12); }
        .form-textarea { resize: vertical; min-height: 120px; line-height: 1.55; }
        .form-hint { font-size: 0.68rem; color: #5a6480; margin-top: 4px; }
        .form-row { display: flex; gap: 10px; flex-wrap: wrap; }
        /* BUTTONS */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 20px; border: none; border-radius: 7px; font-size: 0.8rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s; font-family: inherit; }
        .btn-primary { background: linear-gradient(135deg, #7aa2ff, #5a80cc); color: #fff; }
        .btn-primary:hover { opacity: 0.88; transform: translateY(-1px); }
        .btn-secondary { background: rgba(122,162,255,0.08); color: #7aa2ff; border: 1px solid rgba(122,162,255,0.18); }
        .btn-secondary:hover { background: rgba(122,162,255,0.15); border-color: rgba(122,162,255,0.3); }
        .btn-danger { background: rgba(255,107,107,0.1); color: #ff6b6b; border: 1px solid rgba(255,107,107,0.2); }
        .btn-danger:hover { background: rgba(255,107,107,0.2); }
        .btn-sm { padding: 5px 12px; font-size: 0.72rem; }
        /* ALERT */
        .alert { padding: 12px 16px; border-radius: 8px; font-size: 0.8rem; margin-bottom: 16px; }
        .alert-error { background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.2); color: #ff6b6b; }
        .alert-success { background: rgba(107,255,184,0.1); border: 1px solid rgba(107,255,184,0.2); color: #6bffb8; }
        /* PAGINATION */
        .pagination { display: flex; gap: 4px; justify-content: center; padding: 16px; }
        .pagination a, .pagination span { padding: 6px 12px; border-radius: 5px; font-size: 0.78rem; text-decoration: none; border: 1px solid rgba(122,162,255,0.1); color: #8a96b8; transition: all 0.15s; }
        .pagination a:hover { background: rgba(122,162,255,0.1); border-color: rgba(122,162,255,0.25); color: #7aa2ff; }
        .pagination .active { background: rgba(122,162,255,0.15); border-color: rgba(122,162,255,0.3); color: #7aa2ff; font-weight: 700; }
        /* EMPTY STATE */
        .empty-state { text-align: center; padding: 40px 20px; color: #5a6480; font-size: 0.85rem; }
        .empty-state p { margin-bottom: 14px; }
        /* TABS */
        .tabs { display: flex; gap: 2px; margin-bottom: 20px; }
        .tab { padding: 9px 18px; border-radius: 7px 7px 0 0; background: rgba(17,17,24,0.5); color: #5a6480; font-size: 0.78rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; border-bottom: none; transition: all 0.15s; }
        .tab:hover { color: #8a96b8; }
        .tab.active { background: rgba(17,17,24,0.75); color: #7aa2ff; border-color: rgba(122,162,255,0.08); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        /* TABLE */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { text-align: left; padding: 10px 16px; font-size: 0.68rem; font-weight: 700; color: #5a6480; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid rgba(122,162,255,0.08); }
        .data-table td { padding: 10px 16px; font-size: 0.8rem; border-bottom: 1px solid rgba(122,162,255,0.04); }
        .data-table tr:hover td { background: rgba(122,162,255,0.02); }
        /* PROFILE */
        .profile-header { display: flex; align-items: center; gap: 20px; padding: 24px; }
        .profile-info h1 { font-size: 1.2rem; color: #e5e5e5; font-weight: 700; }
        .profile-stats { display: flex; gap: 24px; margin-top: 8px; }
        .profile-stat-val { color: #7aa2ff; font-weight: 700; }
        /* AUTH PAGE */
        .auth-wrap { max-width: 400px; margin: 60px auto; padding: 0 20px; }
        .auth-card { padding: 30px; }
        .auth-card h1 { font-size: 1.1rem; color: #e5e5e5; font-weight: 700; margin-bottom: 4px; }
        .auth-card .subtitle { color: #5a6480; font-size: 0.78rem; margin-bottom: 24px; }
        .auth-footer { text-align: center; margin-top: 16px; font-size: 0.78rem; color: #5a6480; }
        .auth-footer a { color: #7aa2ff; text-decoration: none; }
        .auth-footer a:hover { text-decoration: underline; }
        /* MESSAGES */
        .msg-list-item { display: flex; align-items: center; gap: 12px; padding: 14px 20px; border-bottom: 1px solid rgba(122,162,255,0.04); transition: background 0.15s; text-decoration: none; color: inherit; }
        .msg-list-item:hover { background: rgba(122,162,255,0.03); }
        .msg-list-item.unread { background: rgba(122,162,255,0.04); }
        .msg-preview { flex: 1; min-width: 0; }
        .msg-preview-user { font-weight: 600; color: #e5e5e5; font-size: 0.85rem; }
        .msg-preview-text { color: #5a6480; font-size: 0.75rem; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .msg-time { color: #5a6480; font-size: 0.68rem; flex-shrink: 0; }
        .msg-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: #7aa2ff; flex-shrink: 0; }
        .msg-bubble { max-width: 70%; padding: 10px 14px; border-radius: 12px; font-size: 0.82rem; line-height: 1.5; margin-bottom: 6px; word-break: break-word; }
        .msg-bubble.sent { background: rgba(122,162,255,0.15); color: #d0d4dc; margin-left: auto; border-bottom-right-radius: 4px; }
        .msg-bubble.received { background: rgba(30,30,42,0.8); color: #d0d4dc; border-bottom-left-radius: 4px; }
        .msg-bubble-time { font-size: 0.62rem; color: #5a6480; margin-top: 2px; }
        .msg-bubble-wrap { display: flex; flex-direction: column; }
        .msg-bubble-wrap.sent { align-items: flex-end; }
        .msg-bubble-wrap.received { align-items: flex-start; }
        /* MEMBERS */
        .member-card { display: flex; align-items: center; gap: 14px; padding: 14px 20px; border-bottom: 1px solid rgba(122,162,255,0.04); }
        .member-card:last-child { border-bottom: none; }
        .member-info { flex: 1; }
        .member-info a { color: #e5e5e5; text-decoration: none; font-weight: 600; font-size: 0.88rem; }
        .member-info a:hover { color: #7aa2ff; }
        .member-detail { color: #5a6480; font-size: 0.72rem; margin-top: 2px; }
        /* STATS BAR */
        .stats-bar { display: flex; gap: 20px; padding: 16px 20px; flex-wrap: wrap; }
        .stat-item { text-align: center; flex: 1; min-width: 80px; }
        .stat-num { color: #7aa2ff; font-weight: 800; font-size: 1.1rem; display: block; }
        .stat-label { color: #5a6480; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; }
        /* SEARCH */
        .search-bar { display: flex; gap: 8px; margin-bottom: 20px; }
        .search-bar input { flex: 1; }
        .search-result { padding: 14px 20px; border-bottom: 1px solid rgba(122,162,255,0.04); }
        .search-result:last-child { border-bottom: none; }
        .search-result:hover { background: rgba(122,162,255,0.03); }
        .search-result a { color: #e5e5e5; text-decoration: none; font-weight: 600; font-size: 0.85rem; }
        .search-result a:hover { color: #7aa2ff; }
        .search-excerpt { color: #8a96b8; font-size: 0.78rem; margin-top: 3px; }
        .search-excerpt mark { background: rgba(122,162,255,0.2); color: #7aa2ff; border-radius: 2px; padding: 0 2px; }
        /* LIVE PREVIEW */
        .preview-pane { background: rgba(10,10,18,0.6); border: 1px solid rgba(122,162,255,0.08); border-radius: 7px; padding: 14px; min-height: 60px; font-size: 0.85rem; line-height: 1.65; color: #d0d4dc; margin-top: 8px; display: none; word-break: break-word; }
        .preview-pane.active { display: block; }
        .preview-toggle { font-size: 0.7rem; color: #5a6480; cursor: pointer; margin-top: 4px; }
        .preview-toggle:hover { color: #7aa2ff; }
        /* TAG CHECKBOXES */
        .tag-select { display: flex; gap: 6px; flex-wrap: wrap; }
        .tag-select label { cursor: pointer; }
        .tag-select input { display: none; }
        .tag-select input:checked + .thread-tag { opacity: 1; box-shadow: 0 0 8px currentColor; }
        .tag-select .thread-tag { opacity: 0.5; transition: all 0.15s; }
        .tag-select .thread-tag:hover { opacity: 0.8; }
        /* POLL FORM */
        .poll-options-form { display: flex; flex-direction: column; gap: 6px; }
        .poll-options-form input { width: 100%; }
        /* REPORT MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 200; display: none; align-items: center; justify-content: center; }
        .modal-overlay.show { display: flex; }
        .modal { background: #13131c; border: 1px solid rgba(122,162,255,0.15); border-radius: 12px; padding: 24px; width: 90%; max-width: 400px; }
        .modal h3 { color: #e5e5e5; font-size: 0.95rem; margin-bottom: 14px; }
        /* COPY BUTTON */
        .copy-btn { background: rgba(122,162,255,0.1); border: 1px solid rgba(122,162,255,0.2); color: #7aa2ff; padding: 3px 8px; border-radius: 4px; font-size: 0.68rem; cursor: pointer; transition: all 0.15s; }
        .copy-btn:hover { background: rgba(122,162,255,0.2); }
        /* THREAD PREFIX */
        .thread-prefix { font-size: 0.6rem; padding: 2px 7px; border-radius: 4px; font-weight: 700; letter-spacing: 0.5px; border: 1px solid; flex-shrink: 0; text-transform: uppercase; }
        /* VOTE BUTTONS */
        .vote-wrap { display: flex; flex-direction: column; align-items: center; gap: 2px; margin-right: 4px; flex-shrink: 0; }
        .vote-btn { background: none; border: 1px solid rgba(122,162,255,0.1); color: #5a6480; width: 26px; height: 22px; border-radius: 4px; cursor: pointer; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; transition: all 0.15s; padding: 0; }
        .vote-btn:hover { background: rgba(122,162,255,0.1); color: #7aa2ff; border-color: rgba(122,162,255,0.25); }
        .vote-btn.voted-up { background: rgba(107,255,184,0.12); color: #6bffb8; border-color: rgba(107,255,184,0.3); }
        .vote-btn.voted-down { background: rgba(255,107,107,0.12); color: #ff6b6b; border-color: rgba(255,107,107,0.3); }
        .vote-score { font-size: 0.72rem; font-weight: 700; color: #8a96b8; }
        .vote-score.positive { color: #6bffb8; }
        .vote-score.negative { color: #ff6b6b; }
        /* SIGNATURE */
        .post-signature { margin-top: 12px; padding-top: 10px; border-top: 1px dashed rgba(122,162,255,0.08); font-size: 0.72rem; color: #5a6480; font-style: italic; max-height: 60px; overflow: hidden; }
        /* CUSTOM TITLE */
        .post-custom-title { font-size: 0.62rem; color: #7aa2ff; margin-top: 1px; font-style: italic; }
        /* @MENTION */
        .mention { color: #7aa2ff; font-weight: 600; text-decoration: none; background: rgba(122,162,255,0.08); padding: 0 3px; border-radius: 3px; }
        .mention:hover { background: rgba(122,162,255,0.15); text-decoration: underline; }
        /* NOTIFICATION BELL */
        .notif-bell { position: relative; color: #8a96b8; text-decoration: none; font-size: 1rem; padding: 4px; transition: color 0.15s; }
        .notif-bell:hover { color: #7aa2ff; }
        .notif-bell .notif-count { position: absolute; top: -4px; right: -6px; background: #ff6b6b; color: #fff; font-size: 0.5rem; padding: 1px 4px; border-radius: 8px; font-weight: 700; }
        .notif-dropdown { display: none; position: absolute; top: 100%; right: 0; width: 320px; background: rgba(17,17,24,0.97); border: 1px solid rgba(122,162,255,0.15); border-radius: 10px; backdrop-filter: blur(12px); z-index: 150; max-height: 400px; overflow-y: auto; }
        .notif-dropdown.show { display: block; }
        .notif-item { padding: 10px 14px; border-bottom: 1px solid rgba(122,162,255,0.04); font-size: 0.75rem; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item.unread { background: rgba(122,162,255,0.04); }
        .notif-item a { color: #7aa2ff; text-decoration: none; }
        .notif-item a:hover { text-decoration: underline; }
        .notif-item .notif-time { color: #5a6480; font-size: 0.65rem; margin-top: 2px; }
        /* ACHIEVEMENTS */
        .achievement { display: inline-flex; align-items: center; gap: 4px; background: rgba(122,162,255,0.06); border: 1px solid rgba(122,162,255,0.1); border-radius: 6px; padding: 4px 10px; font-size: 0.72rem; color: #8a96b8; }
        .achievement .ach-icon { font-size: 0.9rem; }
        .achievement .ach-name { font-weight: 600; color: #e5e5e5; }
        .achievements-grid { display: flex; flex-wrap: wrap; gap: 6px; }
        .achievement-locked { opacity: 0.35; }
        /* SHOUTBOX */
        .shoutbox { border: 1px solid rgba(122,162,255,0.08); border-radius: 10px; background: rgba(17,17,24,0.75); overflow: hidden; }
        .shoutbox-header { padding: 10px 16px; border-bottom: 1px solid rgba(122,162,255,0.06); display: flex; justify-content: space-between; align-items: center; }
        .shoutbox-header h3 { font-size: 0.78rem; font-weight: 700; color: #7aa2ff; letter-spacing: 1px; text-transform: uppercase; }
        .shoutbox-messages { height: 200px; overflow-y: auto; padding: 8px 14px; display: flex; flex-direction: column; gap: 4px; }
        .shoutbox-msg { font-size: 0.75rem; line-height: 1.4; }
        .shoutbox-msg .sb-author { font-weight: 700; color: #7aa2ff; margin-right: 6px; text-decoration: none; font-size: 0.72rem; }
        .shoutbox-msg .sb-author:hover { text-decoration: underline; }
        .shoutbox-msg .sb-text { color: #c8ccd4; }
        .shoutbox-msg .sb-time { color: #3a4060; font-size: 0.6rem; margin-left: 6px; }
        .shoutbox-input { display: flex; gap: 6px; padding: 8px 14px; border-top: 1px solid rgba(122,162,255,0.06); }
        .shoutbox-input input { flex: 1; background: rgba(10,10,18,0.8); border: 1px solid rgba(122,162,255,0.12); border-radius: 6px; color: #e5e5e5; padding: 6px 10px; font-size: 0.78rem; font-family: inherit; }
        .shoutbox-input input:focus { outline: none; border-color: rgba(122,162,255,0.4); }
        .shoutbox-input button { background: linear-gradient(135deg, #7aa2ff, #5a80cc); color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 0.72rem; cursor: pointer; font-weight: 600; }
        /* LEADERBOARD */
        .lb-row { display: flex; align-items: center; gap: 14px; padding: 12px 20px; border-bottom: 1px solid rgba(122,162,255,0.04); }
        .lb-row:last-child { border-bottom: none; }
        .lb-rank { width: 30px; text-align: center; font-weight: 800; font-size: 0.9rem; }
        .lb-rank.gold { color: #ffb86b; }
        .lb-rank.silver { color: #c8ccd4; }
        .lb-rank.bronze { color: #cd7f32; }
        .lb-user { flex: 1; display: flex; align-items: center; gap: 10px; }
        .lb-user a { color: #e5e5e5; text-decoration: none; font-weight: 600; font-size: 0.85rem; }
        .lb-user a:hover { color: #7aa2ff; }
        .lb-stat { text-align: center; min-width: 70px; }
        .lb-stat-val { font-weight: 700; color: #7aa2ff; font-size: 0.9rem; display: block; }
        .lb-stat-label { font-size: 0.62rem; color: #5a6480; text-transform: uppercase; }
        /* GLOBAL STICKY TAG */
        .sticky-global-tag { font-size: 0.6rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; letter-spacing: 0.5px; background: rgba(255,184,107,0.12); color: #ffb86b; flex-shrink: 0; }
        /* REP DISPLAY */
        .rep-badge { display: inline-flex; align-items: center; gap: 3px; font-size: 0.65rem; font-weight: 600; padding: 1px 6px; border-radius: 4px; }
        .rep-badge.positive { background: rgba(107,255,184,0.1); color: #6bffb8; }
        .rep-badge.negative { background: rgba(255,107,107,0.1); color: #ff6b6b; }
        .rep-badge.neutral { background: rgba(138,150,184,0.1); color: #8a96b8; }
        /* MOD LOG */
        .modlog-entry { padding: 10px 16px; border-bottom: 1px solid rgba(122,162,255,0.04); font-size: 0.78rem; }
        .modlog-entry:last-child { border-bottom: none; }
        .modlog-action { font-weight: 600; color: #7aa2ff; text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.5px; }
        .modlog-details { color: #8a96b8; margin-top: 2px; }
        .modlog-meta { color: #5a6480; font-size: 0.68rem; margin-top: 2px; }
        /* SUB-CATEGORY */
        .sub-cat-list { padding-left: 56px; }
        .sub-cat-row { display: flex; align-items: center; gap: 10px; padding: 8px 20px; border-bottom: 1px solid rgba(122,162,255,0.03); font-size: 0.82rem; }
        .sub-cat-row:last-child { border-bottom: none; }
        .sub-cat-row .cat-name { font-size: 0.82rem; }
        .sub-cat-row .cat-desc { font-size: 0.68rem; }
        /* MISC */
        .text-muted { color: #5a6480; }
        .text-sm { font-size: 0.75rem; }
        .mt-2 { margin-top: 8px; }
        .mt-4 { margin-top: 16px; }
        .mb-4 { margin-bottom: 16px; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        .inline-form { display: inline; }
        .permalink { color: #3a4060; text-decoration: none; font-size: 0.7rem; }
        .permalink:hover { color: #7aa2ff; }
        /* IMAGE UPLOAD INLINE */
        .img-upload-btn { display: inline-flex; align-items: center; gap: 4px; background: rgba(122,162,255,0.08); border: 1px solid rgba(122,162,255,0.15); color: #7aa2ff; padding: 4px 10px; border-radius: 5px; font-size: 0.72rem; cursor: pointer; transition: all 0.15s; }
        .img-upload-btn:hover { background: rgba(122,162,255,0.15); }
        .img-upload-btn input { display: none; }
        /* EDIT FORM INLINE */
        .edit-form-inline { margin-top: 8px; }
        .edit-form-inline textarea { width: 100%; min-height: 80px; }
        @media (max-width: 700px) {
            .forum-nav { padding: 10px 14px; flex-wrap: wrap; gap: 8px; }
            .forum-wrap { padding: 14px 10px 40px; }
            .cat-stats, .cat-last-post, .thread-stats, .thread-last { display: none; }
            .post { flex-direction: column; gap: 8px; }
            .post-sidebar { width: auto; display: flex; align-items: center; gap: 10px; text-align: left; }
            .profile-header { flex-direction: column; text-align: center; }
            .msg-bubble { max-width: 90%; }
            .stats-bar { gap: 10px; }
        }
    </style>
</head>
<body>
    <nav class="forum-nav">
        <div class="forum-nav-left">
            <a href="/forum/" class="forum-nav-brand">m190</a>
            <div class="forum-nav-links">
                <a href="/" class="<?= ($navActive ?? '') === 'home' ? 'active' : '' ?>">Main Site</a>
                <a href="/forum/" class="<?= ($navActive ?? '') === 'forum' ? 'active' : '' ?>">Categories</a>
                <a href="/forum/members.php" class="<?= ($navActive ?? '') === 'members' ? 'active' : '' ?>">Members</a>
                <a href="/forum/search.php" class="<?= ($navActive ?? '') === 'search' ? 'active' : '' ?>">Search</a>
                <a href="/forum/leaderboard.php" class="<?= ($navActive ?? '') === 'leaderboard' ? 'active' : '' ?>">Leaderboard</a>
                <?php if (isLoggedIn()): ?>
                    <?php $__unread = getUnreadCount(); ?>
                    <a href="/forum/messages.php" class="<?= ($navActive ?? '') === 'messages' ? 'active' : '' ?>">
                        Messages<?php if ($__unread > 0): ?><span class="nav-badge"><?= $__unread ?></span><?php endif; ?>
                    </a>
                    <a href="/forum/profile.php?user=<?= e(currentUser()) ?>" class="<?= ($navActive ?? '') === 'profile' ? 'active' : '' ?>">Profile</a>
                    <?php if (isAdmin()): ?>
                        <?php $__reports = getOpenReportCount(); ?>
                        <a href="/forum/admin.php" class="<?= ($navActive ?? '') === 'admin' ? 'active' : '' ?>">
                            Admin<?php if ($__reports > 0): ?><span class="nav-badge"><?= $__reports ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="forum-nav-right">
            <?php if (isLoggedIn()): ?>
                <div style="position:relative;">
                    <a href="#" class="notif-bell" onclick="toggleNotifs(event)" title="Notifications">
                        &#128276;<?php $__notifCount = getUnreadNotificationCount(); if ($__notifCount > 0): ?><span class="notif-count"><?= $__notifCount ?></span><?php endif; ?>
                    </a>
                    <div class="notif-dropdown" id="notif-dropdown"></div>
                </div>
                <span class="forum-nav-user"><?= e(currentUser()) ?></span>
                <a href="/forum/logout.php" class="nav-btn">Logout</a>
            <?php else: ?>
                <a href="/forum/login.php" class="nav-btn">Login</a>
                <a href="/forum/register.php" class="nav-btn nav-btn-primary">Register</a>
            <?php endif; ?>
        </div>
    </nav>
