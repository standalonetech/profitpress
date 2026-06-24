<?php
/**
 * WP admin dashboard widget for Profitly.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Dashboard;

use DateTimeImmutable;
use Profitly\Constants;
use Profitly\Reports\DateRangeFilter;
use Profitly\Reports\ProductPerformance;
use Profitly\Reports\ProfitAggregator;
use Profitly\Reports\ReportCache;
use Profitly\Reports\ReportsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a compact "last 7 days" profit widget to the WordPress dashboard.
 *
 * Its single responsibility is the dashboard surface: it registers one widget,
 * reads the same cached 7-day aggregation the reports page uses, and renders a
 * small, fast summary. It never recomputes profit logic itself — all numbers come
 * from {@see ProfitAggregator} / {@see ProductPerformance} via {@see ReportCache},
 * so a warm cache renders in well under the dashboard budget.
 */
final class DashboardWidget {

	/**
	 * The widget id.
	 */
	private const WIDGET_ID = 'profitly_dashboard_widget';

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget when allowed.
	 *
	 * @return void
	 */
	public function register_widget(): void {
		/**
		 * Toggle the Profitly dashboard widget. Pro can disable the free widget
		 * to substitute a richer one.
		 *
		 * @param bool $enabled Whether to register the widget.
		 */
		if ( ! apply_filters( 'profitly_dashboard_widget_enabled', true ) ) {
			return;
		}

		if ( ! current_user_can( Constants::CAP_VIEW_REPORTS ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Profitly: Last 7 Days', 'profitly' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render the widget contents.
	 *
	 * @return void
	 */
	public function render(): void {
		$range    = DateRangeFilter::get_range( '7d' );
		$currency = get_woocommerce_currency();

		$current  = self::cached_aggregation( $range['start'], $range['end'], 'agg' );
		$previous = self::cached_aggregation( $range['previous_start'], $range['previous_end'], 'prev' );

		$top_product = null;

		if ( $current['order_count'] > 0 ) {
			$top         = self::cached_top_product( $range['start'], $range['end'] );
			$top_product = $top[0] ?? null;
		}

		$deltas = array(
			'net_profit' => ReportsPage::calculate_delta( (string) $current['net_profit'], (string) $previous['net_profit'] ),
			'revenue'    => ReportsPage::calculate_delta( (string) $current['revenue'], (string) $previous['revenue'] ),
			'avg_margin' => ReportsPage::calculate_delta( (string) $current['avg_margin'], (string) $previous['avg_margin'] ),
		);

		require __DIR__ . '/Views/widget.php';
	}

	/**
	 * Fetch (and cache) the 7-day aggregation, sharing keys with the reports page.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @param string            $type  Cache namespace ('agg' or 'prev').
	 * @return array<string, mixed> Aggregation result.
	 */
	private static function cached_aggregation( DateTimeImmutable $start, DateTimeImmutable $end, string $type ): array {
		$key    = 'aggregation_' . $type . '_7d_' . $start->getTimestamp() . '_' . $end->getTimestamp();
		$cached = ReportCache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = ProfitAggregator::aggregate_for_range( $start, $end );
		ReportCache::set( $key, $data );

		return $data;
	}

	/**
	 * Fetch (and cache) the top product for the 7-day window.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array<int, array<string, mixed>> Top product rows (one needed).
	 */
	private static function cached_top_product( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$key    = 'products_top_7d_' . $start->getTimestamp() . '_' . $end->getTimestamp();
		$cached = ReportCache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = ProductPerformance::get_top_profitable( $start, $end );
		ReportCache::set( $key, $data );

		return $data;
	}
}
