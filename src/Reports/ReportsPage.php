<?php
/**
 * Profitly reports admin page.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Reports;

use Profitly\COGS\COGSCalculator;
use Profitly\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Reports" page under the top-level Profitly menu.
 *
 * Its single responsibility is the report screen's orchestration: it resolves
 * the requested date range, pulls (cached) aggregations and product rankings,
 * and hands them to the plain-PHP view
 * templates. Menu registration lives in {@see Menu}, which calls {@see render()}.
 * All heavy computation lives in {@see ProfitAggregator} and
 * {@see ProductPerformance}; results are cached via {@see ReportCache}.
 */
final class ReportsPage {

	/**
	 * Render the reports page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Constants::CAP_VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to view Profitly reports.', 'profitly' ) );
		}

		$range    = DateRangeFilter::get_current_range();
		$currency = get_woocommerce_currency();

		$aggregation          = self::cached_aggregation( $range['key'], $range['start'], $range['end'], 'agg' );
		$previous_aggregation = self::cached_aggregation( $range['key'], $range['previous_start'], $range['previous_end'], 'prev' );

		/** This filter is documented in src/Reports/ReportsPage.php */
		$aggregation = apply_filters( 'profitly_report_aggregation_data', $aggregation, $range );

		$has_data = $aggregation['order_count'] > 0;

		$top_products  = array();
		$loss_products = array();

		if ( $has_data ) {
			$top_products  = self::cached_products( $range['key'], $range['start'], $range['end'], 'top' );
			$loss_products = self::cached_products( $range['key'], $range['start'], $range['end'], 'loss' );
		}

		// Variables consumed by the included templates.
		$cards = self::build_cards( $aggregation, $previous_aggregation, $range, $currency );

