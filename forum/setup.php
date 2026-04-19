<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!isLoggedIn() || !needsPassword()) {
    header('Location: /forum/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if ($pass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $result = setPassword($pass);
            if ($result['ok']) {
                header('Location: /forum/');
                exit;
            }
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Set Password';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="card auth-card">
        <h1>Set your password</h1>
        <p class="subtitle">Welcome, <?= e(currentUser()) ?>. Choose a password for your account.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label" for="password">New Password</label>
                <input class="form-input" type="password" id="password" name="password" required minlength="8" autofocus>
                <p class="form-hint">Minimum 8 characters</p>
            </div>
            <div class="form-group">
                <label class="form-label" for="password_confirm">Confirm Password</label>
                <input class="form-input" type="password" id="password_confirm" name="password_confirm" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Set Password</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
