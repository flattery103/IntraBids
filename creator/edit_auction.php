<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_auction_creator();
$user = current_user();
$id = (int)($_GET['id'] ?? 0);
$auction = db_one('SELECT * FROM auctions WHERE id = ?', [$id]);
if (!$auction) {
    http_response_code(404);
    include dirname(__DIR__) . '/includes/header.php';
    echo '<div class="card"><h1>Auction not found</h1></div>';
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}
if (!is_admin() && (int)$auction['created_by'] !== (int)$user['id']) {
    http_response_code(403);
    include dirname(__DIR__) . '/includes/header.php';
    echo '<div class="card"><h1>Access denied</h1></div>';
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}
$bidCount = (int)(db_one('SELECT COUNT(*) AS c FROM bids WHERE auction_id = ?', [$id])['c'] ?? 0);
$canMajorEdit = is_admin() || ($bidCount === 0 && in_array($auction['status'], ['draft', 'scheduled'], true));
$categories = db_all('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, name');

if (isset($_GET['delete_image'])) {
    require_login();
    $imageId = (int)$_GET['delete_image'];
    $image = db_one('SELECT * FROM auction_images WHERE id = ? AND auction_id = ?', [$imageId, $id]);
    if ($image && $canMajorEdit) {
        db_exec('DELETE FROM auction_images WHERE id = ?', [$imageId]);
        if ((int)($image['is_primary'] ?? 0) === 1) {
            $replacement = db_one('SELECT id FROM auction_images WHERE auction_id = ? ORDER BY sort_order, id LIMIT 1', [$id]);
            if ($replacement) {
                db_exec('UPDATE auction_images SET is_primary = 1 WHERE id = ?', [(int)$replacement['id']]);
            }
        }
        $path = ROOT_PATH . '/' . $image['file_path'];
        if (is_file($path)) {
            @unlink($path);
        }
        log_action((int)$user['id'], 'auction_image_deleted', 'auction', $id, $image, null);
        flash('success', 'Image deleted.');
    }
    redirect('creator/edit_auction.php?id=' . $id);
}

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'set_primary_image') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $image = db_one('SELECT * FROM auction_images WHERE id = ? AND auction_id = ?', [$imageId, $id]);
        if (!$image) {
            flash('error', 'Image not found.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                db_exec('UPDATE auction_images SET is_primary = 0 WHERE auction_id = ?', [$id]);
                db_exec('UPDATE auction_images SET is_primary = 1 WHERE id = ? AND auction_id = ?', [$imageId, $id]);
                $pdo->commit();
                log_action((int)$user['id'], 'auction_primary_image_changed', 'auction', $id, null, ['image_id' => $imageId]);
                flash('success', 'Primary auction image updated.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', $e->getMessage());
            }
        }
        redirect('creator/edit_auction.php?id=' . $id);
    }

    if ($action === 'cancel') {
        if (in_array($auction['status'], ['ended', 'awarded', 'cancelled'], true)) {
            flash('error', 'This auction cannot be cancelled.');
        } else {
            db_exec("UPDATE auctions SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [$id]);
            log_action((int)$user['id'], 'auction_cancelled', 'auction', $id);
            flash('success', 'Auction cancelled.');
        }
        redirect('creator/edit_auction.php?id=' . $id);
    }

    if (!$canMajorEdit) {
        flash('error', 'This auction has bids or is active. Only a global admin can make major edits now.');
        redirect('creator/edit_auction.php?id=' . $id);
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $condition = trim($_POST['item_condition'] ?? '');
    $startingBid = round((float)($_POST['starting_bid'] ?? 0), 2);
    $bidIncrement = round((float)($_POST['bid_increment'] ?? 1), 2);
    $startTime = str_replace('T', ' ', trim($_POST['start_time'] ?? '')) . ':00';
    $endTime = str_replace('T', ' ', trim($_POST['end_time'] ?? '')) . ':00';
    $pickupLocation = trim($_POST['pickup_location'] ?? '');
    $pickupInstructions = trim($_POST['pickup_instructions'] ?? '');
    $publish = $action === 'publish';

    if ($title === '' || $description === '' || !$categoryId) {
        flash('error', 'Title, description, and category are required.');
    } elseif ($startingBid < 0 || $bidIncrement <= 0) {
        flash('error', 'Starting bid must be zero or higher, and bid increment must be greater than zero.');
    } elseif (strtotime($startTime) === false || strtotime($endTime) === false || strtotime($endTime) <= strtotime($startTime)) {
        flash('error', 'End time must be after start time.');
    } else {
        try {
            $newStatus = $publish ? calculate_publish_status($startTime, $endTime) : $auction['status'];
            if ($action === 'draft') {
                $newStatus = 'draft';
            }
            db_exec('UPDATE auctions SET category_id = ?, title = ?, description = ?, item_condition = ?, starting_bid = ?, bid_increment = ?, start_time = ?, end_time = ?, status = ?, pickup_location = ?, pickup_instructions = ?, updated_at = NOW() WHERE id = ?', [
                $categoryId, $title, $description, $condition, $startingBid, $bidIncrement, $startTime, $endTime, $newStatus, $pickupLocation, $pickupInstructions, $id,
            ]);
            upload_auction_images($id, $_FILES['images'] ?? []);
            log_action((int)$user['id'], 'auction_updated', 'auction', $id, $auction, ['status' => $newStatus]);
            flash('success', 'Auction updated.');
            redirect('auction.php?id=' . $id);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }
}

$auction = db_one('SELECT * FROM auctions WHERE id = ?', [$id]);
$images = db_all('SELECT * FROM auction_images WHERE auction_id = ? ORDER BY is_primary DESC, sort_order, id', [$id]);
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <div class="inline" style="justify-content:space-between;">
        <div>
            <h1>Edit Auction</h1>
            <p class="meta">Status: <?= status_badge(effective_auction_status($auction)) ?> | Bids: <?= $bidCount ?></p>
        </div>
        <div class="inline"><a class="btn btn-secondary" href="<?= h(base_url('admin/categories.php')) ?>">Manage Categories</a><a class="btn btn-secondary" href="<?= h(base_url('auction.php?id=' . $id)) ?>">View Auction</a></div>
    </div>
    <?php if (!$canMajorEdit): ?>
        <div class="alert alert-info">This auction has bids or is active. Major fields are locked for auction creators. Global admins can still edit if needed.</div>
    <?php endif; ?>
</div>

<div class="card">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <fieldset <?= $canMajorEdit ? '' : 'disabled' ?>>
            <div class="grid">
                <div class="form-row">
                    <label for="title">Title</label>
                    <input id="title" name="title" value="<?= h($auction['title']) ?>" required>
                </div>
                <div class="form-row">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= ((int)$auction['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label for="description">Description</label>
                <textarea id="description" name="description" required><?= h($auction['description']) ?></textarea>
            </div>
            <div class="grid">
                <div class="form-row">
                    <label for="item_condition">Condition</label>
                    <input id="item_condition" name="item_condition" value="<?= h($auction['item_condition']) ?>">
                </div>
                <div class="form-row">
                    <label for="images">Add Images</label>
                    <input id="images" name="images[]" type="file" accept="image/*" multiple>
                </div>
            </div>
            <div class="grid">
                <div class="form-row">
                    <label for="starting_bid">Starting Bid</label>
                    <input id="starting_bid" name="starting_bid" type="number" min="0" step="0.01" value="<?= h($auction['starting_bid']) ?>" required>
                </div>
                <div class="form-row">
                    <label for="bid_increment">Bid Increment</label>
                    <input id="bid_increment" name="bid_increment" type="number" min="0.01" step="0.01" value="<?= h($auction['bid_increment']) ?>" required>
                </div>
                <div class="form-row">
                    <label for="start_time">Start Date/Time</label>
                    <input id="start_time" name="start_time" type="datetime-local" value="<?= h(input_dt_value($auction['start_time'])) ?>" required>
                </div>
                <div class="form-row">
                    <label for="end_time">End Date/Time</label>
                    <input id="end_time" name="end_time" type="datetime-local" value="<?= h(input_dt_value($auction['end_time'])) ?>" required>
                </div>
            </div>
            <div class="grid">
                <div class="form-row">
                    <label for="pickup_location">Pickup Location</label>
                    <input id="pickup_location" name="pickup_location" value="<?= h($auction['pickup_location']) ?>">
                </div>
                <div class="form-row">
                    <label for="pickup_instructions">Pickup Instructions</label>
                    <textarea id="pickup_instructions" name="pickup_instructions"><?= h($auction['pickup_instructions']) ?></textarea>
                </div>
            </div>
        </fieldset>
        <?php if ($canMajorEdit): ?>
            <button name="action" value="save" type="submit">Save Changes</button>
            <button name="action" value="draft" type="submit" class="btn-secondary">Save as Draft</button>
            <button name="action" value="publish" type="submit">Publish / Recalculate Status</button>
        <?php endif; ?>
        <?php if (!in_array($auction['status'], ['ended', 'awarded', 'cancelled'], true)): ?>
            <button name="action" value="cancel" type="submit" class="btn-danger" onclick="return confirm('Cancel this auction?');">Cancel Auction</button>
        <?php endif; ?>
    </form>
</div>

<?php if ($images): ?>
<div class="card">
    <h2>Images</h2>
    <div class="image-gallery">
        <?php foreach ($images as $img): ?>
            <div class="auction-image-management-item <?= (int)$img['is_primary'] === 1 ? 'is-primary' : '' ?>">
                <img src="<?= h(base_url($img['file_path'])) ?>" alt="Auction image">
                <div class="image-management-actions">
                    <?php if ((int)$img['is_primary'] === 1): ?>
                        <span class="badge badge-active">Primary Image</span>
                    <?php else: ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_primary_image">
                            <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                            <button class="btn-small btn-secondary" type="submit">Make Primary</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canMajorEdit): ?>
                        <a class="btn btn-small btn-danger" onclick="return confirm('Delete this image?');" href="<?= h(base_url('creator/edit_auction.php?id=' . $id . '&delete_image=' . $img['id'])) ?>">Delete</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
