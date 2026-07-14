<?php
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php concurrent_bid_worker.php <barrier> <auction-id> <user-id> <amount>\n");
    exit(2);
}

$required = ['INTRABIDS_TEST_DB_HOST', 'INTRABIDS_TEST_DB_NAME', 'INTRABIDS_TEST_DB_USER'];
foreach ($required as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        fwrite(STDERR, "Missing environment variable: $name\n");
        exit(2);
    }
}

define('ROOT_PATH', dirname(__DIR__));
define('DB_HOST', (string)getenv('INTRABIDS_TEST_DB_HOST'));
define('DB_NAME', (string)getenv('INTRABIDS_TEST_DB_NAME'));
define('DB_USER', (string)getenv('INTRABIDS_TEST_DB_USER'));
define('DB_PASS', (string)(getenv('INTRABIDS_TEST_DB_PASS') ?: ''));
define('DB_CHARSET', 'utf8mb4');
define('APP_URL', 'http://localhost/intrabids-test');
define('APP_TIMEZONE', 'America/Chicago');
date_default_timezone_set(APP_TIMEZONE);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'IntraBids concurrent bid integration test';

require ROOT_PATH . '/includes/db.php';
require ROOT_PATH . '/includes/helpers.php';
require ROOT_PATH . '/includes/bidding.php';

$barrier = $argv[1];
$auctionId = (int)$argv[2];
$userId = (int)$argv[3];
$amount = (string)$argv[4];

$deadline = microtime(true) + 15;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($barrier)) {
    echo json_encode(['ok' => false, 'error' => 'Timed out waiting for test barrier.']) . PHP_EOL;
    exit(1);
}

try {
    $result = place_auction_bid($auctionId, ['id' => $userId], $amount, false);
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}
