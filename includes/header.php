<?php
$user = current_user();
$siteName = setting('site_name', 'IntraBids');
$siteLogo = setting('site_logo_path', '');
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($siteName) ?></title>
    <link rel="stylesheet" href="<?= h(base_url('assets/css/style.css?v=' . rawurlencode(intrabid_version()))) ?>">
</head>
<body>
<header class="topbar">
    <div class="brand">
        <a href="<?= h(base_url('index.php')) ?>">
            <?php if ($siteLogo): ?>
                <img class="site-logo" src="<?= h(base_url($siteLogo)) ?>" alt="<?= h($siteName) ?> logo" style="max-height:46px;max-width:190px;width:auto;height:auto;object-fit:contain;display:block;">
            <?php endif; ?>
            <span><?= h($siteName) ?></span>
        </a>
    </div>
    <nav>
        <a href="<?= h(base_url('index.php')) ?>">Auctions</a>
        <?php if ($user): ?>
            <a href="<?= h(base_url('my_bids.php')) ?>">My Bids</a>
            <a href="<?= h(base_url('account.php')) ?>">My Account</a>
            <?php if (can_create_auctions()): ?>
                <a href="<?= h(base_url('creator/my_auctions.php')) ?>">My Auctions</a>
                <a href="<?= h(base_url('admin/categories.php')) ?>">Categories</a>
            <?php endif; ?>
            <?php if (is_admin()): ?>
                <a href="<?= h(base_url('admin/index.php')) ?>">Admin</a>
            <?php endif; ?>
            <a href="<?= h(base_url('logout.php')) ?>">Logout</a>
        <?php else: ?>
            <a href="<?= h(base_url('login.php')) ?>">Login</a>
            <?php if ((string)setting('registration_enabled', '1') === '1'): ?>
                <a href="<?= h(base_url('register.php')) ?>">Register</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
<?php render_flash(); ?>
