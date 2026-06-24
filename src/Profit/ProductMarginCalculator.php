<?php
/**
 * Per-product gross margin.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Profit;

use Profitly\COGS\COGSCalculator;
use Profitly\COGS\ProductCOGS;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Computes a product's gross margin from its price and cost of goods.
 *
 * Its single responsibility is the gross-margin figure for a product: price
 * minus COGS, as both an amount and a percentage. Gross margin deliberately
 * excludes gateway fees and shipping, which are per-order concerns. For
 * variable products it averages the margin of variations that have a cost set.
 */
final class ProductMarginCalculator {

	/**
	 * Get the gross margin for a product.
	 *
	 * @param WC_Product $product The product (simple or variable).
	 * @return array{cogs: string, price: string, margin_amount: string, margin_percent: string, is_average?: bool}|null
	 *     The margin breakdown, or null when no COGS is recorded.
	 */
	public static function get_gross_margin( WC_Product $product ): ?array {
		if ( $product->is_type( 'variable' ) ) {
			return self::variable_margin( $product );
		}

		return self::simple_margin( $product );
	}

	/**
	 * Compute the margin for a simple (non-variable) product.
	 *
	 * @param WC_Product $product The product.
	 * @return array{cogs: string, price: string, margin_amount: string, margin_percent: string}|null
	 */
	private static function simple_margin( WC_Product $product ): ?array {
		if ( ! self::has_cogs( $product ) ) {
			return null;
		}

		$cogs  = ProductCOGS::get( $product->get_id() );
		$price = wc_format_decimal( (string) $product->get_price(), 2 );

		return self::build( $cogs, $price );
	}

	/**
	 * Compute the average margin across variations that have a cost set.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array{cogs: string, price: string, margin_amount: string, margin_percent: string, is_average: bool}|null
	 */
	private static function variable_margin( WC_Product $product ): ?array {
		$cogs_values   = array();
		$price_values  = array();
		$margin_values = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product || ! self::has_cogs( $variation ) ) {
				continue;
			}

			$cogs  = ProductCOGS::get( $variation->get_id() );
			$price = wc_format_decimal( (string) $variation->get_price(), 2 );
			$row   = self::build( $cogs, $price );

			$cogs_values[]   = $row['cogs'];
			$price_values[]  = $row['price'];
			$margin_values[] = $row['margin_percent'];
		}

		$count = count( $margin_values );

		if ( 0 === $count ) {
			return null;
		}

		return array(
			'cogs'           => self::average( $cogs_values, $count ),
			'price'          => self::average( $price_values, $count ),
			'margin_amount'  => self::average(
				array_map(
					static function ( $c, $p ) {
						return COGSCalculator::subtract( $p, $c, 2 );
					},
					$cogs_values,
					$price_values
				),
				$count
			),
			'margin_percent' => self::average( $margin_values, $count ),
			'is_average'     => true,
		);
	}

	/**
	 * Build a margin breakdown from a cost and price.
	 *
	 * @param string $cogs  Cost of goods as a decimal string.
	 * @param string $price Active price as a decimal string.
	 * @return array{cogs: string, price: string, margin_amount: string, margin_percent: string}
	 */
	private static function build( string $cogs, string $price ): array {
		$margin_amount  = COGSCalculator::subtract( $price, $cogs, 2 );
		$margin_percent = '0';

		if ( 0.0 !== (float) $price ) {
			$margin_percent = COGSCalculator::multiply(
				COGSCalculator::divide( $margin_amount, $price, 6 ),
				'100',
				2
			);
		}

		return array(
			'cogs'           => $cogs,
			'price'          => $price,
			'margin_amount'  => $margin_amount,
			'margin_percent' => $margin_percent,
		);
	}

	/**
	 * Average a list of decimal strings.
	 *
	 * @param array<int, string> $values Decimal strings.
	 * @param int                $count  Divisor (number of contributing items).
	 * @return string The mean as a decimal string.
	 */
	private static function average( array $values, int $count ): string {
		return COGSCalculator::divide( COGSCalculator::sum( $values ), (string) $count, 2 );
	}

	/**
	 * Determine whether a product/variation has a COGS value explicitly set.
	 *
	 * Distinct from {@see ProductCOGS::get()}, which returns '0' for unset — a
	 * genuine zero cost is treated as "set" here.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return bool True when the COGS meta exists.
	 */
	private static function has_cogs( WC_Product $product ): bool {
		return '' !== (string) $product->get_meta( ProductCOGS::META_COGS, true );
	}
}
