# Tests

PHPUnit test suite for ProfitPress.

## Running

```bash
composer install   # ensure dev dependencies (PHPUnit) are present
composer test      # or: vendor/bin/phpunit
```

## Layout

```
tests/
├── bootstrap.php          # defines ABSPATH, then loads the Composer autoloader
└── Unit/                  # pure PHP tests — no WordPress/WooCommerce runtime
    └── COGS/
        └── COGSCalculatorTest.php
```

Test classes are namespaced `ProfitPress\Tests\` (PSR-4 → `tests/`) and named
`*Test.php`. The `unit` test suite (see `phpunit.xml.dist`) runs everything under
`tests/Unit/`.

## What lives where

- **`Unit/`** — stateless, WordPress-free classes such as `COGSCalculator`. These
  run without loading WordPress and are the easiest, fastest things to cover. Start
  here.
- **Integration tests** (not yet set up) — classes that call WordPress/WooCommerce
  functions (`OrderSnapshot`, `ShippingCostResolver`, `ProfitAggregator`, etc.) need
  either a WordPress test harness (`wp-phpunit` + a test database) or a mocking layer
  such as Brain Monkey. Add the harness to `bootstrap.php` and a second test suite
  before writing these.

## Note on ABSPATH

Every `src/` class begins with `defined( 'ABSPATH' ) || exit;`. `bootstrap.php`
defines `ABSPATH` **before** requiring the autoloader so that loading a class under
test does not terminate the run.
