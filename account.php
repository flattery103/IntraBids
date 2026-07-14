<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, (string)$user['password_hash'])) {
            flash('error', 'Your current password is not correct.');
        } elseif (strlen($newPassword) < 10) {
            flash('error', 'The new password must be at least 10 characters.');
        } elseif ($newPassword !== $confirmPassword) {
            flash('error', 'The new password and confirmation do not match.');
        } else {
            db_exec('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
            db_exec('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_sql(), (int)$user['id']]);
            log_action((int)$user['id'], 'password_changed', 'user', (int)$user['id']);
            flash('success', 'Your password has been changed.');
        }
        redirect('account.php');
    }

    if ($action === 'request_auction_access') {
        if ((string)setting('access_requests_enabled', '0') !== '1') {
            flash('error', 'Auction posting access requests are currently disabled.');
            redirect('account.php');
        }
        if (can_create_auctions()) {
            flash('success', 'Your account already has permission to post auctions.');
            redirect('account.php');
        }

        $pending = db_one("SELECT * FROM auction_access_requests WHERE user_id = ? AND status = 'pending' ORDER BY requested_at DESC LIMIT 1", [(int)$user['id']]);
        if ($pending) {
            flash('error', 'You already have a pending auction posting access request.');
            redirect('account.php');
        }

        $emailApprovalEnabled = (string)setting('access_request_email_approval_enabled', '0') === '1';
        $plainToken = $emailApprovalEnabled ? bin2hex(random_bytes(32)) : '';
        $tokenHash = $plainToken !== '' ? hash('sha256', $plainToken) : null;
        $expiresAt = $plainToken !== '' ? date('Y-m-d H:i:s', time() + (7 * 86400)) : null;

        db_exec(
            "INSERT INTO auction_access_requests (user_id, status, requested_at, approval_token_hash, approval_token_expires_at) VALUES (?, 'pending', ?, ?, ?)",
            [(int)$user['id'], now_sql(), $tokenHash, $expiresAt]
        );
        $requestId = (int)db()->lastInsertId();
        log_action((int)$user['id'], 'auction_access_requested', 'auction_access_request', $requestId);

        $admins = db_all("SELECT id, name, email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id");
        $sentCount = 0;
        $failedCount = 0;
        $siteName = (string)setting('site_name', 'IntraBids');
        $subject = 'Auction posting access request from ' . user_display($user);

        foreach ($admins as $admin) {
            $adminName = trim((string)($admin['name'] ?: 'Global Admin'));
            $requesterName = user_display($user);
            if ($emailApprovalEnabled && $plainToken !== '') {
                $actionUrl = base_url('approve_access.php?token=' . rawurlencode($plainToken));
                $buttonLabel = 'REVIEW AND APPROVE';
                $actionText = "Review and approve without signing in: " . $actionUrl;
                $expiryText = 'This approval link expires in 7 days. Opening the link does not approve the request until the confirmation button is selected.';
            } else {
                $actionUrl = base_url('admin/users.php');
                $buttonLabel = 'REVIEW REQUEST';
                $actionText = "Sign in to review the request: " . $actionUrl;
                $expiryText = 'Sign in to the global admin area to approve or deny this request.';
            }

            $plain = "Hello " . $adminName . ",\n\n"
                . $requesterName . " has requested access to post auctions.\n\n"
                . "Name: " . $requesterName . "\n"
                . "Email: " . $user['email'] . "\n"
                . "Requested: " . dt(now_sql()) . "\n\n"
                . $actionText . "\n\n" . $expiryText;

            $bodyHtml = '<p>Hello <strong>' . h($adminName) . '</strong>,</p>'
                . '<p><strong>' . h($requesterName) . '</strong> has submitted a request for access to post auctions.</p>'
                . '<p>Name: <strong>' . h($requesterName) . '</strong><br>Email: <strong>' . h((string)$user['email']) . '</strong><br>Requested: <strong>' . h(dt(now_sql())) . '</strong></p>'
                . email_button_html($actionUrl, $buttonLabel)
                . '<p style="margin-top:30px;"><strong>' . h($expiryText) . '</strong></p>';
            $html = email_page_html('Auction Posting Access Request Needs Action', $bodyHtml);

            if (send_app_html_email((string)$admin['email'], $subject, $plain, $html)) {
                $sentCount++;
            } else {
                $failedCount++;
                log_action((int)$user['id'], 'auction_access_email_failed', 'auction_access_request', $requestId, null, ['admin_id' => (int)$admin['id'], 'error' => get_last_email_error()]);
            }
        }

        if (!$admins) {
            flash('error', 'Your request was saved, but there are no active global administrators to notify.');
        } elseif ($sentCount === 0 && $failedCount > 0) {
            flash('error', 'Your request was saved, but the administrator notification email could not be sent. Global admins can still review it in the admin area.');
        } elseif ($failedCount > 0) {
            flash('success', 'Your request was submitted. Some administrator notification emails could not be delivered, but the request is available in the admin area.');
        } else {
            flash('success', 'Your request for auction posting access was submitted.');
        }
        redirect('account.php');
    }
}

$pendingRequest = db_one("SELECT * FROM auction_access_requests WHERE user_id = ? AND status = 'pending' ORDER BY requested_at DESC LIMIT 1", [(int)$user['id']]);
$latestRequest = db_one('SELECT * FROM auction_access_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 1', [(int)$user['id']]);

include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h1>My Account</h1>
    <p class="meta"><?= h($user['name']) ?> &mdash; <?= h($user['email']) ?></p>
</div>

<div class="grid account-grid">
    <div class="card">
        <h2>Change Password</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-row">
                <label for="current_password">Current Password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            </div>
            <div class="form-row">
                <label for="new_password">New Password</label>
                <input id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password" required>
                <div class="help">Minimum 10 characters.</div>
            </div>
            <div class="form-row">
                <label for="confirm_password">Confirm New Password</label>
                <input id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required>
            </div>
            <button type="submit">Change Password</button>
        </form>
    </div>

    <div class="card">
        <h2>Auction Posting Access</h2>
        <?php if (can_create_auctions()): ?>
            <div class="alert alert-success">Your account can create and publish auctions.</div>
        <?php elseif ((string)setting('access_requests_enabled', '0') !== '1'): ?>
            <p>Auction posting access requests are currently disabled. Contact a global administrator for access.</p>
        <?php elseif ($pendingRequest): ?>
            <div class="alert alert-info">Your request is pending review.</div>
            <p><strong>Requested:</strong> <?= h(dt($pendingRequest['requested_at'])) ?></p>
        <?php else: ?>
            <p>Request permission from the global administrators to create and publish auctions.</p>
            <?php if ($latestRequest): ?>
                <p><strong>Most recent request:</strong> <?= h(ucfirst((string)$latestRequest['status'])) ?> on <?= h(dt($latestRequest['requested_at'])) ?></p>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request_auction_access">
                <button type="submit">Request Access to Post Auctions</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
