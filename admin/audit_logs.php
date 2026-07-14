<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$q = trim((string)($_GET['q'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$entityType = trim((string)($_GET['entity_type'] ?? ''));
$ip = trim((string)($_GET['ip'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(al.action LIKE ? OR al.entity_type LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR al.ip_address LIKE ? OR CAST(al.old_value AS CHAR) LIKE ? OR CAST(al.new_value AS CHAR) LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
if ($action !== '') {
    $where[] = 'al.action = ?';
    $params[] = $action;
}
if ($userId > 0) {
    $where[] = 'al.user_id = ?';
    $params[] = $userId;
}
if ($entityType !== '') {
    $where[] = 'al.entity_type = ?';
    $params[] = $entityType;
}
if ($ip !== '') {
    $where[] = 'al.ip_address = ?';
    $params[] = $ip;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'al.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'al.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$logs = db_all(
    "SELECT al.*, u.name, u.email FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id $whereSql ORDER BY al.created_at DESC LIMIT 500",
    $params
);
$actions = db_all('SELECT DISTINCT action FROM audit_logs ORDER BY action');
$entityTypes = db_all("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL AND entity_type <> '' ORDER BY entity_type");
$users = db_all('SELECT id, name, email FROM users ORDER BY name, email');

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <div class="inline page-heading-actions">
        <div>
            <h1>Audit Logs</h1>
            <p class="meta">Search and filter the 500 most recent matching events.</p>
        </div>
        <a class="btn btn-secondary" href="<?= h(base_url('admin/security.php')) ?>">Security &amp; Audit Dashboard</a>
    </div>
</div>

<div class="card">
    <h2>Filters</h2>
    <form method="get" class="audit-filter-form">
        <div class="grid">
            <div class="form-row">
                <label for="q">Search</label>
                <input id="q" name="q" value="<?= h($q) ?>" placeholder="Action, user, IP, entity, or details">
            </div>
            <div class="form-row">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $row): ?>
                        <option value="<?= h((string)$row['action']) ?>" <?= $action === $row['action'] ? 'selected' : '' ?>><?= h((string)$row['action']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="user_id">User</label>
                <select id="user_id" name="user_id">
                    <option value="0">All users</option>
                    <?php foreach ($users as $filterUser): ?>
                        <option value="<?= (int)$filterUser['id'] ?>" <?= $userId === (int)$filterUser['id'] ? 'selected' : '' ?>><?= h(user_display($filterUser)) ?> (<?= h((string)$filterUser['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="entity_type">Entity Type</label>
                <select id="entity_type" name="entity_type">
                    <option value="">All entity types</option>
                    <?php foreach ($entityTypes as $row): ?>
                        <option value="<?= h((string)$row['entity_type']) ?>" <?= $entityType === $row['entity_type'] ? 'selected' : '' ?>><?= h((string)$row['entity_type']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="ip">IP Address</label>
                <input id="ip" name="ip" value="<?= h($ip) ?>" placeholder="192.0.2.10">
            </div>
            <div class="form-row">
                <label for="date_from">From Date</label>
                <input id="date_from" name="date_from" type="date" value="<?= h($dateFrom) ?>">
            </div>
            <div class="form-row">
                <label for="date_to">Through Date</label>
                <input id="date_to" name="date_to" type="date" value="<?= h($dateTo) ?>">
            </div>
        </div>
        <div class="inline">
            <button type="submit">Apply Filters</button>
            <a class="btn btn-secondary" href="<?= h(base_url('admin/audit_logs.php')) ?>">Clear Filters</a>
        </div>
    </form>
</div>

<div class="card table-wrap">
    <h2>Results <span class="badge"><?= count($logs) ?></span></h2>
    <table>
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td><?= h(dt($log['created_at'])) ?></td>
            <td><?= h($log['name'] ?: $log['email'] ?: 'System') ?></td>
            <td><a href="<?= h(base_url('admin/audit_logs.php?action=' . rawurlencode((string)$log['action']))) ?>"><?= h($log['action']) ?></a></td>
            <td><?= h(trim(($log['entity_type'] ?: '') . ' #' . ($log['entity_id'] ?: ''), ' #')) ?></td>
            <td><?php if ($log['ip_address']): ?><a href="<?= h(base_url('admin/audit_logs.php?ip=' . rawurlencode((string)$log['ip_address']))) ?>"><?= h($log['ip_address']) ?></a><?php else: ?>—<?php endif; ?></td>
            <td><details><summary>View</summary><pre><?= h(trim('Old: ' . ($log['old_value'] ?? '') . "\nNew: " . ($log['new_value'] ?? ''))) ?></pre></details></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="6">No audit logs matched the selected filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
