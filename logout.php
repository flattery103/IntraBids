<?php
require __DIR__ . '/includes/bootstrap.php';
$user = current_user();
if ($user) {
    log_action((int)$user['id'], 'logout', 'user', (int)$user['id']);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: ' . base_url('login.php'));
exit;
