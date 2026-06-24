<?php
/**
 * Order line-item cost-of-goods snapshotting.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\COGS;

use WC_Abstract_Order;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots each product's cost of goods onto order line items at sale time.
 *
 * Its single responsibility is preserving historical cost: once an order line
 * item is created its cost is frozen, so later supplier price changes never
 * rewrite past profit. It captures cost across every order-creation path
 * (classic checkout, Blocks checkout, admin, REST, and programmatic creation)
 * and exposes read helpers for downstream reporting.
 *
 * All order access uses the WooCommerce CRUD API, so this is HPOS-safe.
 */
final class OrderLineCOGS {

	/**
	 * Meta key holding the snapshotted per-unit cost.
	 */
	public const META_UNIT = '_profitly_line_cogs';

	/**
	 * Meta key holding the snapshotted line total cost (unit × quantity).
	 */
	public const META_TOTAL = '_profitly_line_cogs_total';

	/**
	 * Register WordPress/WooCommerce hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Classic + Blocks checkout: fires as each line item is built.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'snapshot_on_checkout' ), 10, 4 );

		// Admin-created and REST-created orders: fires when an item is added.
		add_action( 'woocommerce_new_order_item', array( $this, 'snapshot_on_new_item' ), 10, 3 );

		// Safety net for programmatic orders (e.g. subscription renewals):
		// fill in only the line items still missing a snapshot before save.
		add_action( 'woocommerce_before_order_object_save', array( $this, 'backfill_missing' ), 10, 1 );
	}

	/**
	 * Snapshot cost during checkout (classic and Blocks).
	 *
	 * @param WC_Order_Item_Product $item          The line item being created.
	 * @param string                $cart_item_key The cart item key (unused).
	 * @param array<string, mixed>  $values        Cart item values (unused).
	 * @param WC_Order              $order         The parent order (unused here).
	 * @return void
	 */
	public function snapshot_on_checkout( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
		unset( $cart_item_key, $values, $order );
		$this->apply_snapshot( $item );
	}

	/**
	 * Snapshot cost when an item is added to an admin- or REST-created order.
	 *
	 * @param int           $item_id The order item ID (unused).
	 * @param WC_Order_Item $item    The order item.
	 * @param int           $order_id The parent order ID (unused).
	 * @return void
	 */
	public function snapshot_on_new_item( int $item_id, WC_Order_Item $item, int $order_id ): void {
		unset( $item_id, $order_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		// Only set if missing, so we never clobber a checkout snapshot.
		if ( '' !== (string) $item->get_meta( self::META_UNIT, true ) ) {
			return;
		}

		$this->apply_snapshot( $item );
		$item->save();
	}

	/**
	 * Safety net: fill in snapshots missing on any line item before save.
	 *
	 * Covers orders created entirely in code where neither checkout nor the
	 * item hooks ran (for example some subscription renewal flows).
	 *
	 * @param WC_Abstract_Order $order The order about to be saved.
	 * @return void
	 */
	public function backfill_missing( WC_Abstract_Order $order ): void {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( '' !== (string) $item->get_meta( self::META_UNIT, true ) ) {
				continue;
			}

			$this->apply_snapshot( $item );
		}
	}

	/**
	 * Read the snapshotted cost from a line item.
	 *
	 * @param WC_Order_Item_Product $item The order line item.
	 * @return array{unit: string, total: string} The per-unit and total cost.
	 */
	public static function get_line_cogs( WC_Order_Item_Product $item ): array {
		$unit  = (string) $item->get_meta( self::META_UNIT, true );
		$total = (string) $item->get_meta( self::META_TOTAL, true );

		return array(
			'unit'  => '' === $unit ? '0' : $unit,
			'total' => '' === $total ? '0' : $total,
		);
	}

	/**
	 * Sum the snapshotted cost across all line items in an order.
	 *
	 * @param WC_Order $order The order to total.
	 * @return string The total cost of goods as a decimal string.
	 */
	public static function get_order_total_cogs( WC_Order $order ): string {
		$totals = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$totals[] = self::get_line_cogs( $item )['total'];
		}

		return COGSCalculator::sum( $totals );
	}

	/**
	 * Compute and store the per-unit and total cost on a line item.
	 *
	 * Resolves the cost from the variation (with parent fallback) or the simple
	 * product, then writes both meta values. Does not call save(): checkout and
	 * the order save pipeline persist the item for us; callers that need an
	 * immediate write handle it themselves.
	 *
	 * @param WC_Order_Item_Product $item The line item to annotate.
	 * @return void
	 */
	private function apply_snapshot( WC_Order_Item_Product $item ): void {
		$variation_id = (int) $item->get_variation_id();
		$product_id   = (int) $item->get_product_id();

		if ( $variation_id > 0 ) {
			$unit_cost = ProductCOGS::get_for_variation( $variation_id, $product_id );
		} else {
			$unit_cost = ProductCOGS::get( $product_id );
		}

		$quantity   = (int) $item->get_quantity();
		$line_total = COGSCalculator::calculate_line_total( $unit_cost, $quantity );

		$item->update_meta_data( self::META_UNIT, $unit_cost );
		$item->update_meta_data( self::META_TOTAL, $line_total );
	}
}
