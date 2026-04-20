<?php
require_once __DIR__ . '/includes/bootstrap.php';

$threadId = $_GET['id'] ?? '';
$thread = getThread($threadId);

if (!$thread) {
    http_response_code(404);
    $pageTitle = 'Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="forum-wrap"><div class="alert alert-error">Thread not found.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$replyError = '';
$editError = '';
$reportSuccess = '';
$reportError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reply' && !($thread['locked'] ?? false)) {
        enforceRateLimit('forum_reply', 10, 60);
        $result = addReply($threadId, $_POST['content'] ?? '');
        if ($result['ok']) {
            header('Location: /forum/thread.php?id=' . e($threadId) . '#bottom');
            exit;
        }
        $replyError = $result['error'];
    }

    if ($action === 'edit_post') {
        $result = editPost($threadId, $_POST['post_id'] ?? '', $_POST['content'] ?? '');
        if ($result['ok']) {
            header('Location: /forum/thread.php?id=' . e($threadId) . '#post-' . e($_POST['post_id']));
            exit;
        }
        $editError = $result['error'];
    }

    if ($action === 'react_inline') {
        enforceRateLimit('forum_reaction', 30, 60);
        toggleReaction($_POST['thread_id'] ?? '', $_POST['post_id'] ?? '', $_POST['emoji'] ?? '');
        header('Location: /forum/thread.php?id=' . e($threadId) . '#post-' . e($_POST['post_id'] ?? ''));
        exit;
    }

    if ($action === 'report') {
        enforceRateLimit('forum_report', 5, 60);
        $result = reportPost($threadId, $_POST['post_id'] ?? '', $_POST['reason'] ?? '');
        if ($result['ok']) $reportSuccess = 'Report submitted.';
        else $reportError = $result['error'];
    }

    if ($action === 'vote_poll') {
        votePoll($threadId, (int)($_POST['option'] ?? -1));
        header('Location: /forum/thread.php?id=' . e($threadId));
        exit;
    }

    if (isAdmin()) {
        if ($action === 'pin') { togglePin($threadId); logModAction('pin', ($thread['pinned'] ?? false) ? "Unpinned \"{$thread['title']}\"" : "Pinned \"{$thread['title']}\""); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'lock') { toggleLock($threadId); logModAction('lock', ($thread['locked'] ?? false) ? "Unlocked \"{$thread['title']}\"" : "Locked \"{$thread['title']}\""); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'delete_thread') { logModAction('delete_thread', "Deleted \"{$thread['title']}\""); deleteThread($threadId); header('Location: /forum/'); exit; }
        if ($action === 'delete_post') { logModAction('delete_post', "Deleted post in \"{$thread['title']}\""); deletePost($threadId, $_POST['post_id'] ?? ''); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'close_poll') { closePoll($threadId); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'global_sticky') { toggleGlobalSticky($threadId); logModAction('global_sticky', "Toggled global sticky on \"{$thread['title']}\""); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'move_thread') { $newCat = $_POST['new_category'] ?? ''; if ($newCat) moveThread($threadId, $newCat); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
        if ($action === 'set_prefix') { setThreadPrefix($threadId, $_POST['prefix'] ?? ''); header('Location: /forum/thread.php?id=' . e($threadId)); exit; }
    }

    $thread = getThread($threadId);
    if (!$thread) { header('Location: /forum/'); exit; }
}

