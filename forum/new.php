<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!isLoggedIn()) { header('Location: /forum/login.php'); exit; }

$error = '';
$selectedCategory = $_GET['category'] ?? $_POST['category'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        enforceRateLimit('forum_thread', 5, 60);

        $tags = $_POST['tags'] ?? [];
        $poll = null;
        if (!empty($_POST['poll_question'])) {
            $poll = [
                'question' => $_POST['poll_question'],
                'options' => array_values(array_filter($_POST['poll_options'] ?? [], fn($o) => trim($o) !== ''))
            ];
        }

        $prefix = $_POST['prefix'] ?? '';

        $result = createThread(
            $_POST['category'] ?? '',
            $_POST['title'] ?? '',
            $_POST['content'] ?? '',
            $tags,
            $poll,
            $prefix
        );
        if ($result['ok']) {
            header('Location: /forum/thread.php?id=' . $result['id']);
            exit;
        }
        $error = $result['error'];
    }
}

$categories = getCategories();
$availableTags = getAvailableTags();
$availablePrefixes = getAvailablePrefixes();
$navActive = 'forum';
$pageTitle = 'New Thread';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap" style="max-width:700px;">
    <div class="breadcrumbs">
        <a href="/forum/">m190</a><span class="sep">/</span><span>New Thread</span>
    </div>

    <div class="card">
        <div class="card-header"><h2>Create a New Thread</h2></div>
        <div style="padding:20px;">
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label" for="category">Category</label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">Select...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= e($cat['id']) ?>" <?= $selectedCategory === $cat['id'] ? 'selected' : '' ?>><?= e((!empty($cat['parent']) ? '-- ' : '') . $cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label" for="prefix">Prefix</label>
                        <select class="form-select" id="prefix" name="prefix">
                            <option value="">None</option>
                            <?php foreach ($availablePrefixes as $pfx): ?>
                                <option value="<?= e($pfx['id']) ?>" <?= ($_POST['prefix'] ?? '') === $pfx['id'] ? 'selected' : '' ?>><?= e($pfx['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Tags</label>
                    <div class="tag-select">
                        <?php foreach ($availableTags as $tag): ?>
                        <label>
                            <input type="checkbox" name="tags[]" value="<?= e($tag['id']) ?>" <?= in_array($tag['id'], $_POST['tags'] ?? []) ? 'checked' : '' ?>>
                            <span class="thread-tag" style="background:<?= $tag['color'] ?>18;color:<?= $tag['color'] ?>;border-color:<?= $tag['color'] ?>30"><?= e($tag['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="title">Title</label>
                    <input class="form-input" type="text" id="title" name="title" required minlength="3" maxlength="120" value="<?= e($_POST['title'] ?? '') ?>" placeholder="Thread title...">
                </div>

                <div class="form-group">
                    <label class="form-label" for="content">Content</label>
                    <textarea class="form-textarea" id="content" name="content" required maxlength="10000" style="min-height:180px;" placeholder="Write your post..."><?= e($_POST['content'] ?? '') ?></textarea>
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

                <!-- Poll (collapsible) -->
                <details style="margin-bottom:16px;">
                    <summary style="color:#7aa2ff; font-size:0.8rem; cursor:pointer; font-weight:600;">Add a Poll (optional)</summary>
                    <div style="padding-top:12px;">
                        <div class="form-group">
                            <label class="form-label" for="poll_question">Poll Question</label>
                            <input class="form-input" type="text" id="poll_question" name="poll_question" value="<?= e($_POST['poll_question'] ?? '') ?>" placeholder="What do you want to ask?">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Options (2-10)</label>
                            <div class="poll-options-form" id="poll-options">
                                <?php for ($i = 0; $i < max(3, count($_POST['poll_options'] ?? [])); $i++): ?>
                                <input class="form-input" type="text" name="poll_options[]" value="<?= e($_POST['poll_options'][$i] ?? '') ?>" placeholder="Option <?= $i + 1 ?>">
                                <?php endfor; ?>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm mt-2" onclick="addPollOption()">+ Add Option</button>
                        </div>
                    </div>
                </details>

                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary">Create Thread</button>
                    <a href="/forum/" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function addPollOption() {
    var c = document.getElementById('poll-options');
    if (c.children.length >= 10) return;
    var inp = document.createElement('input');
    inp.className = 'form-input';
    inp.type = 'text';
    inp.name = 'poll_options[]';
    inp.placeholder = 'Option ' + (c.children.length + 1);
    c.appendChild(inp);
}

function uploadImg(input) {
    if (!input.files[0]) return;
    var fd = new FormData();
    fd.append('image', input.files[0]);
    fd.append('action', 'upload_image');
    fetch('/forum/api.php', {method:'POST', body:fd})
        .then(function(r){return r.json()})
        .then(function(data) {
            if (data.ok) {
                var ta = document.getElementById('content');
                ta.value += (ta.value ? '\n' : '') + '![image](' + data.url + ')';
                ta.focus();
            } else { alert(data.error || 'Upload failed'); }
        }).catch(function(){alert('Upload failed');});
    input.value = '';
}

function togglePreview() {
    var pane = document.getElementById('preview-pane');
    var ta = document.getElementById('content');
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
            if(line.match(/^&gt;\s?(.*)/)){if(!inQ){out.push('<blockquote class="quote-block">');inQ=true;}out.push(line.replace(/^&gt;\s?/,''));}
            else{if(inQ){out.push('</blockquote>');inQ=false;}out.push(line);}
        });
        if(inQ) out.push('</blockquote>');
        t = out.join('<br>');
        pane.innerHTML = t || '<span class="text-muted">Nothing to preview</span>';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
