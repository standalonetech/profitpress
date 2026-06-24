<?php
/**
 * SQL-level profit aggregation across a date range.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Profitly\COGS\COGSCalculator;
use Profitly\COGS\OrderLineCOGS;
use Profitly\Fees\GatewayFeeCalculator;
use Profitly\Settings\SettingsRegistry;
use Profitly\Shipping\ShippingCostResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates the profit breakdown for every order in a date range.
 *
 * Its single responsibility is the fast, store-wide roll-up that powers the
 * report cards and the dashboard widget. It works directly against the HPOS
 * order tables (never hydrating WC_Order objects) and is bounded by the date
 * range, so it stays fast even on stores with hundreds of thousands of lifetime
 * orders. SQL is used only for set-bounded SUMs over DECIMAL columns (which MySQL
 * totals exactly); every cross-term calculation — ratios, fee percentages, net
 * profit — is performed in PHP with bcmath via {@see COGSCalculator}, because
 * floating-point/percentage math inside MySQL is not money-safe.
 *
 * The per-order math mirrors {@see \Profitly\Profit\OrderProfitCalculator} so
 * the totals reconcile with the on-order metabox and the CSV export. The only
 * deliberate simplification is that refunds reduce COGS proportionally to
 * refunded revenue (rather than by exact refunded quantity), which keeps the
 * roll-up to a handful of set-bounded queries.
 */
final class ProfitAggregator {

	/**
	 * Aggregate the profit breakdown for a date range.
	 *
	 * @param DateTimeImmutable $start Window start (site timezone, inclusive).
	 * @param DateTimeImmutable $end   Window end (site timezone, inclusive).
	 * @return array{
	 *     revenue: string,
	 *     cogs: string,
	 *     gateway_fees: string,
	 *     shipping_cost: string,
	 *     refund_loss: string,
	 *     net_profit: string,
	 *     order_count: int,
	 *     avg_margin: string
	 * }
	 */
	public static function aggregate_for_range( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$orders = self::fetch_orders( $start, $end );

		if ( empty( $orders ) ) {
			return self::empty_result();
		}

		$order_ids = array_map( static fn( $row ) => (int) $row->id, $orders );

		$line_totals = self::fetch_line_totals( $order_ids );
		$refunds     = self::fetch_refund_totals( $order_ids );

		$revenue       = '0';
		$cogs          = '0';
		$gateway_fees  = '0';
		$shipping_cost = '0';
		$refund_loss   = '0';
		$net_profit    = '0';

		foreach ( $orders as $order ) {
			$id          = (int) $order->id;
			$order_total = wc_format_decimal( (string) $order->total_amount, 2 );
			$refunded    = isset( $refunds[ $id ] ) ? $refunds[ $id ] : '0';
			$gross_cogs  = isset( $line_totals[ $id ]['cogs'] ) ? $line_totals[ $id ]['cogs'] : '0';
			$subtotal    = isset( $line_totals[ $id ]['subtotal'] ) ? $line_totals[ $id ]['subtotal'] : '0';

			// Revenue retained after refunds.
			$net_revenue = COGSCalculator::subtract( $order_total, $refunded, 2 );

			// Refund ratio drives the proportional COGS reduction and the fee split.
			$refund_ratio = COGSCalculator::divide( $refunded, $order_total, 6 );
			$net_cogs     = COGSCalculator::subtract(
				$gross_cogs,
				COGSCalculator::multiply( $gross_cogs, $refund_ratio, 2 ),
				2
			);

			$full_fee     = self::order_gateway_fee( $order, $order_total, $subtotal );
			$order_refund = COGSCalculator::multiply( $full_fee, $refund_ratio, 2 );
			$order_fee    = COGSCalculator::subtract( $full_fee, $order_refund, 2 );

			$order_shipping = self::order_shipping_cost( $order );

			$order_net = COGSCalculator::subtract( $net_revenue, $net_cogs, 2 );
			$order_net = COGSCalculator::subtract( $order_net, $order_fee, 2 );
			$order_net = COGSCalculator::subtract( $order_net, $order_shipping, 2 );
			$order_net = COGSCalculator::subtract( $order_net, $order_refund, 2 );

			$revenue       = COGSCalculator::add( $revenue, $net_revenue, 2 );
			$cogs          = COGSCalculator::add( $cogs, $net_cogs, 2 );
			$gateway_fees  = COGSCalculator::add( $gateway_fees, $order_fee, 2 );
			$shipping_cost = COGSCalculator::add( $shipping_cost, $order_shipping, 2 );
			$refund_loss   = COGSCalculator::add( $refund_loss, $order_refund, 2 );
			$net_profit    = COGSCalculator::add( $net_profit, $order_net, 2 );
		}

		$avg_margin = '0';

		if ( 0.0 !== (float) $revenue ) {
			$avg_margin = COGSCalculator::multiply(
				COGSCalculator::divide( $net_profit, $revenue, 6 ),
				'100',
				2
			);
		}

		return array(
			'revenue'       => $revenue,
			'cogs'          => $cogs,
			'gateway_fees'  => $gateway_fees,
			'shipping_cost' => $shipping_cost,
			'refund_loss'   => $refund_loss,
			'net_profit'    => $net_profit,
			'order_count'   => count( $orders ),
			'avg_margin'    => $avg_margin,
		);
	}