$category = getCategoryById($thread['category']);
$navActive = 'forum';
$pageTitle = $thread['title'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap">
    <div class="breadcrumbs">
        <a href="/forum/">Forum</a><span class="sep">/</span>
        <a href="/forum/category.php?id=<?= e($thread['category']) ?>"><?= e($category['name'] ?? $thread['category']) ?></a>
        <span class="sep">/</span>
        <span><?= e(mb_strimwidth($thread['title'], 0, 40, '...')) ?></span>
    </div>

    <?php if ($reportSuccess): ?><div class="alert alert-success"><?= e($reportSuccess) ?></div><?php endif; ?>
    <?php if ($reportError): ?><div class="alert alert-error"><?= e($reportError) ?></div><?php endif; ?>

    <div class="flex-between mb-4">
        <div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <?php if ($thread['sticky_global'] ?? false): ?><span class="sticky-global-tag">ANNOUNCEMENT</span><?php endif; ?>
                <?php if ($thread['pinned'] ?? false): ?><span class="pin-tag">PINNED</span><?php endif; ?>
                <?php if ($thread['locked'] ?? false): ?><span class="lock-tag">LOCKED</span><?php endif; ?>
                <?php if (!empty($thread['prefix'])): ?><?= prefixHtml($thread['prefix']) ?><?php endif; ?>
                <?php foreach ($thread['tags'] ?? [] as $tagId): ?><?= tagHtml($tagId) ?><?php endforeach; ?>
                <h1 style="font-size:1.1rem; color:#e5e5e5; font-weight:700;"><?= e($thread['title']) ?></h1>
            </div>
            <p style="font-size:0.75rem; color:#5a6480; margin-top:3px;">
                Started by <a href="/forum/profile.php?user=<?= e($thread['author']) ?>" style="color:#7aa2ff; text-decoration:none;"><?= e($thread['author']) ?></a>
                &middot; <?= timeAgo($thread['created']) ?>
            </p>
        </div>
        <?php if (isAdmin()): ?>
        <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
            <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="pin"><button class="btn btn-secondary btn-sm"><?= ($thread['pinned'] ?? false) ? 'Unpin' : 'Pin' ?></button></form>
            <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="lock"><button class="btn btn-secondary btn-sm"><?= ($thread['locked'] ?? false) ? 'Unlock' : 'Lock' ?></button></form>
            <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="global_sticky"><button class="btn btn-secondary btn-sm"><?= ($thread['sticky_global'] ?? false) ? 'Unannounce' : 'Announce' ?></button></form>
            <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="set_prefix">
                <select name="prefix" onchange="this.form.submit()" style="background:rgba(10,10,18,0.8);border:1px solid rgba(122,162,255,0.12);color:#8a96b8;padding:4px 8px;border-radius:5px;font-size:0.68rem;">
                    <option value="">No Prefix</option>
                    <?php foreach (getAvailablePrefixes() as $pfx): ?>
                        <option value="<?= e($pfx['id']) ?>" <?= ($thread['prefix'] ?? '') === $pfx['id'] ? 'selected' : '' ?>><?= e($pfx['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="move_thread">
                <select name="new_category" onchange="if(this.value)this.form.submit()" style="background:rgba(10,10,18,0.8);border:1px solid rgba(122,162,255,0.12);color:#8a96b8;padding:4px 8px;border-radius:5px;font-size:0.68rem;">
                    <option value="">Move to...</option>
                    <?php foreach (getCategories() as $mc): if ($mc['id'] !== $thread['category']): ?>
                        <option value="<?= e($mc['id']) ?>"><?= e($mc['name']) ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </form>
            <form method="POST" class="inline-form" onsubmit="return confirm('Delete this entire thread?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_thread"><button class="btn btn-danger btn-sm">Delete</button></form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Poll -->
    <?php if (isset($thread['poll'])): ?>
    <?php
        $poll = $thread['poll'];
        $totalVotes = 0;
        $userVoted = null;
        foreach ($poll['options'] as $oi => $opt) {
            $totalVotes += count($opt['votes']);
            if (isLoggedIn() && in_array(currentUser(), $opt['votes'])) $userVoted = $oi;
        }
    ?>
    <div class="card mb-4">
        <div class="poll-card">
            <div class="poll-question"><?= e($poll['question']) ?></div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="vote_poll">
                <?php foreach ($poll['options'] as $oi => $opt):
                    $vc = count($opt['votes']);
                    $pct = $totalVotes > 0 ? round($vc / $totalVotes * 100) : 0;
                ?>
                <div class="poll-option">
                    <div class="poll-option-bar" style="width:<?= $pct ?>%;"></div>
                    <label>
                        <?php if (!$poll['closed'] && isLoggedIn()): ?>
                            <input type="radio" name="option" value="<?= $oi ?>" onchange="this.form.submit()" <?= $userVoted === $oi ? 'checked' : '' ?>>
                        <?php endif; ?>
                        <span><?= e($opt['text']) ?></span>
                        <span class="poll-votes"><?= $vc ?> vote<?= $vc !== 1 ? 's' : '' ?> (<?= $pct ?>%)</span>
                    </label>
                </div>
                <?php endforeach; ?>
            </form>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span class="poll-total"><?= $totalVotes ?> total vote<?= $totalVotes !== 1 ? 's' : '' ?><?= $poll['closed'] ? ' &middot; Poll closed' : '' ?></span>
                <?php if (isAdmin()): ?>
                    <form method="POST" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="close_poll"><button class="btn btn-secondary btn-sm"><?= $poll['closed'] ? 'Reopen Poll' : 'Close Poll' ?></button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Posts -->
    <div class="card mb-4">
        <div class="card-body">
            <?php foreach ($thread['posts'] as $i => $post):
                $profile = getUserProfile($post['author']);
                $role = $profile['role'] ?? 'member';
                $pc = $profile['post_count'] ?? 0;
                $rank = getUserRank($pc);
                $customTitle = getUserTitle($post['author']);
                $postScore = getPostScore($post);
                $userVotedUp = isLoggedIn() && in_array(currentUser(), $post['votes']['up'] ?? []);
                $userVotedDown = isLoggedIn() && in_array(currentUser(), $post['votes']['down'] ?? []);
                $signature = getUserSignature($post['author']);
                $isEditing = isset($_GET['edit']) && $_GET['edit'] === $post['id'] && isLoggedIn() && ($post['author'] === currentUser() || isAdmin());
            ?>
            <div class="post" id="post-<?= e($post['id']) ?>">
                <div class="post-sidebar">
                    <?= avatarHtml($post['author'], 48) ?>
                    <a href="/forum/profile.php?user=<?= e($post['author']) ?>" class="post-author-link"><?= e($post['author']) ?></a>
                    <div class="post-role"><?= roleBadge($role) ?></div>
                    <?php if ($customTitle): ?><div class="post-custom-title"><?= e($customTitle) ?></div><?php endif; ?>
                    <div class="post-rank" style="color:<?= getRankColor($rank) ?>"><?= $rank ?></div>
                    <div style="margin-top:2px;">
                        <span class="online-dot <?= isUserOnline($post['author']) ? 'on' : 'off' ?>"></span>
                    </div>
                    <?php $postRep = getUserReputation($post['author']); ?>
                    <div class="rep-badge <?= $postRep > 0 ? 'positive' : ($postRep < 0 ? 'negative' : 'neutral') ?>" title="Reputation"><?= $postRep > 0 ? '+' : '' ?><?= $postRep ?> rep</div>
                </div>
                <div class="post-body" style="display:flex;gap:10px;">
                    <?php if (isLoggedIn() && $post['author'] !== currentUser()): ?>
                    <div class="vote-wrap">
                        <button class="vote-btn <?= $userVotedUp ? 'voted-up' : '' ?>" onclick="votePost('<?= e($threadId) ?>','<?= e($post['id']) ?>','up')" title="Upvote">&#9650;</button>
                        <span class="vote-score <?= $postScore > 0 ? 'positive' : ($postScore < 0 ? 'negative' : '') ?>"><?= $postScore ?></span>
                        <button class="vote-btn <?= $userVotedDown ? 'voted-down' : '' ?>" onclick="votePost('<?= e($threadId) ?>','<?= e($post['id']) ?>','down')" title="Downvote">&#9660;</button>
                    </div>
                    <?php elseif ($postScore != 0): ?>
                    <div class="vote-wrap">
                        <span class="vote-score <?= $postScore > 0 ? 'positive' : ($postScore < 0 ? 'negative' : '') ?>" style="margin-top:22px;"><?= $postScore > 0 ? '+' : '' ?><?= $postScore ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                    <?php if ($isEditing): ?>
                        <?php if ($editError): ?><div class="alert alert-error"><?= e($editError) ?></div><?php endif; ?>
                        <form method="POST" class="edit-form-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="edit_post">
                            <input type="hidden" name="post_id" value="<?= e($post['id']) ?>">
                            <textarea class="form-textarea" name="content" required maxlength="10000"><?= e($post['content']) ?></textarea>
                            <div style="display:flex; gap:6px; margin-top:8px;">
                                <button class="btn btn-primary btn-sm">Save</button>
                                <a href="/forum/thread.php?id=<?= e($threadId) ?>#post-<?= e($post['id']) ?>" class="btn btn-secondary btn-sm">Cancel</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="post-content"><?= formatContent($post['content']) ?></div>

                        <!-- Reactions -->
                        <?php $reactions = $post['reactions'] ?? []; ?>
                        <div class="reactions">
                            <?php foreach ($reactions as $emoji => $users): ?>
                                <form method="POST" action="/forum/api.php" class="inline-form reaction-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reaction">
                                    <input type="hidden" name="thread_id" value="<?= e($threadId) ?>">
                                    <input type="hidden" name="post_id" value="<?= e($post['id']) ?>">
                                    <input type="hidden" name="emoji" value="<?= e($emoji) ?>">
                                    <button type="submit" class="reaction-btn <?= isLoggedIn() && in_array(currentUser(), $users) ? 'active' : '' ?>"
                                            title="<?= e(implode(', ', $users)) ?>">
                                        <?= $emoji ?> <span class="r-count"><?= count($users) ?></span>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                            <?php if (isLoggedIn()): ?>
                            <div class="reaction-add" onclick="this.querySelector('.reaction-picker').classList.toggle('show')">
                                +
                                <div class="reaction-picker">
                                    <?php foreach (getReactionEmojis() as $em): ?>
                                    <form method="POST" action="/forum/thread.php?id=<?= e($threadId) ?>" class="inline-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="react_inline">
                                        <input type="hidden" name="thread_id" value="<?= e($threadId) ?>">
                                        <input type="hidden" name="post_id" value="<?= e($post['id']) ?>">
                                        <input type="hidden" name="emoji" value="<?= e($em) ?>">
                                        <button type="submit"><?= $em ?></button>
                                    </form>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="post-footer">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span><?= timeAgo($post['created']) ?></span>
                                <?php if (isset($post['edited'])): ?>
                                    <span class="post-edited">(edited <?= timeAgo($post['edited']) ?>)</span>
                                <?php endif; ?>
                                <a href="#post-<?= e($post['id']) ?>" class="permalink">#<?= $i + 1 ?></a>
                            </div>
                            <div class="post-actions">
                                <?php if (isLoggedIn()): ?>
                                    <button class="post-action-btn" onclick="quotePost('<?= e(addslashes($post['author'])) ?>', this)" data-content="<?= e($post['content']) ?>">quote</button>
                                    <?php if ($post['author'] === currentUser() || isAdmin()): ?>
                                        <a href="/forum/thread.php?id=<?= e($threadId) ?>&edit=<?= e($post['id']) ?>#post-<?= e($post['id']) ?>" class="post-action-btn">edit</a>
                                    <?php endif; ?>
                                    <button class="post-action-btn" onclick="openReport('<?= e($post['id']) ?>')">report</button>
                                <?php endif; ?>
                                <?php if (isAdmin() && count($thread['posts']) > 1): ?>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Delete this post?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="post_id" value="<?= e($post['id']) ?>">
                                        <button class="post-action-btn danger">delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($signature && !$isEditing): ?>
                        <div class="post-signature"><?= e($signature) ?></div>
                    <?php endif; ?>
                    </div><!-- /flex inner -->
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Reply form -->
    <?php if (isLoggedIn() && !($thread['locked'] ?? false)): ?>
    <div class="card" id="bottom">
        <div class="card-header"><h2>Reply</h2></div>
        <div style="padding:20px;">
            <?php if ($replyError): ?><div class="alert alert-error"><?= e($replyError) ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reply">
                <div class="form-group">
                    <textarea class="form-textarea" name="content" id="reply-content" required placeholder="Write your reply..." maxlength="10000"></textarea>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <label class="img-upload-btn">
                                <input type="file" accept="image/*" onchange="uploadImg(this)"> Upload Image
                            </label>
                            <span class="form-hint">**bold** *italic* `code` ||spoiler|| > quote</span>
                        </div>
                        <span class="preview-toggle" onclick="togglePreview()">Preview</span>
                    </div>
                    <div class="preview-pane" id="preview-pane"></div>
                </div>
                <button type="submit" class="btn btn-primary">Post Reply</button>
            </form>
        </div>
    </div>
    <?php elseif ($thread['locked'] ?? false): ?>
    <div class="alert" style="background:rgba(122,162,255,0.05); border:1px solid rgba(122,162,255,0.1); color:#5a6480; text-align:center;">
        This thread is locked. No new replies can be posted.
    </div>
    <?php elseif (!isLoggedIn()): ?>
    <div style="text-align:center; margin-top:20px;">
        <a href="/forum/login.php" class="btn btn-primary">Login to reply</a>
    </div>
    <?php endif; ?>
</div>

<!-- Report modal -->
<div class="modal-overlay" id="report-modal">
    <div class="modal">
        <h3>Report Post</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="report">
            <input type="hidden" name="post_id" id="report-post-id" value="">
            <div class="form-group">
                <label class="form-label">Reason</label>
                <textarea class="form-textarea" name="reason" required minlength="3" maxlength="500" placeholder="Why are you reporting this post?" style="min-height:80px;"></textarea>
            </div>
            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-danger btn-sm">Submit Report</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeReport()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function quotePost(author, btn) {
    var content = btn.getAttribute('data-content');
    var lines = content.split('\n').map(function(l) { return '> ' + l; }).join('\n');
    var textarea = document.getElementById('reply-content');
    textarea.value += (textarea.value ? '\n\n' : '') + '> **' + author + '** wrote:\n' + lines + '\n\n';
    textarea.focus();
    document.getElementById('bottom').scrollIntoView({behavior:'smooth'});
}

function openReport(postId) {
    document.getElementById('report-post-id').value = postId;
    document.getElementById('report-modal').classList.add('show');
}
function closeReport() { document.getElementById('report-modal').classList.remove('show'); }

function uploadImg(input) {
    if (!input.files[0]) return;
    var fd = new FormData();
    fd.append('image', input.files[0]);
    fd.append('action', 'upload_image');
    fetch('/forum/api.php', {method:'POST', body:fd})
        .then(function(r){return r.json()})
        .then(function(data) {
            if (data.ok) {
                var ta = document.getElementById('reply-content');
                ta.value += (ta.value ? '\n' : '') + '![image](' + data.url + ')';
                ta.focus();
            } else { alert(data.error || 'Upload failed'); }
        }).catch(function(){alert('Upload failed');});
    input.value = '';
}

function togglePreview() {
    var pane = document.getElementById('preview-pane');
    var ta = document.getElementById('reply-content');
    pane.classList.toggle('active');
    if (pane.classList.contains('active')) {
        var t = ta.value;
        t = t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        t = t.replace(/```([\s\S]*?)```/g,'<pre class="code-block">$1</pre>');
        t = t.replace(/`([^`]+)`/g,'<code>$1</code>');
        t = t.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
        t = t.replace(/\*(.+?)\*/g,'<em>$1</em>');
        t = t.replace(/\|\|(.+?)\|\|/g,'<span class="spoiler">$1</span>');
        t = t.replace(/!\[([^\]]*)\]\(([^)]+)\)/g,'<img class="post-image" src="$2" alt="$1" style="max-width:100%;max-height:300px;">');
        t = t.replace(/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([\w-]{11})[^\s]*/g,'<div class="yt-embed" style="position:relative;padding-bottom:56.25%;height:0;max-width:400px;"><iframe src="https://www.youtube-nocookie.com/embed/$1" style="position:absolute;top:0;left:0;width:100%;height:100%;" frameborder="0" allowfullscreen></iframe></div>');
        t = t.replace(/(?:https?:\/\/)?youtu\.be\/([\w-]{11})[^\s]*/g,'<div class="yt-embed" style="position:relative;padding-bottom:56.25%;height:0;max-width:400px;"><iframe src="https://www.youtube-nocookie.com/embed/$1" style="position:absolute;top:0;left:0;width:100%;height:100%;" frameborder="0" allowfullscreen></iframe></div>');
        var lines = t.split('\n'), out=[], inQ=false;
        lines.forEach(function(line){
            if(line.match(/^&gt;\s?(.*)/)){
                if(!inQ){out.push('<blockquote class="quote-block">');inQ=true;}
                out.push(line.replace(/^&gt;\s?/,''));
            } else {
                if(inQ){out.push('</blockquote>');inQ=false;}
                out.push(line);
            }
        });
        if(inQ) out.push('</blockquote>');
        t = out.join('<br>');
        pane.innerHTML = t || '<span class="text-muted">Nothing to preview</span>';
    }
}

// Handle inline reactions via form submission (redirect back)
document.querySelectorAll('.reaction-form').forEach(function(f) {
    f.addEventListener('submit', function(e) {
        e.preventDefault();
        var fd = new FormData(f);
        fetch('/forum/api.php', {method:'POST', body:fd})
            .then(function(){location.reload()});
    });
});

function votePost(threadId, postId, direction) {
    var fd = new FormData();
    fd.append('action', 'vote');
    fd.append('thread_id', threadId);
    fd.append('post_id', postId);
    fd.append('direction', direction);
    fd.append('csrf_token', '<?= csrfToken() ?>');
    fetch('/forum/api.php', {method:'POST', body:fd})
        .then(function(r){return r.json()})
        .then(function(data){ if(data.ok) location.reload(); else if(data.error) alert(data.error); });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
