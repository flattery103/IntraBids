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

            db_exec(
                'UPDATE auction_access_requests SET status = ?, resolved_at = ?, resolved_by = ?, resolution_method = ?, approval_token_hash = NULL, approval_token_expires_at = NULL WHERE id = ?',
                [$status, now_sql(), (int)$current['id'], 'admin_interface', $requestId]
            );
            $pdo->commit();
            log_action((int)$current['id'], $logAction, 'auction_access_request', $requestId, null, ['user_id' => (int)$request['user_id']]);
            flash('success', $message);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
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
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    db_exec('UPDATE users SET name = ?, role = ?, can_create_auctions = ?, is_active = ?, updated_at = NOW() WHERE id = ?', [$name, $role, $canCreate, $isActive, $userId]);
                    if ($role === 'admin' || $canCreate === 1) {
                        db_exec(
                            "UPDATE auction_access_requests SET status = 'approved', resolved_at = ?, resolved_by = ?, resolution_method = 'admin_user_update', approval_token_hash = NULL, approval_token_expires_at = NULL WHERE user_id = ? AND status = 'pending'",
                            [now_sql(), (int)$current['id'], $userId]
                        );
                    }
                    $pdo->commit();
                    log_action((int)$current['id'], 'user_updated', 'user', $userId, $target, ['role' => $role, 'can_create_auctions' => $canCreate, 'is_active' => $isActive]);
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
    <p class="meta">Grant auction creation access here. There is no auction approval workflow; posting access means the user is trusted to publish auctions.</p>
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
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Can Create Auctions</th><th>Active</th><th>Last Login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <td><input name="name" value="<?= h($u['name']) ?>"></td>
                    <td><?= h($u['email']) ?></td>
                    <td>
                        <select name="role">
                            <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Global Admin</option>
                        </select>
                    </td>
                    <td class="inline"><input type="checkbox" name="can_create_auctions" value="1" <?= ((int)$u['can_create_auctions'] === 1 || $u['role'] === 'admin') ? 'checked' : '' ?>> Yes</td>
                    <td class="inline"><input type="checkbox" name="is_active" value="1" <?= (int)$u['is_active'] === 1 ? 'checked' : '' ?>> Active</td>
                    <td><?= h($u['last_login_at'] ? dt($u['last_login_at']) : 'Never') ?></td>
                    <td><button class="btn-small" type="submit">Save</button></td>
                </form>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
