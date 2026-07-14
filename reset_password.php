<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('account.php');
}

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenRow = null;
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $tokenRow = db_one(
        'SELECT prt.*, u.email, u.name, u.is_active FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE prt.token_hash = ? AND prt.used_at IS NULL AND prt.expires_at >= ? LIMIT 1',
        [hash('sha256', $token), now_sql()]
    );
    if ($tokenRow && (int)$tokenRow['is_active'] !== 1) {
        $tokenRow = null;
    }
}

if (is_post()) {
    verify_csrf();
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!$tokenRow) {
        flash('error', 'This password reset link is invalid, expired, or has already been used.');
        redirect('forgot_password.php');
    }
    if (strlen($newPassword) < 10) {
        flash('error', 'The new password must be at least 10 characters.');
        redirect('reset_password.php?token=' . rawurlencode($token));
    }
    if ($newPassword !== $confirmPassword) {
        flash('error', 'The new password and confirmation do not match.');
        redirect('reset_password.php?token=' . rawurlencode($token));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $locked = db_one('SELECT * FROM password_reset_tokens WHERE id = ? FOR UPDATE', [(int)$tokenRow['id']]);
        if (!$locked || $locked['used_at'] !== null || strtotime((string)$locked['expires_at']) < time()) {
            throw new RuntimeException('This password reset link is invalid, expired, or has already been used.');
        }
        db_exec('UPDATE users SET password_hash = ?, must_reset_password = 0, updated_at = NOW() WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), (int)$locked['user_id']]);
        db_exec('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_sql(), (int)$locked['user_id']]);
        $pdo->commit();
        log_action((int)$locked['user_id'], 'password_reset_completed', 'user', (int)$locked['user_id']);
        flash('success', 'Your password has been reset. You can now log in.');
        redirect('login.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
        redirect('forgot_password.php');
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="card auth-card">
    <?php if (!$tokenRow): ?>
        <h1>Reset Link Unavailable</h1>
        <div class="alert alert-error">This password reset link is invalid, expired, or has already been used.</div>
        <a class="btn" href="<?= h(base_url('forgot_password.php')) ?>">Request a New Link</a>
    <?php else: ?>
        <h1>Reset Password</h1>
        <p>Set a new password for <strong><?= h((string)$tokenRow['email']) ?></strong>.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div class="form-row">
                <label for="new_password">New Password</label>
                <input id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password" required autofocus>
                <div class="help">Minimum 10 characters.</div>
            </div>
            <div class="form-row">
                <label for="confirm_password">Confirm New Password</label>
                <input id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required>
            </div>
            <button type="submit">Reset Password</button>
        </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
