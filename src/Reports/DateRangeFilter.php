<?php
/**
 * Date-range selection for the reports layer.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Reports;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the report date range from the request and derives its comparison window.
 *
 * Its single responsibility is turning the `?range=` query argument into a pair of
 * concrete windows: the current period and the equally long period immediately
 * before it (used for "vs previous period" deltas). All dates are computed in the
 * site timezone via {@see wp_timezone()} so the windows line up with how the
 * merchant perceives "today".
 */
final class DateRangeFilter {

	/**
	 * The valid range keys accepted from the request.
	 */
	public const RANGES = array( 'today', '7d', '30d' );

	/**
	 * The default range when none/invalid is supplied.
	 */
	public const DEFAULT_RANGE = '30d';

	/**
	 * Resolve the current and previous windows for the requested range.
	 *
	 * @return array{
	 *     key: string,
	 *     label: string,
	 *     start: DateTimeImmutable,
	 *     end: DateTimeImmutable,
	 *     previous_start: DateTimeImmutable,
	 *     previous_end: DateTimeImmutable,
	 *     previous_label: string
	 * }
	 */
	public static function get_current_range(): array {
		return self::get_range( self::get_requested_key() );
	}

	/**
	 * Read and sanitise the requested range key from the query string.
	 *
	 * No nonce is needed: this only selects a read-only report window.
	 *
	 * @return string One of self::RANGES.
	 */
	public static function get_requested_key(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only range selector, no state change.
		$raw = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return in_array( $raw, self::RANGES, true ) ? $raw : self::DEFAULT_RANGE;
	}

	/**
	 * Build the window pair for a specific range key.
	 *
	 * @param string $key One of self::RANGES (anything else falls back to the default).
	 * @return array{
	 *     key: string,
	 *     label: string,
	 *     start: DateTimeImmutable,
	 *     end: DateTimeImmutable,
	 *     previous_start: DateTimeImmutable,
	 *     previous_end: DateTimeImmutable,
	 *     previous_label: string
	 * }
	 */
	public static function get_range( string $key ): array {
		if ( ! in_array( $key, self::RANGES, true ) ) {
			$key = self::DEFAULT_RANGE;
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$end   = $now;
		$start = self::start_for( $key, $now );

		// Previous period: the same elapsed length, immediately before the current one.
		$length         = $end->getTimestamp() - $start->getTimestamp();
		$previous_end   = $start;
		$previous_start = $start->modify( '-' . $length . ' seconds' );

		return array(
			'key'            => $key,
			'label'          => self::label_for( $key ),
			'start'          => $start,
			'end'            => $end,
			'previous_start' => $previous_start,
			'previous_end'   => $previous_end,
			'previous_label' => self::previous_label_for( $key ),
		);
	}

	/**
	 * Compute the start boundary for a range, at midnight in the site timezone.
	 *
	 * @param string            $key One of self::RANGES.
	 * @param DateTimeImmutable $now Current time in the site timezone.
	 * @return DateTimeImmutable Start of the window.
	 */
	private static function start_for( string $key, DateTimeImmutable $now ): DateTimeImmutable {
		$midnight = $now->setTime( 0, 0, 0 );

		switch ( $key ) {
			case 'today':
				return $midnight;

			case '7d':
				return $midnight->modify( '-7 days' );

			case '30d':
			default:
				return $midnight->modify( '-30 days' );
		}
	}

	/**
	 * Human label for a range key.
	 *
	 * @param string $key One of self::RANGES.
	 * @return string Translated label.
	 */
	public static function label_for( string $key ): string {
		switch ( $key ) {
			case 'today':
				return __( 'Today', 'profitly' );

			case '7d':
				return __( 'Last 7 days', 'profitly' );

			case '30d':
			default:
				return __( 'Last 30 days', 'profitly' );
		}
	}

	/**
	 * Human label for the comparison window of a range key.
	 *
	 * @param string $key One of self::RANGES.
	 * @return string Translated label.
	 */
	private static function previous_label_for( string $key ): string {
		switch ( $key ) {
			case 'today':
				return __( 'vs previous day', 'profitly' );

			case '7d':
				return __( 'vs previous 7 days', 'profitly' );

			case '30d':
			default:
				return __( 'vs previous 30 days', 'profitly' );
		}
	}
}
