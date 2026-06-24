<?php
/**
 * Order-creation snapshot of fee and shipping settings.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Profit;

use Profitly\Fees\GatewayFeeCalculator;
use Profitly\Settings\SettingsRegistry;
use Profitly\Shipping\ShippingCostResolver;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Freezes the current gateway-fee and shipping-cost settings onto each order.
 *
 * Its single responsibility is snapshotting: at order creation it copies the
 * live settings that apply to the order (the chosen gateway's fee config and
 * the destination zone's shipping estimate) into order meta, mirroring how COGS
 * is snapshotted onto line items. Later settings changes therefore never
 * rewrite a historical order's profit. The write is idempotent — an order that
 * already carries a snapshot is left untouched.
 */
final class OrderSnapshot {

	/**
	 * Order meta key: id of the gateway used for the order.
	 */
	public const META_GATEWAY_ID = '_profitly_gateway_id';

	/**
	 * Register WordPress/WooCommerce hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Classic + Blocks checkout: runs after Woo finalises the order object.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'snapshot_on_checkout' ), 20, 2 );

		// Admin- and API-created orders.
		add_action( 'woocommerce_new_order', array( $this, 'snapshot_on_new_order' ), 20, 2 );
	}

	/**
	 * Snapshot during checkout, before the order is first saved.
	 *
	 * @param WC_Order             $order The order being created.
	 * @param array<string, mixed> $data  Posted checkout data (unused).
	 * @return void
	 */
	public function snapshot_on_checkout( WC_Order $order, array $data ): void {
		unset( $data );
		$this->apply_snapshot( $order );
		// No explicit save(): checkout persists the order after this hook.
	}

	/**
	 * Snapshot an admin- or API-created order after it is inserted.
	 *
	 * @param int           $order_id The new order id.
	 * @param WC_Order|null $order    The order object, when provided by WooCommerce.
	 * @return void
	 */
	public function snapshot_on_new_order( int $order_id, $order = null ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $this->already_snapshotted( $order ) ) {
			return;
		}

		$this->apply_snapshot( $order );
		$order->save();
	}

	/**
	 * Determine whether an order already carries a fee snapshot.
	 *
	 * @param WC_Order $order The order.
	 * @return bool True when a snapshot is present.
	 */
	private function already_snapshotted( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::META_GATEWAY_ID, true )
			|| '' !== (string) $order->get_meta( GatewayFeeCalculator::META_BASIS, true );
	}

	/**
	 * Write the fee and shipping snapshot onto the order meta.
	 *
	 * @param WC_Order $order The order.
	 * @return void
	 */
	private function apply_snapshot( WC_Order $order ): void {
		if ( $this->already_snapshotted( $order ) ) {
			return;
		}

		$gateway_id = $order->get_payment_method();
		$fee        = SettingsRegistry::get_gateway_fee( (string) $gateway_id );

		$order->update_meta_data( self::META_GATEWAY_ID, (string) $gateway_id );
		$order->update_meta_data( GatewayFeeCalculator::META_PERCENT, $fee['percent'] );
		$order->update_meta_data( GatewayFeeCalculator::META_FIXED, $fee['fixed'] );
		$order->update_meta_data( GatewayFeeCalculator::META_BASIS, $fee['basis'] );

		$order->update_meta_data(
			ShippingCostResolver::META_SNAPSHOT,
			SettingsRegistry::get_shipping_cost( $this->resolve_zone_id( $order ) )
		);

		$order->update_meta_data(
			ShippingCostResolver::META_SNAPSHOT_MODEL,
			SettingsRegistry::get_shipping_cost_model()
		);
	}

	/**
	 * Resolve the shipping zone id for an order's destination.
	 *
	 * @param WC_Order $order The order.
	 * @return int The matching zone id (0 = Rest of the World).
	 */
	private function resolve_zone_id( WC_Order $order ): int {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return 0;
		}

		$country = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();

		$package = array(
			'destination' => array(
				'country'  => $country,
				'state'    => $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state(),
				'postcode' => $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
				'city'     => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
			),
		);

		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

		return $zone ? (int) $zone->get_id() : 0;
	}
}
