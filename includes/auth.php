<?php
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }
    $user = db_one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$_SESSION['user_id']]);
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

function can_create_auctions(): bool
{
    $user = current_user();
    return $user && ($user['role'] === 'admin' || (int)$user['can_create_auctions'] === 1);
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please log in first.');
        redirect('login.php');
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        include ROOT_PATH . '/includes/header.php';
        echo '<div class="card"><h1>Access denied</h1><p>You do not have permission to access this page.</p></div>';
        include ROOT_PATH . '/includes/footer.php';
        exit;
    }
}

function require_auction_creator(): void
{
    require_login();
    if (!can_create_auctions()) {
        http_response_code(403);
        include ROOT_PATH . '/includes/header.php';
        echo '<div class="card"><h1>Access denied</h1><p>Your account is not allowed to create auctions.</p></div>';
        include ROOT_PATH . '/includes/footer.php';
        exit;
    }
}
