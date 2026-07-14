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
            db_exec('UPDATE users SET password_hash = ?, must_reset_password = 0, updated_at = NOW() WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
            db_exec('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_sql(), (int)$user['id']]);
            log_action((int)$user['id'], 'password_changed', 'user', (int)$user['id']);
            flash('success', 'Your password has been changed.');
        }
        redirect('account.php');
    }

    if ($action === 'save_email_preferences') {
        $values = [
            isset($_POST['email_outbid']) ? 1 : 0,
            isset($_POST['email_auction_won']) ? 1 : 0,
            isset($_POST['email_creator_ended']) ? 1 : 0,
            isset($_POST['email_access_request_updates']) ? 1 : 0,
        ];
        db_exec(
            'INSERT INTO user_notification_preferences (user_id, email_outbid, email_auction_won, email_creator_ended, email_access_request_updates, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE email_outbid = VALUES(email_outbid), email_auction_won = VALUES(email_auction_won), email_creator_ended = VALUES(email_creator_ended), email_access_request_updates = VALUES(email_access_request_updates), updated_at = VALUES(updated_at)',
            [(int)$user['id'], ...$values, now_sql()]
        );
        log_action((int)$user['id'], 'notification_preferences_updated', 'user', (int)$user['id'], null, [
            'email_outbid' => $values[0],
            'email_auction_won' => $values[1],
            'email_creator_ended' => $values[2],
            'email_access_request_updates' => $values[3],
        ]);
        flash('success', 'Email notification preferences updated.');
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
        db_exec(
            "INSERT INTO auction_access_requests (user_id, status, requested_at, approval_token_hash, approval_token_expires_at) VALUES (?, 'pending', ?, NULL, NULL)",
            [(int)$user['id'], now_sql()]
        );
        $requestId = (int)db()->lastInsertId();
        log_action((int)$user['id'], 'auction_access_requested', 'auction_access_request', $requestId);

        $admins = db_all("SELECT id, name, email FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id");
        $sentCount = 0;
        $failedCount = 0;
        $subject = 'Auction posting access request from ' . user_display($user);

        foreach ($admins as $admin) {
            $adminName = trim((string)($admin['name'] ?: 'Global Admin'));
            $requesterName = user_display($user);
            $tokenRowId = 0;

            if ($emailApprovalEnabled) {
                $plainToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + (7 * 86400));
                db_exec(
                    'INSERT INTO auction_access_approval_tokens (request_id, admin_user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$requestId, (int)$admin['id'], hash('sha256', $plainToken), $expiresAt, now_sql()]
                );
                $tokenRowId = (int)db()->lastInsertId();
                $actionUrl = base_url('approve_access.php?token=' . rawurlencode($plainToken));
                $buttonLabel = 'REVIEW AND APPROVE';
                $actionText = 'Review and approve without signing in: ' . $actionUrl;
                $expiryText = 'This approval link is unique to you and expires in 7 days. Opening the link does not approve the request until the confirmation button is selected.';
            } else {
                $actionUrl = base_url('admin/users.php');
                $buttonLabel = 'REVIEW REQUEST';
                $actionText = 'Sign in to review the request: ' . $actionUrl;
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
                if ($tokenRowId > 0) {
                    db_exec('DELETE FROM auction_access_approval_tokens WHERE id = ?', [$tokenRowId]);
                }
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
$emailPreferences = notification_preferences((int)$user['id']);

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

    <div class="card">
        <h2>Email Notifications</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_email_preferences">
            <div class="form-row checkbox-stack">
                <label><input type="checkbox" name="email_outbid" value="1" <?= (int)$emailPreferences['email_outbid'] === 1 ? 'checked' : '' ?>> Email me when I am outbid</label>
                <label><input type="checkbox" name="email_auction_won" value="1" <?= (int)$emailPreferences['email_auction_won'] === 1 ? 'checked' : '' ?>> Email me when I win an auction</label>
                <label><input type="checkbox" name="email_creator_ended" value="1" <?= (int)$emailPreferences['email_creator_ended'] === 1 ? 'checked' : '' ?>> Email me when an auction I created ends</label>
                <label><input type="checkbox" name="email_access_request_updates" value="1" <?= (int)$emailPreferences['email_access_request_updates'] === 1 ? 'checked' : '' ?>> Email me when my posting-access request is approved or denied</label>
            </div>
            <p class="help">Password-reset and other essential security messages are always sent when needed.</p>
            <button type="submit">Save Notification Preferences</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
