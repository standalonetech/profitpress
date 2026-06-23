# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

ProfitPress is a WooCommerce extension that tracks the *real* profit of a store by
capturing cost of goods (COGS), gateway fees, and shipping costs and **snapshotting**
them onto historical orders. Requires WordPress 6.4+, PHP 7.4+, WooCommerce 8.2+.

## Commands

All tooling runs through Composer (defined in `composer.json` scripts):

```bash
composer install      # install dev deps + build the PSR-4 autoloader
composer lint         # phpcs — WordPress-Extra + WordPress-Docs (config: phpcs.xml.dist)
composer lint:fix     # phpcbf — auto-fix lint violations
composer analyze      # phpstan analyse — level 6, src/ only (config: phpstan.neon.dist)
composer test         # phpunit
```

Tests live in `tests/` (PSR-4 maps `ProfitPress\Tests\` → `tests/`), configured by
`phpunit.xml.dist` with `tests/bootstrap.php`. The bootstrap **defines `ABSPATH` before
loading the autoloader** — every `src/` class guards itself with `defined( 'ABSPATH' ) || exit;`,
so without this the file under test would terminate the run. The suite currently covers the
pure, WordPress-free units (`tests/Unit/`, starting with `COGSCalculator`); WP/WooCommerce-coupled
classes would need a WP test harness (e.g. wp-phpunit / Brain Monkey) that is not yet set up.
`composer test` runs PHPUnit; PHPStan scans `src/` only with WooCommerce stubs. After any code
change, run `composer lint && composer analyze && composer test` before considering the work done;
the WordPress Coding Standards are strict (escaping, i18n text domain `profitpress`, prefixing) and
enforced for WordPress.org publication. `phpcs.xml.dist` relaxes a few sniffs under `/tests/*`
(StudlyCase filenames, mandatory docblocks, `WP_Filesystem`) since those are runtime-code rules
that don't fit test infrastructure.

There is no JS/CSS build step — `assets/css` and `assets/js` ship as-is.

## Architecture

### Bootstrap
`profitpress.php` (the WP plugin header file) defines `PROFITPRESS_*` constants, loads
the Composer autoloader, and on `plugins_loaded` (priority 20) bails with an admin
notice if WooCommerce is absent, else calls `Plugin::instance()`. `src/Plugin.php` is a
singleton whose **only** job is bootstrapping: it instantiates each feature class and
calls its `register_hooks()`. Every feature class follows this `register_hooks()`
convention — there is no logic in `Plugin`; add new features by instantiating them in
`boot()`.

### The central idea: snapshotting
Profit numbers must never change retroactively when prices/settings change. So values
are frozen onto orders **at creation time** and read back later:

- `COGS/OrderLineCOGS` — snapshots per-unit & line-total COGS onto each line item across
  *all* order paths (classic checkout, Blocks, admin, REST, programmatic) via multiple
  hooks plus a `woocommerce_before_order_object_save` backfill safety net.
- `Profit/OrderSnapshot` — at order creation, freezes the chosen gateway's fee config and
  the destination zone's shipping estimate + shipping model onto order meta. The write is
  **idempotent**: an order already carrying a snapshot is left untouched.

All order access uses the WooCommerce CRUD API (`$order->get_meta()` /
`update_meta_data()`), never direct post meta — this is what makes the plugin **HPOS-safe**
(`Compatibility/HPOS` declares compatibility). Meta keys are prefixed `_profitpress_*` and
defined as class constants (e.g. `OrderLineCOGS::META_UNIT`, `ShippingCostResolver::META_SNAPSHOT`).

### Money math
`COGS/COGSCalculator` is stateless, WordPress-free, decimal-**string** arithmetic using
bcmath when available (falls back to float). Never do profit math with native float
operators — route it through this class so rounding stays correct. `Profit/OrderProfitCalculator`
assembles the full per-order breakdown (revenue, cogs, gateway_fee, shipping_cost,
refund_loss, net_profit, margin). Note its refund model: the full gateway fee is split into
`gateway_fee` (on retained revenue) + `refund_loss` (on refunded revenue) so the fee is
charged exactly once.

### Settings — one option, one registry
There is exactly **one** stored option, `profitpress_settings` (`Constants::OPTION`), holding
all config. `Settings/SettingsRegistry` is its single source of truth: it owns the default
shape (`get_defaults()`), registers the option, and exposes **typed read accessors**
(`get_gateway_fee()`, `get_shipping_cost()`, `get_shipping_cost_model()`). Every consumer
(`OrderSnapshot`, `ShippingCostResolver`, `ProfitAggregator`) reads through these accessors —
never poke the raw option array. The settings UI is split into tabs implementing
`Settings/Tabs/TabInterface` (GatewayFees, ShippingCosts, General), registered in
`SettingsRegistry::TAB_CLASSES`. `Settings/SettingsHandler` processes the admin-post form
submission (nonce + capability + sanitize); `Settings/SettingsPage` is UI-only chrome.

### Admin menu & routing
`Admin/Menu` is the single source of truth for navigation: it registers the top-level
"ProfitPress" menu (Reports as the default page, Settings as a sub-page) and exposes the
canonical URL helpers `Menu::reports_url()` / `Menu::settings_url($tab)`. Never hand-build
these admin URLs elsewhere. (The Reports submenu is deliberately re-registered against the
parent slug with an *empty* callback to avoid rendering the report twice.)

### Reporting
`Reports/ProfitAggregator` and `Reports/ProductPerformance` run aggregate DB queries (with
`$wpdb->prepare`; intentional `phpcs:ignore WordPress.DB.DirectDatabaseQuery` suppressions are
present and deliberate). `Reports/ReportCache` caches results; `Reports/DateRangeFilter` handles
range selection; `Reports/ReportsPage` + `Reports/Views/*` render. `Dashboard/DashboardWidget`
and `Export/CsvExporter` are sibling consumers of the same aggregation layer.

### Capabilities
Two caps gate everything (`Constants`): `view_woocommerce_reports` for read-only screens
(`CAP_VIEW_REPORTS`), `manage_woocommerce` for write/settings screens (`CAP_MANAGE`).

## Directory map

| Area | Location |
|------|----------|
| Bootstrap | `profitpress.php`, `src/Plugin.php`, `src/Constants.php`, `src/Activator.php`, `src/Deactivator.php` |
| COGS data layer + money math | `src/COGS/` |
| Profit snapshotting & calculation | `src/Profit/` |
| Gateway fees | `src/Fees/` |
| Shipping cost resolution | `src/Shipping/` |
| Reporting & aggregation | `src/Reports/` (+ `Views/`) |
| Dashboard widget | `src/Dashboard/` |
| CSV export | `src/Export/` |
| Settings | `src/Settings/`, `src/Settings/Tabs/` |
| Admin menu, product fields, meta boxes | `src/Admin/` |
| HPOS compatibility | `src/Compatibility/` |

## Releasing

WordPress.org ships via SVN and installs the plugin **without** running Composer, so the
shipped package **must include `vendor/`** (the autoloader) but exclude dev tooling. `vendor/`
is git-ignored but intentionally NOT in `.distignore`. Build with
`composer install --no-dev --optimize-autoloader` then `wp dist-archive`. The version must be
bumped identically in **three** places: `profitpress.php` (`Version:` header and
`PROFITPRESS_VERSION`), `readme.txt` (`Stable tag:`), and `SettingsRegistry::VERSION`. See
`RELEASING.md` for the full SVN procedure.
