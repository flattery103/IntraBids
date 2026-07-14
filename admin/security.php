<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$now = now_sql();
$dayAgo = date('Y-m-d H:i:s', time() - 86400);
$weekAgo = date('Y-m-d H:i:s', time() - 7 * 86400);
$monthAgo = date('Y-m-d H:i:s', time() - 30 * 86400);

$stats = [
    'failed_logins_24h' => (int)(db_one("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'login_failed' AND created_at >= ?", [$dayAgo])['c'] ?? 0),
    'password_events_7d' => (int)(db_one("SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('password_changed','password_reset_requested','password_reset_completed','forced_password_reset_completed','user_force_password_reset') AND created_at >= ?", [$weekAgo])['c'] ?? 0),
    'access_decisions_30d' => (int)(db_one("SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('auction_access_approved','auction_access_approved_by_email','auction_access_denied') AND created_at >= ?", [$monthAgo])['c'] ?? 0),
    'admin_changes_30d' => (int)(db_one("SELECT COUNT(*) AS c FROM audit_logs WHERE action IN ('user_updated','settings_updated','user_force_password_reset','auction_access_approved','auction_access_approved_by_email','auction_access_denied') AND created_at >= ?", [$monthAgo])['c'] ?? 0),
    'audit_total' => (int)(db_one('SELECT COUNT(*) AS c FROM audit_logs')['c'] ?? 0),
    'pending_access' => (int)(db_one("SELECT COUNT(*) AS c FROM auction_access_requests WHERE status = 'pending'")['c'] ?? 0),
];

$expiredPasswordTokens = (int)(db_one('SELECT COUNT(*) AS c FROM password_reset_tokens WHERE expires_at < ? OR used_at IS NOT NULL', [$now])['c'] ?? 0);
$expiredApprovalTokens = (int)(db_one('SELECT COUNT(*) AS c FROM auction_access_approval_tokens WHERE expires_at < ? OR used_at IS NOT NULL', [$now])['c'] ?? 0);

$securityActions = [
    'login', 'login_failed', 'password_changed', 'password_reset_requested', 'password_reset_completed',
    'password_reset_email_failed', 'user_force_password_reset', 'forced_password_reset_completed',
    'auction_access_requested', 'auction_access_approved', 'auction_access_approved_by_email',
    'auction_access_denied', 'auction_access_email_failed', 'auction_access_resolution_email_failed',
    'settings_updated', 'user_updated', 'security_retention_cleanup',
];
$placeholders = implode(',', array_fill(0, count($securityActions), '?'));
$recentSecurityEvents = db_all(
    "SELECT al.*, u.name, u.email FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE al.action IN ($placeholders) ORDER BY al.created_at DESC LIMIT 30",
    $securityActions
);
$failedLoginIps = db_all(
    "SELECT COALESCE(ip_address, 'Unknown') AS ip_address, COUNT(*) AS attempts, MAX(created_at) AS last_attempt FROM audit_logs WHERE action = 'login_failed' AND created_at >= ? GROUP BY ip_address ORDER BY attempts DESC, last_attempt DESC LIMIT 10",
    [$weekAgo]
);
$adminAccounts = db_all("SELECT id, name, email, last_login_at, must_reset_password FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY name");

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <div class="inline page-heading-actions">
        <div>
            <h1>Security &amp; Audit Dashboard</h1>
            <p class="meta">Security-related activity, privileged changes, token status, and audit retention.</p>
        </div>
        <div class="inline">
            <a class="btn" href="<?= h(base_url('admin/audit_logs.php')) ?>">Search Audit Logs</a>
            <a class="btn btn-secondary" href="<?= h(base_url('admin/settings.php')) ?>">Retention Settings</a>
        </div>
    </div>
</div>

