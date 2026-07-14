<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();
$current = current_user();

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'update_user');

    if (in_array($action, ['approve_access_request', 'deny_access_request'], true)) {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $request = db_one("SELECT r.*, u.name, u.email FROM auction_access_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? FOR UPDATE", [$requestId]);
            if (!$request || $request['status'] !== 'pending') {
                throw new RuntimeException('That access request is no longer pending.');
            }

            if ($action === 'approve_access_request') {
                db_exec('UPDATE users SET can_create_auctions = 1, updated_at = NOW() WHERE id = ?', [(int)$request['user_id']]);
                $status = 'approved';
                $logAction = 'auction_access_approved';
                $message = user_display($request) . ' can now create and publish auctions.';
            } else {
                $status = 'denied';
                $logAction = 'auction_access_denied';
                $message = 'The auction posting access request was denied.';
            }

            $resolvedAt = now_sql();
            db_exec(
                'UPDATE auction_access_requests SET status = ?, resolved_at = ?, resolved_by = ?, resolution_method = ?, approval_token_hash = NULL, approval_token_expires_at = NULL WHERE id = ?',
                [$status, $resolvedAt, (int)$current['id'], 'admin_interface', $requestId]
            );
            db_exec('UPDATE auction_access_approval_tokens SET used_at = ? WHERE request_id = ? AND used_at IS NULL', [$resolvedAt, $requestId]);
            $pdo->commit();
            log_action((int)$current['id'], $logAction, 'auction_access_request', $requestId, null, ['user_id' => (int)$request['user_id']]);
            notify_access_request_resolution($requestId);
            flash('success', $message);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
        }
        redirect('admin/users.php');
    }

    if ($action === 'force_password_reset') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$target) {
            flash('error', 'User not found.');
            redirect('admin/users.php');
        }
        if ((int)$target['is_active'] !== 1) {
            flash('error', 'Activate the user before requiring a password reset.');
            redirect('admin/users.php');
        }

        $token = null;
        $emailSent = false;
        try {
            $pdo = db();
            $pdo->beginTransaction();
            db_exec('UPDATE users SET must_reset_password = 1, updated_at = NOW() WHERE id = ?', [$userId]);
            $token = create_password_reset_token($userId, $_SERVER['REMOTE_ADDR'] ?? null, 3600);
            $pdo->commit();
            $emailSent = send_password_reset_message($target, (string)$token['token'], true);
            if (!$emailSent) {
                db_exec('DELETE FROM password_reset_tokens WHERE id = ?', [(int)$token['id']]);
            }
            log_action((int)$current['id'], 'user_force_password_reset', 'user', $userId, null, [
                'email_sent' => $emailSent,
                'email_error' => $emailSent ? null : get_last_email_error(),
            ]);
            flash('success', $emailSent
                ? 'The user must reset their password. A one-hour reset link was emailed to them.'
                : 'The user must reset their password at their next login, but the reset email could not be sent: ' . get_last_email_error());
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'The password-reset requirement could not be applied: ' . $e->getMessage());
        }
        redirect('admin/users.php');
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    $target = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($target) {
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $canCreate = isset($_POST['can_create_auctions']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $name = trim((string)($_POST['name'] ?? $target['name']));

        if ((int)$target['id'] === (int)$current['id'] && ($role !== 'admin' || !$isActive)) {
            flash('error', 'You cannot remove your own admin access or deactivate your own account.');
        } else {
            $adminCount = (int)(db_one('SELECT COUNT(*) AS c FROM users WHERE role = "admin" AND is_active = 1')['c'] ?? 0);
            if ($target['role'] === 'admin' && $role !== 'admin' && $adminCount <= 1) {
                flash('error', 'At least one active global admin is required.');
            } else {
                $pendingRequestIds = [];
                if ($role === 'admin' || $canCreate === 1) {
                    $pendingRequestIds = array_map(
                        static fn(array $row): int => (int)$row['id'],
                        db_all("SELECT id FROM auction_access_requests WHERE user_id = ? AND status = 'pending'", [$userId])
                    );
                }
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    db_exec('UPDATE users SET name = ?, role = ?, can_create_auctions = ?, is_active = ?, updated_at = NOW() WHERE id = ?', [$name, $role, $canCreate, $isActive, $userId]);
                    if ($pendingRequestIds) {
                        $resolvedAt = now_sql();
                        db_exec(
                            "UPDATE auction_access_requests SET status = 'approved', resolved_at = ?, resolved_by = ?, resolution_method = 'admin_user_update', approval_token_hash = NULL, approval_token_expires_at = NULL WHERE user_id = ? AND status = 'pending'",
                            [$resolvedAt, (int)$current['id'], $userId]
                        );
                        $placeholders = implode(',', array_fill(0, count($pendingRequestIds), '?'));
                        db_exec("UPDATE auction_access_approval_tokens SET used_at = ? WHERE request_id IN ($placeholders) AND used_at IS NULL", array_merge([$resolvedAt], $pendingRequestIds));
                    }
                    $pdo->commit();
                    log_action((int)$current['id'], 'user_updated', 'user', $userId, $target, ['role' => $role, 'can_create_auctions' => $canCreate, 'is_active' => $isActive]);
                    foreach ($pendingRequestIds as $requestId) {
                        notify_access_request_resolution($requestId);
                    }
                    flash('success', 'User updated.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    flash('error', $e->getMessage());
                }
            }
        }
    }
    redirect('admin/users.php');
}

