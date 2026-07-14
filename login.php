<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('index.php');
}

if (is_post()) {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        db_exec('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?', [(int)$user['id']]);
        log_action((int)$user['id'], 'login', 'user', (int)$user['id']);
        if ((int)($user['must_reset_password'] ?? 0) === 1) {
            redirect('forced_password_reset.php');
        }
        redirect('index.php');
    }
    log_action($user ? (int)$user['id'] : null, 'login_failed', 'user', $user ? (int)$user['id'] : null, null, ['email' => $email]);
    flash('error', 'Invalid email, password, or inactive account.');
}

include __DIR__ . '/includes/header.php';
?>
<div class="card auth-card">
    <h1>Login</h1>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autofocus>
        </div>
        <div class="form-row">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>
        </div>
        <button type="submit">Login</button>
        <div class="auth-secondary-action">
            <a href="<?= h(base_url('forgot_password.php')) ?>">Forgot your password?</a>
        </div>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
