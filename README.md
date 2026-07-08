# IntraBids

IntraBids is a lightweight internal auction site built with PHP and MySQL/MariaDB.

It is designed for a small business environment where employees can register, bid on timed auctions, and selected trusted users can create and publish auctions directly. There is intentionally no committee/admin approval workflow. A global admin controls who is allowed to post auctions.

Current release: **IntraBids v1.5.2**

---

## Project links

Website:

```text
https://IntraBids.com
```

GitHub repository:

```text
https://github.com/flattery103/IntraBids
```

Latest release download:

```text
https://github.com/flattery103/IntraBids/releases/latest/download/IntraBids-latest.zip
```

---

## Timezone and bid timestamps

IntraBids stores and displays auction times using the **Application Timezone** setting under **Admin > Settings**.

Bid timestamps are written using the configured application timezone, and displayed timestamps include the timezone abbreviation.

Example:

```text
Jul 8, 2026 3:42 PM CDT
```

The server still enforces all auction start and end times. Browser countdowns are visual only.

---

## Included features

- User registration, login, and logout
- Global admin user management
- Admin-granted auction creator permission
- Category management by global admins and auction creators
- Auction drafts
- Scheduled auctions
- Active auctions
- Ended auctions
- Awarded auctions
- Cancelled auctions
- Multiple image uploads per auction
- Clickable portrait auction thumbnails with full-image contained display
- Larger image display on auction detail pages
- Left-side category menu with per-category auction counts
- Home page defaults to showing all available items
- Category pages show only items in the selected category
- Recently Ended section shows all ended/awarded auctions within a configurable day window
- Recently Ended defaults to 7 days
- Configurable site name
- Configurable site logo with constrained header logo sizing
- Current version displayed in the footer
- My Auctions page shows the winning bidder once an auction is over
- Timed auctions with start and end date/time
- Server-side bid validation
- Bid increment enforcement
- Optional anti-sniping extension
- Automatic winner selection
- SMTP-based email notifications using a configured SMTP account
- SMTP test page with direct configuration error feedback
- Winning bidder receives an email notification when they win
- Auction creator receives an email notification when their auction closes
- Admin dashboard
- Auction reporting/listing
- Audit logs
- Web-based install routine when `config/config.php` does not exist
- Cron-compatible auction closing script

---

## Requirements

- PHP 8.1 or newer
- MySQL 5.7+ or MariaDB 10.3+
- Apache or Nginx
- PHP file uploads enabled
- A writable `config/` directory during installation
- A writable `uploads/` directory for auction images and site logo uploads

Required/recommended PHP extensions:

- `pdo`
- `pdo_mysql`
- `fileinfo`
- `mbstring` recommended
- `openssl` required for STARTTLS/SSL SMTP

---

## Installation

1. Copy the IntraBids folder to your web server.

   Example:

   ```bash
   sudo cp -r intrabids /var/www/html/intrabids
   sudo chown -R www-data:www-data /var/www/html/intrabids/config /var/www/html/intrabids/uploads
   ```

