<?php
/**
 * Settings option registration, defaults, tab registry, and read accessors.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Settings;

use Profitly\Constants;
use Profitly\Settings\Tabs\GatewayFeesTab;
use Profitly\Settings\Tabs\GeneralTab;
use Profitly\Settings\Tabs\ShippingCostsTab;
use Profitly\Settings\Tabs\TabInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for the `profitly_settings` option.
 *
 * It registers the option with WordPress, owns the default structure and the
 * list of tabs, and exposes typed read accessors so every consumer (snapshot,
 * resolver, aggregator) reads settings through one place rather than poking at
 * the raw option array. There is exactly one stored option; this class defines
 * its shape.
 */
final class SettingsRegistry {

	/**
	 * Settings schema version stored under the `_version` key.
	 */
	public const VERSION = '1.0.1';

	/**
	 * The registered tab classes, in display order.
	 *
	 * @var array<int, class-string<TabInterface>>
	 */
	private const TAB_CLASSES = array(
		GatewayFeesTab::class,
		ShippingCostsTab::class,
		GeneralTab::class,
	);

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register' ), 10 );
	}

	/**
	 * Register the single settings option with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			Constants::OPTION_GROUP,
			Constants::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( SettingsHandler::class, 'sanitize_callback' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Instantiate every registered tab, keyed by its id.
	 *
	 * @return array<string, TabInterface> Tab id => tab instance.
	 */
	public static function get_tabs(): array {
		$tabs = array();

		foreach ( self::TAB_CLASSES as $class ) {
			$tab                    = new $class();
			$tabs[ $tab->get_id() ] = $tab;
		}

		return $tabs;
	}

	/**
	 * Get a single tab by id.
	 *
	 * @param string $id Tab id.
	 * @return TabInterface|null The tab, or null when unknown.
	 */
	public static function get_tab( string $id ): ?TabInterface {
		return self::get_tabs()[ $id ] ?? null;
	}

	/**
	 * The default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'_version'     => self::VERSION,
			'gateway_fees' => array(),
			'shipping'     => array(
				'model' => 'carrier_estimate',
				'zones' => array(),
			),
			'general'      => array(
				'delete_on_uninstall' => false,
			),
		);
	}

	/**
	 * Read the full settings array, with defaults applied.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$stored = get_option( Constants::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_replace_recursive( self::get_defaults(), $stored );
	}

	/**
	 * Get the configured fee for a single gateway.
	 *
	 * @param string $gateway_id Payment gateway id.
	 * @return array{percent: string, fixed: string, basis: string} Fee config.
	 */
	public static function get_gateway_fee( string $gateway_id ): array {
		$settings = self::get_settings();
		$fee      = $settings['gateway_fees'][ $gateway_id ] ?? array();

		return array(
			'percent' => isset( $fee['percent'] ) ? (string) $fee['percent'] : '0',
			'fixed'   => isset( $fee['fixed'] ) ? (string) $fee['fixed'] : '0',
			'basis'   => isset( $fee['basis'] ) ? (string) $fee['basis'] : 'total',
		);
	}

	/**
	 * Get the configured shipping cost estimate for a zone.
	 *
	 * @param int $zone_id Shipping zone id (0 = Rest of the World).
	 * @return string The estimated cost as a decimal string; '0' when unset.
	 */
	public static function get_shipping_cost( int $zone_id ): string {
		$settings = self::get_settings();
		$value    = $settings['shipping']['zones'][ (string) $zone_id ] ?? '0';

		return '' === (string) $value ? '0' : (string) $value;
	}

	/**
	 * Get the configured shipping cost model.
	 *
	 * @return string One of 'carrier_estimate', 'customer_paid', 'included'.
	 */
	public static function get_shipping_cost_model(): string {
		$settings = self::get_settings();
		$model    = isset( $settings['shipping']['model'] ) ? (string) $settings['shipping']['model'] : '';
		$valid    = array( 'carrier_estimate', 'customer_paid', 'included' );

		return in_array( $model, $valid, true ) ? $model : 'carrier_estimate';
	}
}
