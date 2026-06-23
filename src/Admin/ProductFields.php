<?php
/**
 * Cost-of-goods product editor fields.
 *
 * @package ProfitPress
 */

declare( strict_types=1 );

namespace ProfitPress\Admin;

use ProfitPress\COGS\ProductCOGS;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the cost-of-goods inputs in the WooCommerce product editor.
 *
 * Its single responsibility is the admin UI for COGS: drawing the cost and
 * supplier fields for simple products and each variation, and routing the
 * submitted values into the {@see ProductCOGS} data layer. It holds no storage
 * logic of its own.
 */
final class ProductFields {

	/**
	 * Register WordPress/WooCommerce hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Simple product: render fields and enqueue admin assets.
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_simple_fields' ) );

		// Variations: render fields per variation and save them.
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		// Editor assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render the COGS and supplier fields for a simple product.
	 *
	 * @return void
	 */
	public function render_simple_fields(): void {
		global $post;

		$product_id = isset( $post->ID ) ? (int) $post->ID : 0;

		woocommerce_wp_text_input(
			array(
				'id'          => ProductCOGS::META_COGS,
				'value'       => ProductCOGS::get( $product_id ),
				'label'       => $this->cost_label(),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => $this->cost_tooltip(),
				'wrapper_class' => 'profitpress-cogs-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => ProductCOGS::META_SUPPLIER,
				'value'       => ProductCOGS::get_supplier( $product_id ),
				'label'       => __( 'Supplier name', 'profitpress' ),
				'desc_tip'    => true,
				'description' => __( 'Optional. Who you buy this product from.', 'profitpress' ),
				'wrapper_class' => 'profitpress-supplier-field',
			)
		);
	}

	/**
	 * Save the COGS and supplier fields for a simple product.
	 *
	 * @param WC_Product $product The product being saved.
	 * @return void
	 */
	public function save_simple_fields( WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product editor nonce before this hook.
		// On variable products the variation rows post these same keys as
		// arrays (name="_profitpress_cogs[loop]"). Those belong to the
		// per-variation save path, so only handle scalar values here.
		if ( isset( $_POST[ ProductCOGS::META_COGS ] ) && is_scalar( $_POST[ ProductCOGS::META_COGS ] ) ) {
			ProductCOGS::save( $product, wp_unslash( $_POST[ ProductCOGS::META_COGS ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitised inside ProductCOGS::save().
		}

		if ( isset( $_POST[ ProductCOGS::META_SUPPLIER ] ) && is_scalar( $_POST[ ProductCOGS::META_SUPPLIER ] ) ) {
			ProductCOGS::save_supplier( $product, wp_unslash( $_POST[ ProductCOGS::META_SUPPLIER ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitised inside ProductCOGS::save_supplier().
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Render the COGS and supplier fields for a single variation.
	 *
	 * @param int                  $loop           Position of the variation in the editor loop.
	 * @param array<string, mixed> $variation_data Variation data (unused).
	 * @param \WP_Post             $variation      The variation post object.
	 * @return void
	 */
	public function render_variation_fields( int $loop, array $variation_data, \WP_Post $variation ): void {
		unset( $variation_data );

		$variation_id = (int) $variation->ID;
		$parent_id    = (int) $variation->post_parent;

		woocommerce_wp_text_input(
			array(
				'id'            => ProductCOGS::META_COGS . '_' . $loop,
				'name'          => ProductCOGS::META_COGS . '[' . $loop . ']',
				'value'         => get_post_meta( $variation_id, ProductCOGS::META_COGS, true ),
				'label'         => $this->cost_label(),
				'data_type'     => 'price',
				'desc_tip'      => true,
				'description'   => $this->cost_tooltip(),
				'wrapper_class' => 'form-row form-row-first profitpress-cogs-field',
				'placeholder'   => ProductCOGS::get( $parent_id ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => ProductCOGS::META_SUPPLIER . '_' . $loop,
				'name'          => ProductCOGS::META_SUPPLIER . '[' . $loop . ']',
				'value'         => get_post_meta( $variation_id, ProductCOGS::META_SUPPLIER, true ),
				'label'         => __( 'Supplier name', 'profitpress' ),
				'wrapper_class' => 'form-row form-row-last profitpress-supplier-field',
			)
		);
	}

	/**
	 * Save the COGS and supplier fields for a single variation.
	 *
	 * @param int $variation_id The variation post ID.
	 * @param int $loop         Position of the variation in the editor loop.
	 * @return void
	 */
	public function save_variation_fields( int $variation_id, int $loop ): void {
		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variation save nonce before this hook.
		if ( isset( $_POST[ ProductCOGS::META_COGS ][ $loop ] ) ) {
			ProductCOGS::save( $variation, wp_unslash( $_POST[ ProductCOGS::META_COGS ][ $loop ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitised inside ProductCOGS::save().
		}

		if ( isset( $_POST[ ProductCOGS::META_SUPPLIER ][ $loop ] ) ) {
			ProductCOGS::save_supplier( $variation, wp_unslash( $_POST[ ProductCOGS::META_SUPPLIER ][ $loop ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitised inside ProductCOGS::save_supplier().
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$variation->save();
	}

	/**
	 * Enqueue the admin CSS/JS on the product editor screen.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'profitpress-admin',
			PROFITPRESS_URL . 'assets/css/admin.css',
			array(),
			PROFITPRESS_VERSION
		);

		wp_enqueue_script(
			'profitpress-admin',
			PROFITPRESS_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			PROFITPRESS_VERSION,
			true
		);
	}

	/**
	 * Build the cost field label with the store currency symbol.
	 *
	 * @return string Escaped, translated label including the currency symbol.
	 */
	private function cost_label(): string {
		/* translators: %s: store currency symbol. */
		return sprintf( __( 'Cost of Goods (per unit) (%s)', 'profitpress' ), get_woocommerce_currency_symbol() );
	}

	/**
	 * The shared help tooltip for the cost field.
	 *
	 * @return string Translated tooltip text.
	 */
	private function cost_tooltip(): string {
		return __( 'Your actual cost to acquire or produce one unit. Used to calculate profit. Changing this value does NOT affect past orders.', 'profitpress' );
	}
}
