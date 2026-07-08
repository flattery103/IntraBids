# IntraBid

IntraBid is a lightweight internal auction site built with PHP and MySQL/MariaDB.

It is designed for a small business environment where employees can register, bid on timed auctions, and selected trusted users can create and publish auctions directly. There is intentionally no committee/admin approval workflow. A global admin controls who is allowed to post auctions.

## Upgrading

After copying the new files over your existing IntraBid install, run the built-in upgrader. It reads your existing `config/config.php`, connects to MySQL using the stored database credentials, and applies any missing SQL migrations.

From the command line:

```bash
php /path/to/IntraBid/upgrade.php
```

Or log in as a global admin and open:

```text
https://your-site.example.com/IntraBid/upgrade.php
```

The upgrader tracks completed migrations in the `schema_migrations` table so future upgrades do not require you to manually choose SQL files.

IntraBid adds the app version to the stylesheet URL, which helps force browsers to load the updated layout after an upgrade.

## Timezone and bid timestamps

IntraBid stores and displays auction times using the Application Timezone setting under **Admin > Settings**. New bid timestamps are written using that configured timezone, and displayed timestamps include the timezone abbreviation.

## Included features

- User registration, login, and logout
- Global admin user management
- Admin-granted auction creator permission
- Category management by global admins and auction creators
- Auction drafts, scheduled auctions, active auctions, ended auctions, awarded auctions, and cancelled auctions
- Multiple image uploads per auction
- Clickable portrait auction thumbnails with full-image contained display
- Left-side category menu with per-category auction counts
- Recently Ended section shows all ended/awarded auctions within a configurable day window
- Configurable site name and logo with constrained header logo sizing
- Current version displayed in the footer
- My Auctions page shows the winning bidder once an auction is over
- Timed auctions with start and end date/time
- Server-side bid validation
- Bid increments
- Optional anti-sniping extension
- Automatic winner selection
- SMTP-based email notifications using a configured SMTP account
- SMTP test page shows common configuration errors directly
- Auction creators receive email notification when their auction closes
- Admin dashboard
- Auction reporting/listing
- Audit logs
- Web-based install routine when `config/config.php` does not exist
- Cron-compatible auction closing script

## Requirements

- PHP 8.1 or newer
- MySQL 5.7+ or MariaDB 10.3+
- PHP extensions:
  - PDO MySQL
  - fileinfo
  - mbstring recommended
  - openssl required for STARTTLS/SSL SMTP
- Apache or Nginx
- A writable `config/` directory during installation
- A writable `uploads/` directory for auction images and site logo uploads

## Installation

1. Copy the IntraBid folder to your web server.

   Example:

   ```bash
   sudo cp -r intrabid /var/www/html/intrabid
   sudo chown -R www-data:www-data /var/www/html/intrabid/config /var/www/html/intrabid/uploads
   ```

