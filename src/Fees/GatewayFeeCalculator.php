<?php
/**
 * Gateway fee calculation from order snapshots.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Fees;

use Profitly\COGS\COGSCalculator;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the payment-gateway fee for an order from its snapshot.
 *
 * Its single responsibility is turning the fee snapshot captured at order
 * creation (percent, fixed, and basis) into a concrete fee amount. It reads
 * exclusively from order meta — never from the live settings — so historical
 * orders keep the fee that applied when they were placed.
 */
final class GatewayFeeCalculator {

	/**
	 * Order meta key: percentage fee captured at order time.
	 */
	public const META_PERCENT = '_profitly_gateway_fee_percent';

	/**
	 * Order meta key: fixed fee captured at order time.
	 */
	public const META_FIXED = '_profitly_gateway_fee_fixed';

	/**
	 * Order meta key: which amount the percentage applies to.
	 */
	public const META_BASIS = '_profitly_gateway_fee_basis';

	/**
	 * Order meta key: flag recording that a "fee unavailable" note was added.
	 */
	private const META_NOTE_ADDED = '_profitly_fee_note_added';

	/**
	 * Calculate the gateway fee for an order.
	 *
	 * @param WC_Order $order The order.
	 * @return string The fee amount as a decimal string ('0' for legacy orders).
	 */
	public static function calculate_for_order( WC_Order $order ): string {
		$percent = (string) $order->get_meta( self::META_PERCENT, true );
		$fixed   = (string) $order->get_meta( self::META_FIXED, true );
		$basis   = (string) $order->get_meta( self::META_BASIS, true );

		// Legacy order placed before the plugin captured a snapshot.
		if ( '' === $percent && '' === $fixed && '' === $basis ) {
			self::add_missing_note( $order );
			return '0';
		}

		$basis_amount = self::get_basis_amount( $order, '' === $basis ? 'total' : $basis );

		// fee = (basis_amount * percent / 100) + fixed.
		$percent_part = COGSCalculator::divide(
			COGSCalculator::multiply( $basis_amount, '' === $percent ? '0' : $percent, 6 ),
			'100',
			2
		);

		return COGSCalculator::add( $percent_part, '' === $fixed ? '0' : $fixed, 2 );
	}

	/**
	 * Build a display-friendly breakdown of the fee.
	 *
	 * @param WC_Order $order The order.
	 * @return array{percent: string, fixed: string, basis: string, basis_amount: string, total: string}
	 */
	public static function get_fee_breakdown( WC_Order $order ): array {
		$percent = (string) $order->get_meta( self::META_PERCENT, true );
		$fixed   = (string) $order->get_meta( self::META_FIXED, true );
		$basis   = (string) $order->get_meta( self::META_BASIS, true );
		$basis   = '' === $basis ? 'total' : $basis;

		return array(
			'percent'      => '' === $percent ? '0' : $percent,
			'fixed'        => '' === $fixed ? '0' : $fixed,
			'basis'        => $basis,
			'basis_amount' => self::get_basis_amount( $order, $basis ),
			'total'        => self::calculate_for_order( $order ),
		);
	}

	/**
	 * Resolve the monetary amount the percentage fee applies to.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $basis One of 'total', 'subtotal_shipping', 'subtotal'.
	 * @return string The basis amount as a decimal string.
	 */
	private static function get_basis_amount( WC_Order $order, string $basis ): string {
		switch ( $basis ) {
			case 'subtotal':
				return wc_format_decimal( (string) $order->get_subtotal(), 2 );

			case 'subtotal_shipping':
				return COGSCalculator::add(
					wc_format_decimal( (string) $order->get_subtotal(), 2 ),
					wc_format_decimal( (string) $order->get_shipping_total(), 2 ),
					2
				);

			case 'total':
			default:
				return wc_format_decimal( (string) $order->get_total(), 2 );
		}
	}

	/**
	 * Add a one-time order note explaining the fee could not be calculated.
	 *
	 * @param WC_Order $order The order.
	 * @return void
	 */
	private static function add_missing_note( WC_Order $order ): void {
		if ( '' !== (string) $order->get_meta( self::META_NOTE_ADDED, true ) ) {
			return;
		}

		$order->add_order_note(
			__( 'Profitly: gateway fee could not be calculated because no fee snapshot was recorded for this order (it predates the plugin or its settings).', 'profitly' )
		);

		$order->update_meta_data( self::META_NOTE_ADDED, 'yes' );
		$order->save();
	}
}
