<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_auction_creator();
if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if ($name === '') {
            flash('error', 'Category name is required.');
        } else {
            db_exec('INSERT INTO categories (name, description, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())', [$name, $description, $sortOrder]);
            log_action((int)current_user()['id'], 'category_created', 'category', (int)db()->lastInsertId());
            flash('success', 'Category added.');
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $old = db_one('SELECT * FROM categories WHERE id = ?', [$id]);
        if ($old) {
            db_exec('UPDATE categories SET name = ?, description = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?', [
                trim($_POST['name'] ?? ''),
                trim($_POST['description'] ?? ''),
                (int)($_POST['sort_order'] ?? 0),
                isset($_POST['is_active']) ? 1 : 0,
                $id,
            ]);
            log_action((int)current_user()['id'], 'category_updated', 'category', $id, $old, $_POST);
            flash('success', 'Category updated.');
        }
    }
    redirect('admin/categories.php');
}
$categories = db_all('SELECT * FROM categories ORDER BY sort_order, name');
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card"><h1>Categories</h1><p class="meta">Auction creators and global admins can create and manage auction categories.</p></div>
<div class="card">
    <h2>Add Category</h2>
    <form method="post" class="grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-row"><label>Name</label><input name="name" required></div>
        <div class="form-row"><label>Description</label><input name="description"></div>
        <div class="form-row"><label>Sort Order</label><input name="sort_order" type="number" value="0"></div>
        <div class="form-row"><label>&nbsp;</label><button type="submit">Add</button></div>
    </form>
</div>
<div class="card table-wrap">
    <table>
        <thead><tr><th>Name</th><th>Description</th><th>Sort</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
        <tr>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                <td><input name="name" value="<?= h($cat['name']) ?>" required></td>
                <td><input name="description" value="<?= h($cat['description']) ?>"></td>
                <td><input name="sort_order" type="number" value="<?= (int)$cat['sort_order'] ?>"></td>
                <td class="inline"><input name="is_active" type="checkbox" value="1" <?= (int)$cat['is_active'] === 1 ? 'checked' : '' ?>> Active</td>
                <td><button class="btn-small" type="submit">Save</button></td>
            </form>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
