<?php
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function base_url(string $path = ''): string
{
    $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');
    if ($base === '') {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base = $dir === '/' ? '' : $dir;
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . base_url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    foreach ($_SESSION['flash'] as $item) {
        echo '<div class="alert alert-' . h($item['type']) . '">' . h($item['message']) . '</div>';
    }
    unset($_SESSION['flash']);
}

function money($amount): string
{
    return '$' . number_format((float)$amount, 2);
}

function intrabid_version(): string
{
    static $version = null;
    if ($version === null) {
        $path = ROOT_PATH . '/VERSION';
        $version = is_file($path) ? trim((string)file_get_contents($path)) : 'unknown';
        if ($version === '') {
            $version = 'unknown';
        }
    }
    return $version;
}

function app_timezone(): string
{
    $timezone = (string)setting('app_timezone', defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Chicago');
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $timezone = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Chicago';
    }
    return $timezone;
}

function dt(?string $value): string
{
    if (!$value) {
        return '';
    }
    return date('M j, Y g:i A T', strtotime($value));
}

function input_dt_value(?string $value): string
{
    if (!$value) {
        return '';
    }
    return date('Y-m-d\TH:i', strtotime($value));
}

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

function setting(string $key, $default = null)
{
    static $settings = null;
    if ($key === '__reset__') {
        $settings = null;
        return null;
    }
    if ($settings === null) {
        try {
            $settings = [];
            foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            $settings = [];
        }
    }
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

function set_setting_value(string $key, string $value): void
{
    db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    reset_settings_cache();
}

function reset_settings_cache(): void
{
    setting('__reset__');
}

function log_action(?int $userId, string $action, string $entityType = '', ?int $entityId = null, $oldValue = null, $newValue = null): void
{
    try {
        db_exec(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValue === null ? null : json_encode($oldValue),
                $newValue === null ? null : json_encode($newValue),
                $_SERVER['REMOTE_ADDR'] ?? null,
                now_sql(),
            ]
        );
    } catch (Throwable $e) {
        // Logging should never break the user flow.
    }
}

function user_display(?array $user): string
{
    if (!$user) {
        return 'Unknown';
    }
    return trim(($user['name'] ?? '') ?: ($user['email'] ?? 'Unknown'));
}

function upload_auction_images(int $auctionId, array $files): array
{
    $saved = [];
    if (empty($files['name']) || !is_array($files['name'])) {
        return $saved;
    }

    $targetDir = ROOT_PATH . '/uploads/auctions';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($files['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the image uploads failed.');
        }
        if (($files['size'][$i] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException('Images must be 5 MB or smaller.');
        }
        $tmp = $files['tmp_name'][$i];
        $mime = mime_content_type($tmp);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Only JPG, PNG, GIF, and WebP images are allowed.');
        }
        $filename = 'auction-' . $auctionId . '-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        $dest = $targetDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Could not save uploaded image.');
        }
        $relative = 'uploads/auctions/' . $filename;
        db_exec('INSERT INTO auction_images (auction_id, file_path, sort_order, created_at) VALUES (?, ?, ?, ?)', [$auctionId, $relative, $i, now_sql()]);
        $saved[] = $relative;
    }
    return $saved;
}


function upload_site_logo(array $file): ?string
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo upload failed.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo must be 2 MB or smaller.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $tmp = $file['tmp_name'];
    $mime = mime_content_type($tmp);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, GIF, and WebP logos are allowed.');
    }

    $targetDir = ROOT_PATH . '/uploads/site';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $filename = 'logo-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $dest = $targetDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save uploaded logo.');
    }

    return 'uploads/site/' . $filename;
}

function effective_auction_status(array $auction): string
{
    if (in_array($auction['status'], ['draft', 'cancelled', 'ended', 'awarded'], true)) {
        return $auction['status'];
    }
    $now = time();
    $start = strtotime($auction['start_time']);
    $end = strtotime($auction['end_time']);
    if ($end <= $now) {
        return empty($auction['winning_bid_id']) ? 'ended' : 'awarded';
    }
    if ($start > $now) {
        return 'scheduled';
    }
    return 'active';
}

