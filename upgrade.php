<?php
define('INTRABID_SKIP_MAINTENANCE', true);
require __DIR__ . '/includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_admin();
}

$messages = [];
$errors = [];

function upgrade_out(string $message): void
{
    global $messages, $isCli;
    $messages[] = $message;
    if ($isCli) {
        echo $message . PHP_EOL;
    }
}

function upgrade_setting_exists(string $key): bool
{
    $row = db_one('SELECT id FROM settings WHERE setting_key = ? LIMIT 1', [$key]);
    return (bool)$row;
}

function upgrade_mark_migration(string $migration): void
{
    db_exec('INSERT IGNORE INTO schema_migrations (migration, executed_at) VALUES (?, ?)', [$migration, now_sql()]);
}

function upgrade_has_migration(string $migration): bool
{
    $row = db_one('SELECT id FROM schema_migrations WHERE migration = ? LIMIT 1', [$migration]);
    return (bool)$row;
}

function upgrade_split_sql(string $sql): array
{
    $statements = [];
    $current = '';
    $inSingle = false;
    $inDouble = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';
        if ($ch === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
        }
        if ($ch === ';' && !$inSingle && !$inDouble) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
        } else {
            $current .= $ch;
        }
    }
    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}

try {
    db_exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(190) NOT NULL UNIQUE,
        executed_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Backfill migration history for upgrades that may have been run manually before this upgrade runner existed.
    if (upgrade_setting_exists('smtp_host')) {
        upgrade_mark_migration('upgrade_v1.0.0_to_v1.1.0.sql');
    }
    if (upgrade_setting_exists('site_logo_path')) {
        upgrade_mark_migration('upgrade_v1.1.0_to_v1.2.0.sql');
    }

    $configTimezone = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Chicago';
    if (!upgrade_setting_exists('app_timezone')) {
        set_setting_value('app_timezone', $configTimezone);
    }

    $upgradeDir = ROOT_PATH . '/database/upgrades';
    $files = glob($upgradeDir . '/upgrade_*.sql') ?: [];
    sort($files, SORT_NATURAL);

    foreach ($files as $file) {
        $migration = basename($file);
        if (upgrade_has_migration($migration)) {
            upgrade_out('Skipping already applied migration: ' . $migration);
            continue;
        }

        upgrade_out('Running migration: ' . $migration);
        $sql = file_get_contents($file);
        foreach (upgrade_split_sql($sql) as $statement) {
            db()->exec($statement);
        }
        upgrade_mark_migration($migration);
    }

    set_setting_value('schema_version', trim((string)file_get_contents(ROOT_PATH . '/VERSION')));
    upgrade_out('Upgrade complete. Installed version: ' . trim((string)file_get_contents(ROOT_PATH . '/VERSION')));
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if (!$isCli):
    include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h1>IntraBids Upgrade</h1>
    <?php if ($errors): ?>
        <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endforeach; ?>
    <?php else: ?>
        <div class="alert alert-success">Upgrade complete.</div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <pre><?php foreach ($messages as $message) { echo h($message) . "\n"; } ?></pre>
    <?php endif; ?>
    <p><a class="btn" href="<?= h(base_url('admin/settings.php')) ?>">Go to Settings</a></p>
</div>
<?php
    include __DIR__ . '/includes/footer.php';
endif;
