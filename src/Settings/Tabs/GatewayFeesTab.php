<?php
/**
 * Gateway Fees settings tab.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Settings\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * Per-gateway transaction fee fields.
 *
 * One row per enabled payment gateway: a percentage fee, a fixed fee, and the
 * basis the percentage is applied to. These fees are deducted from revenue when
 * calculating profit.
 */
final class GatewayFeesTab implements TabInterface {

	/**
	 * Allowed values for the percentage "basis".
	 */
	private const BASES = array( 'total', 'subtotal_shipping', 'subtotal' );

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'gateway-fees';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Gateway Fees', 'profitly' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $settings Current settings array.
	 * @return void
	 */
	public function render( array $settings ): void {
		$stored   = isset( $settings['gateway_fees'] ) && is_array( $settings['gateway_fees'] ) ? $settings['gateway_fees'] : array();
		$gateways = $this->get_enabled_gateways();
		$symbol   = get_woocommerce_currency_symbol();

		echo '<p>' . esc_html__( 'Fees you enter here will be deducted from revenue when calculating profit. Changing fees affects new orders only — historical orders snapshot their fee at order creation time.', 'profitly' ) . '</p>';

		if ( empty( $gateways ) ) {
			echo '<p class="description">' . esc_html__( 'No payment gateways are enabled. Configure payment methods in WooCommerce → Settings → Payments first.', 'profitly' ) . '</p>';
			return;
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $gateways as $gateway ) {
			$id      = (string) $gateway->id;
			$percent = isset( $stored[ $id ]['percent'] ) ? (string) $stored[ $id ]['percent'] : '';
			$fixed   = isset( $stored[ $id ]['fixed'] ) ? (string) $stored[ $id ]['fixed'] : '';
			$basis   = isset( $stored[ $id ]['basis'] ) ? (string) $stored[ $id ]['basis'] : 'total';

			echo '<tr>';
			echo '<th scope="row">' . esc_html( $gateway->get_method_title() ) . '</th>';
			echo '<td>';

			printf(
				'<label>%1$s <input type="number" class="small-text" step="0.001" min="0" max="100" name="profitly_settings[gateway_fees][%2$s][percent]" value="%3$s" /> %%</label> &nbsp; ',
				esc_html__( 'Percentage', 'profitly' ),
				esc_attr( $id ),
				esc_attr( $percent )
			);

			printf(
				'<label>%1$s %2$s <input type="number" class="small-text" step="0.01" min="0" name="profitly_settings[gateway_fees][%3$s][fixed]" value="%4$s" /></label> &nbsp; ',
				esc_html__( 'Fixed', 'profitly' ),
				esc_html( $symbol ),
				esc_attr( $id ),
				esc_attr( $fixed )
			);

			printf(
				'<label>%1$s <select name="profitly_settings[gateway_fees][%2$s][basis]">',
				esc_html__( 'on', 'profitly' ),
				esc_attr( $id )
			);
			foreach ( $this->basis_options() as $value => $label ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $value ),
					selected( $basis, $value, false ),
					esc_html( $label )
				);
			}
			echo '</select></label>';

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
	 * @return array<string, mixed> The sanitised gateway-fees slice.
	 */
	public function sanitize( array $input, array $existing ): array {
		unset( $existing );

		$raw       = $input['profitly_settings']['gateway_fees'] ?? array();
		$sanitized = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $gateway_id => $values ) {
				if ( ! is_array( $values ) ) {
					continue;
				}

				$gateway_id = sanitize_key( (string) $gateway_id );

				$sanitized[ $gateway_id ] = array(
					'percent' => $this->sanitize_percent( $values['percent'] ?? '' ),
					'fixed'   => $this->sanitize_fixed( $values['fixed'] ?? '' ),
					'basis'   => $this->sanitize_basis( $values['basis'] ?? 'total' ),
				);
			}
		}

		return array( 'gateway_fees' => $sanitized );
	}

	/**
	 * Get every enabled payment gateway.
	 *
	 * @return array<int, \WC_Payment_Gateway> Enabled gateway objects.
	 */
	private function get_enabled_gateways(): array {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return array();
		}

		$enabled = array();

		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( isset( $gateway->enabled ) && 'yes' === $gateway->enabled ) {
				$enabled[] = $gateway;
			}
		}

		return $enabled;
	}

	/**
	 * Labels for the basis dropdown.
	 *
	 * @return array<string, string> Basis value => label.
	 */
	private function basis_options(): array {
		return array(
			'total'             => __( 'Order total (incl. tax & shipping)', 'profitly' ),
			'subtotal_shipping' => __( 'Subtotal + shipping (excl. tax)', 'profitly' ),
			'subtotal'          => __( 'Product subtotal only', 'profitly' ),
		);
	}

	/**
	 * Sanitise a percentage value to 0-100 with up to 3 decimals.
	 *
	 * @param mixed $value Raw value.
	 * @return string Clean decimal string.
	 */
	private function sanitize_percent( $value ): string {
		$clean = wc_format_decimal( is_scalar( $value ) ? (string) $value : '', 3, true );

		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return '0';
		}

		$number = max( 0.0, min( 100.0, (float) $clean ) );

		return wc_format_decimal( (string) $number, 3 );
	}

	/**
	 * Sanitise a fixed fee value to a non-negative 2-decimal string, clamped 0-999999.
	 *
	 * @param mixed $value Raw value.
	 * @return string Clean decimal string.
	 */
	private function sanitize_fixed( $value ): string {
		$clean = wc_format_decimal( is_scalar( $value ) ? (string) $value : '', 2, true );

		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return '0';
		}

		$number = max( 0.0, min( 999999.0, (float) $clean ) );

		return wc_format_decimal( (string) $number, 2 );
	}

	/**
	 * Sanitise the basis selection against the allowed list.
	 *
	 * @param mixed $value Raw value.
	 * @return string A valid basis key.
	 */
	private function sanitize_basis( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : 'total';

		return in_array( $value, self::BASES, true ) ? $value : 'total';
	}
}