function calculate_publish_status(string $startTime, string $endTime): string
{
    $now = time();
    if (strtotime($endTime) <= $now) {
        return 'ended';
    }
    if (strtotime($startTime) > $now) {
        return 'scheduled';
    }
    return 'active';
}

function run_auction_maintenance(): void
{
    try {
        $now = now_sql();
        db_exec("UPDATE auctions SET status = 'active', updated_at = ? WHERE status = 'scheduled' AND start_time <= ? AND end_time > ?", [$now, $now, $now]);
        $due = db_all("SELECT id FROM auctions WHERE status IN ('active', 'scheduled') AND end_time <= ? LIMIT 100", [$now]);
        foreach ($due as $row) {
            close_single_auction((int)$row['id']);
        }
    } catch (Throwable $e) {
        // Avoid breaking normal page loads if maintenance encounters a temporary issue.
    }
}

function close_single_auction(int $auctionId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM auctions WHERE id = ? FOR UPDATE');
        $stmt->execute([$auctionId]);
        $auction = $stmt->fetch();
        if (!$auction || !in_array($auction['status'], ['active', 'scheduled'], true) || strtotime($auction['end_time']) > time()) {
            $pdo->commit();
            return;
        }
        $bidStmt = $pdo->prepare('SELECT * FROM bids WHERE auction_id = ? ORDER BY bid_amount DESC, created_at ASC, id ASC LIMIT 1');
        $bidStmt->execute([$auctionId]);
        $winningBid = $bidStmt->fetch();
        if ($winningBid) {
            $update = $pdo->prepare("UPDATE auctions SET status = 'awarded', winning_bid_id = ?, winning_user_id = ?, current_high_bid = ?, current_high_bidder_id = ?, awarded_at = ?, updated_at = ? WHERE id = ?");
            $closedAt = now_sql();
            $update->execute([(int)$winningBid['id'], (int)$winningBid['user_id'], $winningBid['bid_amount'], (int)$winningBid['user_id'], $closedAt, $closedAt, $auctionId]);
        } else {
            $update = $pdo->prepare("UPDATE auctions SET status = 'ended', awarded_at = ?, updated_at = ? WHERE id = ?");
            $closedAt = now_sql();
            $update->execute([$closedAt, $closedAt, $auctionId]);
        }
        $pdo->commit();
        log_action(null, $winningBid ? 'auction_awarded' : 'auction_ended_no_bids', 'auction', $auctionId, null, $winningBid ?: []);
        if ($winningBid) {
            notify_winner($auctionId, (int)$winningBid['user_id']);
        }
        notify_auction_creator($auctionId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function smtp_default_port(string $encryption): int
{
    if ($encryption === 'ssl') {
        return 465;
    }
    if ($encryption === 'tls') {
        return 587;
    }
    return 25;
}

function smtp_read_response($socket): array
{
    $lines = [];
    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^\\d{3} /', $line)) {
            break;
        }
    }
    if (!$lines) {
        throw new RuntimeException('SMTP server did not respond.');
    }
    $last = end($lines);
    $code = (int)substr($last, 0, 3);
    return [$code, implode("\n", $lines)];
}

function smtp_send_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    [$code, $response] = smtp_read_response($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP command failed: ' . $response);
    }
    return $response;
}