<div class="grid security-kpi-grid">
    <a class="card kpi-link" href="<?= h(base_url('admin/audit_logs.php?action=login_failed')) ?>"><div class="kpi"><?= $stats['failed_logins_24h'] ?></div><div>Failed Logins — 24 Hours</div></a>
    <div class="card"><div class="kpi"><?= $stats['password_events_7d'] ?></div><div>Password Events — 7 Days</div></div>
    <div class="card"><div class="kpi"><?= $stats['access_decisions_30d'] ?></div><div>Access Decisions — 30 Days</div></div>
    <div class="card"><div class="kpi"><?= $stats['admin_changes_30d'] ?></div><div>Privileged Changes — 30 Days</div></div>
    <div class="card"><div class="kpi"><?= $stats['audit_total'] ?></div><div>Stored Audit Events</div></div>
    <div class="card"><div class="kpi"><?= $stats['pending_access'] ?></div><div>Pending Posting Requests</div></div>
</div>

<div class="grid">
    <div class="card">
        <h2>Retention Status</h2>
        <p><strong>Audit retention:</strong> <?= (int)setting('audit_log_retention_days', '365') ?> days</p>
        <p><strong>Expired-token retention:</strong> <?= (int)setting('security_token_retention_days', '30') ?> days</p>
        <p><strong>Last cleanup:</strong> <?= h(setting('last_security_cleanup_at', '') ? dt((string)setting('last_security_cleanup_at', '')) : 'Not yet run') ?></p>
        <p><strong>Expired or used password tokens currently stored:</strong> <?= $expiredPasswordTokens ?></p>
        <p><strong>Expired or used approval tokens currently stored:</strong> <?= $expiredApprovalTokens ?></p>
    </div>
    <div class="card table-wrap">
        <h2>Active Global Administrators</h2>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Last Login</th><th>Password</th></tr></thead>
            <tbody>
            <?php foreach ($adminAccounts as $admin): ?>
                <tr>
                    <td><?= h((string)$admin['name']) ?></td>
                    <td><?= h((string)$admin['email']) ?></td>
                    <td><?= h($admin['last_login_at'] ? dt((string)$admin['last_login_at']) : 'Never') ?></td>
                    <td><?= (int)$admin['must_reset_password'] === 1 ? '<span class="badge badge-ended">Reset required</span>' : '<span class="badge badge-active">Current</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card table-wrap">
    <h2>Top Failed-Login IP Addresses — 7 Days</h2>
    <table>
        <thead><tr><th>IP Address</th><th>Attempts</th><th>Most Recent</th></tr></thead>
        <tbody>
        <?php foreach ($failedLoginIps as $row): ?>
            <tr>
                <td><?php if ((string)$row['ip_address'] === 'Unknown'): ?>Unknown<?php else: ?><a href="<?= h(base_url('admin/audit_logs.php?action=login_failed&ip=' . rawurlencode((string)$row['ip_address']))) ?>"><?= h((string)$row['ip_address']) ?></a><?php endif; ?></td>
                <td><?= (int)$row['attempts'] ?></td>
                <td><?= h(dt((string)$row['last_attempt'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$failedLoginIps): ?><tr><td colspan="3">No failed logins were recorded during the last seven days.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card table-wrap">
    <h2>Recent Security and Privileged Events</h2>
    <table>
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>IP</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($recentSecurityEvents as $event): ?>
            <tr>
                <td><?= h(dt((string)$event['created_at'])) ?></td>
                <td><?= h($event['name'] ?: $event['email'] ?: 'System') ?></td>
                <td><a href="<?= h(base_url('admin/audit_logs.php?action=' . rawurlencode((string)$event['action']))) ?>"><?= h((string)$event['action']) ?></a></td>
                <td><?= h((string)($event['ip_address'] ?: '—')) ?></td>
                <td><details><summary>View</summary><pre><?= h((string)($event['new_value'] ?: $event['old_value'] ?: 'No additional details')) ?></pre></details></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentSecurityEvents): ?><tr><td colspan="5">No security or privileged events have been recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
