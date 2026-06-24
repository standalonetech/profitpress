<?php
/**
 * Pure cost-of-goods math.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\COGS;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless arithmetic helpers for cost-of-goods calculations.
 *
 * Its single responsibility is decimal-safe money math. Every method is static
 * and free of WordPress/WooCommerce dependencies so it can be unit tested in
 * isolation. All arithmetic is performed on decimal strings (via bcmath when
 * available) to avoid binary floating-point rounding errors.
 */
final class COGSCalculator {

	/**
	 * Number of decimal places used internally for all results.
	 */
	private const SCALE = 2;

	/**
	 * Multiply a per-unit cost by a quantity.
	 *
	 * @param string $unit_cost Per-unit cost as a decimal string.
	 * @param int    $quantity  Number of units (negative values are clamped to 0).
	 * @return string Line total as a decimal string with fixed scale.
	 */
	public static function calculate_line_total( string $unit_cost, int $quantity ): string {
		$unit_cost = self::normalize( $unit_cost );
		$quantity  = max( 0, $quantity );

		if ( function_exists( 'bcmul' ) ) {
			return self::format( bcmul( $unit_cost, (string) $quantity, self::SCALE ) );
		}

		return self::format( (string) ( (float) $unit_cost * $quantity ) );
	}

	/**
	 * Sum an array of decimal strings without floating-point error.
	 *
	 * @param array<int|string, string|int|float> $values Decimal values to add together.
	 * @return string The total as a decimal string with fixed scale.
	 */
	public static function sum( array $values ): string {
		$total = '0';

		foreach ( $values as $value ) {
			$value = self::normalize( (string) $value );

			if ( function_exists( 'bcadd' ) ) {
				$total = bcadd( $total, $value, self::SCALE );
			} else {
				$total = (string) ( (float) $total + (float) $value );
			}
		}

		return self::format( $total );
	}

	/**
	 * Validate that a value is an acceptable cost input.
	 *
	 * Accepts non-negative numbers up to 999999.99 with at most two decimal
	 * places. Rejects negatives, non-numeric strings, and out-of-range values.
	 *
	 * @param mixed $value Candidate cost value.
	 * @return bool True when the value is a valid cost.
	 */
	public static function is_valid_cost( $value ): bool {
		if ( is_bool( $value ) || null === $value || '' === $value ) {
			return false;
		}

		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$value = (float) $value;

		if ( $value < 0 || $value > 999999.99 ) {
			return false;
		}

		return true;
	}

	/**
	 * Add two decimal strings without floating-point error.
	 *
	 * @param string $a     Left operand.
	 * @param string $b     Right operand.
	 * @param int    $scale Decimal places in the result.
	 * @return string The sum as a decimal string.
	 */
	public static function add( string $a, string $b, int $scale = self::SCALE ): string {
		$a = self::normalize( $a );
		$b = self::normalize( $b );

		if ( function_exists( 'bcadd' ) ) {
			return self::format_scale( bcadd( $a, $b, $scale ), $scale );
		}

		return self::format_scale( (string) ( (float) $a + (float) $b ), $scale );
	}

	/**
	 * Subtract one decimal string from another without floating-point error.
	 *
	 * @param string $a     Minuend.
	 * @param string $b     Subtrahend.
	 * @param int    $scale Decimal places in the result.
	 * @return string The difference as a decimal string.
	 */
	public static function subtract( string $a, string $b, int $scale = self::SCALE ): string {
		$a = self::normalize( $a );
		$b = self::normalize( $b );

		if ( function_exists( 'bcsub' ) ) {
			return self::format_scale( bcsub( $a, $b, $scale ), $scale );
		}

		return self::format_scale( (string) ( (float) $a - (float) $b ), $scale );
	}

	/**
	 * Multiply two decimal strings without floating-point error.
	 *
	 * @param string $a     Left operand.
	 * @param string $b     Right operand.
	 * @param int    $scale Decimal places in the result.
	 * @return string The product as a decimal string.
	 */
	public static function multiply( string $a, string $b, int $scale = self::SCALE ): string {
		$a = self::normalize( $a );
		$b = self::normalize( $b );

		if ( function_exists( 'bcmul' ) ) {
			return self::format_scale( bcmul( $a, $b, $scale + 4 ), $scale );
		}

		return self::format_scale( (string) ( (float) $a * (float) $b ), $scale );
	}

	/**
	 * Divide one decimal string by another, returning '0' on division by zero.
	 *
	 * @param string $a     Dividend.
	 * @param string $b     Divisor.
	 * @param int    $scale Decimal places in the result.
	 * @return string The quotient as a decimal string; '0' when the divisor is zero.
	 */
	public static function divide( string $a, string $b, int $scale = self::SCALE ): string {
		$a = self::normalize( $a );
		$b = self::normalize( $b );

		if ( 0.0 === (float) $b ) {
			return self::format_scale( '0', $scale );
		}

		if ( function_exists( 'bcdiv' ) ) {
			return self::format_scale( bcdiv( $a, $b, $scale + 4 ), $scale );
		}

		return self::format_scale( (string) ( (float) $a / (float) $b ), $scale );
	}

	/**
	 * Coerce a possibly-empty/invalid value into a clean decimal string.
	 *
	 * @param string $value Raw value.
	 * @return string A numeric decimal string, defaulting to '0'.
	 */
	private static function normalize( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return '0';
		}

		return $value;
	}

	/**
	 * Format a decimal string to the internal fixed scale.
	 *
	 * @param string $value Decimal string.
	 * @return string Value with exactly SCALE decimal places.
	 */
	private static function format( string $value ): string {
		return self::format_scale( $value, self::SCALE );
	}

	/**
	 * Format a decimal string to an arbitrary fixed scale.
	 *
	 * @param string $value Decimal string.
	 * @param int    $scale Number of decimal places.
	 * @return string Value with exactly $scale decimal places.
	 */
	private static function format_scale( string $value, int $scale ): string {
		return number_format( (float) $value, max( 0, $scale ), '.', '' );
	}
}
