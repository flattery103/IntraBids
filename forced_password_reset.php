<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$user = current_user();

if ((int)($user['must_reset_password'] ?? 0) !== 1) {
    redirect('account.php');
}

if (is_post()) {
    verify_csrf();
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if (strlen($newPassword) < 10) {
        flash('error', 'The new password must be at least 10 characters.');
    } elseif ($newPassword !== $confirmPassword) {
        flash('error', 'The new password and confirmation do not match.');
    } else {
        db_exec('UPDATE users SET password_hash = ?, must_reset_password = 0, updated_at = NOW() WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
        db_exec('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_sql(), (int)$user['id']]);
        session_regenerate_id(true);
        log_action((int)$user['id'], 'forced_password_reset_completed', 'user', (int)$user['id']);
        flash('success', 'Your password has been reset.');
        redirect('index.php');
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="card auth-card">
    <h1>Password Reset Required</h1>
    <p>A global administrator requires you to set a new password before continuing.</p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="new_password">New Password</label>
            <input id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password" required autofocus>
            <div class="help">Minimum 10 characters.</div>
        </div>
        <div class="form-row">
            <label for="confirm_password">Confirm New Password</label>
            <input id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required>
        </div>
        <button type="submit">Set New Password</button>
        <a class="btn btn-secondary" href="<?= h(base_url('logout.php')) ?>">Logout</a>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
