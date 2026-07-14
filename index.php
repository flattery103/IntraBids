<?php
require __DIR__ . '/includes/bootstrap.php';

$now = now_sql();
$recentlyEndedDays = max(1, min(365, (int)setting('recently_ended_days', '7')));
$homeAlertEnabled = (string)setting('home_alert_enabled', '0') === '1';
$homeAlertText = trim((string)setting('home_alert_text', ''));
$endedCutoff = date('Y-m-d H:i:s', strtotime('-' . $recentlyEndedDays . ' days'));
$categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selectedCategory = null;
if ($categoryId > 0) {
    $selectedCategory = db_one('SELECT * FROM categories WHERE id = ? AND is_active = 1', [$categoryId]);
    if (!$selectedCategory) {
        $categoryId = 0;
    }
}

$categoryCounts = db_all("SELECT c.*, COALESCE(SUM(CASE WHEN a.id IS NULL THEN 0 ELSE 1 END), 0) AS auction_count
    FROM categories c
    LEFT JOIN auctions a ON a.category_id = c.id
        AND a.status IN ('active','scheduled')
        AND a.end_time > ?
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY c.sort_order, c.name", [$now]);
$totalCurrentCount = 0;
foreach ($categoryCounts as $cat) {
    $totalCurrentCount += (int)$cat['auction_count'];
}

$categoryWhere = '';
$paramsActive = [$now, $now];
$paramsScheduled = [$now, $now];
$paramsEnded = [$endedCutoff];
if ($categoryId > 0) {
    $categoryWhere = ' AND a.category_id = ?';
    $paramsActive[] = $categoryId;
    $paramsScheduled[] = $categoryId;
    $paramsEnded[] = $categoryId;
}

$active = db_all("SELECT a.*, c.name AS category_name, u.name AS creator_name,
        (SELECT file_path FROM auction_images ai WHERE ai.auction_id = a.id ORDER BY sort_order, id LIMIT 1) AS image_path
    FROM auctions a
    JOIN categories c ON c.id = a.category_id
    JOIN users u ON u.id = a.created_by
    WHERE a.status IN ('active','scheduled')
      AND a.start_time <= ?
      AND a.end_time > ?
      $categoryWhere
    ORDER BY a.end_time ASC", $paramsActive);

$scheduled = db_all("SELECT a.*, c.name AS category_name,
        (SELECT file_path FROM auction_images ai WHERE ai.auction_id = a.id ORDER BY sort_order, id LIMIT 1) AS image_path
    FROM auctions a
    JOIN categories c ON c.id = a.category_id
    WHERE a.status IN ('active','scheduled')
      AND a.start_time > ?
      AND a.end_time > ?
      $categoryWhere
    ORDER BY a.start_time ASC
    LIMIT 12", $paramsScheduled);

$endedWhere = $categoryId > 0 ? 'AND a.category_id = ?' : '';
$ended = db_all("SELECT a.*, c.name AS category_name,
        (SELECT file_path FROM auction_images ai WHERE ai.auction_id = a.id ORDER BY sort_order, id LIMIT 1) AS image_path
    FROM auctions a
    JOIN categories c ON c.id = a.category_id
    WHERE a.status IN ('ended','awarded')
      AND a.end_time >= ?
      $endedWhere
    ORDER BY a.end_time DESC", $paramsEnded);

include __DIR__ . '/includes/header.php';
?>
<?php if ($homeAlertEnabled && $homeAlertText !== ''): ?>
    <div class="home-alert-banner" role="alert"><?= nl2br(h($homeAlertText)) ?></div>
<?php endif; ?>
<div class="auction-layout catalog-layout">
    <aside class="auction-sidebar card category-sidebar">
        <?php if (can_create_auctions()): ?>
            <div class="sidebar-actions">
                <a class="btn" href="<?= h(base_url('creator/create_auction.php')) ?>">Create Auction</a>
                <a class="btn btn-secondary" href="<?= h(base_url('admin/categories.php')) ?>">Manage Categories</a>
            </div>
        <?php endif; ?>
        <h2>Auction Categories</h2>
        <p class="meta">Filter the auction list by category.</p>
        <nav class="category-menu" aria-label="Auction categories">
            <a class="category-pill <?= $categoryId === 0 ? 'active' : '' ?>" href="<?= h(base_url('index.php')) ?>">
                <span>All Items</span>
                <span class="category-count">(<?= (int)$totalCurrentCount ?>)</span>
            </a>
            <?php foreach ($categoryCounts as $cat): ?>
                <a class="category-pill <?= $categoryId === (int)$cat['id'] ? 'active' : '' ?>" href="<?= h(base_url('index.php?category_id=' . (int)$cat['id'])) ?>">
                    <span><?= h($cat['name']) ?></span>
                    <span class="category-count">(<?= (int)$cat['auction_count'] ?>)</span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="auction-content">
        <div class="card">
            <h1><?= $selectedCategory ? h($selectedCategory['name']) . ' Auctions' : 'Current Auctions' ?></h1>
            <p class="meta">Browse active internal auctions and place bids before the timer ends.</p>
        </div>
        <?php if (!$active): ?>
            <div class="card"><p>No auctions are active right now<?= $selectedCategory ? ' in this category' : '' ?>.</p></div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($active as $auction): ?>
                    <div class="card auction-card">
                        <?php if ($auction['image_path']): ?>
                            <a class="auction-image-link auction-image-portrait" href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>" style="display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:3/4;min-height:260px;max-height:360px;background:#f8fafc;border:1px solid #d8dee6;border-radius:12px;overflow:hidden;"><img class="auction-thumb" src="<?= h(base_url($auction['image_path'])) ?>" alt="<?= h($auction['title']) ?>" style="width:100%;height:100%;object-fit:contain;display:block;"></a>
                        <?php else: ?>
                            <a class="auction-image-link auction-image-portrait" href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>" style="display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:3/4;min-height:260px;max-height:360px;background:#f8fafc;border:1px dashed #d8dee6;border-radius:12px;overflow:hidden;"><span class="auction-image-placeholder">No Image</span></a>
                        <?php endif; ?>
                        <div><?= status_badge('active') ?> <span class="meta"><?= h($auction['category_name']) ?></span></div>
                        <h2><a href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>"><?= h($auction['title']) ?></a></h2>
                        <div class="price"><?= $auction['current_high_bid'] === null ? money($auction['starting_bid']) . ' starting bid' : money($auction['current_high_bid']) ?></div>
                        <div class="meta">Ends in <strong data-countdown="<?= h(date('c', strtotime($auction['end_time']))) ?>"></strong></div>
                        <a class="btn" href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>">View / Bid</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($scheduled): ?>
            <div class="card"><h2>Scheduled Auctions</h2></div>
            <div class="grid">
                <?php foreach ($scheduled as $auction): ?>
                    <div class="card auction-card">
                        <?php if ($auction['image_path']): ?>
                            <a class="auction-image-link auction-image-portrait" href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>" style="display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:3/4;min-height:260px;max-height:360px;background:#f8fafc;border:1px solid #d8dee6;border-radius:12px;overflow:hidden;"><img class="auction-thumb" src="<?= h(base_url($auction['image_path'])) ?>" alt="<?= h($auction['title']) ?>" style="width:100%;height:100%;object-fit:contain;display:block;"></a>
                        <?php else: ?>
                            <a class="auction-image-link auction-image-portrait" href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>" style="display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:3/4;min-height:260px;max-height:360px;background:#f8fafc;border:1px dashed #d8dee6;border-radius:12px;overflow:hidden;"><span class="auction-image-placeholder">No Image</span></a>
                        <?php endif; ?>
                        <div><?= status_badge('scheduled') ?> <span class="meta"><?= h($auction['category_name']) ?></span></div>
                        <h3><a href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>"><?= h($auction['title']) ?></a></h3>
                        <div class="meta">Starts <?= h(dt($auction['start_time'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($ended): ?>
            <div class="card"><h2>Recently Ended<?= $selectedCategory ? ' in ' . h($selectedCategory['name']) : '' ?></h2><p class="meta">Showing all auctions ended in the last <?= (int)$recentlyEndedDays ?> day<?= $recentlyEndedDays === 1 ? '' : 's' ?>.</p></div>
            <div class="grid">
                <?php foreach ($ended as $auction): ?>
                    <div class="card auction-card">
                        <?php if ($auction['image_path']): ?>
                            <a class="auction-image-link auction-image-portrait" href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>" style="display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:3/4;min-height:260px;max-height:360px;background:#f8fafc;border:1px solid #d8dee6;border-radius:12px;overflow:hidden;"><img class="auction-thumb" src="<?= h(base_url($auction['image_path'])) ?>" alt="<?= h($auction['title']) ?>" style="width:100%;height:100%;object-fit:contain;display:block;"></a>
                        <?php else: ?>
                            <a class="auction-image-link auction-image-portrait" href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>" style="display:flex;align-items:center;justify-content:center;width:100%;aspect-ratio:3/4;min-height:260px;max-height:360px;background:#f8fafc;border:1px dashed #d8dee6;border-radius:12px;overflow:hidden;"><span class="auction-image-placeholder">No Image</span></a>
                        <?php endif; ?>
                        <div><?= status_badge($auction['status']) ?> <span class="meta"><?= h($auction['category_name']) ?></span></div>
                        <h3><a href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>"><?= h($auction['title']) ?></a></h3>
                        <div class="meta">Ended <?= h(dt($auction['end_time'])) ?></div>
                        <?php if ($auction['current_high_bid'] !== null): ?><div class="price"><?= money($auction['current_high_bid']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
