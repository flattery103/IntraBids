# IntraBids

IntraBids is a lightweight internal company auction site built with PHP and MySQL/MariaDB. Employees can register, browse auctions, place bids, and review their bidding activity. Trusted users can be granted auction creator access, while global administrators manage users, auctions, settings, and audit logs.

There is intentionally no committee role or auction approval workflow. A user who has auction creator permission is authorized to publish auctions directly.

## Current version

**IntraBids v1.7.1**

## Included features

- User registration, login, and logout
- Forgot-password workflow with secure, one-time emailed reset links
- Logged-in password changes from the My Account page
- Configurable auction posting access requests
- Global administrator access-request review with approve and deny actions
- Optional passwordless email approval links for posting-access requests
- Global administrator user management
- Admin-granted **Can Create Auctions** permission
- Auction category creation and management
- Drag-and-drop category ordering with automatic saving
- Safe category deletion when a category has not been used by an auction
- Active/inactive category controls
- Auction drafts, scheduled auctions, active auctions, ended auctions, awarded auctions, and cancelled auctions
- Timed auctions with server-side start and end enforcement
- Multiple image uploads per auction
- Clickable portrait auction thumbnails that display the full image
- Left-side category menu with per-category auction counts
- Configurable Recently Ended time window
- Configurable site name and site logo
- Optional home page alert banner with administrator-controlled text
- My Auctions winner display after an auction closes
- My Bids history
- Server-side bid validation and bid increments
- Optional anti-sniping extension
- Automatic auction closing and winner selection
- SMTP-based winner and auction creator notifications
- SMTP configuration testing with useful error messages
- Application timezone control with timezone abbreviations on displayed timestamps
- Audit logs
- Web-based installation routine
- Built-in database upgrade routine
- Cron-compatible auction closing script
- Current application version in the footer

## Requirements

- PHP 8.1 or newer
- MySQL 5.7+ or MariaDB 10.3+
- Apache or Nginx
- PHP extensions:
  - PDO MySQL
  - fileinfo
  - openssl for encrypted SMTP connections
  - mbstring recommended
- Writable `config/` directory during installation
- Writable `uploads/` directory for auction images and site logos

## Installation

1. Copy the IntraBids application folder to the web server.

   Example:

   ```bash
   sudo cp -r intrabid /var/www/html/intrabid
   sudo chown -R www-data:www-data /var/www/html/intrabid/config /var/www/html/intrabid/uploads
   ```

