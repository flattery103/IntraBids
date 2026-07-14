# Changelog

## v1.6.1
- Fixed category drag-and-drop so rows follow mouse and touch movement reliably across supported browsers.
- Kept automatic category-order saving and keyboard arrow reordering.
- Moved the Home Page Alert Banner enable checkbox directly above its banner text field.

## v1.6.0
- Added drag-and-drop category ordering with automatic server-side saving.
- Removed manual category sort-number entry from the category management page.
- Added safe deletion for categories that are not used by any auction.
- Added an optional administrator-controlled alert banner on the home page.
- Updated README branding and documentation to use the IntraBids name.
- Added upgrade defaults for the new home page alert settings.

## v1.5.2
- Improved SMTP test failures so missing or disabled SMTP settings show a specific error instead of only a generic log-check message.
- Added auction creator email notification when an auction closes, including the winning bidder and winning bid when there is a winner.
- Updated winner notification wording to use the configured Site Name instead of hardcoded “IntraBid”.
- No database schema changes are required for this release.

## v1.5.1
- Moved the over-auction winning bidder display from My Bids to My Auctions.
- Removed the Winning Bidder column from My Bids.
- No database schema changes are required for this release.

## v1.5.0
- Added configurable Recently Ended window under Admin > Settings, defaulting to 7 days.
- Recently Ended now shows all ended/awarded auctions inside the configured day window instead of limiting to 8 items.
- Moved Create Auction and Manage Categories buttons above the category list in the left sidebar.
- Added initial winner display work; corrected in v1.5.1 to show the winner on My Auctions.

## v1.4.1
- Added a versioned cache-buster to the stylesheet URL so browser/server caches do not keep showing old layout CSS after upgrades.
- Added inline safeguards to constrain oversized header logos even if old CSS is cached.
- Changed auction card images from square boxes to portrait-oriented contained image frames.
- Reworked the home page so the category filter is clearly rendered as a left-side menu beside the auction list.
- Added stronger CSS selectors for logo, portrait thumbnails, category sidebar, and auction detail images.
- No database schema changes are required for this release.

## v1.4.0
- Constrained header logo sizing so oversized uploaded logos cannot take over the page header.
- Changed auction cards to use square thumbnail image areas with contained image scaling.
- Moved category navigation into a left-side menu with counts for each category.
- Added the current IntraBid version to the footer.
- No database schema changes are required for this release.

## v1.3.0
- Added `upgrade.php` so database upgrades can use the existing `config/config.php` database credentials.
- Added automatic migration tracking with the `schema_migrations` table.
- Added Application Timezone to Admin > Settings.
- Fixed new bid timestamps to use the configured application timezone instead of relying on the MySQL server timezone.
- Synchronized the MySQL connection timezone with the configured application timezone when possible.
- Updated displayed dates/times to include the timezone abbreviation.

## v1.2.0

- Made auction card images clickable so they open the auction item.
- Changed auction card and auction detail images to use contained image sizing so the full image remains visible.
- Added category navigation on the homepage with counts, including categories with zero current auctions.
- Added category filtering so clicking a category shows only auctions in that category.
- Allowed auction creators, not only global admins, to create and manage categories.
- Updated active/scheduled auction listing logic to use start/end times so stale scheduled status values do not display active auctions under Scheduled.
- Added configurable site branding with site title display and global admin logo upload/removal.
- Added an upgrade SQL script for existing v1.1.0 installations.

## v1.1.0

- Replaced PHP `mail()` notifications with a configurable SMTP sender.
- Added SMTP settings to the installer and Admin > Settings.
- Added support for STARTTLS/TLS, implicit SSL, and unencrypted internal SMTP relay modes.
- Added an upgrade SQL script for existing v1.0.0 installations.
- SMTP notifications are skipped when SMTP is disabled or incomplete; there is no PHP `mail()` fallback.

## v1.0.0

- Initial IntraBid PHP/MySQL internal auction site.
- Added installer, schema, registration, login, admin users/categories/settings, auction creator workflow, bidding, image uploads, audit logs, and cron-based auction closing.
- No committee/admin approval workflow by design.
