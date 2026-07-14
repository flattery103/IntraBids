<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_auction_creator();
$user = current_user();
$categories = db_all('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, name');
$defaultIncrement = setting('default_bid_increment', '1.00');
$defaultStart = date('Y-m-d\TH:i');
$defaultEnd = date('Y-m-d\TH:i', time() + 7 * 86400);

if (is_post()) {
    verify_csrf();
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
    $publish = ($_POST['action'] ?? '') === 'publish';

    if ($title === '' || $description === '' || !$categoryId) {
        flash('error', 'Title, description, and category are required.');
    } elseif ($startingBid < 0 || $bidIncrement <= 0) {
        flash('error', 'Starting bid must be zero or higher, and bid increment must be greater than zero.');
    } elseif (strtotime($startTime) === false || strtotime($endTime) === false || strtotime($endTime) <= strtotime($startTime)) {
        flash('error', 'End time must be after start time.');
    } else {
        try {
            $status = $publish ? calculate_publish_status($startTime, $endTime) : 'draft';
            db_exec('INSERT INTO auctions (category_id, created_by, title, description, item_condition, starting_bid, bid_increment, start_time, end_time, status, pickup_location, pickup_instructions, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())', [
                $categoryId, (int)$user['id'], $title, $description, $condition, $startingBid, $bidIncrement, $startTime, $endTime, $status, $pickupLocation, $pickupInstructions,
            ]);
            $auctionId = (int)db()->lastInsertId();
            upload_auction_images($auctionId, $_FILES['images'] ?? []);
            log_action((int)$user['id'], 'auction_created', 'auction', $auctionId, null, ['status' => $status]);
            flash('success', $publish ? 'Auction published.' : 'Auction saved as draft.');
            redirect('auction.php?id=' . $auctionId);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }
}
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <div class="inline" style="justify-content:space-between;"><h1>Create Auction</h1><a class="btn btn-secondary" href="<?= h(base_url('admin/categories.php')) ?>">Manage Categories</a></div>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="grid">
            <div class="form-row">
                <label for="title">Title</label>
                <input id="title" name="title" value="<?= h($_POST['title'] ?? '') ?>" required>
            </div>
            <div class="form-row">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id" required>
                    <option value="">Choose category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= ((int)($_POST['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <label for="description">Description</label>
            <textarea id="description" name="description" required><?= h($_POST['description'] ?? '') ?></textarea>
        </div>
        <div class="grid">
            <div class="form-row">
                <label for="item_condition">Condition</label>
                <input id="item_condition" name="item_condition" value="<?= h($_POST['item_condition'] ?? '') ?>" placeholder="Used, new, working, as-is, etc.">
            </div>
            <div class="form-row">
                <label for="images">Images</label>
                <input id="images" name="images[]" type="file" accept="image/*" multiple>
                <div class="help">JPG, PNG, GIF, or WebP. Max 5 MB each. The first image becomes primary; you can change it from Edit Auction.</div>
            </div>
        </div>
        <div class="grid">
            <div class="form-row">
                <label for="starting_bid">Starting Bid</label>
                <input id="starting_bid" name="starting_bid" type="number" min="0" step="0.01" value="<?= h($_POST['starting_bid'] ?? '0.00') ?>" required>
            </div>
            <div class="form-row">
                <label for="bid_increment">Bid Increment</label>
                <input id="bid_increment" name="bid_increment" type="number" min="0.01" step="0.01" value="<?= h($_POST['bid_increment'] ?? $defaultIncrement) ?>" required>
            </div>
            <div class="form-row">
                <label for="start_time">Start Date/Time</label>
                <input id="start_time" name="start_time" type="datetime-local" value="<?= h($_POST['start_time'] ?? $defaultStart) ?>" required>
            </div>
            <div class="form-row">
                <label for="end_time">End Date/Time</label>
                <input id="end_time" name="end_time" type="datetime-local" value="<?= h($_POST['end_time'] ?? $defaultEnd) ?>" required>
            </div>
        </div>
        <div class="grid">
            <div class="form-row">
                <label for="pickup_location">Pickup Location</label>
                <input id="pickup_location" name="pickup_location" value="<?= h($_POST['pickup_location'] ?? '') ?>">
            </div>
            <div class="form-row">
                <label for="pickup_instructions">Pickup Instructions</label>
                <textarea id="pickup_instructions" name="pickup_instructions"><?= h($_POST['pickup_instructions'] ?? '') ?></textarea>
            </div>
        </div>
        <button name="action" value="draft" type="submit" class="btn-secondary">Save Draft</button>
        <button name="action" value="publish" type="submit">Publish Auction</button>
    </form>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
