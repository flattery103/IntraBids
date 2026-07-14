<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('account.php');
}

if (is_post()) {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email address.');
        redirect('forgot_password.php');
    }

    $user = db_one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
    if ($user) {
        $recent = db_one('SELECT id FROM password_reset_tokens WHERE user_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 1', [(int)$user['id'], date('Y-m-d H:i:s', time() - 300)]);
        if (!$recent) {
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $plainToken);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            db_exec('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_sql(), (int)$user['id']]);
            db_exec('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at, requested_ip) VALUES (?, ?, ?, ?, ?)', [
                (int)$user['id'],
                $tokenHash,
                $expiresAt,
                now_sql(),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $tokenId = (int)db()->lastInsertId();
            $resetUrl = base_url('reset_password.php?token=' . rawurlencode($plainToken));
            $siteName = (string)setting('site_name', 'IntraBids');
            $subject = $siteName . ' password reset';
            $plain = "Hello " . user_display($user) . ",\n\nA password reset was requested for your " . $siteName . " account.\n\nReset your password: " . $resetUrl . "\n\nThis link expires in 1 hour and can only be used once. If you did not request this reset, you can ignore this email.";
            $bodyHtml = '<p>Hello <strong>' . h(user_display($user)) . '</strong>,</p>'
                . '<p>A password reset was requested for your ' . h($siteName) . ' account.</p>'
                . email_button_html($resetUrl, 'RESET PASSWORD')
                . '<p><strong>This link expires in 1 hour and can only be used once.</strong></p>'
                . '<p>If you did not request this reset, you can ignore this email.</p>';
            $html = email_page_html('Reset Your Password', $bodyHtml);

            if (!send_app_html_email((string)$user['email'], $subject, $plain, $html)) {
                db_exec('DELETE FROM password_reset_tokens WHERE id = ?', [$tokenId]);
                log_action((int)$user['id'], 'password_reset_email_failed', 'user', (int)$user['id'], null, ['error' => get_last_email_error()]);
            } else {
                log_action((int)$user['id'], 'password_reset_requested', 'user', (int)$user['id']);
            }
        }
    }

    flash('success', 'If an active account matches that email address and email delivery is available, a password reset link has been sent.');
    redirect('forgot_password.php');
}

include __DIR__ . '/includes/header.php';
?>
<div class="card auth-card">
    <h1>Forgot Your Password?</h1>
    <p>Enter the email address for your account. A one-time reset link will be emailed to you.</p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-row">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" autocomplete="email" required autofocus>
        </div>
        <button type="submit">Send Reset Link</button>
        <a class="btn btn-secondary" href="<?= h(base_url('login.php')) ?>">Back to Login</a>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
