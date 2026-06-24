<?php
/**
 * Shipping Costs settings tab.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Settings\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping cost model selector plus per-zone merchant-side cost estimates.
 *
 * The model decides how Profitly accounts for shipping in profit: estimate
 * per zone, use what the customer paid, or treat it as zero. The per-zone fields
 * only apply to the carrier-estimate model; they stay editable but are visually
 * greyed when another model is active, so switching never loses configuration.
 */
final class ShippingCostsTab implements TabInterface {

	/**
	 * Allowed shipping cost models.
	 */
	private const MODELS = array( 'carrier_estimate', 'customer_paid', 'included' );

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'shipping-costs';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Shipping Costs', 'profitly' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $settings Current settings array.
	 * @return void
	 */
	public function render( array $settings ): void {
		$shipping = isset( $settings['shipping'] ) && is_array( $settings['shipping'] ) ? $settings['shipping'] : array();
		$model    = $this->resolve_model( $shipping );
		$zones    = isset( $shipping['zones'] ) && is_array( $shipping['zones'] ) ? $shipping['zones'] : array();
		$symbol   = get_woocommerce_currency_symbol();

		echo '<p>' . esc_html__( 'Choose how Profitly accounts for shipping costs when calculating profit. Used as a fallback when no per-order shipping cost is set.', 'profitly' ) . '</p>';

		// Model selector.
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Shipping cost model', 'profitly' ) . '</th>';
		echo '<td><fieldset id="profitly-shipping-model">';

		foreach ( $this->model_options() as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="radio" name="profitly_settings[shipping][model]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $value ),
				checked( $model, $value, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset></td></tr></tbody></table>';

		// Per-zone estimates.
		$disabled_class = 'carrier_estimate' === $model ? '' : ' is-disabled';

		echo '<h2>' . esc_html__( 'Estimated cost per order, by zone', 'profitly' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Your average cost to fulfil one order shipped to each zone — what YOU pay the carrier, not what the customer pays. Applies only to the per-zone estimate model.', 'profitly' ) . '</p>';
		echo '<table class="form-table profitly-zone-costs' . esc_attr( $disabled_class ) . '" role="presentation" id="profitly-zone-costs"><tbody>';

		foreach ( $this->get_zones() as $zone_id => $zone_name ) {
			$value = isset( $zones[ (string) $zone_id ] ) ? (string) $zones[ (string) $zone_id ] : '';

			echo '<tr>';
			echo '<th scope="row">' . esc_html( $zone_name ) . '</th>';
			echo '<td>';
			printf(
				'%1$s <input type="number" class="regular-text" step="0.01" min="0" name="profitly_settings[shipping][zones][%2$s]" value="%3$s" placeholder="0.00" />',
				esc_html( $symbol ),
				esc_attr( (string) $zone_id ),
				esc_attr( $value )
			);
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input    Raw, unslashed submitted data.
	 * @param array<string, mixed> $existing Current full settings array.
	 * @return array<string, mixed> The sanitised shipping slice.
	 */
	public function sanitize( array $input, array $existing ): array {
		unset( $existing );

		$raw = $input['profitly_settings']['shipping'] ?? array();
		$raw = is_array( $raw ) ? $raw : array();

		$model = isset( $raw['model'] ) ? (string) $raw['model'] : '';
		$model = in_array( $model, self::MODELS, true ) ? $model : 'carrier_estimate';

		$zones    = array();
		$raw_zone = isset( $raw['zones'] ) && is_array( $raw['zones'] ) ? $raw['zones'] : array();

		foreach ( $raw_zone as $zone_id => $value ) {
			$zone_id           = (string) absint( $zone_id );
			$zones[ $zone_id ] = $this->sanitize_cost( is_scalar( $value ) ? (string) $value : '' );
		}

		return array(
			'shipping' => array(
				'model' => $model,
				'zones' => $zones,
			),
		);
	}

	/**
	 * Resolve the stored model, falling back to the default.
	 *
	 * @param array<string, mixed> $shipping The shipping settings slice.
	 * @return string A valid model slug.
	 */
	private function resolve_model( array $shipping ): string {
		$model = isset( $shipping['model'] ) ? (string) $shipping['model'] : '';

		return in_array( $model, self::MODELS, true ) ? $model : 'carrier_estimate';
	}

	/**
	 * Labels for the model radio options.
	 *
	 * @return array<string, string> Model slug => label.
	 */
	private function model_options(): array {
		return array(
			'carrier_estimate' => __( 'I pay carriers separately — estimate per zone below', 'profitly' ),
			'customer_paid'    => __( 'My shipping charges equal my shipping costs — use what the customer paid', 'profitly' ),
			'included'         => __( 'Shipping is included in product cost or not applicable — use zero', 'profitly' ),
		);
	}

	/**
	 * Build a map of zone id => display name, including Rest of the World (id 0).
	 *
	 * @return array<int, string> Zone id => name.
	 */
	private function get_zones(): array {
		$zones = array();

		if ( class_exists( '\WC_Shipping_Zones' ) ) {
			foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
				$zone_id           = (int) $zone['id'];
				$zones[ $zone_id ] = (string) $zone['zone_name'];
			}
		}

		$zones[0] = __( 'Rest of the World', 'profitly' );

		ksort( $zones );

		return $zones;
	}

	/**
	 * Sanitise a shipping cost to a non-negative 2-decimal string.
	 *
	 * @param string $value Raw value.
	 * @return string Clean decimal string.
	 */
	private function sanitize_cost( string $value ): string {
		$clean = wc_format_decimal( $value, 2, true );

		if ( '' === $clean || ! is_numeric( $clean ) || (float) $clean < 0 ) {
			return '0';
		}

		return $clean;
	}
}