2. Create a MySQL/MariaDB database and user.

   Example:

   ```sql
   CREATE DATABASE intrabids CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'intrabids'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
   GRANT ALL PRIVILEGES ON intrabids.* TO 'intrabids'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. Open the installer in a browser:

   ```text
   https://your-server.example.com/intrabids/install.php
   ```

4. Enter the database connection information.

5. Configure the basic site settings.

6. Create the first global admin account.

7. After installation, normal pages will use:

   ```text
   config/config.php
   ```

---

## Installer behavior

If `config/config.php` does not exist, IntraBids redirects normal pages to `install.php`.

The installer:

- Creates the configured database if the database user has permission
- Runs `database/schema.sql`
- Creates default categories
- Creates default settings
- Creates the first global admin account
- Writes `config/config.php`

To reinstall from scratch:

1. Remove `config/config.php`
2. Clear or recreate the database
3. Open `install.php` again

Do not commit your generated `config/config.php` file to a public repository.

---

## File permissions

The web server needs write access to:

```text
config/
uploads/
```

The `config/` directory is used to store the generated application configuration file.

The `uploads/` directory is used for:

- Auction images
- Site logo uploads

Example for Apache on Ubuntu/Debian:

```bash
sudo chown -R www-data:www-data /var/www/html/intrabids/config
sudo chown -R www-data:www-data /var/www/html/intrabids/uploads
```

Recommended permissions after installation:

```bash
sudo find /var/www/html/intrabids/config -type d -exec chmod 750 {} \;
sudo find /var/www/html/intrabids/uploads -type d -exec chmod 750 {} \;
sudo find /var/www/html/intrabids/uploads -type f -exec chmod 640 {} \;
sudo chmod 640 /var/www/html/intrabids/config/config.php
```

---

## SMTP notifications

IntraBids uses configurable SMTP settings for email notifications.

SMTP can be configured during installation or later from:

```text
Admin > Settings
```

SMTP settings include:

- Enable SMTP notifications
- From email
- From name
- SMTP host
- SMTP port
- Encryption
- SMTP username
- SMTP password
- SMTP test email recipient

Common port choices:

```text
587 = STARTTLS / TLS
465 = implicit SSL
25  = internal relay or no encryption
```

Typical SMTP settings:

```text
SMTP Enabled: Yes
SMTP Host: smtp.example.com
SMTP Port: 587
SMTP Encryption: TLS / STARTTLS
SMTP Username: your-email@example.com
SMTP Password: your SMTP password or app password
From Email: your-email@example.com
From Name: IntraBids
```

The SMTP test section on the settings page can be used to confirm email delivery.

Common SMTP test errors include:

- SMTP is not enabled
- SMTP host is missing
- From Email is missing
- From Email is invalid
- OpenSSL is unavailable for TLS/SSL
- Authentication failed
- Connection timed out
- STARTTLS failed

For Microsoft 365, Gmail, and many hosted mail services, you may need an app password or SMTP authentication enabled for the mailbox.

---

## Email notifications

IntraBids can send email notifications for important auction events.

Current notification behavior includes:

- Winning bidder receives an email when they win an auction
- Auction creator receives an email when their auction ends
- Auction creator email includes the winning bidder and winning bid when there is a winner
- Auction creator receives a no-winner message when an auction ends with no bids
- SMTP test email can be sent from Admin Settings

Email wording uses the configured site title from settings.

---

## Cron job for auction closing

IntraBids performs lightweight auction maintenance during normal page loads, but you should also add a cron job so auctions close on time even when nobody is browsing the site.

Run this every minute:

```bash
* * * * * /usr/bin/php /var/www/html/intrabids/cron/close_auctions.php >/dev/null 2>&1
```

Update the path to match your installation.

The cron script handles:

- Finding auctions that have reached their end time
- Closing ended auctions
- Recording the winning bid
- Recording the winning bidder
- Marking auctions as ended or awarded
- Sending winner notifications
- Sending auction creator notifications

---

## First steps after install

1. Log in with the global admin account.

2. Go to **Admin > Settings** and configure:

   - Site name
   - Optional site logo
   - Application timezone
   - SMTP notification settings
   - Allowed email domain, if desired
   - Default bid increment
   - Anti-sniping preference
   - Recently Ended Days

3. Go to **Categories** or **Admin > Categories** and adjust categories.

4. Go to **Admin > Users** and grant **Can Create Auctions** to trusted employees.

5. Create or publish the first auction.

---

## Roles

### User / Employee

Users can:

- Register and log in
- Browse active and scheduled auctions
- Browse auctions by category
- View auction details
- Place bids
- View their own bids

### Auction Creator

Auction creators can:

- Create and manage categories
- Create auctions
- Save auction drafts
- Publish auctions directly
- Edit their own auctions before bidding starts
- Add auction images
- View their own auctions
- View the winning bidder once one of their auctions is over
- Cancel their own auctions before they are ended/awarded

Auction creator access is granted by a global admin.

### Global Admin

Global admins can:

- Manage users
- Grant auction creator access
- Manage categories
- Change the site name
- Upload/remove the site logo
- Manage all auctions
- Close/cancel auctions
- Change settings
- Configure SMTP
- Send SMTP test emails
- View audit logs

---

## Auction flow

A typical auction works like this:

1. A user registers for an account.
2. A global admin grants auction creator access to trusted users.
3. An auction creator creates an auction.
4. The creator adds title, description, category, images, start time, end time, starting bid, and bid increment.
5. The auction is saved as a draft, scheduled, or made active depending on its start time.
6. Employees bid while the auction is active.
7. The server validates all bids.
8. The auction closes automatically after the end time.
9. The highest valid bid wins.
10. The winning bidder receives an email notification.
11. The auction creator receives an email notification with the result.

---

## Important business rules

- There is no auction approval process.
- A user with auction creator access is considered fully authorized to publish auctions.
- By default, auction creators cannot bid on their own auctions.
- By default, winners are visible publicly after the auction ends.
- Recently Ended defaults to 7 days and can be changed under **Admin > Settings**.
- Bids are permanent and cannot be deleted by normal users.
- The server enforces auction start/end times.
- The browser countdown is only visual.
- Auction creators can edit their own auctions before bidding starts.
- Global admins can manage all auctions.
- Bid corrections or removals should be handled by an admin if needed.

---

## Auction statuses

IntraBids uses the following auction statuses:

| Status | Description |
|---|---|
| Draft | Auction is being prepared and is not visible for bidding |
| Scheduled | Auction is published but the start time is in the future |
| Active | Auction is currently open for bidding |
| Ended | Auction time has expired |
| Awarded | Auction has ended and a winning bidder was recorded |
| Cancelled | Auction was cancelled |

---

## Bidding rules

IntraBids validates bids on the server.

Bid rules include:

- User must be logged in
- Auction must be active
- Current time must be within the auction start/end window
- Bid must be at least the starting bid if there are no existing bids
- Bid must be at least the current high bid plus the required bid increment
- Auction creators cannot bid on their own auctions by default
- Users cannot delete their own bids
- Highest valid bid wins
- If two bids are submitted at nearly the same time, the first valid bid recorded by the server wins

---

## Categories

Categories help organize auction items.

Categories are shown in a left-side menu with item counts.

Example:

```text
All Items
Electronics (3)
Furniture (0)
Office Equipment (2)
```

By default, the home page shows all current items. Clicking a category filters the list to that category.

Global admins and auction creators can manage categories.

---

## Recently Ended auctions

The Recently Ended section shows ended/awarded auctions within a configurable day window.

Default:

```text
7 days
```

This can be changed under:

```text
Admin > Settings > Recently Ended Days
```

The section is time-based instead of limited to a fixed number of auctions, so it can show more than a small fixed quantity when many auctions end around the same time.

---

## Site branding

Global admins can configure site branding under:

```text
Admin > Settings
```

Branding options include:

- Site title
- Site logo
- Remove uploaded logo

The uploaded logo is constrained in the header so large images do not take over the page layout.

---

## Security notes

This app includes basic security controls:

- Passwords are hashed using PHP `password_hash()`
- Database queries use PDO prepared statements
- Forms use CSRF tokens
- Admin pages use server-side permission checks
- Auction creator pages use server-side permission checks
- Uploaded images are validated by MIME type and size
- Sensitive directories include `.htaccess` deny rules for Apache
- Audit logs track key user/admin/auction events

Recommended production hardening:

- Serve the site only over HTTPS
- Limit access to the internal network or VPN if appropriate
- Use a dedicated database user for the application
- Use a dedicated SMTP account or internal SMTP relay for notifications
- Use SMTP app passwords where supported
- Back up the database regularly
- Back up uploaded auction images
- Keep PHP patched
- Keep MySQL/MariaDB patched
- Review file permissions after installation
- Do not commit `config/config.php` to a public repository
- Consider SSO/Active Directory integration in a future version

---

## Backups

At minimum, back up:

```text
Database
uploads/
config/config.php
```

The database contains users, auctions, bids, categories, settings, and audit logs.

The `uploads/` folder contains auction images and uploaded site logos.

The `config/config.php` file contains the application database connection settings.

---

## File layout

```text
admin/                  Global admin pages
assets/css/             Site CSS
assets/js/              Site JavaScript
config/                 Generated config.php lives here after install
creator/                Auction creator pages
cron/                   Scheduled maintenance scripts
database/schema.sql     Database schema
includes/               Shared PHP helpers, auth, CSRF, database functions
uploads/auctions/       Uploaded auction images
uploads/site/           Uploaded site logo
install.php             Web installer
index.php               Public auction list
auction.php             Auction detail and bidding page
login.php               Login
logout.php              Logout
register.php            Registration
my_bids.php             User bid history
```

---

## Development notes

IntraBids is intentionally built as a straightforward PHP/MySQL application for easier deployment on typical internal web servers.

Design goals:

- Simple install process
- Minimal external dependencies
- Easy PHP/MySQL hosting
- Clear role-based permissions
- Internal business auction workflow
- No unnecessary approval process
- Practical admin settings
- Simple future expansion

Potential future enhancements:

- Active Directory / SSO integration
- Department-based auction visibility
- Pickup confirmation workflow
- More reporting/export options
- Watchlist/favorites
- Additional notification options
- More detailed admin analytics

---

## License

- GPLv3

---

## Version

Current package: **IntraBids v1.5.2**
