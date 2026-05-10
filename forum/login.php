<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (isLoggedIn() && !needsPassword()) {
    header('Location: /forum/');
    exit;
}
if (isLoggedIn() && needsPassword()) {
    header('Location: /forum/setup.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } elseif (!empty($_POST['website_url'])) {
        // Honeypot triggered — silent reject
        $error = 'Invalid username or password.';
    } else {
        enforceRateLimit('forum_login', 10, 60);
        $result = doLogin($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($result['ok']) {
            if ($result['needs_password'] ?? false) {
                header('Location: /forum/setup.php');
            } else {
                header('Location: /forum/');
            }
            exit;
        }
        $error = $result['error'];
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="card auth-card">
        <h1>Welcome back</h1>
        <p class="subtitle">Sign in to your account</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/forum/login.php">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input class="form-input" type="text" id="username" name="username" required autofocus
                       value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input class="form-input" type="password" id="password" name="password">
            </div>
            <!-- Honeypot -->
            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                <input type="text" name="website_url" tabindex="-1" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Login</button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="/forum/register.php">Register with invite code</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
