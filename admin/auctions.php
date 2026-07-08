<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();
if (is_post()) {
    verify_csrf();
    $auctionId = (int)($_POST['auction_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $auction = db_one('SELECT * FROM auctions WHERE id = ?', [$auctionId]);
    if ($auction && $action === 'cancel' && !in_array($auction['status'], ['ended','awarded','cancelled'], true)) {
        db_exec("UPDATE auctions SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [$auctionId]);
        log_action((int)current_user()['id'], 'auction_cancelled_by_admin', 'auction', $auctionId, $auction, null);
        flash('success', 'Auction cancelled.');
    } elseif ($auction && $action === 'close' && in_array(effective_auction_status($auction), ['active','scheduled'], true)) {
        db_exec('UPDATE auctions SET end_time = DATE_SUB(NOW(), INTERVAL 1 SECOND), updated_at = NOW() WHERE id = ?', [$auctionId]);
        close_single_auction($auctionId);
        flash('success', 'Auction closed.');
    }
    redirect('admin/auctions.php');
}
$status = $_GET['status'] ?? '';
$params = [];
$where = '';
if (in_array($status, ['draft','scheduled','active','ended','awarded','cancelled'], true)) {
    if ($status === 'active') {
        $where = "WHERE a.status IN ('active','scheduled') AND a.start_time <= ? AND a.end_time > ?";
        $params[] = now_sql();
        $params[] = now_sql();
    } elseif ($status === 'scheduled') {
        $where = "WHERE a.status IN ('active','scheduled') AND a.start_time > ? AND a.end_time > ?";
        $params[] = now_sql();
        $params[] = now_sql();
    } else {
        $where = 'WHERE a.status = ?';
        $params[] = $status;
    }
}
$auctions = db_all("SELECT a.*, c.name AS category_name, u.name AS creator_name, wu.name AS winner_name, COUNT(b.id) AS bid_count
    FROM auctions a
    JOIN categories c ON c.id = a.category_id
    JOIN users u ON u.id = a.created_by
    LEFT JOIN users wu ON wu.id = a.winning_user_id
    LEFT JOIN bids b ON b.auction_id = a.id
    $where
    GROUP BY a.id
    ORDER BY a.created_at DESC", $params);
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <h1>Auctions</h1>
    <div class="inline">
        <a class="btn btn-secondary" href="<?= h(base_url('admin/auctions.php')) ?>">All</a>
        <?php foreach (['draft','scheduled','active','awarded','ended','cancelled'] as $s): ?>
            <a class="btn btn-secondary" href="<?= h(base_url('admin/auctions.php?status=' . $s)) ?>"><?= h(ucfirst($s)) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<div class="card table-wrap">
    <table>
        <thead><tr><th>Title</th><th>Creator</th><th>Category</th><th>Status</th><th>Bids</th><th>High Bid/Winner</th><th>End</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($auctions as $auction): ?>
        <tr>
            <td><a href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>"><?= h($auction['title']) ?></a></td>
            <td><?= h($auction['creator_name']) ?></td>
            <td><?= h($auction['category_name']) ?></td>
            <td><?= status_badge(effective_auction_status($auction)) ?></td>
            <td><?= (int)$auction['bid_count'] ?></td>
            <td><?= $auction['current_high_bid'] === null ? '—' : money($auction['current_high_bid']) ?><?= $auction['winner_name'] ? '<br><span class="meta">' . h($auction['winner_name']) . '</span>' : '' ?></td>
            <td><?= h(dt($auction['end_time'])) ?></td>
            <td>
                <div class="inline">
                    <a class="btn btn-small btn-secondary" href="<?= h(base_url('creator/edit_auction.php?id=' . $auction['id'])) ?>">Edit</a>
                    <?php if (in_array(effective_auction_status($auction), ['active','scheduled'], true)): ?>
                    <form method="post" onsubmit="return confirm('Close this auction now?');">
                        <?= csrf_field() ?><input type="hidden" name="auction_id" value="<?= (int)$auction['id'] ?>"><button class="btn-small" name="action" value="close">Close</button>
                    </form>
                    <?php endif; ?>
                    <?php if (!in_array($auction['status'], ['ended','awarded','cancelled'], true)): ?>
                    <form method="post" onsubmit="return confirm('Cancel this auction?');">
                        <?= csrf_field() ?><input type="hidden" name="auction_id" value="<?= (int)$auction['id'] ?>"><button class="btn-small btn-danger" name="action" value="cancel">Cancel</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$auctions): ?><tr><td colspan="8">No auctions found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