	/**
	 * The order statuses that count toward reports.
	 *
	 * @return array<int, string> Status slugs (with the `wc-` prefix).
	 */
	public static function get_statuses(): array {
		$statuses = apply_filters(
			'profitly_report_order_statuses',
			array( 'wc-completed', 'wc-processing', 'wc-on-hold' )
		);

		return array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );
	}

	/**
	 * Fetch the in-range orders with their fee/shipping snapshot meta pivoted in.
	 *
	 * @param DateTimeImmutable $start Window start (site timezone).
	 * @param DateTimeImmutable $end   Window end (site timezone).
	 * @return array<int, object> Raw order rows.
	 */
	private static function fetch_orders( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		global $wpdb;

		$orders_table = self::orders_table();
		$meta_table   = self::orders_meta_table();
		$op_table     = self::operational_table();

		$statuses     = self::get_statuses();
		$status_place = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$params   = $statuses;
		$params[] = self::to_utc( $start );
		$params[] = self::to_utc( $end );

		// One query per range: the order row, the pivoted snapshot meta (fee +
		// shipping), and the operational shipping total used by some fee bases and
		// the customer-paid shipping model.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table/column names and meta keys are class constants, values are bound below.
		$sql = $wpdb->prepare(
			"SELECT o.id, o.total_amount, o.currency,
				MAX( CASE WHEN m.meta_key = '" . GatewayFeeCalculator::META_PERCENT . "' THEN m.meta_value END ) AS fee_percent,
				MAX( CASE WHEN m.meta_key = '" . GatewayFeeCalculator::META_FIXED . "' THEN m.meta_value END ) AS fee_fixed,
				MAX( CASE WHEN m.meta_key = '" . GatewayFeeCalculator::META_BASIS . "' THEN m.meta_value END ) AS fee_basis,
				MAX( CASE WHEN m.meta_key = '" . ShippingCostResolver::META_SNAPSHOT . "' THEN m.meta_value END ) AS ship_snapshot,
				MAX( CASE WHEN m.meta_key = '" . ShippingCostResolver::META_OVERRIDE . "' THEN m.meta_value END ) AS ship_override,
				MAX( CASE WHEN m.meta_key = '" . ShippingCostResolver::META_SNAPSHOT_MODEL . "' THEN m.meta_value END ) AS ship_model,
				od.shipping_total_amount AS shipping_total
			FROM {$orders_table} o
			LEFT JOIN {$meta_table} m ON m.order_id = o.id
				AND m.meta_key IN (
					'" . GatewayFeeCalculator::META_PERCENT . "',
					'" . GatewayFeeCalculator::META_FIXED . "',
					'" . GatewayFeeCalculator::META_BASIS . "',
					'" . ShippingCostResolver::META_SNAPSHOT . "',
					'" . ShippingCostResolver::META_OVERRIDE . "',
					'" . ShippingCostResolver::META_SNAPSHOT_MODEL . "'
				)
			LEFT JOIN {$op_table} od ON od.order_id = o.id
			WHERE o.type = 'shop_order'
				AND o.status IN ( {$status_place} )
				AND o.date_created_gmt >= %s
				AND o.date_created_gmt <= %s
			GROUP BY o.id, o.total_amount, o.currency, od.shipping_total_amount",
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built by $wpdb->prepare() above; only $wpdb-derived table names and class-constant meta keys are interpolated, all values are bound. Results cached by ReportCache.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sum the COGS snapshot and line subtotal per order, in one query.
	 *
	 * @param array<int, int> $order_ids Order ids in the range.
	 * @return array<int, array{cogs: string, subtotal: string}> Keyed by order id.
	 */
	private static function fetch_line_totals( array $order_ids ): array {
		global $wpdb;

		$items_table    = self::items_table();
		$itemmeta_table = self::itemmeta_table();

		$id_place = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names are derived from $wpdb, meta keys are class constants, ids bound below.
		$sql = $wpdb->prepare(
			"SELECT oi.order_id,
				SUM( CASE WHEN oim.meta_key = '" . OrderLineCOGS::META_TOTAL . "' THEN CAST( oim.meta_value AS DECIMAL(20,4) ) ELSE 0 END ) AS cogs,
				SUM( CASE WHEN oim.meta_key = '_line_subtotal' THEN CAST( oim.meta_value AS DECIMAL(20,4) ) ELSE 0 END ) AS subtotal
			FROM {$items_table} oi
			INNER JOIN {$itemmeta_table} oim ON oim.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item'
				AND oim.meta_key IN ( '" . OrderLineCOGS::META_TOTAL . "', '_line_subtotal' )
				AND oi.order_id IN ( {$id_place} )
			GROUP BY oi.order_id",
			$order_ids
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built by $wpdb->prepare() above; only $wpdb-derived table names and class-constant meta keys are interpolated, all values are bound. Results cached by ReportCache.

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->order_id ] = array(
				'cogs'     => wc_format_decimal( (string) $row->cogs, 2 ),
				'subtotal' => wc_format_decimal( (string) $row->subtotal, 2 ),
			);
		}

		return $out;
	}

	/**
	 * Sum the refunded amount per parent order.
	 *
	 * Refund rows store a negative total_amount; we return the positive magnitude.
	 *
	 * @param array<int, int> $order_ids Parent order ids in the range.
	 * @return array<int, string> Refunded amount keyed by parent order id.
	 */
	private static function fetch_refund_totals( array $order_ids ): array {
		global $wpdb;

		$orders_table = self::orders_table();
		$id_place     = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name derived from $wpdb, ids bound below.
		$sql = $wpdb->prepare(
			"SELECT parent_order_id, SUM( total_amount ) AS refunded
			FROM {$orders_table}
			WHERE type = 'shop_order_refund'
				AND parent_order_id IN ( {$id_place} )
			GROUP BY parent_order_id",
			$order_ids
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built by $wpdb->prepare() above; only $wpdb-derived table names and class-constant meta keys are interpolated, all values are bound. Results cached by ReportCache.

		$out = array();

		foreach ( (array) $rows as $row ) {
			// Refund totals are negative; flip the sign to a positive magnitude.
			$out[ (int) $row->parent_order_id ] = wc_format_decimal( (string) abs( (float) $row->refunded ), 2 );
		}

		return $out;
	}

	/**
	 * Compute an order's full gateway fee from its snapshot, mirroring
	 * {@see GatewayFeeCalculator}.
	 *
	 * @param object $order       The raw order row.
	 * @param string $order_total The order total as a decimal string.
	 * @param string $subtotal    The line subtotal as a decimal string.
	 * @return string The fee as a decimal string.
	 */
	private static function order_gateway_fee( $order, string $order_total, string $subtotal ): string {
		$percent = null === $order->fee_percent ? '' : (string) $order->fee_percent;
		$fixed   = null === $order->fee_fixed ? '' : (string) $order->fee_fixed;
		$basis   = null === $order->fee_basis ? '' : (string) $order->fee_basis;

		// Legacy order placed before a snapshot existed: no fee.
		if ( '' === $percent && '' === $fixed && '' === $basis ) {
			return '0';
		}

		$shipping     = wc_format_decimal( (string) $order->shipping_total, 2 );
		$basis_amount = self::basis_amount( '' === $basis ? 'total' : $basis, $order_total, $subtotal, $shipping );

		$percent_part = COGSCalculator::divide(
			COGSCalculator::multiply( $basis_amount, '' === $percent ? '0' : $percent, 6 ),
			'100',
			2
		);

		return COGSCalculator::add( $percent_part, '' === $fixed ? '0' : $fixed, 2 );
	}

	/**
	 * Resolve the monetary amount a percentage fee applies to.
	 *
	 * @param string $basis       One of 'total', 'subtotal', 'subtotal_shipping'.
	 * @param string $order_total Order total decimal string.
	 * @param string $subtotal    Line subtotal decimal string.
	 * @param string $shipping    Shipping total decimal string.
	 * @return string Basis amount decimal string.
	 */
	private static function basis_amount( string $basis, string $order_total, string $subtotal, string $shipping ): string {
		switch ( $basis ) {
			case 'subtotal':
				return $subtotal;

			case 'subtotal_shipping':
				return COGSCalculator::add( $subtotal, $shipping, 2 );

			case 'total':
			default:
				return $order_total;
		}
	}

	/**
	 * Compute an order's merchant shipping cost, mirroring {@see ShippingCostResolver}.
	 *
	 * @param object $order The raw order row.
	 * @return string Shipping cost decimal string.
	 */
	private static function order_shipping_cost( $order ): string {
		$override = null === $order->ship_override ? '' : (string) $order->ship_override;

		if ( '' !== $override && is_numeric( $override ) ) {
			return wc_format_decimal( $override, 2 );
		}

		$model = null === $order->ship_model ? '' : (string) $order->ship_model;

		// Legacy order placed before the model was snapshotted: use live settings.
		if ( '' === $model ) {
			$model = SettingsRegistry::get_shipping_cost_model();
		}

		switch ( $model ) {
			case 'customer_paid':
				return wc_format_decimal( (string) $order->shipping_total, 2 );

			case 'included':
				return '0';

			case 'carrier_estimate':
			default:
				$snapshot = null === $order->ship_snapshot ? '' : (string) $order->ship_snapshot;

				return '' !== $snapshot && is_numeric( $snapshot ) ? wc_format_decimal( $snapshot, 2 ) : '0';
		}
	}

	/**
	 * Convert a site-timezone datetime to a UTC `Y-m-d H:i:s` string for the
	 * `date_created_gmt` column.
	 *
	 * @param DateTimeImmutable $dt The datetime.
	 * @return string UTC datetime string.
	 */
	private static function to_utc( DateTimeImmutable $dt ): string {
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The zeroed result returned for an empty range.
	 *
	 * @return array{revenue: string, cogs: string, gateway_fees: string, shipping_cost: string, refund_loss: string, net_profit: string, order_count: int, avg_margin: string}
	 */
	private static function empty_result(): array {
		return array(
			'revenue'       => '0.00',
			'cogs'          => '0.00',
			'gateway_fees'  => '0.00',
			'shipping_cost' => '0.00',
			'refund_loss'   => '0.00',
			'net_profit'    => '0.00',
			'order_count'   => 0,
			'avg_margin'    => '0.00',
		);
	}

	/**
	 * The HPOS orders table name.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function orders_table(): string {
		global $wpdb;

		if ( is_callable( array( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore', 'get_orders_table_name' ) ) ) {
			return \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
		}

		return $wpdb->prefix . 'wc_orders';
	}

	/**
	 * The HPOS order meta table name.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function orders_meta_table(): string {
		global $wpdb;

		if ( is_callable( array( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore', 'get_meta_table_name' ) ) ) {
			return \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
		}

		return $wpdb->prefix . 'wc_orders_meta';
	}

	/**
	 * The HPOS operational data table name.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function operational_table(): string {
		global $wpdb;

		if ( is_callable( array( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore', 'get_operational_data_table_name' ) ) ) {
			return \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_operational_data_table_name();
		}

		return $wpdb->prefix . 'wc_order_operational_data';
	}

	/**
	 * The order items table name.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function items_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'woocommerce_order_items';
	}

	/**
	 * The order item meta table name.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function itemmeta_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'woocommerce_order_itemmeta';
	}
}
