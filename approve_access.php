<?php
require __DIR__ . '/includes/bootstrap.php';

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenRow = null;
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $tokenRow = db_one(
        "SELECT t.*, r.user_id AS requester_user_id, r.status AS request_status, r.requested_at,
                requester.name, requester.email, requester.can_create_auctions, requester.role, requester.is_active,
                admin.name AS admin_name, admin.email AS admin_email, admin.role AS admin_role, admin.is_active AS admin_is_active
         FROM auction_access_approval_tokens t
         JOIN auction_access_requests r ON r.id = t.request_id
         JOIN users requester ON requester.id = r.user_id
         JOIN users admin ON admin.id = t.admin_user_id
         WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at >= ? AND r.status = 'pending'
         LIMIT 1",
        [hash('sha256', $token), now_sql()]
    );
    if ($tokenRow && ((int)$tokenRow['is_active'] !== 1 || (int)$tokenRow['admin_is_active'] !== 1 || $tokenRow['admin_role'] !== 'admin')) {
        $tokenRow = null;
    }
}

$resultMessage = '';
$resultType = '';

if (is_post()) {
    if (!$tokenRow) {
        $resultMessage = 'This approval link is invalid, expired, or has already been used.';
        $resultType = 'error';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lockedToken = db_one('SELECT * FROM auction_access_approval_tokens WHERE id = ? FOR UPDATE', [(int)$tokenRow['id']]);
            $lockedRequest = db_one('SELECT * FROM auction_access_requests WHERE id = ? FOR UPDATE', [(int)$tokenRow['request_id']]);
            $admin = db_one("SELECT id, name, email, role, is_active FROM users WHERE id = ? FOR UPDATE", [(int)$tokenRow['admin_user_id']]);
            if (!$lockedToken || $lockedToken['used_at'] !== null || !hash_equals((string)$lockedToken['token_hash'], hash('sha256', $token)) || strtotime((string)$lockedToken['expires_at']) < time()) {
                throw new RuntimeException('This approval link is invalid, expired, or has already been used.');
            }
            if (!$lockedRequest || $lockedRequest['status'] !== 'pending') {
                throw new RuntimeException('This access request is no longer pending.');
            }
            if (!$admin || $admin['role'] !== 'admin' || (int)$admin['is_active'] !== 1) {
                throw new RuntimeException('The administrator account associated with this link is no longer authorized.');
            }

            $resolvedAt = now_sql();
            db_exec('UPDATE users SET can_create_auctions = 1, updated_at = NOW() WHERE id = ?', [(int)$lockedRequest['user_id']]);
            db_exec(
                "UPDATE auction_access_requests SET status = 'approved', resolved_at = ?, resolved_by = ?, resolution_method = 'email_link', approval_token_hash = NULL, approval_token_expires_at = NULL WHERE id = ?",
                [$resolvedAt, (int)$admin['id'], (int)$lockedRequest['id']]
            );
            db_exec('UPDATE auction_access_approval_tokens SET used_at = ? WHERE request_id = ? AND used_at IS NULL', [$resolvedAt, (int)$lockedRequest['id']]);
            $pdo->commit();

            log_action((int)$admin['id'], 'auction_access_approved_by_email', 'auction_access_request', (int)$lockedRequest['id'], null, [
                'user_id' => (int)$lockedRequest['user_id'],
                'administrator_id' => (int)$admin['id'],
                'administrator_name' => (string)$admin['name'],
            ]);
            notify_access_request_resolution((int)$lockedRequest['id']);
            $resultMessage = user_display($tokenRow) . ' now has access to create and publish auctions. Approval was recorded for ' . user_display($admin) . '.';
            $resultType = 'success';
            $tokenRow = null;
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
    <?php elseif (!$tokenRow): ?>
        <div class="alert alert-error">This approval link is invalid, expired, or has already been used.</div>
    <?php else: ?>
        <p><strong><?= h(user_display($tokenRow)) ?></strong> has requested permission to create and publish auctions.</p>
        <p>Email: <strong><?= h((string)$tokenRow['email']) ?></strong><br>Requested: <strong><?= h(dt((string)$tokenRow['requested_at'])) ?></strong></p>
        <div class="alert alert-info">This link is assigned to <strong><?= h((string)$tokenRow['admin_name']) ?></strong>. Opening this page did not approve the request. Select the button below to confirm approval. No login is required.</div>
        <form method="post">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit">Approve Auction Posting Access</button>
        </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
