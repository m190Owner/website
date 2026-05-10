<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (isLoggedIn()) {
    header('Location: /forum/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } elseif (!empty($_POST['website_url'])) {
        $error = 'Registration failed.';
    } else {
        enforceRateLimit('forum_register', 5, 60);
        $result = doRegister(
            $_POST['username'] ?? '',
            $_POST['password'] ?? '',
            $_POST['invite_code'] ?? ''
        );
        if ($result['ok']) {
            header('Location: /forum/');
            exit;
        }
        $error = $result['error'];
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="card auth-card">
        <h1>Join m190</h1>
        <p class="subtitle">You need an invite code to register</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/forum/register.php">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label" for="invite_code">Invite Code</label>
                <input class="form-input" type="text" id="invite_code" name="invite_code" required
                       placeholder="XXXXXXXX" value="<?= e($_POST['invite_code'] ?? '') ?>"
                       style="text-transform:uppercase; letter-spacing:2px; font-family:Consolas,monospace;">
            </div>
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input class="form-input" type="text" id="username" name="username" required
                       minlength="3" maxlength="24" pattern="[a-zA-Z0-9_]+"
                       value="<?= e($_POST['username'] ?? '') ?>">
                <p class="form-hint">3-24 characters, letters, numbers, and underscores only</p>
            </div>
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input class="form-input" type="password" id="password" name="password" required minlength="8">
                <p class="form-hint">Minimum 8 characters</p>
            </div>
            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                <input type="text" name="website_url" tabindex="-1" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Create Account</button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="/forum/login.php">Login</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
