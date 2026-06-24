<?php
/**
 * Product-level cost-of-goods storage.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\COGS;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitises, and persists the cost of goods stored against products and
 * variations.
 *
 * Its single responsibility is the product-meta data layer for COGS: the meta
 * key contract (`_profitly_cogs`, `_profitly_supplier`), value
 * sanitisation, and getters — including variation-to-parent fallback. UI
 * rendering lives in {@see \Profitly\Admin\ProductFields}.
 */
final class ProductCOGS {

	/**
	 * Meta key holding the per-unit cost of goods (decimal string).
	 */
	public const META_COGS = '_profitly_cogs';

	/**
	 * Meta key holding the optional supplier name.
	 */
	public const META_SUPPLIER = '_profitly_supplier';

	/**
	 * Hard upper bound for a single unit cost.
	 */
	private const MAX_COST = 999999.99;

	/**
	 * Register WordPress/WooCommerce hooks for this component.
	 *
	 * This data layer is hookless by default; saving is driven by the admin
	 * field component. The method exists to satisfy the uniform component
	 * contract called from {@see \Profitly\Plugin}.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Intentionally empty: persistence is invoked by the admin field layer.
	}

	/**
	 * Get the stored cost of goods for a product or variation.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string The cost as a decimal string; '0' when unset. Never null.
	 */
	public static function get( int $product_id ): string {
		$raw = get_post_meta( $product_id, self::META_COGS, true );

		if ( '' === $raw || null === $raw || false === $raw ) {
			return '0';
		}

		return wc_format_decimal( $raw, false, true );
	}

	/**
	 * Get the cost of goods for a variation, falling back to its parent.
	 *
	 * When a variation has no cost of its own, the parent product's cost is
	 * returned so variable products do not silently report zero cost.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $parent_id    Parent (variable) product ID.
	 * @return string The cost as a decimal string; '0' when neither is set.
	 */
	public static function get_for_variation( int $variation_id, int $parent_id ): string {
		$raw = get_post_meta( $variation_id, self::META_COGS, true );

		if ( '' === $raw || null === $raw || false === $raw ) {
			return self::get( $parent_id );
		}

		return wc_format_decimal( $raw, false, true );
	}

	/**
	 * Get the supplier name for a product or variation.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string Supplier name, or an empty string when unset.
	 */
	public static function get_supplier( int $product_id ): string {
		$raw = get_post_meta( $product_id, self::META_SUPPLIER, true );

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Persist the cost of goods against a product or variation.
	 *
	 * The value is sanitised with {@see wc_format_decimal()}, forced
	 * non-negative, and capped at the maximum allowed cost. Invalid input is
	 * stored as '0'.
	 *
	 * @param WC_Product $product   Product or variation object.
	 * @param mixed      $raw_value Raw user-supplied cost.
	 * @return string The sanitised value that was stored.
	 */
	public static function save( WC_Product $product, $raw_value ): string {
		$value = self::sanitize_cost( $raw_value );
		$product->update_meta_data( self::META_COGS, $value );

		return $value;
	}

	/**
	 * Persist the supplier name against a product or variation.
	 *
	 * @param WC_Product $product   Product or variation object.
	 * @param mixed      $raw_value Raw user-supplied supplier name.
	 * @return string The sanitised supplier name that was stored.
	 */
	public static function save_supplier( WC_Product $product, $raw_value ): string {
		$value = is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';
		$product->update_meta_data( self::META_SUPPLIER, $value );

		return $value;
	}

	/**
	 * Sanitise a raw cost value into a safe, bounded decimal string.
	 *
	 * @param mixed $raw_value Raw user-supplied cost.
	 * @return string A non-negative decimal string no greater than MAX_COST.
	 */
	public static function sanitize_cost( $raw_value ): string {
		// Guard against array/object input (e.g. a colliding $_POST key) before
		// handing the value to WooCommerce, which expects a scalar.
		if ( ! is_scalar( $raw_value ) ) {
			return '0';
		}

		$value = wc_format_decimal( $raw_value, false, true );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return '0';
		}

		// Clamp to the allowed range.
		$numeric = (float) $value;

		if ( $numeric < 0 ) {
			return '0';
		}

		if ( $numeric > self::MAX_COST ) {
			return wc_format_decimal( self::MAX_COST, false, true );
		}

		return $value;
	}
}
