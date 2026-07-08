<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$user = current_user();
$bids = db_all('SELECT b.*, a.title, a.status, a.start_time, a.end_time, a.current_high_bid, a.current_high_bidder_id
    FROM bids b
    JOIN auctions a ON a.id = b.auction_id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC', [(int)$user['id']]);
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h1>My Bids</h1>
    <?php if (!$bids): ?>
        <p>You have not placed any bids yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Auction</th><th>Your Bid</th><th>Current High Bid</th><th>Status</th><th>Bid Time</th></tr></thead>
                <tbody>
                <?php foreach ($bids as $bid): ?>
                    <?php $effectiveStatus = effective_auction_status($bid); ?>
                    <tr>
                        <td><a href="<?= h(base_url('auction.php?id=' . $bid['auction_id'])) ?>"><?= h($bid['title']) ?></a></td>
                        <td><?= money($bid['bid_amount']) ?></td>
                        <td><?= $bid['current_high_bid'] === null ? '—' : money($bid['current_high_bid']) ?> <?= ((int)$bid['current_high_bidder_id'] === (int)$user['id']) ? '<span class="badge badge-active">High bidder</span>' : '' ?></td>
                        <td><?= status_badge($effectiveStatus) ?></td>
                        <td><?= h(dt($bid['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