$pendingRequests = db_all(
    "SELECT r.*, u.name, u.email FROM auction_access_requests r JOIN users u ON u.id = r.user_id WHERE r.status = 'pending' ORDER BY r.requested_at ASC"
);
$users = db_all('SELECT * FROM users ORDER BY is_active DESC, name');
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <h1>Users</h1>
</div>

<div class="card table-wrap">
    <h2>Pending Auction Posting Access Requests<?php if ($pendingRequests): ?> <span class="badge badge-scheduled"><?= count($pendingRequests) ?></span><?php endif; ?></h2>
    <?php if (!$pendingRequests): ?>
        <p class="meta">There are no pending access requests.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Requested</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pendingRequests as $request): ?>
                <tr>
                    <td><?= h((string)$request['name']) ?></td>
                    <td><?= h((string)$request['email']) ?></td>
                    <td><?= h(dt((string)$request['requested_at'])) ?></td>
                    <td>
                        <div class="inline">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve_access_request">
                                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                <button class="btn-small" type="submit">Approve</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Deny this auction posting access request?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deny_access_request">
                                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                <button class="btn-small btn-danger" type="submit">Deny</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card table-wrap">
    <h2>All Users</h2>
    <table class="admin-users-table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Can Create Auctions</th><th>Active</th><th>Password</th><th>Last Login</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <?php $formId = 'user-form-' . (int)$u['id']; ?>
            <tr>
                <td><input form="<?= h($formId) ?>" name="name" value="<?= h($u['name']) ?>" aria-label="Name for <?= h($u['name']) ?>"></td>
                <td><?= h($u['email']) ?></td>
                <td>
                    <select form="<?= h($formId) ?>" name="role" aria-label="Role for <?= h($u['name']) ?>">
                        <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Global Admin</option>
                    </select>
                </td>
                <td><label class="inline-choice"><input form="<?= h($formId) ?>" type="checkbox" name="can_create_auctions" value="1" <?= ((int)$u['can_create_auctions'] === 1 || $u['role'] === 'admin') ? 'checked' : '' ?>> Yes</label></td>
                <td><label class="inline-choice"><input form="<?= h($formId) ?>" type="checkbox" name="is_active" value="1" <?= (int)$u['is_active'] === 1 ? 'checked' : '' ?>> Active</label></td>
                <td><?= (int)$u['must_reset_password'] === 1 ? '<span class="badge badge-ended">Reset required</span>' : '<span class="badge badge-active">Current</span>' ?></td>
                <td><?= h($u['last_login_at'] ? dt($u['last_login_at']) : 'Never') ?></td>
                <td>
                    <form id="<?= h($formId) ?>" method="post" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button class="btn-small" type="submit">Save</button>
                    </form>
                    <form method="post" class="inline-form" onsubmit="return confirm('Require this user to reset their password?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="force_password_reset">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button class="btn-small btn-secondary" type="submit">Force Password Reset</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