2. Create a MySQL/MariaDB user, or use an existing database user that can create/use the IntraBid database.

   Example:

   ```sql
   CREATE DATABASE intrabid CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'intrabid'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
   GRANT ALL PRIVILEGES ON intrabid.* TO 'intrabid'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. Open the installer in a browser:

   ```text
   http://your-server/intrabid/install.php
   ```

4. Enter the database connection information and create the first global admin account.

5. After installation, normal pages will use `config/config.php`.

## Installer behavior

If `config/config.php` does not exist, IntraBid redirects normal pages to `install.php`.

The installer:

- Creates the configured database if the DB user has permission
- Runs `database/schema.sql`
- Creates default categories
- Creates default settings
- Creates the first global admin account
- Writes `config/config.php`

To reinstall, remove `config/config.php` and clear or recreate the database.

## SMTP notifications

IntraBid no longer uses PHP `mail()` for notifications. Configure SMTP during installation or later from **Admin > Settings**.

SMTP settings include:

- Enable SMTP notifications
- From email
- From name
- SMTP host
- SMTP port
- Encryption: STARTTLS/TLS, implicit SSL, or none for an internal relay
- SMTP username
- SMTP password
- SMTP test email recipient

Common port choices:

```text
587 = STARTTLS / TLS
465 = implicit SSL
25  = internal relay or no encryption
```

If SMTP is not enabled or the SMTP host/from address is blank, notifications are skipped instead of falling back to PHP `mail()`.
In v1.5.2 and newer, the SMTP test page displays the most common configuration error directly, such as SMTP being disabled or the From Email being missing.

## Upgrade from v1.5.1 to v1.5.2

1. Back up your existing IntraBid folder and database.
2. Copy the new files over the existing installation, preserving your existing `config/config.php` and `uploads/` folder.
3. Run the built-in upgrader to update the stored version record. This release does not require database schema changes, but running the upgrader is still recommended:

```bash
php /path/to/IntraBid/upgrade.php
```

## Upgrade from v1.3.0 to v1.4.0

1. Back up your existing IntraBid folder and database.
2. Copy the new files over the existing installation, preserving your existing `config/config.php` and `uploads/` folder.
3. Run the built-in upgrader to update the stored schema/version record. This release does not require database schema changes, but running the upgrader is still recommended:

```bash
php /path/to/IntraBid/upgrade.php
```

## Upgrade from v1.1.0 to v1.2.0

1. Back up your existing IntraBid folder and database.
2. Copy the new files over the existing installation, but preserve your existing `config/config.php` and `uploads/` folder.
3. Run this SQL against the IntraBid database to add the site logo setting if it does not already exist:

```bash
mysql -u intrabid -p intrabid < database/upgrades/upgrade_v1.1.0_to_v1.2.0.sql
```

4. Log in as a global admin and go to **Admin > Settings** to change the site title or upload a logo.

## Upgrade from v1.0.0 to v1.1.0

1. Back up your existing IntraBid folder and database.
2. Copy the new files over the existing installation, but preserve your existing `config/config.php` and `uploads/` folder.
3. Run this SQL against the IntraBid database to add the SMTP settings if they do not already exist:

```bash
mysql -u intrabid -p intrabid < database/upgrades/upgrade_v1.0.0_to_v1.1.0.sql
```

4. Log in as a global admin, configure SMTP under **Admin > Settings**, save, then use **Send SMTP Test** on that page.

## Cron job for auction closing

IntraBid performs lightweight auction maintenance during normal page loads, but you should also add a cron job so auctions close on time even when nobody is browsing the site.

Run this every minute:

```bash
* * * * * /usr/bin/php /var/www/html/intrabid/cron/close_auctions.php >/dev/null 2>&1
```

Update the path to match your installation.

## First steps after install

1. Log in with the global admin account.
2. Go to **Admin > Settings** and configure:
   - Site name and optional logo
   - SMTP notification settings
   - Allowed email domain, if desired
   - Default bid increment
   - Anti-sniping preference
   - Recently Ended Days
3. Go to **Categories** or **Admin > Categories** and adjust categories.
4. Go to **Admin > Users** and grant **Can Create Auctions** to trusted employees.
5. Create or publish the first auction.

## Roles

### User / Employee

- Register and log in
- Browse active and scheduled auctions
- Place bids
- View their own bids

### Auction Creator

- Create and manage categories
- Create auctions
- Save drafts
- Publish auctions directly
- Edit their own auctions before bidding starts
- Add auction images
- Cancel auctions before they are ended/awarded

### Global Admin

- Manage users
- Grant auction creator access
- Manage categories
- Change the site name and upload/remove the site logo
- Manage all auctions
- Close/cancel auctions
- Change settings
- View audit logs

## Important business rules

- There is no auction approval process.
- A user with auction creator access is considered fully authorized to publish auctions.
- By default, auction creators cannot bid on their own auctions.
- By default, winners are visible publicly after the auction ends.
- Recently Ended defaults to 7 days and can be changed under Admin > Settings.
- Bids are permanent and cannot be deleted by normal users.
- The server enforces auction start/end times. The browser countdown is only visual.

## Security notes

This app includes basic security controls:

- Passwords are hashed using PHP `password_hash()`
- Database queries use PDO prepared statements
- Forms use CSRF tokens
- Admin and auction creator pages use server-side permission checks
- Uploaded images are validated by MIME type and size
- Sensitive directories include `.htaccess` deny rules for Apache
- Audit logs track key user/admin/auction events

Recommended production hardening:

- Serve the site only over HTTPS
- Limit access to the internal network or VPN if appropriate
- Use a dedicated SMTP account or internal SMTP relay for notifications
- Back up the database and uploaded images
- Keep PHP and MySQL/MariaDB patched
- Consider SSO/Active Directory integration in a future version

## File layout

```text
admin/                 Global admin pages
assets/css/            Site CSS
config/                Generated config.php lives here after install
creator/               Auction creator pages
cron/                  Scheduled maintenance scripts
database/schema.sql    Database schema
includes/              Shared PHP helpers, auth, CSRF, database functions
uploads/auctions/      Uploaded auction images
uploads/site/          Uploaded site logo
database/upgrades/      Optional SQL upgrade scripts
install.php            Web installer
index.php              Public auction list
auction.php            Auction detail and bidding page
login.php              Login
register.php           Registration
my_bids.php            User bid history
```

## Version

Current package: IntraBid v1.1.0
