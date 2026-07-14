<?php
session_start();
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config/config.php');

$installMode = defined('INTRABID_INSTALL') && INTRABID_INSTALL === true;

if (!file_exists(CONFIG_PATH)) {
    if (!$installMode) {
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $base = $scriptDir === '/' ? '' : $scriptDir;
        header('Location: ' . $base . '/install.php');
        exit;
    }
} else {
    require CONFIG_PATH;
}

if (defined('APP_TIMEZONE')) {
    date_default_timezone_set(APP_TIMEZONE);
} else {
    date_default_timezone_set('America/Chicago');
}

require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/helpers.php';
require_once ROOT_PATH . '/includes/bidding.php';
require_once ROOT_PATH . '/includes/csrf.php';
require_once ROOT_PATH . '/includes/auth.php';

if (!$installMode && file_exists(CONFIG_PATH)) {
    $configuredTimezone = app_timezone();
    date_default_timezone_set($configuredTimezone);
    sync_database_timezone();
    if (!(defined('INTRABID_SKIP_MAINTENANCE') && INTRABID_SKIP_MAINTENANCE === true)) {
        run_auction_maintenance();
    }
    $authenticatedUser = current_user();
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($authenticatedUser && (int)($authenticatedUser['must_reset_password'] ?? 0) === 1
        && !in_array($currentScript, ['forced_password_reset.php', 'logout.php'], true)) {
        redirect('forced_password_reset.php');
    }
}
