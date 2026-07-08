<?php
require __DIR__ . '/includes/bootstrap.php';

if ((string)setting('registration_enabled', '1') !== '1') {
    include __DIR__ . '/includes/header.php';
    echo '<div class="card"><h1>Registration disabled</h1><p>New account registration is currently disabled.</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

if (is_logged_in()) {
    redirect('index.php');
}

if (is_post()) {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $domain = trim((string)setting('allowed_email_domain', ''));

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter your name and a valid email address.');
    } elseif ($domain !== '' && !str_ends_with($email, '@' . ltrim(strtolower($domain), '@'))) {
        flash('error', 'Registration is limited to the company email domain.');
    } elseif (strlen($password) < 10) {
        flash('error', 'Password must be at least 10 characters.');
    } elseif (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
        flash('error', 'That email address is already registered.');
    } else {
        db_exec('INSERT INTO users (name, email, password_hash, role, can_create_auctions, is_active, created_at, updated_at) VALUES (?, ?, ?, "user", 0, 1, NOW(), NOW())', [
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
        ]);
        $userId = (int)db()->lastInsertId();
        log_action($userId, 'registered', 'user', $userId);
        flash('success', 'Account created. You can now log in.');
        redirect('login.php');
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:620px;margin:0 auto;">
    <h1>Create account</h1>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="name">Name</label>
            <input id="name" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
        </div>
        <div class="form-row">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-row">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" minlength="10" required>
            <div class="help">Minimum 10 characters.</div>
        </div>
        <button type="submit">Register</button>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
