<?php
/**
 * Gross margin column on the products admin list.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Admin;

use Profitly\Profit\ProductMarginCalculator;
use WC_Product;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a sortable "Gross Margin" column to the products list table.
 *
 * Its single responsibility is the product-list margin column: rendering each
 * product's gross margin (percentage plus amount) and making the column
 * sortable. Because margin is computed from price and COGS rather than stored,
 * a cached percentage is maintained on product save purely to back the sort.
 */
final class ProductListColumn {

	/**
	 * Product meta key caching the margin percent for sorting only.
	 */
	public const META_MARGIN_CACHE = '_profitly_margin_percent';

	/**
	 * Query var used to trigger sorting by margin.
	 */
	private const ORDERBY = 'profitly_margin';

	/**
	 * Register WordPress/WooCommerce hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Classic products list (edit.php?post_type=product).
		add_filter( 'manage_product_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( $this, 'register_sortable' ) );

		// WooCommerce's newer admin product list reuses the same column filters
		// where available; alias them defensively so the column also appears
		// there without assuming experimental, unstable hooks.
		add_filter( 'woocommerce_product_list_table_columns', array( $this, 'add_column' ) );

		// Sorting + cache maintenance.
		add_action( 'pre_get_posts', array( $this, 'apply_sorting' ) );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'refresh_cache' ), 10, 1 );
	}

	/**
	 * Insert the "Gross Margin" column after the price column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Columns with ours added.
	 */
	public function add_column( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'price' === $key ) {
				$new['profitly_margin'] = __( 'Gross Margin', 'profitly' );
			}
		}

		// If there was no price column, append at the end.
		if ( ! isset( $new['profitly_margin'] ) ) {
			$new['profitly_margin'] = __( 'Gross Margin', 'profitly' );
		}

		return $new;
	}

	/**
	 * Render the margin cell for a product row.
	 *
	 * @param string $column     The column key being rendered.
	 * @param int    $product_id The product id for this row.
	 * @return void
	 */
	public function render_column( string $column, int $product_id ): void {
		if ( 'profitly_margin' !== $column ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			echo '—';
			return;
		}

		$margin = ProductMarginCalculator::get_gross_margin( $product );

		if ( null === $margin ) {
			echo '—';
			return;
		}

		$percent = $margin['margin_percent'];
		$amount  = wc_price( (float) $margin['margin_amount'] );

		if ( ! empty( $margin['is_average'] ) ) {
			printf(
				'%1$s %2$s%%<br /><small>%3$s</small>',
				esc_html__( 'Avg:', 'profitly' ),
				esc_html( $percent ),
				wp_kses_post( $amount )
			);
			return;
		}

		printf(
			'%1$s%%<br /><small>%2$s</small>',
			esc_html( $percent ),
			wp_kses_post( $amount )
		);
	}

	/**
	 * Declare the column sortable.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string> Sortable columns including ours.
	 */
	public function register_sortable( array $columns ): array {
		$columns['profitly_margin'] = self::ORDERBY;

		return $columns;
	}

	/**
	 * Order the products query by the cached margin when requested.
	 *
	 * @param WP_Query $query The current query.
	 * @return void
	 */
	public function apply_sorting( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::ORDERBY !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', self::META_MARGIN_CACHE );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Refresh the cached margin percent whenever a product is saved.
	 *
	 * @param WC_Product $product The product that was saved.
	 * @return void
	 */
	public function refresh_cache( WC_Product $product ): void {
		$margin = ProductMarginCalculator::get_gross_margin( $product );
		$value  = null === $margin ? '' : $margin['margin_percent'];

		// Avoid recursion: only persist if the cached value actually changed.
		if ( (string) $product->get_meta( self::META_MARGIN_CACHE, true ) === (string) $value ) {
			return;
		}

		$product->update_meta_data( self::META_MARGIN_CACHE, $value );
		$product->save_meta_data();
	}
}
