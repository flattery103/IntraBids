<?php
// Run from cron every minute:
// * * * * * /usr/bin/php /path/to/intrabid/cron/close_auctions.php >/dev/null 2>&1
require dirname(__DIR__) . '/includes/bootstrap.php';
run_auction_maintenance();
echo '[' . date('c') . "] IntraBid auction maintenance complete.\n";
