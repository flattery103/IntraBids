<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_auction_creator();
$user = current_user();
$params = [];
$where = '';
if (!is_admin()) {
    $where = 'WHERE a.created_by = ?';
    $params[] = (int)$user['id'];
}
$auctions = db_all("SELECT a.*, c.name AS category_name, COUNT(b.id) AS bid_count,
        COALESCE(wu.name, hu.name) AS winning_name,
        COALESCE(wu.email, hu.email) AS winning_email
    FROM auctions a
    JOIN categories c ON c.id = a.category_id
    LEFT JOIN bids b ON b.auction_id = a.id
    LEFT JOIN users wu ON wu.id = a.winning_user_id
    LEFT JOIN users hu ON hu.id = a.current_high_bidder_id
    $where
    GROUP BY a.id
    ORDER BY a.created_at DESC", $params);
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <div class="inline" style="justify-content:space-between;">
        <div>
            <h1><?= is_admin() ? 'All Auctions' : 'My Auctions' ?></h1>
            <p class="meta">Create and manage auctions you are authorized to post.</p>
        </div>
        <a class="btn" href="<?= h(base_url('creator/create_auction.php')) ?>">Create Auction</a>
    </div>
</div>
<div class="card table-wrap">
    <table>
        <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Bids</th><th>Start</th><th>End</th><th>High Bid</th><th>Winning Bidder</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($auctions as $auction): ?>
            <?php
                $effectiveStatus = effective_auction_status($auction);
                $isOver = in_array($effectiveStatus, ['ended', 'awarded'], true);
                $winnerId = (int)($auction['winning_user_id'] ?: $auction['current_high_bidder_id']);
                $winnerName = trim((string)($auction['winning_name'] ?: $auction['winning_email'] ?: ''));
            ?>
            <tr>
                <td><a href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>"><?= h($auction['title']) ?></a></td>
                <td><?= h($auction['category_name']) ?></td>
                <td><?= status_badge($effectiveStatus) ?></td>
                <td><?= (int)$auction['bid_count'] ?></td>
                <td><?= h(dt($auction['start_time'])) ?></td>
                <td><?= h(dt($auction['end_time'])) ?></td>
                <td><?= $auction['current_high_bid'] === null ? '—' : money($auction['current_high_bid']) ?></td>
                <td>
                    <?php if (!$isOver): ?>
                        —
                    <?php elseif ($auction['current_high_bid'] === null || $winnerId <= 0): ?>
                        No winner
                    <?php else: ?>
                        <?= h($winnerName ?: 'Unknown') ?>
                    <?php endif; ?>
                </td>
                <td><a class="btn btn-small btn-secondary" href="<?= h(base_url('creator/edit_auction.php?id=' . $auction['id'])) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$auctions): ?><tr><td colspan="9">No auctions found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
