<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$textKeys = [
    'site_name',
    'home_alert_text',
    'site_email',
    'app_timezone',
    'site_email_name',
    'allowed_email_domain',
    'default_bid_increment',
    'anti_sniping_minutes',
    'recently_ended_days',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_username',
];
$checkboxKeys = [
    'registration_enabled',
    'home_alert_enabled',
    'anti_sniping_enabled',
    'allow_creator_to_bid',
    'show_winner_publicly',
    'smtp_enabled',
    'access_requests_enabled',
    'access_request_email_approval_enabled',
];

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'test_smtp') {
        $testEmail = trim((string)($_POST['test_email'] ?? (current_user()['email'] ?? '')));
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address for the SMTP test.');
        } else {
            $siteName = (string)setting('site_name', 'IntraBids');
            $sent = send_app_email($testEmail, $siteName . ' SMTP test', "This is a test email from " . $siteName . ".\n\nIf you received this message, SMTP notifications are working.");
            if ($sent) {
                flash('success', 'SMTP test email sent.');
            } else {
                $error = get_last_email_error();
                flash('error', $error !== '' ? 'SMTP test email failed: ' . $error : 'SMTP test email failed. Check the SMTP settings and the PHP/web server error log.');
            }
            log_action((int)current_user()['id'], $sent ? 'smtp_test_sent' : 'smtp_test_failed', 'settings', null, null, ['to' => $testEmail, 'error' => $sent ? null : get_last_email_error()]);
        }
        redirect('admin/settings.php');
    }

    foreach ($textKeys as $key) {
        $value = trim((string)($_POST[$key] ?? ''));
        if ($key === 'app_timezone') {
            if (!in_array($value, timezone_identifiers_list(), true)) {
                flash('error', 'Invalid application timezone. Use a valid PHP timezone such as America/Chicago.');
                redirect('admin/settings.php');
            }
        }
        if ($key === 'home_alert_text') {
            $value = function_exists('mb_substr') ? mb_substr($value, 0, 2000) : substr($value, 0, 2000);
        }
        if ($key === 'smtp_encryption' && !in_array($value, ['tls', 'ssl', 'none'], true)) {
            $value = 'tls';
        }
        if ($key === 'recently_ended_days') {
            $value = (string)max(1, min(365, (int)$value));
        }
        if ($key === 'smtp_port' && $value !== '') {
            $value = (string)max(1, min(65535, (int)$value));
        }
        set_setting_value($key, $value);
    }

    foreach ($checkboxKeys as $key) {
        set_setting_value($key, isset($_POST[$key]) ? '1' : '0');
    }

    if (isset($_POST['clear_smtp_password'])) {
        set_setting_value('smtp_password', '');
    } else {
        $smtpPassword = (string)($_POST['smtp_password'] ?? '');
        if ($smtpPassword !== '') {
            set_setting_value('smtp_password', $smtpPassword);
        }
    }

    try {
        if (isset($_POST['clear_site_logo'])) {
            $oldLogo = setting('site_logo_path', '');
            set_setting_value('site_logo_path', '');
            if ($oldLogo) {
                $oldPath = ROOT_PATH . '/' . $oldLogo;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        } elseif (!empty($_FILES['site_logo']['name'])) {
            $oldLogo = setting('site_logo_path', '');
            $newLogo = upload_site_logo($_FILES['site_logo']);
            if ($newLogo) {
                set_setting_value('site_logo_path', $newLogo);
                if ($oldLogo && $oldLogo !== $newLogo) {
                    $oldPath = ROOT_PATH . '/' . $oldLogo;
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('admin/settings.php');
    }

    $safePost = $_POST;
    if (isset($safePost['smtp_password']) && $safePost['smtp_password'] !== '') {
        $safePost['smtp_password'] = '[updated]';
    }
    log_action((int)current_user()['id'], 'settings_updated', 'settings', null, null, $safePost);
    flash('success', 'Settings updated.');
    redirect('admin/settings.php');
}

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="card">
    <h1>Settings</h1>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h2>Site</h2>
        <div class="grid">
            <div class="form-row">
                <label for="site_name">Site Name</label>
                <input id="site_name" name="site_name" value="<?= h(setting('site_name', 'IntraBids')) ?>">
                <div class="help">This controls the browser title and header name.</div>
            </div>
            <div class="form-row">
                <label for="app_timezone">Application Timezone</label>
                <input id="app_timezone" name="app_timezone" value="<?= h(setting('app_timezone', defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Chicago')) ?>" required>
                <div class="help">Used for auction start/end times and bid timestamps. Example: America/Chicago.</div>
            </div>
            <div class="form-row">
                <label for="site_logo">Site Logo</label>
                <?php if (setting('site_logo_path', '')): ?>
                    <div><img class="logo-preview" src="<?= h(base_url(setting('site_logo_path', ''))) ?>" alt="Current site logo"></div>
                    <div class="inline"><input id="clear_site_logo" type="checkbox" name="clear_site_logo" value="1"><label for="clear_site_logo">Remove current logo</label></div>
                <?php endif; ?>
                <input id="site_logo" name="site_logo" type="file" accept="image/*">
                <div class="help">Optional. JPG, PNG, GIF, or WebP. Max 2 MB.</div>
            </div>
            <div class="form-row">
                <label for="allowed_email_domain">Allowed Email Domain</label>
                <input id="allowed_email_domain" name="allowed_email_domain" value="<?= h(setting('allowed_email_domain', '')) ?>" placeholder="example.com">
                <div class="help">Optional. Leave blank to allow any email domain.</div>
            </div>
            <div class="form-row home-alert-setting">
                <h3>Home Page Alert Banner</h3>
                <div class="inline home-alert-toggle"><input id="home_alert_enabled" type="checkbox" name="home_alert_enabled" value="1" <?= (string)setting('home_alert_enabled','0') === '1' ? 'checked' : '' ?>><label for="home_alert_enabled">Enable the Home Page Alert Banner</label></div>
                <label for="home_alert_text">Banner Text</label>
                <textarea id="home_alert_text" name="home_alert_text" maxlength="2000" placeholder="Example: We have updated our site. Please register a new user."><?= h(setting('home_alert_text', '')) ?></textarea>
                <div class="help">Optional text displayed prominently at the top of the home page. Line breaks are preserved.</div>
            </div>
            <div class="form-row">
                <label for="default_bid_increment">Default Bid Increment</label>
                <input id="default_bid_increment" name="default_bid_increment" type="number" min="0.01" step="0.01" value="<?= h(setting('default_bid_increment', '1.00')) ?>">
            </div>
            <div class="form-row">
                <label for="anti_sniping_minutes">Anti-Sniping Extension Minutes</label>
                <input id="anti_sniping_minutes" name="anti_sniping_minutes" type="number" min="1" value="<?= h(setting('anti_sniping_minutes', '2')) ?>">
            </div>
            <div class="form-row">
                <label for="recently_ended_days">Recently Ended Days</label>
                <input id="recently_ended_days" name="recently_ended_days" type="number" min="1" max="365" value="<?= h(setting('recently_ended_days', '7')) ?>">
                <div class="help">Home page will show all ended auctions from this many days back. Default is 7 days.</div>
            </div>
        </div>

        <hr class="settings-separator">
        <section class="settings-section" aria-labelledby="registration-auction-settings-heading">
        <h2 id="registration-auction-settings-heading">Registration and Auction Behavior</h2>
        <div class="form-row inline"><input id="registration_enabled" type="checkbox" name="registration_enabled" value="1" <?= (string)setting('registration_enabled','1') === '1' ? 'checked' : '' ?>><label for="registration_enabled">Allow users to register</label></div>
        <div class="form-row inline"><input id="anti_sniping_enabled" type="checkbox" name="anti_sniping_enabled" value="1" <?= (string)setting('anti_sniping_enabled','0') === '1' ? 'checked' : '' ?>><label for="anti_sniping_enabled">Enable anti-sniping extension</label></div>
        <div class="form-row inline"><input id="allow_creator_to_bid" type="checkbox" name="allow_creator_to_bid" value="1" <?= (string)setting('allow_creator_to_bid','0') === '1' ? 'checked' : '' ?>><label for="allow_creator_to_bid">Allow auction creators to bid on their own auctions</label></div>
        <div class="form-row inline"><input id="show_winner_publicly" type="checkbox" name="show_winner_publicly" value="1" <?= (string)setting('show_winner_publicly','1') === '1' ? 'checked' : '' ?>><label for="show_winner_publicly">Show auction winner publicly after auction ends</label></div>
        </section>

        <hr class="settings-separator">
        <section class="settings-section" aria-labelledby="access-request-settings-heading">
        <h2 id="access-request-settings-heading">Auction Posting Access Requests</h2>
        <div class="form-row inline"><input id="access_requests_enabled" type="checkbox" name="access_requests_enabled" value="1" <?= (string)setting('access_requests_enabled','0') === '1' ? 'checked' : '' ?>><label for="access_requests_enabled">Allow users to request auction posting access from My Account</label></div>
        <div class="form-row inline"><input id="access_request_email_approval_enabled" type="checkbox" name="access_request_email_approval_enabled" value="1" <?= (string)setting('access_request_email_approval_enabled','0') === '1' ? 'checked' : '' ?>><label for="access_request_email_approval_enabled">Include a passwordless approval button in administrator request emails</label></div>
        <p class="help">When passwordless approval is enabled, active global administrators receive a secure one-time link that expires after 7 days. The link opens a confirmation page and does not approve access until the administrator selects the confirmation button.</p>
        </section>

        <hr class="settings-separator">
        <section class="settings-section" aria-labelledby="smtp-settings-heading">
        <h2 id="smtp-settings-heading">SMTP Email</h2>
        <p class="help">IntraBids sends notifications through this SMTP account. PHP <code>mail()</code> is not used.</p>
        <div class="form-row inline"><input id="smtp_enabled" type="checkbox" name="smtp_enabled" value="1" <?= (string)setting('smtp_enabled','0') === '1' ? 'checked' : '' ?>><label for="smtp_enabled">Enable SMTP notifications</label></div>
        <div class="grid">
            <div class="form-row">
                <label for="site_email">From Email</label>
                <input id="site_email" name="site_email" type="email" value="<?= h(setting('site_email', '')) ?>" placeholder="auction@example.com">
            </div>
            <div class="form-row">
                <label for="site_email_name">From Name</label>
                <input id="site_email_name" name="site_email_name" value="<?= h(setting('site_email_name', 'IntraBids')) ?>" placeholder="IntraBids">
            </div>
            <div class="form-row">
                <label for="smtp_host">SMTP Host</label>
                <input id="smtp_host" name="smtp_host" value="<?= h(setting('smtp_host', '')) ?>" placeholder="smtp.example.com">
            </div>
            <div class="form-row">
                <label for="smtp_port">SMTP Port</label>
                <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= h(setting('smtp_port', '587')) ?>">
            </div>
            <div class="form-row">
                <label for="smtp_encryption">SMTP Encryption</label>
                <select id="smtp_encryption" name="smtp_encryption">
                    <?php $smtpEncryption = (string)setting('smtp_encryption', 'tls'); ?>
                    <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>STARTTLS / TLS, usually port 587</option>
                    <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>Implicit SSL, usually port 465</option>
                    <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>None / internal relay, usually port 25</option>
                </select>
            </div>
            <div class="form-row">
                <label for="smtp_username">SMTP Username</label>
                <input id="smtp_username" name="smtp_username" value="<?= h(setting('smtp_username', '')) ?>" autocomplete="off">
                <div class="help">Leave blank only if your SMTP relay does not require authentication.</div>
            </div>
            <div class="form-row">
                <label for="smtp_password">SMTP Password</label>
                <input id="smtp_password" name="smtp_password" type="password" value="" autocomplete="new-password" placeholder="Leave blank to keep existing password">
            </div>
        </div>
        <div class="form-row inline"><input id="clear_smtp_password" type="checkbox" name="clear_smtp_password" value="1"><label for="clear_smtp_password">Clear saved SMTP password</label></div>
        </section>

        <button type="submit" name="action" value="save">Save Settings</button>
    </form>
</div>

<div class="card">
    <h2>Send SMTP Test</h2>
    <p class="help">Save your SMTP settings first, then send a test email.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_smtp">
        <div class="form-row">
            <label for="test_email">Test Recipient</label>
            <input id="test_email" name="test_email" type="email" value="<?= h(current_user()['email'] ?? '') ?>" required>
        </div>
        <button type="submit">Send Test Email</button>
    </form>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
