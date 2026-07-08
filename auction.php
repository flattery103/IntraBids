<?php
require __DIR__ . '/includes/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
$auction = db_one('SELECT a.*, c.name AS category_name, u.name AS creator_name, u.email AS creator_email,
        wu.name AS winner_name
    FROM auctions a
    JOIN categories c ON c.id = a.category_id
    JOIN users u ON u.id = a.created_by
    LEFT JOIN users wu ON wu.id = a.winning_user_id
    WHERE a.id = ?', [$id]);
if (!$auction) {
    http_response_code(404);
    include __DIR__ . '/includes/header.php';
    echo '<div class="card"><h1>Auction not found</h1></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}
$status = effective_auction_status($auction);
$user = current_user();
if (in_array($status, ['draft', 'cancelled'], true) && (!$user || (!is_admin() && (int)$auction['created_by'] !== (int)$user['id']))) {
    http_response_code(403);
    include __DIR__ . '/includes/header.php';
    echo '<div class="card"><h1>Auction unavailable</h1><p>This auction is not available for public viewing.</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

if (is_post()) {
    require_login();
    verify_csrf();
    $bidAmount = round((float)($_POST['bid_amount'] ?? 0), 2);
    $user = current_user();
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM auctions WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $locked = $stmt->fetch();
        if (!$locked) {
            throw new RuntimeException('Auction not found.');
        }
        $lockedStatus = effective_auction_status($locked);
        if ($lockedStatus !== 'active') {
            throw new RuntimeException('This auction is not currently active.');
        }
        if ((int)setting('allow_creator_to_bid', '0') !== 1 && (int)$locked['created_by'] === (int)$user['id']) {
            throw new RuntimeException('Auction creators cannot bid on their own auctions.');
        }
        $currentHigh = $locked['current_high_bid'] === null ? null : (float)$locked['current_high_bid'];
        $minimum = $currentHigh === null ? (float)$locked['starting_bid'] : $currentHigh + (float)$locked['bid_increment'];
        if ($bidAmount < $minimum) {
            throw new RuntimeException('Your bid must be at least ' . money($minimum) . '.');
        }
        $previousHighBidderId = $locked['current_high_bidder_id'] ? (int)$locked['current_high_bidder_id'] : null;
        $bidCreatedAt = now_sql();
        $insert = $pdo->prepare('INSERT INTO bids (auction_id, user_id, bid_amount, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$id, (int)$user['id'], $bidAmount, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $bidCreatedAt]);
        $bidId = (int)$pdo->lastInsertId();

        $newEndTime = $locked['end_time'];
        if ((string)setting('anti_sniping_enabled', '0') === '1') {
            $minutes = max(1, (int)setting('anti_sniping_minutes', '2'));
            $secondsLeft = strtotime($locked['end_time']) - time();
            if ($secondsLeft <= ($minutes * 60)) {
                $newEndTime = date('Y-m-d H:i:s', time() + ($minutes * 60));
            }
        }

        $update = $pdo->prepare('UPDATE auctions SET current_high_bid = ?, current_high_bidder_id = ?, end_time = ?, updated_at = ? WHERE id = ?');
        $update->execute([$bidAmount, (int)$user['id'], $newEndTime, now_sql(), $id]);
        $pdo->commit();
        log_action((int)$user['id'], 'bid_placed', 'auction', $id, null, ['bid_id' => $bidId, 'amount' => $bidAmount]);

        if ($previousHighBidderId && $previousHighBidderId !== (int)$user['id']) {
            $previous = db_one('SELECT email, name FROM users WHERE id = ?', [$previousHighBidderId]);
            if ($previous) {
                send_app_email($previous['email'], 'You were outbid: ' . $locked['title'], "You were outbid on " . $locked['title'] . ".\n\nCurrent high bid: " . money($bidAmount) . "\n\n" . base_url('auction.php?id=' . $id));
            }
        }
        flash('success', 'Your bid was placed.');
        redirect('auction.php?id=' . $id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
        redirect('auction.php?id=' . $id);
    }
}

$images = db_all('SELECT * FROM auction_images WHERE auction_id = ? ORDER BY sort_order, id', [$id]);
$bids = db_all('SELECT b.*, u.name FROM bids b JOIN users u ON u.id = b.user_id WHERE b.auction_id = ? ORDER BY b.bid_amount DESC, b.created_at ASC, b.id ASC', [$id]);
$minimumBid = $auction['current_high_bid'] === null ? (float)$auction['starting_bid'] : ((float)$auction['current_high_bid'] + (float)$auction['bid_increment']);
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="inline" style="justify-content:space-between;">
        <div>
            <?= status_badge($status) ?> <span class="meta"><?= h($auction['category_name']) ?></span>
            <h1><?= h($auction['title']) ?></h1>
        </div>
        <?php if (can_create_auctions() && ($user && (is_admin() || (int)$auction['created_by'] === (int)$user['id']))): ?>
            <a class="btn btn-secondary" href="<?= h(base_url('creator/edit_auction.php?id=' . $auction['id'])) ?>">Edit Auction</a>
        <?php endif; ?>
    </div>
    <?php if ($images): ?>
        <div class="image-gallery auction-detail-gallery">
            <?php foreach ($images as $img): ?>
                <div class="auction-detail-image-frame" style="display:flex;align-items:center;justify-content:center;width:100%;min-height:420px;max-height:620px;background:#f8fafc;border:1px solid #d8dee6;border-radius:12px;overflow:hidden;">
                    <img src="<?= h(base_url($img['file_path'])) ?>" alt="" style="width:100%;height:100%;max-height:620px;object-fit:contain;display:block;">
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid">
    <div class="card">
        <h2>Details</h2>
        <p><?= nl2br(h($auction['description'])) ?></p>
        <p><strong>Condition:</strong> <?= h($auction['item_condition'] ?: 'Not specified') ?></p>
        <p><strong>Created by:</strong> <?= h($auction['creator_name']) ?></p>
        <p><strong>Pickup location:</strong> <?= h($auction['pickup_location'] ?: 'Not specified') ?></p>
        <p><strong>Pickup instructions:</strong><br><?= nl2br(h($auction['pickup_instructions'] ?: 'Not specified')) ?></p>
    </div>
    <div class="card">
        <h2>Bidding</h2>
        <p><strong>Starting bid:</strong> <?= money($auction['starting_bid']) ?></p>
        <p><strong>Bid increment:</strong> <?= money($auction['bid_increment']) ?></p>
        <p class="price">Current high bid: <?= $auction['current_high_bid'] === null ? 'No bids yet' : money($auction['current_high_bid']) ?></p>
        <?php if ($status === 'active'): ?>
            <p><strong>Ends in:</strong> <span data-countdown="<?= h(date('c', strtotime($auction['end_time']))) ?>"></span></p>
            <?php if (!$user): ?>
                <p><a class="btn" href="<?= h(base_url('login.php')) ?>">Log in to bid</a></p>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <label for="bid_amount">Your bid</label>
                        <input id="bid_amount" name="bid_amount" type="number" min="<?= h((string)$minimumBid) ?>" step="0.01" value="<?= h(number_format($minimumBid, 2, '.', '')) ?>" required>
                        <div class="help">Minimum bid is <?= money($minimumBid) ?>.</div>
                    </div>
                    <button type="submit">Place Bid</button>
                </form>
            <?php endif; ?>
        <?php elseif ($status === 'scheduled'): ?>
            <p>This auction starts <?= h(dt($auction['start_time'])) ?>.</p>
        <?php elseif (in_array($status, ['ended','awarded'], true)): ?>
            <p>This auction ended <?= h(dt($auction['end_time'])) ?>.</p>
            <?php if ($auction['winning_user_id'] && ((string)setting('show_winner_publicly', '1') === '1' || is_admin() || ($user && (int)$user['id'] === (int)$auction['winning_user_id']))): ?>
                <p><strong>Winner:</strong> <?= h($auction['winner_name']) ?> for <?= money($auction['current_high_bid']) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p>This auction is not open for bidding.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>Bid History</h2>
    <?php if (!$bids): ?>
        <p>No bids have been placed.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Bidder</th><th>Amount</th><th>Time</th></tr></thead>
                <tbody>
                <?php foreach ($bids as $bid): ?>
                    <tr>
                        <td><?= h($bid['name']) ?></td>
                        <td><?= money($bid['bid_amount']) ?></td>
                        <td><?= h(dt($bid['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
