<?php
/**
 * Per-product profit performance queries.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Profitly\COGS\COGSCalculator;
use Profitly\COGS\OrderLineCOGS;

defined( 'ABSPATH' ) || exit;

/**
 * Ranks products by profitability over a date range.
 *
 * Its single responsibility is the top/bottom product tables: it aggregates
 * units, revenue, and COGS per product in one set-bounded SQL query and ranks
 * the result. Variations roll up to their parent product (the line item's
 * `_product_id` is always the parent), so merchants see product-level figures
 * rather than dozens of variation rows. Deleted products are excluded by an inner
 * join to `wp_posts`.
 *
 * Product "net profit" here is intentionally revenue minus COGS only — gateway
 * fees and shipping are order-level costs and allocating them per product would
 * mislead. The report templates carry a footnote saying so.
 */
final class ProductPerformance {

	/**
	 * The 10 most profitable products in the range, most profitable first.
	 *
	 * @param DateTimeImmutable $start Window start (site timezone).
	 * @param DateTimeImmutable $end   Window end (site timezone).
	 * @param int               $limit Maximum rows.
	 * @return array<int, array{product_id: int, product_name: string, units_sold: int, revenue: string, cogs: string, net_profit: string, margin: string}>
	 */
	public static function get_top_profitable( DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 10 ): array {
		return self::query( $start, $end, $limit, false );
	}

	/**
	 * The 10 worst loss-making products in the range, worst first.
	 *
	 * Only products whose net profit is negative are returned.
	 *
	 * @param DateTimeImmutable $start Window start (site timezone).
	 * @param DateTimeImmutable $end   Window end (site timezone).
	 * @param int               $limit Maximum rows.
	 * @return array<int, array{product_id: int, product_name: string, units_sold: int, revenue: string, cogs: string, net_profit: string, margin: string}>
	 */
	public static function get_loss_making( DateTimeImmutable $start, DateTimeImmutable $end, int $limit = 10 ): array {
		return self::query( $start, $end, $limit, true );
	}

	/**
	 * Run the per-product aggregation.
	 *
	 * @param DateTimeImmutable $start        Window start (site timezone).
	 * @param DateTimeImmutable $end          Window end (site timezone).
	 * @param int               $limit        Maximum rows.
	 * @param bool              $loss_making  When true, restrict to negative net profit, worst first.
	 * @return array<int, array{product_id: int, product_name: string, units_sold: int, revenue: string, cogs: string, net_profit: string, margin: string}>
	 */
	private static function query( DateTimeImmutable $start, DateTimeImmutable $end, int $limit, bool $loss_making ): array {
		global $wpdb;

		$orders_table   = ProfitAggregator::orders_table();
		$items_table    = ProfitAggregator::items_table();
		$itemmeta_table = ProfitAggregator::itemmeta_table();
		$posts_table    = $wpdb->posts;

		$statuses     = ProfitAggregator::get_statuses();
		$status_place = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$having = $loss_making ? 'HAVING net_profit < 0' : '';
		$order  = $loss_making ? 'ASC' : 'DESC';
		$limit  = max( 1, $limit );

		$params   = $statuses;
		$params[] = self::to_utc( $start );
		$params[] = self::to_utc( $end );
		$params[] = $limit;

		// One query: join the line item to its product id, sum quantity / revenue
		// (_line_total) / COGS snapshot, grouped by the PARENT product id so
		// variations roll up. The inner join to wp_posts drops deleted products.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names derived from $wpdb, meta keys are class constants, values bound below.
		$sql = $wpdb->prepare(
			"SELECT pid.meta_value AS product_id,
				p.post_title AS product_name,
				SUM( CASE WHEN oim.meta_key = '_qty' THEN CAST( oim.meta_value AS DECIMAL(20,4) ) ELSE 0 END ) AS units_sold,
				SUM( CASE WHEN oim.meta_key = '_line_total' THEN CAST( oim.meta_value AS DECIMAL(20,4) ) ELSE 0 END ) AS revenue,
				SUM( CASE WHEN oim.meta_key = '" . OrderLineCOGS::META_TOTAL . "' THEN CAST( oim.meta_value AS DECIMAL(20,4) ) ELSE 0 END ) AS cogs,
				(
					SUM( CASE WHEN oim.meta_key = '_line_total' THEN CAST( oim.meta_value AS DECIMAL(20,4) ) ELSE 0 END )
					- SUM( CASE WHEN oim.meta_key = '" . OrderLineCOGS::META_TOTAL . "' THEN CAST( oim.meta_value AS DECIMAL(20,4) ) ELSE 0 END )
				) AS net_profit
			FROM {$items_table} oi
			INNER JOIN {$orders_table} o ON o.id = oi.order_id
				AND o.type = 'shop_order'
				AND o.status IN ( {$status_place} )
				AND o.date_created_gmt >= %s
				AND o.date_created_gmt <= %s
			INNER JOIN {$itemmeta_table} pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
			INNER JOIN {$itemmeta_table} oim ON oim.order_item_id = oi.order_item_id
				AND oim.meta_key IN ( '_qty', '_line_total', '" . OrderLineCOGS::META_TOTAL . "' )
			INNER JOIN {$posts_table} p ON p.ID = pid.meta_value AND p.post_type = 'product'
			WHERE oi.order_item_type = 'line_item'
				AND pid.meta_value > 0
			GROUP BY pid.meta_value, p.post_title
			{$having}
			ORDER BY net_profit {$order}
			LIMIT %d",
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built by $wpdb->prepare() above; only $wpdb-derived table names, class-constant meta keys, and fixed ASC/DESC/HAVING literals are interpolated. Results cached by ReportCache.

		return self::format_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Turn raw rows into the typed, money-safe output shape.
	 *
	 * @param array<int, object> $rows Raw aggregation rows.
	 * @return array<int, array{product_id: int, product_name: string, units_sold: int, revenue: string, cogs: string, net_profit: string, margin: string}>
	 */
	private static function format_rows( array $rows ): array {
		$out = array();

		foreach ( $rows as $row ) {
			$revenue    = wc_format_decimal( (string) $row->revenue, 2 );
			$cogs       = wc_format_decimal( (string) $row->cogs, 2 );
			$net_profit = COGSCalculator::subtract( $revenue, $cogs, 2 );

			$margin = '0';

			if ( 0.0 !== (float) $revenue ) {
				$margin = COGSCalculator::multiply(
					COGSCalculator::divide( $net_profit, $revenue, 6 ),
					'100',
					2
				);
			}

			$out[] = array(
				'product_id'   => (int) $row->product_id,
				'product_name' => (string) $row->product_name,
				'units_sold'   => (int) round( (float) $row->units_sold ),
				'revenue'      => $revenue,
				'cogs'         => $cogs,
				'net_profit'   => $net_profit,
				'margin'       => $margin,
			);
		}

		return $out;
	}

	/**
	 * Convert a site-timezone datetime to a UTC `Y-m-d H:i:s` string.
	 *
	 * @param DateTimeImmutable $dt The datetime.
	 * @return string UTC datetime string.
	 */
	private static function to_utc( DateTimeImmutable $dt ): string {
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
