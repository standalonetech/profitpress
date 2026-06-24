<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Compatibility;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Declares this plugin's compatibility with modern WooCommerce features.
 *
 * Its single responsibility is to announce, before WooCommerce initialises,
 * that the plugin supports High-Performance Order Storage (custom order tables)
 * and the cart/checkout Blocks. All order access in this plugin uses the CRUD
 * API so these declarations hold true.
 */
final class HPOS {

	/**
	 * Register WordPress/WooCommerce hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
	}

	/**
	 * Declare compatibility with HPOS and checkout Blocks.
	 *
	 * @return void
	 */
	public function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', PROFITLY_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PROFITLY_FILE, true );
	}
}