function smtp_encode_header(string $value): string
{
    $value = trim(str_replace(["\r", "\n"], '', $value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^[\x20-\x7E]+$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_format_address(string $email, string $name = ''): string
{
    $email = trim(str_replace(["\r", "\n"], '', $email));
    $name = trim(str_replace(["\r", "\n", '"'], ['', '', '\\"'], $name));
    if ($name === '') {
        return '<' . $email . '>';
    }
    return '"' . smtp_encode_header($name) . '" <' . $email . '>';
}

function smtp_dot_stuff(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body);
    return str_replace("\n", "\r\n", $body);
}

function set_last_email_error(string $message): void
{
    $GLOBALS['intrabid_last_email_error'] = $message;
}

function get_last_email_error(): string
{
    return (string)($GLOBALS['intrabid_last_email_error'] ?? '');
}

function clear_last_email_error(): void
{
    unset($GLOBALS['intrabid_last_email_error']);
}

function smtp_configuration_error(): ?string
{
    if ((string)setting('smtp_enabled', '0') !== '1') {
        return 'SMTP notifications are disabled. Check Enable SMTP notifications, save settings, then try again.';
    }

    $host = trim((string)setting('smtp_host', ''));
    if ($host === '') {
        return 'SMTP Host is required.';
    }

    $from = trim((string)setting('site_email', ''));
    if ($from === '') {
        return 'From Email is required.';
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return 'From Email is not a valid email address.';
    }

    $encryption = strtolower(trim((string)setting('smtp_encryption', 'tls')));
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        return 'SMTP Encryption must be STARTTLS/TLS, SSL, or None.';
    }
    if (in_array($encryption, ['tls', 'ssl'], true) && !extension_loaded('openssl')) {
        return 'PHP OpenSSL is not enabled, but SMTP encryption requires it.';
    }

    $port = (int)setting('smtp_port', '0');
    if ($port < 0 || $port > 65535) {
        return 'SMTP Port must be between 1 and 65535.';
    }

    return null;
}

function smtp_send_message(string $to, string $subject, string $plainMessage, ?string $htmlMessage = null): bool
{
    clear_last_email_error();
    $configError = smtp_configuration_error();
    if ($configError !== null) {
        set_last_email_error($configError);
        error_log('IntraBids SMTP configuration error: ' . $configError);
        return false;
    }

    $host = trim((string)setting('smtp_host', ''));
    $from = trim((string)setting('site_email', ''));
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        set_last_email_error('Recipient email address is not valid.');
        return false;
    }

    $encryption = strtolower(trim((string)setting('smtp_encryption', 'tls')));
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }
    $port = (int)setting('smtp_port', '0');
    if ($port <= 0) {
        $port = smtp_default_port($encryption);
    }

    $username = (string)setting('smtp_username', '');
    $password = (string)setting('smtp_password', '');
    $fromName = (string)setting('site_email_name', setting('site_name', 'IntraBids'));
    $timeout = 20;
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $error = 'Could not connect to SMTP server: ' . $errstr . ' (' . $errno . ').';
        set_last_email_error($error);
        error_log('IntraBids SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    try {
        stream_set_timeout($socket, $timeout);
        [$code, $response] = smtp_read_response($socket);
        if ($code !== 220) {
            throw new RuntimeException('SMTP greeting failed: ' . $response);
        }

        $ehloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        smtp_send_command($socket, 'EHLO ' . $ehloName, [250]);

        if ($encryption === 'tls') {
            smtp_send_command($socket, 'STARTTLS', [220]);
            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                throw new RuntimeException('Could not enable SMTP TLS encryption.');
            }
            smtp_send_command($socket, 'EHLO ' . $ehloName, [250]);
        }

        if ($username !== '') {
            smtp_send_command($socket, 'AUTH LOGIN', [334]);
            smtp_send_command($socket, base64_encode($username), [334]);
            smtp_send_command($socket, base64_encode($password), [235]);
        }

        smtp_send_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_send_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_send_command($socket, 'DATA', [354]);

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . smtp_format_address($from, $fromName);
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . smtp_encode_header($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: IntraBids SMTP';

        if ($htmlMessage !== null) {
            $boundary = '=_IntraBids_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= str_replace(["\r\n", "\r"], "\n", $plainMessage) . "\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $htmlMessage . "\r\n";
            $body .= '--' . $boundary . "--";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = $plainMessage;
        }

        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . smtp_dot_stuff($body) . "\r\n.\r\n");
        [$dataCode, $dataResponse] = smtp_read_response($socket);
        if ($dataCode !== 250) {
            throw new RuntimeException('SMTP DATA failed: ' . $dataResponse);
        }
        smtp_send_command($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        set_last_email_error($e->getMessage());
        error_log('IntraBids SMTP send failed: ' . $e->getMessage());
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return false;
    }
}

