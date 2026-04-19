<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!isLoggedIn()) {
    header('Location: /forum/login.php');
    exit;
}

$error = '';
$selectedCategory = $_GET['category'] ?? $_POST['category'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        enforceRateLimit('forum_thread', 5, 60);
        $result = createThread(
            $_POST['category'] ?? '',
            $_POST['title'] ?? '',
            $_POST['content'] ?? ''
        );
        if ($result['ok']) {
            header('Location: /forum/thread.php?id=' . $result['id']);
            exit;
        }
        $error = $result['error'];
    }
}

$categories = getCategories();
$navActive = 'forum';
$pageTitle = 'New Thread';
require_once __DIR__ . '/includes/header.php';
?>

<div class="forum-wrap" style="max-width:700px;">
    <div class="breadcrumbs">
        <a href="/forum/">Forum</a>
        <span class="sep">/</span>
        <span>New Thread</span>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Create a New Thread</h2>
        </div>
        <div style="padding:20px;">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <option value="">Select a category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat['id']) ?>" <?= $selectedCategory === $cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="title">Title</label>
                    <input class="form-input" type="text" id="title" name="title" required
                           minlength="3" maxlength="120"
                           value="<?= e($_POST['title'] ?? '') ?>"
                           placeholder="Thread title...">
                </div>
                <div class="form-group">
                    <label class="form-label" for="content">Content</label>
                    <textarea class="form-textarea" id="content" name="content" required
                              maxlength="10000" style="min-height:180px;"
                              placeholder="Write your post..."><?= e($_POST['content'] ?? '') ?></textarea>
                    <p class="form-hint">Supports **bold**, *italic*, `code`, ```code blocks```, and URLs</p>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" class="btn btn-primary">Create Thread</button>
                    <a href="/forum/" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
