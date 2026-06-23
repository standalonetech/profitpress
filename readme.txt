=== ProfitPress — Real Profit Analytics for WooCommerce ===
Contributors: profitpress
Tags: woocommerce, profit, cogs, analytics, cost of goods
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 8.2
WC tested up to: 9.5
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track the real profit of your WooCommerce store by capturing cost of goods (COGS) and snapshotting it onto historical orders.

== Description ==

ProfitPress adds a Cost of Goods (COGS) field to your WooCommerce products and variations, then snapshots that cost onto each order line item at checkout. Because the cost is captured at the moment of sale, changing a supplier price later never rewrites your historical profit.

It then turns that data into real profit analytics: a dedicated Reports page with revenue, net profit, margin, and best/worst-selling products by date range, a dashboard summary widget, a CSV export, and per-order gateway fee and shipping cost accounting.

== Installation ==

1. Upload the `profitpress` folder to the `/wp-content/plugins/` directory, or install it through the Plugins screen in WordPress.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Enter your product costs and configure gateway fees and shipping costs under the ProfitPress menu.

== Changelog ==

= 1.0.0 =
* New top-level ProfitPress admin menu with Reports and Settings pages.
* Profit reporting: revenue, net profit, margin, and product performance by date range.
* Dashboard summary widget and order-level profit CSV export.
* Standalone settings: gateway fees, shipping cost model and per-zone estimates, and data retention.

= 0.1.0 =
* Initial scaffold: plugin bootstrap, HPOS compatibility, and the COGS data layer.

== Frequently Asked Questions ==

= Does changing a product's COGS affect past orders? =

No. The COGS is snapshotted onto each order line item at the time of sale.