function smtp_send_email(string $to, string $subject, string $message): bool
{
    return smtp_send_message($to, $subject, $message, null);
}

function send_app_email(string $to, string $subject, string $message): bool
{
    return smtp_send_message($to, $subject, $message, null);
}

function send_app_html_email(string $to, string $subject, string $plainMessage, string $htmlMessage): bool
{
    return smtp_send_message($to, $subject, $plainMessage, $htmlMessage);
}

function email_page_html(string $heading, string $bodyHtml): string
{
    $siteName = h((string)setting('site_name', 'IntraBids'));
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#252525;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="760" cellspacing="0" cellpadding="0" style="width:100%;max-width:760px;background:#ffffff;border-collapse:collapse;">'
        . '<tr><td style="padding:34px 42px 18px;text-align:center;font-size:34px;line-height:1.2;font-weight:500;">' . h($heading) . '</td></tr>'
        . '<tr><td style="padding:20px 42px 40px;font-size:20px;line-height:1.55;">' . $bodyHtml . '</td></tr>'
        . '<tr><td style="padding:18px 42px 28px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;text-align:center;">' . $siteName . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function email_button_html(string $url, string $label): string
{
    return '<div style="text-align:center;margin:34px 0;">'
        . '<a href="' . h($url) . '" style="display:inline-block;background:#0b69c7;color:#ffffff;text-decoration:none;font-size:20px;font-weight:700;padding:15px 28px;border-radius:7px;">'
        . h($label) . '</a></div>';
}

function notify_winner(int $auctionId, int $userId): void
{
    $auction = db_one('SELECT * FROM auctions WHERE id = ?', [$auctionId]);
    $user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$auction || !$user) {
        return;
    }
    $siteName = (string)setting('site_name', 'IntraBids');
    $subject = 'You won: ' . $auction['title'];
    $body = "Congratulations!\n\nYou won the " . $siteName . " auction for:\n" . $auction['title'] . "\n\nFinal price: " . money($auction['current_high_bid']) . "\n\nPickup location: " . ($auction['pickup_location'] ?: 'Not specified') . "\nPickup instructions: " . ($auction['pickup_instructions'] ?: 'Not specified') . "\n\n" . base_url('auction.php?id=' . $auctionId);
    send_app_email($user['email'], $subject, $body);
}

function notify_auction_creator(int $auctionId): void
{
    $auction = db_one('SELECT a.*, creator.name AS creator_name, creator.email AS creator_email,
                              winner.name AS winner_name, winner.email AS winner_email
                       FROM auctions a
                       LEFT JOIN users creator ON creator.id = a.created_by
                       LEFT JOIN users winner ON winner.id = a.winning_user_id
                       WHERE a.id = ?', [$auctionId]);
    if (!$auction || empty($auction['creator_email'])) {
        return;
    }

    $siteName = (string)setting('site_name', 'IntraBids');
    $subject = 'Auction ended: ' . $auction['title'];

    if (!empty($auction['winning_user_id'])) {
        $winner = trim((string)($auction['winner_name'] ?: $auction['winner_email'] ?: 'Unknown'));
        $result = "Winning bidder: " . $winner . "\nWinning bid: " . money($auction['current_high_bid']);
    } else {
        $result = "No winning bidder. No bids were placed.";
    }

    $body = "Your " . $siteName . " auction has ended.\n\nAuction: " . $auction['title'] . "\n" . $result . "\n\n" . base_url('auction.php?id=' . $auctionId);
    send_app_email($auction['creator_email'], $subject, $body);
}

function status_badge(string $status): string
{
    return '<span class="badge badge-' . h($status) . '">' . h(ucfirst($status)) . '</span>';
}
