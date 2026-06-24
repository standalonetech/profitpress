<?php
/**
 * Per-order profit engine.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Profit;

use Profitly\COGS\COGSCalculator;
use Profitly\COGS\OrderLineCOGS;
use Profitly\Fees\GatewayFeeCalculator;
use Profitly\Shipping\ShippingCostResolver;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Calculates the full profit breakdown for a single order.
 *
 * Its single responsibility is assembling revenue, cost of goods, gateway fees,
 * shipping cost, and refund effects into one decimal-string breakdown for the
 * order's own currency. It performs no conversion and no display formatting.
 *
 * Refund model: the full gateway fee (which payment processors keep on refunds)
 * is partitioned into two reported terms — `gateway_fee` for the portion
 * attributable to revenue you kept, and `refund_loss` for the portion on
 * refunded revenue that is now a dead loss. Their sum equals the original
 * snapshot fee, so `net_profit = revenue - cogs - gateway_fee - shipping_cost -
 * refund_loss` charges the whole fee exactly once.
 */
final class OrderProfitCalculator {

	/**
	 * Calculate the profit breakdown for an order.
	 *
	 * @param WC_Order $order The order.
	 * @return array{
	 *     revenue: string,
	 *     cogs: string,
	 *     gateway_fee: string,
	 *     shipping_cost: string,
	 *     refund_loss: string,
	 *     gross_profit: string,
	 *     net_profit: string,
	 *     margin_percent: string,
	 *     currency: string,
	 *     has_missing_cogs: bool
	 * }
	 */
	public static function calculate( WC_Order $order ): array {
		$order_total    = wc_format_decimal( (string) $order->get_total(), 2 );
		$refunded_total = wc_format_decimal( (string) $order->get_total_refunded(), 2 );

		// Revenue retained after refunds.
		$revenue = COGSCalculator::subtract( $order_total, $refunded_total, 2 );

		$cogs_result = self::calculate_cogs( $order );
		$cogs        = $cogs_result['cogs'];

		// Full original gateway fee (none of it is refunded by the processor).
		$full_fee = GatewayFeeCalculator::calculate_for_order( $order );

		// Split the fee between retained and refunded revenue.
		$refunded_ratio = COGSCalculator::divide( $refunded_total, $order_total, 6 );
		$refund_loss    = COGSCalculator::multiply( $full_fee, $refunded_ratio, 2 );
		$gateway_fee    = COGSCalculator::subtract( $full_fee, $refund_loss, 2 );

		$shipping_cost = ShippingCostResolver::resolve_for_order( $order );

		// gross_profit = revenue - cogs.
		$gross_profit = COGSCalculator::subtract( $revenue, $cogs, 2 );

		// net_profit = revenue - cogs - gateway_fee - shipping_cost - refund_loss.
		$net_profit = COGSCalculator::subtract( $gross_profit, $gateway_fee, 2 );
		$net_profit = COGSCalculator::subtract( $net_profit, $shipping_cost, 2 );
		$net_profit = COGSCalculator::subtract( $net_profit, $refund_loss, 2 );

		// margin_percent = (net_profit / revenue) * 100, or '0' when revenue is zero.
		$margin_percent = '0';

		if ( 0.0 !== (float) $revenue ) {
			$margin_percent = COGSCalculator::multiply(
				COGSCalculator::divide( $net_profit, $revenue, 6 ),
				'100',
				2
			);
		}

		return array(
			'revenue'          => $revenue,
			'cogs'             => $cogs,
			'gateway_fee'      => $gateway_fee,
			'shipping_cost'    => $shipping_cost,
			'refund_loss'      => $refund_loss,
			'gross_profit'     => $gross_profit,
			'net_profit'       => $net_profit,
			'margin_percent'   => $margin_percent,
			'currency'         => $order->get_currency(),
			'has_missing_cogs' => $cogs_result['has_missing_cogs'],
		);
	}

	/**
	 * Sum the cost of goods across line items, net of refunded quantities.
	 *
	 * @param WC_Order $order The order.
	 * @return array{cogs: string, has_missing_cogs: bool}
	 */
	private static function calculate_cogs( WC_Order $order ): array {
		$line_costs       = array();
		$has_missing_cogs = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$unit_raw = (string) $item->get_meta( OrderLineCOGS::META_UNIT, true );

			// A snapshot is expected for any item tied to a product.
			if ( '' === $unit_raw && $item->get_product_id() > 0 ) {
				$has_missing_cogs = true;
			}

			$unit_cost = '' === $unit_raw ? '0' : $unit_raw;

			// Net quantity after refunds: get_qty_refunded_for_item() is negative.
			$refunded_qty = (int) $order->get_qty_refunded_for_item( $item_id );
			$net_qty      = max( 0, (int) $item->get_quantity() + $refunded_qty );

			$line_costs[] = COGSCalculator::multiply( $unit_cost, (string) $net_qty, 2 );
		}

		return array(
			'cogs'             => COGSCalculator::sum( $line_costs ),
			'has_missing_cogs' => $has_missing_cogs,
		);
	}
}
