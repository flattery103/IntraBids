<?php
declare(strict_types=1);

function fail_test(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function split_sql(string $sql): array
{
    $statements = [];
    $current = '';
    $inSingle = false;
    $inDouble = false;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $previous = $i > 0 ? $sql[$i - 1] : '';
        if ($char === "'" && !$inDouble && $previous !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && $previous !== '\\') {
            $inDouble = !$inDouble;
        }
        if ($char === ';' && !$inSingle && !$inDouble) {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
        } else {
            $current .= $char;
        }
    }
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }
    return $statements;
}

function read_worker_result(array $processInfo): array
{
    [$process, $pipes] = $processInfo;
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$stdout) ?: [])));
    $json = $lines ? json_decode((string)end($lines), true) : null;
    if (!is_array($json)) {
        fail_test('Worker did not return JSON. Exit code: ' . $exitCode . '; stdout: ' . $stdout . '; stderr: ' . $stderr);
    }
    return $json;
}

$host = (string)(getenv('INTRABIDS_TEST_DB_HOST') ?: '127.0.0.1');
$user = (string)(getenv('INTRABIDS_TEST_DB_USER') ?: '');
$password = (string)(getenv('INTRABIDS_TEST_DB_PASS') ?: '');
$database = (string)(getenv('INTRABIDS_TEST_DB_NAME') ?: 'intrabids_concurrency_test');

if ($user === '') {
    fail_test('Set INTRABIDS_TEST_DB_USER and, when needed, INTRABIDS_TEST_DB_PASS.');
}
if (!preg_match('/test/i', $database)) {
    fail_test('INTRABIDS_TEST_DB_NAME must contain the word "test" as a safety precaution.');
}
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fail_test('The PHP PDO MySQL driver is required. Install or enable pdo_mysql before running this test.');
}
if (!function_exists('proc_open')) {
    fail_test('PHP proc_open() is required to launch concurrent bid workers.');
}

$serverDsn = 'mysql:host=' . $host . ';charset=utf8mb4';
try {
    $server = new PDO($serverDsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fail_test('Could not connect to MySQL/MariaDB: ' . $e->getMessage());
}

$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$server->exec("DROP DATABASE IF EXISTS $quotedDatabase");
$server->exec("CREATE DATABASE $quotedDatabase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("USE $quotedDatabase");

try {
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($schema === false) {
        fail_test('Could not read database/schema.sql.');
    }
    foreach (split_sql($schema) as $statement) {
        $server->exec($statement);
    }

    $passwordHash = password_hash('ConcurrencyTestPassword!', PASSWORD_DEFAULT);
    $userStmt = $server->prepare("INSERT INTO users (name, email, password_hash, role, can_create_auctions, is_active, created_at, updated_at) VALUES (?, ?, ?, 'user', ?, 1, NOW(), NOW())");
    $userStmt->execute(['Auction Creator', 'creator@example.test', $passwordHash, 1]);
    $creatorId = (int)$server->lastInsertId();
    $userStmt->execute(['Concurrent Bidder One', 'bidder1@example.test', $passwordHash, 0]);
    $bidderOneId = (int)$server->lastInsertId();
    $userStmt->execute(['Concurrent Bidder Two', 'bidder2@example.test', $passwordHash, 0]);
    $bidderTwoId = (int)$server->lastInsertId();

    $categoryId = (int)$server->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    if ($categoryId <= 0) {
        fail_test('Schema did not create a default category.');
    }

    $environment = array_merge($_ENV, [
        'INTRABIDS_TEST_DB_HOST' => $host,
        'INTRABIDS_TEST_DB_NAME' => $database,
        'INTRABIDS_TEST_DB_USER' => $user,
        'INTRABIDS_TEST_DB_PASS' => $password,
    ]);
    $worker = __DIR__ . '/concurrent_bid_worker.php';

    for ($iteration = 1; $iteration <= 5; $iteration++) {
        $auctionStmt = $server->prepare("INSERT INTO auctions (category_id, created_by, title, description, starting_bid, bid_increment, start_time, end_time, status, created_at, updated_at) VALUES (?, ?, ?, 'Concurrency test', 10.00, 1.00, DATE_SUB(NOW(), INTERVAL 1 MINUTE), DATE_ADD(NOW(), INTERVAL 10 MINUTE), 'active', NOW(), NOW())");
        $auctionStmt->execute([$categoryId, $creatorId, 'Concurrent bid test ' . $iteration]);
        $auctionId = (int)$server->lastInsertId();

        $barrier = sys_get_temp_dir() . '/intrabids-bid-barrier-' . bin2hex(random_bytes(8));
        @unlink($barrier);
        $commandOne = [PHP_BINARY, $worker, $barrier, (string)$auctionId, (string)$bidderOneId, '10.00'];
        $commandTwo = [PHP_BINARY, $worker, $barrier, (string)$auctionId, (string)$bidderTwoId, '10.00'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $processOne = proc_open($commandOne, $descriptors, $pipesOne, dirname(__DIR__), $environment);
        $processTwo = proc_open($commandTwo, $descriptors, $pipesTwo, dirname(__DIR__), $environment);
        if (!is_resource($processOne) || !is_resource($processTwo)) {
            fail_test('Could not start concurrent bid workers.');
        }

        usleep(150000);
        touch($barrier);
        $resultOne = read_worker_result([$processOne, $pipesOne]);
        $resultTwo = read_worker_result([$processTwo, $pipesTwo]);
        @unlink($barrier);

        $successes = ((bool)($resultOne['ok'] ?? false) ? 1 : 0) + ((bool)($resultTwo['ok'] ?? false) ? 1 : 0);
        if ($successes !== 1) {
            fail_test('Iteration ' . $iteration . ' expected exactly one successful identical bid. Results: ' . json_encode([$resultOne, $resultTwo]));
        }

        $checkStmt = $server->prepare('SELECT current_high_bid, current_high_bidder_id, (SELECT COUNT(*) FROM bids WHERE auction_id = a.id) AS bid_count FROM auctions a WHERE id = ?');
        $checkStmt->execute([$auctionId]);
        $auction = $checkStmt->fetch();
        if (!$auction || (int)$auction['bid_count'] !== 1 || number_format((float)$auction['current_high_bid'], 2, '.', '') !== '10.00') {
            fail_test('Iteration ' . $iteration . ' left an invalid auction state: ' . json_encode($auction));
        }
        if (!in_array((int)$auction['current_high_bidder_id'], [$bidderOneId, $bidderTwoId], true)) {
            fail_test('Iteration ' . $iteration . ' recorded an unexpected high bidder.');
        }
        echo 'PASS iteration ' . $iteration . ": one of two simultaneous identical bids was accepted.\n";
    }

    echo "PASS: concurrent bid locking test completed successfully.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS $quotedDatabase");
}
