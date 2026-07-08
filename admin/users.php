<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();
$current = current_user();
if (is_post()) {
    verify_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $target = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($target) {
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $canCreate = isset($_POST['can_create_auctions']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $name = trim($_POST['name'] ?? $target['name']);

        if ((int)$target['id'] === (int)$current['id'] && ($role !== 'admin' || !$isActive)) {
            flash('error', 'You cannot remove your own admin access or deactivate your own account.');
        } else {
            $adminCount = (int)(db_one('SELECT COUNT(*) AS c FROM users WHERE role = "admin" AND is_active = 1')['c'] ?? 0);
            if ($target['role'] === 'admin' && $role !== 'admin' && $adminCount <= 1) {
                flash('error', 'At least one active global admin is required.');
            } else {
                db_exec('UPDATE users SET name = ?, role = ?, can_create_auctions = ?, is_active = ?, updated_at = NOW() WHERE id = ?', [$name, $role, $canCreate, $isActive, $userId]);
                log_action((int)$current['id'], 'user_updated', 'user', $userId, $target, ['role' => $role, 'can_create_auctions' => $canCreate, 'is_active' => $isActive]);
                flash('success', 'User updated.');
            }
        }
    }
    redirect('admin/users.php');
}
$users = db_all('SELECT * FROM users ORDER BY is_active DESC, name');
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <h1>Users</h1>
    <p class="meta">Grant auction creation access here. There is no auction approval workflow; posting access means the user is trusted to publish auctions.</p>
</div>
<div class="card table-wrap">
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Can Create Auctions</th><th>Active</th><th>Last Login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <form method="post">
                    <?= csrf_field() ?>
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
