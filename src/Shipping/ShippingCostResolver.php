<?php
/**
 * Merchant-side shipping cost resolution for an order.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Shipping;

use Profitly\Settings\SettingsRegistry;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the merchant's actual cost to ship an order.
 *
 * Its single responsibility is choosing the right shipping-cost figure for an
 * order, in priority order: a manual per-order override, then the zone-based
 * estimate snapshotted at order creation, then zero. It only reads order meta;
 * it never writes.
 */
final class ShippingCostResolver {

	/**
	 * Order meta key: zone-based estimate captured at order creation.
	 */
	public const META_SNAPSHOT = '_profitly_shipping_cost';

	/**
	 * Order meta key: manual per-order override set by an admin.
	 */
	public const META_OVERRIDE = '_profitly_shipping_cost_override';

	/**
	 * Order meta key: shipping cost model captured at order creation.
	 */
	public const META_SNAPSHOT_MODEL = '_profitly_shipping_cost_model';

	/**
	 * Resolve the shipping cost for an order.
	 *
	 * Priority: a manual per-order override wins outright. Otherwise the model
	 * snapshotted at order creation decides — the customer-paid total, zero, or
	 * the zone estimate snapshot. Legacy orders without a model snapshot fall
	 * back to the current settings model.
	 *
	 * @param WC_Order $order The order.
	 * @return string The shipping cost as a decimal string.
	 */
	public static function resolve_for_order( WC_Order $order ): string {
		$override = (string) $order->get_meta( self::META_OVERRIDE, true );

		if ( '' !== $override && is_numeric( $override ) ) {
			return wc_format_decimal( $override, 2 );
		}

		$model = (string) $order->get_meta( self::META_SNAPSHOT_MODEL, true );

		// Legacy order placed before the model was snapshotted: use live settings.
		if ( '' === $model ) {
			$model = SettingsRegistry::get_shipping_cost_model();
		}

		switch ( $model ) {
			case 'customer_paid':
				return wc_format_decimal( (string) $order->get_shipping_total(), 2 );

			case 'included':
				return '0';

			case 'carrier_estimate':
			default:
				$snapshot = (string) $order->get_meta( self::META_SNAPSHOT, true );

				if ( '' !== $snapshot && is_numeric( $snapshot ) ) {
					return wc_format_decimal( $snapshot, 2 );
				}

				return '0';
		}
	}
}
