<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();
$logs = db_all('SELECT al.*, u.name, u.email FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 250');
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <h1>Audit Logs</h1>
    <p class="meta">Most recent 250 events.</p>
</div>
<div class="card table-wrap">
    <table>
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td><?= h(dt($log['created_at'])) ?></td>
            <td><?= h($log['name'] ?: $log['email'] ?: 'System') ?></td>
            <td><?= h($log['action']) ?></td>
            <td><?= h(trim(($log['entity_type'] ?: '') . ' #' . ($log['entity_id'] ?: ''), ' #')) ?></td>
            <td><?= h($log['ip_address']) ?></td>
            <td><details><summary>View</summary><pre><?= h(trim('Old: ' . ($log['old_value'] ?? '') . "\nNew: " . ($log['new_value'] ?? ''))) ?></pre></details></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6">No audit logs found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
