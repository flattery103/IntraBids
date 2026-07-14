# IntraBids automated tests

## Concurrent bid integration test

This test launches two PHP processes that submit the same bid to the same auction at nearly the same instant. It uses the production `place_auction_bid()` function and verifies that MySQL/MariaDB row locking accepts exactly one bid and leaves a consistent high-bid state.

Use a dedicated MySQL/MariaDB account that may create and drop a test database. As a safety control, the database name must contain `test`.

```bash
export INTRABIDS_TEST_DB_HOST=127.0.0.1
export INTRABIDS_TEST_DB_NAME=intrabids_concurrency_test
export INTRABIDS_TEST_DB_USER=intrabids_test
export INTRABIDS_TEST_DB_PASS='replace-this'
php tests/run_concurrent_bid_test.php
```

The test creates the database, runs five simultaneous-bid iterations, reports pass/fail results, and removes the test database when finished.
