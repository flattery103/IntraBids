<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();
$stats = [
    'users' => db_one('SELECT COUNT(*) AS c FROM users')['c'] ?? 0,
    'creators' => db_one('SELECT COUNT(*) AS c FROM users WHERE can_create_auctions = 1 OR role = "admin"')['c'] ?? 0,
    'active' => db_one('SELECT COUNT(*) AS c FROM auctions WHERE status IN ("active","scheduled") AND start_time <= ? AND end_time > ?', [now_sql(), now_sql()])['c'] ?? 0,
    'scheduled' => db_one('SELECT COUNT(*) AS c FROM auctions WHERE status IN ("active","scheduled") AND start_time > ? AND end_time > ?', [now_sql(), now_sql()])['c'] ?? 0,
    'awarded' => db_one('SELECT COUNT(*) AS c FROM auctions WHERE status = "awarded"')['c'] ?? 0,
    'access_requests' => db_one('SELECT COUNT(*) AS c FROM auction_access_requests WHERE status = "pending"')['c'] ?? 0,
];
$recent = db_all('SELECT a.*, u.name AS creator_name FROM auctions a JOIN users u ON u.id = a.created_by ORDER BY a.created_at DESC LIMIT 10');
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <h1>Admin Dashboard</h1>
    <div class="inline">
        <a class="btn" href="<?= h(base_url('admin/users.php')) ?>">Users</a>
        <a class="btn" href="<?= h(base_url('admin/categories.php')) ?>">Categories</a>
        <a class="btn" href="<?= h(base_url('admin/auctions.php')) ?>">Auctions</a>
        <a class="btn" href="<?= h(base_url('admin/settings.php')) ?>">Settings</a>
        <a class="btn" href="<?= h(base_url('admin/audit_logs.php')) ?>">Audit Logs</a>
        <a class="btn" href="<?= h(base_url('admin/security.php')) ?>">Security &amp; Audit</a>
    </div>
</div>
<div class="grid">
    <div class="card"><div class="kpi"><?= (int)$stats['users'] ?></div><div>Users</div></div>
    <div class="card"><div class="kpi"><?= (int)$stats['creators'] ?></div><div>Auction Creators</div></div>
    <div class="card"><div class="kpi"><?= (int)$stats['active'] ?></div><div>Active Auctions</div></div>
    <div class="card"><div class="kpi"><?= (int)$stats['scheduled'] ?></div><div>Scheduled Auctions</div></div>
    <div class="card"><div class="kpi"><?= (int)$stats['awarded'] ?></div><div>Awarded Auctions</div></div>
    <div class="card"><div class="kpi"><?= (int)$stats['access_requests'] ?></div><div>Pending Access Requests</div></div>
</div>
<div class="card table-wrap">
    <h2>Recent Auctions</h2>
    <table>
        <thead><tr><th>Title</th><th>Creator</th><th>Status</th><th>End</th><th>High Bid</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $auction): ?>
            <tr>
                <td><a href="<?= h(base_url('auction.php?id=' . $auction['id'])) ?>"><?= h($auction['title']) ?></a></td>
                <td><?= h($auction['creator_name']) ?></td>
                <td><?= status_badge(effective_auction_status($auction)) ?></td>
                <td><?= h(dt($auction['end_time'])) ?></td>
                <td><?= $auction['current_high_bid'] === null ? '—' : money($auction['current_high_bid']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
