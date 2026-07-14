<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_auction_creator();

function category_json_response(bool $success, string $message, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
    ]);
    exit;
}

function normalize_category_sort_order(): void
{
    $rows = db_all('SELECT id FROM categories ORDER BY sort_order, name, id');
    foreach ($rows as $index => $row) {
        db_exec('UPDATE categories SET sort_order = ?, updated_at = NOW() WHERE id = ?', [($index + 1) * 10, (int)$row['id']]);
    }
}

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? 'update';

    if ($action === 'reorder') {
        $categoryIds = json_decode((string)($_POST['category_ids'] ?? '[]'), true);
        if (!is_array($categoryIds)) {
            category_json_response(false, 'Invalid category order.', 422);
        }

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn($id) => $id > 0)));
        $existingIds = array_map(static fn($row) => (int)$row['id'], db_all('SELECT id FROM categories ORDER BY id'));
        $submittedCheck = $categoryIds;
        $existingCheck = $existingIds;
        sort($submittedCheck, SORT_NUMERIC);
        sort($existingCheck, SORT_NUMERIC);

        if ($submittedCheck !== $existingCheck) {
            category_json_response(false, 'The category list changed. Refresh the page and try again.', 409);
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();
            foreach ($categoryIds as $index => $categoryId) {
                db_exec('UPDATE categories SET sort_order = ?, updated_at = NOW() WHERE id = ?', [($index + 1) * 10, $categoryId]);
            }
            $pdo->commit();
            log_action((int)current_user()['id'], 'categories_reordered', 'category', null, null, ['category_ids' => $categoryIds]);
            category_json_response(true, 'Category order saved.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            category_json_response(false, 'The category order could not be saved.', 500);
        }
    }

    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($name === '') {
            flash('error', 'Category name is required.');
        } elseif (db_one('SELECT id FROM categories WHERE name = ? LIMIT 1', [$name])) {
            flash('error', 'A category with that name already exists.');
        } else {
            $nextSort = (int)(db_one('SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_sort FROM categories')['next_sort'] ?? 10);
            db_exec('INSERT INTO categories (name, description, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())', [$name, $description, $nextSort]);
            $categoryId = (int)db()->lastInsertId();
            log_action((int)current_user()['id'], 'category_created', 'category', $categoryId, null, ['name' => $name, 'description' => $description, 'sort_order' => $nextSort]);
            flash('success', 'Category added. Drag it to the desired position below.');
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $old = db_one('SELECT * FROM categories WHERE id = ?', [$id]);
        if (!$old) {
            flash('error', 'Category not found.');
        } elseif ($name === '') {
            flash('error', 'Category name is required.');
        } elseif (db_one('SELECT id FROM categories WHERE name = ? AND id <> ? LIMIT 1', [$name, $id])) {
            flash('error', 'A category with that name already exists.');
        } else {
            $newValues = [
                'name' => $name,
                'description' => $description,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];
            db_exec('UPDATE categories SET name = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?', [
                $newValues['name'],
                $newValues['description'],
                $newValues['is_active'],
                $id,
            ]);
            log_action((int)current_user()['id'], 'category_updated', 'category', $id, $old, $newValues);
            flash('success', 'Category updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $old = db_one('SELECT * FROM categories WHERE id = ?', [$id]);
        if (!$old) {
            flash('error', 'Category not found.');
        } else {
            $auctionCount = (int)(db_one('SELECT COUNT(*) AS total FROM auctions WHERE category_id = ?', [$id])['total'] ?? 0);
            if ($auctionCount > 0) {
                flash('error', 'This category cannot be deleted because it is used by ' . $auctionCount . ' auction' . ($auctionCount === 1 ? '' : 's') . '. Deactivate it instead.');
            } else {
                $pdo = db();
                try {
                    $pdo->beginTransaction();
                    db_exec('DELETE FROM categories WHERE id = ?', [$id]);
                    normalize_category_sort_order();
                    $pdo->commit();
                    log_action((int)current_user()['id'], 'category_deleted', 'category', $id, $old, null);
                    flash('success', 'Category deleted.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    flash('error', 'The category could not be deleted.');
                }
            }
        }
    }

    redirect('admin/categories.php');
}

$categories = db_all("SELECT c.*, COUNT(a.id) AS auction_count
    FROM categories c
    LEFT JOIN auctions a ON a.category_id = c.id
    GROUP BY c.id
    ORDER BY c.sort_order, c.name, c.id");
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <h1>Categories</h1>
    <p class="meta">Auction creators and global admins can create and manage auction categories. Drag categories by the handle to change their display order.</p>
</div>
<div class="card">
    <h2>Add Category</h2>
    <form method="post" class="grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-row"><label for="new-category-name">Name</label><input id="new-category-name" name="name" required></div>
        <div class="form-row"><label for="new-category-description">Description</label><input id="new-category-description" name="description"></div>
        <div class="form-row"><label>&nbsp;</label><button type="submit">Add</button></div>
    </form>
</div>
<div class="card table-wrap">
    <div class="category-table-heading">
        <div>
            <h2>Manage Categories</h2>
            <p class="meta">Categories that have been used by an auction cannot be deleted. Deactivate those categories instead.</p>
        </div>
        <div id="category-sort-status" class="category-sort-status" aria-live="polite"></div>
    </div>
    <table class="category-management-table">
        <thead><tr><th class="drag-column">Order</th><th>Name</th><th>Description</th><th>Auctions</th><th>Active</th><th>Actions</th></tr></thead>
        <tbody id="category-sort-list" data-endpoint="<?= h(base_url('admin/categories.php')) ?>" data-csrf-token="<?= h(csrf_token()) ?>">
        <?php foreach ($categories as $cat): ?>
        <?php $formId = 'category-form-' . (int)$cat['id']; ?>
        <tr class="category-sort-row" data-category-id="<?= (int)$cat['id'] ?>">
            <td class="drag-column">
                <button type="button" class="drag-handle" aria-label="Move <?= h($cat['name']) ?>" title="Drag to reorder; arrow keys also work">&#9776;</button>
            </td>
            <td><input form="<?= h($formId) ?>" name="name" value="<?= h($cat['name']) ?>" required></td>
            <td><input form="<?= h($formId) ?>" name="description" value="<?= h($cat['description']) ?>"></td>
            <td><span class="badge"><?= (int)$cat['auction_count'] ?></span></td>
            <td class="inline"><input form="<?= h($formId) ?>" id="category-active-<?= (int)$cat['id'] ?>" name="is_active" type="checkbox" value="1" <?= (int)$cat['is_active'] === 1 ? 'checked' : '' ?>><label for="category-active-<?= (int)$cat['id'] ?>">Active</label></td>
            <td>
                <form id="<?= h($formId) ?>" method="post" class="category-actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                    <button class="btn-small" type="submit" name="action" value="update">Save</button>
                    <?php if ((int)$cat['auction_count'] === 0): ?>
                        <button class="btn-small btn-danger category-delete-button" type="submit" name="action" value="delete" data-category-name="<?= h($cat['name']) ?>">Delete</button>
                    <?php else: ?>
                        <button class="btn-small btn-danger" type="button" disabled title="Categories used by auctions cannot be deleted">Delete</button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$categories): ?>
            <tr><td colspan="6">No categories have been created.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<script src="<?= h(base_url('assets/js/categories.js?v=' . rawurlencode(intrabid_version()))) ?>"></script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
