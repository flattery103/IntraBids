<?php
/**
 * Places a bid using a row-level lock so concurrent requests cannot both
 * validate against the same previous high bid.
 *
 * @return array{bid_id:int,bid_amount:float,end_time:string,previous_high_bidder_id:?int}
 */
function place_auction_bid(int $auctionId, array $user, string $bidAmountInput, bool $sendNotifications = true): array
{
    $bidAmount = round((float)$bidAmountInput, 2);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM auctions WHERE id = ? FOR UPDATE');
        $stmt->execute([$auctionId]);
        $locked = $stmt->fetch();
        if (!$locked) {
            throw new RuntimeException('Auction not found.');
        }
        if (effective_auction_status($locked) !== 'active') {
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
        $insert->execute([
            $auctionId,
            (int)$user['id'],
            $bidAmount,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $bidCreatedAt,
        ]);
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
        $update->execute([$bidAmount, (int)$user['id'], $newEndTime, now_sql(), $auctionId]);
        $pdo->commit();

        log_action((int)$user['id'], 'bid_placed', 'auction', $auctionId, null, ['bid_id' => $bidId, 'amount' => $bidAmount]);

        if ($sendNotifications && $previousHighBidderId && $previousHighBidderId !== (int)$user['id'] && notification_enabled($previousHighBidderId, 'email_outbid')) {
            $previous = db_one('SELECT id, email, name FROM users WHERE id = ?', [$previousHighBidderId]);
            if ($previous) {
                send_app_email((string)$previous['email'], 'You were outbid: ' . $locked['title'], "You were outbid on " . $locked['title'] . ".\n\nCurrent high bid: " . money($bidAmount) . "\n\n" . base_url('auction.php?id=' . $auctionId));
            }
        }

        return [
            'bid_id' => $bidId,
            'bid_amount' => $bidAmount,
            'end_time' => $newEndTime,
            'previous_high_bidder_id' => $previousHighBidderId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
