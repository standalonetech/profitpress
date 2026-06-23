# ProfitPress — Real Profit Analytics for WooCommerce

Track the real profit of your WooCommerce store by capturing cost of goods (COGS) and snapshotting it onto historical orders.

[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## Overview

ProfitPress adds a Cost of Goods (COGS) field to your WooCommerce products and variations, then snapshots that cost onto each order line item at checkout. Because the cost is captured at the moment of sale, changing a supplier price later never rewrites your historical profit.

It then turns that data into real profit analytics: a dedicated Reports page with revenue, net profit, margin, and best/worst-selling products by date range, a dashboard summary widget, a CSV export, and per-order gateway fee and shipping cost accounting.

## Features

- **COGS data layer** — per-product and per-variation cost fields, plus a product list column.
- **Profit snapshotting** — COGS, gateway fees, and shipping costs are captured onto each order at the time of sale, so historical profit never changes when prices do.
- **Reports page** — revenue, net profit, and margin by date range, with best/worst-selling product performance.
- **Dashboard widget** — at-a-glance profit summary on the WordPress dashboard.
- **CSV export** — order-level profit data for offline analysis.
- **Gateway fee accounting** — model per-gateway payment processing fees.
- **Shipping cost model** — carrier estimate, customer-paid, or included, with per-zone estimates.
- **HPOS compatible** — works with WooCommerce High-Performance Order Storage.
- **Standalone admin menu** — a top-level ProfitPress menu with consolidated settings.

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 7.4+ |
| WordPress | 6.4+ |
| WooCommerce | 8.2+ |

## Installation

1. Upload the `profitpress` folder to the `/wp-content/plugins/` directory, or install it through the Plugins screen in WordPress.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Enter your product costs and configure gateway fees and shipping costs under the **ProfitPress** menu.

## Development

This plugin uses Composer for autoloading and tooling.

```bash
# Install dependencies
composer install

# Run the linter (PHP_CodeSniffer / WPCS)
composer lint

# Auto-fix lint issues
composer lint:fix

# Static analysis (PHPStan)
composer analyze

# Run the test suite (PHPUnit)
composer test
```

Source code is namespaced under `ProfitPress\` (PSR-4, `src/`).

## Architecture

| Area | Location |
|------|----------|
| Plugin bootstrap | `profitpress.php`, `src/Plugin.php` |
| COGS data layer | `src/COGS/` |
| Profit calculation & snapshotting | `src/Profit/` |
| Gateway fees | `src/Fees/` |
| Reporting & aggregation | `src/Reports/` |
| Dashboard widget | `src/Dashboard/` |
| CSV export | `src/Export/` |
| Settings & admin menu | `src/Settings/`, `src/Admin/` |
| HPOS compatibility | `src/Compatibility/` |

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) © StandaloneTech