		require self::view( 'reports-page.php' );
	}

	/**
	 * Fetch (and cache) a range aggregation.
	 *
	 * @param string             $range_key The range key, for the cache key.
	 * @param \DateTimeImmutable $start     Window start.
	 * @param \DateTimeImmutable $end       Window end.
	 * @param string             $type      Cache namespace ('agg' or 'prev').
	 * @return array<string, mixed> The aggregation.
	 */
	private static function cached_aggregation( string $range_key, \DateTimeImmutable $start, \DateTimeImmutable $end, string $type ): array {
		$key    = 'aggregation_' . $type . '_' . $range_key . '_' . $start->getTimestamp() . '_' . $end->getTimestamp();
		$cached = ReportCache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = ProfitAggregator::aggregate_for_range( $start, $end );
		ReportCache::set( $key, $data );

		return $data;
	}

	/**
	 * Fetch (and cache) a product ranking.
	 *
	 * @param string             $range_key The range key, for the cache key.
	 * @param \DateTimeImmutable $start     Window start.
	 * @param \DateTimeImmutable $end       Window end.
	 * @param string             $type      'top' or 'loss'.
	 * @return array<int, array<string, mixed>> The ranking rows.
	 */
	private static function cached_products( string $range_key, \DateTimeImmutable $start, \DateTimeImmutable $end, string $type ): array {
		$key    = 'products_' . $type . '_' . $range_key . '_' . $start->getTimestamp() . '_' . $end->getTimestamp();
		$cached = ReportCache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = 'loss' === $type
			? ProductPerformance::get_loss_making( $start, $end )
			: ProductPerformance::get_top_profitable( $start, $end );

		ReportCache::set( $key, $data );

		return $data;
	}

	/**
	 * Build the summary card definitions, including comparison deltas.
	 *
	 * @param array<string, mixed> $current  Current-period aggregation.
	 * @param array<string, mixed> $previous Previous-period aggregation.
	 * @param array<string, mixed> $range    Resolved range descriptor.
	 * @param string               $currency Store currency code.
	 * @return array<int, array<string, mixed>> Card definitions.
	 */
	private static function build_cards( array $current, array $previous, array $range, string $currency ): array {
		$cards = array(
			array(
				'key'      => 'revenue',
				'label'    => __( 'Total Revenue', 'profitly' ),
				'value'    => wc_price( (float) $current['revenue'], array( 'currency' => $currency ) ),
				'delta'    => self::calculate_delta( (string) $current['revenue'], (string) $previous['revenue'] ),
				'is_money' => true,
			),
			array(
				'key'      => 'net_profit',
				'label'    => __( 'Total Net Profit', 'profitly' ),
				'value'    => wc_price( (float) $current['net_profit'], array( 'currency' => $currency ) ),
				'delta'    => self::calculate_delta( (string) $current['net_profit'], (string) $previous['net_profit'] ),
				'is_money' => true,
			),
			array(
				'key'      => 'order_count',
				'label'    => __( 'Total Orders', 'profitly' ),
				'value'    => number_format_i18n( (int) $current['order_count'] ),
				'delta'    => self::calculate_delta( (string) (int) $current['order_count'], (string) (int) $previous['order_count'] ),
				'is_money' => false,
			),
			array(
				'key'      => 'avg_margin',
				'label'    => __( 'Average Margin %', 'profitly' ),
				'value'    => esc_html( $current['avg_margin'] ) . '%',
				'delta'    => self::calculate_delta( (string) $current['avg_margin'], (string) $previous['avg_margin'] ),
				'is_money' => false,
			),
		);

		// Stamp the shared period labels onto every card.
		foreach ( $cards as &$card ) {
			$card['period_label']   = $range['label'];
			$card['previous_label'] = $range['previous_label'];
		}
		unset( $card );

		/**
		 * Filter the summary cards shown on the report page.
		 *
		 * @param array<int, array<string, mixed>> $cards    The card definitions.
		 * @param array<string, mixed>             $current  Current aggregation.
		 * @param array<string, mixed>             $previous Previous aggregation.
		 */
		return apply_filters( 'profitly_report_summary_cards', $cards, $current, $previous );
	}

	/**
	 * Compute a percentage delta between two periods, divide-by-zero safe.
	 *
	 * @param string $current  Current-period value as a decimal string.
	 * @param string $previous Previous-period value as a decimal string.
	 * @return array{percent: string, direction: string, has_previous: bool} Delta descriptor.
	 */
	public static function calculate_delta( string $current, string $previous ): array {
		$has_previous = 0.0 !== (float) $previous;

		if ( ! $has_previous ) {
			// No baseline to compare against: report "new" growth when there is now
			// a value, otherwise a flat zero. Never divide by zero.
			$direction = 0.0 !== (float) $current ? 'up' : 'neutral';

			return array(
				'percent'      => '0.0',
				'direction'    => $direction,
				'has_previous' => false,
			);
		}

		$change  = COGSCalculator::subtract( $current, $previous, 4 );
		$percent = COGSCalculator::multiply( COGSCalculator::divide( $change, $previous, 6 ), '100', 1 );

		$direction = 'neutral';

		if ( (float) $percent > 0 ) {
			$direction = 'up';
		} elseif ( (float) $percent < 0 ) {
			$direction = 'down';
		}

		return array(
			'percent'      => $percent,
			'direction'    => $direction,
			'has_previous' => true,
		);
	}

	/**
	 * Render a delta indicator as escaped HTML.
	 *
	 * @param array{percent: string, direction: string, has_previous: bool} $delta          Delta descriptor.
	 * @param string                                                        $previous_label The "vs previous ..." label.
	 * @return string HTML markup.
	 */
	public static function render_delta( array $delta, string $previous_label ): string {
		$class_map = array(
			'up'      => 'profitly-delta-up',
			'down'    => 'profitly-delta-down',
			'neutral' => 'profitly-delta-neutral',
		);

		$class = $class_map[ $delta['direction'] ] ?? 'profitly-delta-neutral';

		if ( ! $delta['has_previous'] ) {
			$text = 'up' === $delta['direction']
				? __( 'New', 'profitly' )
				: __( 'No prior data', 'profitly' );
		} else {
			$arrow = 'up' === $delta['direction'] ? '▲' : ( 'down' === $delta['direction'] ? '▼' : '' );
			$sign  = (float) $delta['percent'] > 0 ? '+' : '';
			$text  = trim( $arrow . ' ' . $sign . $delta['percent'] . '%' );
		}

		return sprintf(
			'<span class="profitly-delta %1$s">%2$s</span> <span class="profitly-delta-label">%3$s</span>',
			esc_attr( $class ),
			esc_html( $text ),
			esc_html( $previous_label )
		);
	}

	/**
	 * Resolve the absolute path to a view template.
	 *
	 * @param string $file Template filename.
	 * @return string Absolute path.
	 */
	private static function view( string $file ): string {
		return __DIR__ . '/Views/' . $file;
	}

	/**
	 * Public accessor for the views directory, used by templates to include partials.
	 *
	 * @param string $file Template filename.
	 * @return string Absolute path.
	 */
	public static function view_path( string $file ): string {
		return self::view( $file );
	}
}
