<?php
define('INTRABID_INSTALL', true);
require __DIR__ . '/includes/bootstrap.php';

$alreadyInstalled = file_exists(CONFIG_PATH);
$errors = [];
$success = false;

function guess_app_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $dir = $dir === '/' ? '' : $dir;
    return $scheme . '://' . $host . $dir;
}

if (is_post() && !$alreadyInstalled) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? 'intrabid');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPass = (string)($_POST['admin_password'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'America/Chicago');
    $appUrl = rtrim(trim($_POST['app_url'] ?? guess_app_url()), '/');
    $siteEmail = trim($_POST['site_email'] ?? '');
    $siteEmailName = trim($_POST['site_email_name'] ?? 'IntraBids');
    $smtpEnabled = isset($_POST['smtp_enabled']) ? '1' : '0';
    $smtpHost = trim($_POST['smtp_host'] ?? '');
    $smtpPort = trim($_POST['smtp_port'] ?? '587');
    $smtpEncryption = trim($_POST['smtp_encryption'] ?? 'tls');
    $smtpUsername = trim($_POST['smtp_username'] ?? '');
    $smtpPassword = (string)($_POST['smtp_password'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'Database host, name, and user are required.';
    }
    if ($adminName === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid admin name and email are required.';
    }
    if (strlen($adminPass) < 10) {
        $errors[] = 'The admin password must be at least 10 characters.';
    }
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $errors[] = 'Invalid timezone.';
    }
    if ($siteEmail !== '' && !filter_var($siteEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The notification from email must be a valid email address.';
    }
    if ($smtpEnabled === '1') {
        if ($siteEmail === '' || $smtpHost === '') {
            $errors[] = 'SMTP notifications require a from email and SMTP host.';
        }
        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            $errors[] = 'Invalid SMTP encryption option.';
        }
        if ($smtpPort === '' || (int)$smtpPort < 1 || (int)$smtpPort > 65535) {
            $errors[] = 'SMTP port must be between 1 and 65535.';
        }
    }
    if (!is_writable(ROOT_PATH . '/config')) {
        $errors[] = 'The config directory is not writable. Make config/ writable, then run the installer again.';
    }

    if (!$errors) {
        try {
            $serverDsn = 'mysql:host=' . $dbHost . ';charset=utf8mb4';
            $pdo = new PDO($serverDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
            $pdo->exec("CREATE DATABASE IF NOT EXISTS $quotedDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE $quotedDb");

            $schema = file_get_contents(ROOT_PATH . '/database/schema.sql');
            $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema)));
            foreach ($statements as $sql) {
                if ($sql !== '') {
                    $pdo->exec($sql);
                }
            }

            $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, can_create_auctions, is_active, created_at, updated_at)
                VALUES (?, ?, ?, 'admin', 1, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = 'admin', can_create_auctions = 1, is_active = 1, must_reset_password = 0, updated_at = NOW()");
            $stmt->execute([$adminName, $adminEmail, $passwordHash]);
            $adminIdStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $adminIdStmt->execute([$adminEmail]);
            $adminId = (int)$adminIdStmt->fetchColumn();
            if ($adminId > 0) {
                $preferenceStmt = $pdo->prepare('INSERT IGNORE INTO user_notification_preferences (user_id, updated_at) VALUES (?, NOW())');
                $preferenceStmt->execute([$adminId]);
            }

            $settings = [
                'site_name' => 'IntraBids',
                'app_timezone' => $timezone,
                'site_logo_path' => '',
                'home_alert_enabled' => '0',
                'home_alert_text' => '',
                'site_email' => $siteEmail,
                'site_email_name' => $siteEmailName,
                'smtp_enabled' => $smtpEnabled,
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_encryption' => $smtpEncryption,
                'smtp_username' => $smtpUsername,
                'smtp_password' => $smtpPassword,
                'registration_enabled' => '1',
                'access_requests_enabled' => '0',
                'access_request_email_approval_enabled' => '0',
                'allowed_email_domain' => '',
                'default_bid_increment' => '1.00',
                'anti_sniping_enabled' => '0',
                'anti_sniping_minutes' => '2',
                'recently_ended_days' => '7',
                'allow_creator_to_bid' => '0',
                'show_winner_publicly' => '1',
                'bidder_name_privacy' => 'full',
                'audit_log_retention_days' => '365',
                'security_token_retention_days' => '30',
                'last_security_cleanup_at' => '',
                'schema_version' => '1.8.0',
            ];
            $settingsStmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($settings as $key => $value) {
                $settingsStmt->execute([$key, $value]);
            }

            $config = "<?php\n";
            $config .= "define('DB_HOST', " . var_export($dbHost, true) . ");\n";
            $config .= "define('DB_NAME', " . var_export($dbName, true) . ");\n";
            $config .= "define('DB_USER', " . var_export($dbUser, true) . ");\n";
            $config .= "define('DB_PASS', " . var_export($dbPass, true) . ");\n";
            $config .= "define('DB_CHARSET', 'utf8mb4');\n";
            $config .= "define('APP_URL', " . var_export($appUrl, true) . ");\n";
            $config .= "define('APP_TIMEZONE', " . var_export($timezone, true) . ");\n";
            $config .= "define('APP_INSTALLED_AT', " . var_export(date('c'), true) . ");\n";
            file_put_contents(CONFIG_PATH, $config, LOCK_EX);
            chmod(CONFIG_PATH, 0640);
            $success = true;
            $alreadyInstalled = true;
        } catch (Throwable $e) {
            $errors[] = 'Install failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install IntraBids</title>
    <link rel="icon" href="assets/images/favicon.ico?v=1.8.0" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png?v=1.8.0">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png?v=1.8.0">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="container">
    <div class="card">
        <h1>Install IntraBids</h1>
        <p class="meta">This routine creates the MySQL database tables, writes <code>config/config.php</code>, and creates the first global admin account.</p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">IntraBids was installed successfully.</div>
        <div class="card">
            <p>You can now log in with the global admin account you created.</p>
            <a class="btn" href="login.php">Go to login</a>
        </div>
    <?php elseif ($alreadyInstalled): ?>
        <div class="card">
            <h2>Already installed</h2>
            <p><code>config/config.php</code> already exists. To reinstall, remove that file and run the installer again.</p>
            <a class="btn" href="index.php">Go to IntraBids</a>
        </div>
    <?php else: ?>
        <form method="post" class="card">
            <h2>Database</h2>
            <div class="grid">
                <div class="form-row">
                    <label for="db_host">Database Host</label>
                    <input id="db_host" name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>" required>
                </div>
                <div class="form-row">
                    <label for="db_name">Database Name</label>
                    <input id="db_name" name="db_name" value="<?= h($_POST['db_name'] ?? 'intrabid') ?>" required>
                </div>
                <div class="form-row">
                    <label for="db_user">Database User</label>
                    <input id="db_user" name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" required>
                </div>
                <div class="form-row">
                    <label for="db_pass">Database Password</label>
                    <input id="db_pass" type="password" name="db_pass" value="<?= h($_POST['db_pass'] ?? '') ?>">
                </div>
            </div>

            <h2>Site</h2>
            <div class="grid">
                <div class="form-row">
                    <label for="app_url">Application URL</label>
                    <input id="app_url" name="app_url" value="<?= h($_POST['app_url'] ?? guess_app_url()) ?>" required>
                    <div class="help">Example: https://auction.example.com</div>
                </div>
                <div class="form-row">
                    <label for="timezone">Timezone</label>
                    <input id="timezone" name="timezone" value="<?= h($_POST['timezone'] ?? 'America/Chicago') ?>" required>
                </div>
                <div class="form-row">
                    <label for="site_email">Notification From Email</label>
                    <input id="site_email" name="site_email" type="email" value="<?= h($_POST['site_email'] ?? '') ?>" placeholder="auction@example.com">
                    <div class="help">Used as the sender for SMTP notifications.</div>
                </div>
                <div class="form-row">
                    <label for="site_email_name">Notification From Name</label>
                    <input id="site_email_name" name="site_email_name" value="<?= h($_POST['site_email_name'] ?? 'IntraBids') ?>">
                </div>
            </div>

            <h2>SMTP Email</h2>
            <p class="help">Optional during install. You can configure or change SMTP later under Admin &gt; Settings. PHP <code>mail()</code> is not used.</p>
            <div class="form-row inline"><input id="smtp_enabled" type="checkbox" name="smtp_enabled" value="1" <?= isset($_POST['smtp_enabled']) ? 'checked' : '' ?>><label for="smtp_enabled">Enable SMTP notifications now</label></div>
            <div class="grid">
                <div class="form-row">
                    <label for="smtp_host">SMTP Host</label>
                    <input id="smtp_host" name="smtp_host" value="<?= h($_POST['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
                </div>
                <div class="form-row">
                    <label for="smtp_port">SMTP Port</label>
                    <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= h($_POST['smtp_port'] ?? '587') ?>">
                </div>
                <div class="form-row">
                    <label for="smtp_encryption">SMTP Encryption</label>
                    <?php $selectedSmtpEncryption = $_POST['smtp_encryption'] ?? 'tls'; ?>
                    <select id="smtp_encryption" name="smtp_encryption">
                        <option value="tls" <?= $selectedSmtpEncryption === 'tls' ? 'selected' : '' ?>>STARTTLS / TLS, usually port 587</option>
                        <option value="ssl" <?= $selectedSmtpEncryption === 'ssl' ? 'selected' : '' ?>>Implicit SSL, usually port 465</option>
                        <option value="none" <?= $selectedSmtpEncryption === 'none' ? 'selected' : '' ?>>None / internal relay, usually port 25</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="smtp_username">SMTP Username</label>
                    <input id="smtp_username" name="smtp_username" value="<?= h($_POST['smtp_username'] ?? '') ?>" autocomplete="off">
                </div>
                <div class="form-row">
                    <label for="smtp_password">SMTP Password</label>
                    <input id="smtp_password" name="smtp_password" type="password" value="<?= h($_POST['smtp_password'] ?? '') ?>" autocomplete="new-password">
                </div>
            </div>

            <h2>First Global Admin</h2>
            <div class="grid">
                <div class="form-row">
                    <label for="admin_name">Admin Name</label>
                    <input id="admin_name" name="admin_name" value="<?= h($_POST['admin_name'] ?? '') ?>" required>
                </div>
                <div class="form-row">
                    <label for="admin_email">Admin Email</label>
                    <input id="admin_email" name="admin_email" type="email" value="<?= h($_POST['admin_email'] ?? '') ?>" required>
                </div>
                <div class="form-row">
                    <label for="admin_password">Admin Password</label>
                    <input id="admin_password" name="admin_password" type="password" minlength="10" required>
                </div>
            </div>
            <button type="submit">Install IntraBids</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
