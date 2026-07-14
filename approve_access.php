<?php
require __DIR__ . '/includes/bootstrap.php';

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$request = null;
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $request = db_one(
        "SELECT r.*, u.name, u.email, u.can_create_auctions, u.role, u.is_active FROM auction_access_requests r JOIN users u ON u.id = r.user_id WHERE r.approval_token_hash = ? AND r.status = 'pending' AND r.approval_token_expires_at >= ? LIMIT 1",
        [hash('sha256', $token), now_sql()]
    );
    if ($request && (int)$request['is_active'] !== 1) {
        $request = null;
    }
}

$resultMessage = '';
$resultType = '';

if (is_post()) {
    if (!$request) {
        $resultMessage = 'This approval link is invalid, expired, or has already been used.';
        $resultType = 'error';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $locked = db_one("SELECT * FROM auction_access_requests WHERE id = ? FOR UPDATE", [(int)$request['id']]);
            if (!$locked || $locked['status'] !== 'pending' || empty($locked['approval_token_hash']) || !hash_equals((string)$locked['approval_token_hash'], hash('sha256', $token)) || strtotime((string)$locked['approval_token_expires_at']) < time()) {
                throw new RuntimeException('This approval link is invalid, expired, or has already been used.');
            }
            db_exec('UPDATE users SET can_create_auctions = 1, updated_at = NOW() WHERE id = ?', [(int)$locked['user_id']]);
            db_exec("UPDATE auction_access_requests SET status = 'approved', resolved_at = ?, resolved_by = NULL, resolution_method = 'email_link', approval_token_hash = NULL, approval_token_expires_at = NULL WHERE id = ?", [now_sql(), (int)$locked['id']]);
            $pdo->commit();
            log_action(null, 'auction_access_approved_by_email', 'auction_access_request', (int)$locked['id'], null, ['user_id' => (int)$locked['user_id']]);
            $resultMessage = user_display($request) . ' now has access to create and publish auctions.';
            $resultType = 'success';
            $request = null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $resultMessage = $e->getMessage();
            $resultType = 'error';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="card approval-card">
    <h1>Auction Posting Access Request</h1>
    <?php if ($resultMessage !== ''): ?>
        <div class="alert alert-<?= h($resultType) ?>"><?= h($resultMessage) ?></div>
        <a class="btn" href="<?= h(base_url('index.php')) ?>">Go to Auctions</a>
    <?php elseif (!$request): ?>
        <div class="alert alert-error">This approval link is invalid, expired, or has already been used.</div>
    <?php else: ?>
        <p><strong><?= h(user_display($request)) ?></strong> has requested permission to create and publish auctions.</p>
        <p>Email: <strong><?= h((string)$request['email']) ?></strong><br>Requested: <strong><?= h(dt((string)$request['requested_at'])) ?></strong></p>
        <div class="alert alert-info">Opening this page did not approve the request. Select the button below to confirm approval. No login is required.</div>
        <form method="post">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit">Approve Auction Posting Access</button>
        </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