2. Create a MySQL/MariaDB database and database user, or provide a database user that has permission to create and use the database.

   Example:

   ```sql
   CREATE DATABASE intrabid CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'intrabid'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
   GRANT ALL PRIVILEGES ON intrabid.* TO 'intrabid'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. Open the installer in a browser:

   ```text
   https://your-server.example.com/intrabid/install.php
   ```

4. Enter the database connection information and create the first global administrator account.

5. After installation, normal pages use the generated `config/config.php` file.

## Installer behavior

When `config/config.php` does not exist, IntraBids redirects normal pages to `install.php`.

The installer:

- Creates the configured database when the database user has permission
- Runs `database/schema.sql`
- Creates the default categories and settings
- Creates the first global administrator account
- Writes `config/config.php`

Do not publish or share a real `config/config.php` because it contains database credentials.

## Upgrading an existing installation

1. Preserve the existing `config/config.php` file and `uploads/` directory.
2. Copy the new application files over the existing installation.
3. Run the built-in upgrade routine:

   ```bash
   php /path/to/intrabid/upgrade.php
   ```

The upgrader reads the existing database settings from `config/config.php`, applies any missing migrations, and updates the installed version record.

A global administrator can also open `upgrade.php` in a browser after copying the files.

## Home page alert banner

A global administrator can configure the optional banner under **Admin → Settings**:

- Enter the message in **Home Page Alert Banner**.
- Enable **Show the Home Page Alert Banner**.
- Save the settings.

The banner is displayed only when it is enabled and contains text. Line breaks in the message are preserved.

## Category management

Auction creators and global administrators can manage categories.

- Drag the handle beside a category to move it up or down.
- The new order is saved automatically.
- Keyboard users can focus the drag handle and use the Up Arrow or Down Arrow keys.
- A category can be deleted only when no auction has ever used it.
- Categories tied to auctions must be deactivated instead so historical auction records remain intact.

## SMTP notifications

IntraBids sends notifications through the SMTP account configured during installation or under **Admin → Settings**. PHP `mail()` is not used as a fallback.

SMTP options include:

- Enable SMTP notifications
- From email address and display name
- SMTP host and port
- STARTTLS/TLS, implicit SSL, or no encryption for an internal relay
- SMTP username and password
- Test email recipient

Common SMTP ports:

```text
587 = STARTTLS / TLS
465 = implicit SSL
25  = internal relay or no encryption
```

## Password management

Users can change their password at any time from **My Account**. The change requires the current password.

The login page also includes **Forgot Your Password?**. Reset links:

- Are delivered through the configured SMTP account
- Expire after one hour
- Can be used only once
- Are stored in the database as hashes rather than reusable plaintext tokens

A correct `APP_URL` in `config/config.php` is required so emailed links point to the public IntraBids address.

## Auction posting access requests

Global administrators can configure access requests under **Admin → Settings**.

- **Allow users to request auction posting access** displays the request option in **My Account** for users who cannot create auctions.
- Requests are listed at the top of **Admin → Users**, where an administrator can approve or deny them.
- All active global administrators receive a notification email when a request is submitted.
- **Include a passwordless approval button** adds a one-time approval link to the email. The link expires after seven days and opens a confirmation page before permission is granted.
- When passwordless approval is disabled, the email links to the authenticated admin review page instead.

## Application timezone

IntraBids uses the **Application Timezone** setting under **Admin → Settings** for auction times, bid timestamps, automatic closing, and displayed timezone abbreviations.

Example:

```text
America/Chicago
```

## Cron job for auction closing

Normal page requests perform lightweight auction maintenance, but a cron job is recommended so auctions close on time even when nobody is browsing the site.

Run the closing script every minute:

```bash
* * * * * /usr/bin/php /var/www/html/intrabid/cron/close_auctions.php >/dev/null 2>&1
```

Update the path to match the installation.

## Roles

### User / Employee

- Register and log in
- Browse active and scheduled auctions
- Place bids
- View personal bid history
- Change their password from My Account
- Request auction posting access when the feature is enabled

### Auction Creator

- Create and manage categories
- Reorder and safely delete unused categories
- Create auctions
- Save drafts
- Publish auctions directly
- Edit eligible auctions
- Upload auction images
- View the winning bidder on My Auctions after an auction closes

### Global Administrator

- Manage users
- Grant auction creator access
- Approve or deny auction posting access requests
- Manage all auctions and categories
- Configure the site name, logo, timezone, and home page banner
- Enable or disable posting-access requests and passwordless email approval
- Configure and test SMTP
- Change application settings
- View audit logs

## Important business rules

- There is no auction approval process.
- Auction creator permission authorizes the user to publish auctions directly.
- By default, auction creators cannot bid on their own auctions.
- Recently Ended defaults to seven days and is configurable.
- Bids are permanent and cannot be deleted by normal users.
- Categories used by auctions cannot be deleted.
- The server enforces auction start and end times; browser countdowns are visual only.

## Security notes

The application includes:

- Password hashing with `password_hash()`
- PDO prepared statements
- CSRF protection on forms and category reorder requests
- Server-side role and permission checks
- MIME type and size validation for uploaded images
- Audit logging for important actions
- Apache deny rules for sensitive directories

Recommended production practices:

- Serve the site over HTTPS
- Restrict access to the company network or VPN when appropriate
- Use a dedicated SMTP account or internal SMTP relay
- Back up the database and uploaded images
- Keep PHP, the web server, and MySQL/MariaDB patched

## File layout

```text
admin/                  Global administrator and category pages
assets/css/             Site styles
assets/js/              Site JavaScript
config/                 Generated config.php lives here
creator/                Auction creator pages
cron/                   Scheduled auction maintenance
database/schema.sql     Database schema
database/upgrades/      Database migration files
includes/               Shared PHP helpers, authentication, CSRF, and database functions
uploads/auctions/       Uploaded auction images
uploads/site/           Uploaded site logo
install.php             Web installer
upgrade.php             Built-in database upgrade routine
index.php               Public auction list and optional alert banner
auction.php             Auction detail and bidding page
login.php               Login page
register.php            Registration page
my_bids.php             User bid history
```
