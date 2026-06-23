<?php
/**
 * Plugin Name:       ProfitPress — Real Profit Analytics for WooCommerce
 * Plugin URI:        https://standalonetech.com/
 * Description:       Track the real profit of your WooCommerce store by capturing cost of goods (COGS) and snapshotting it onto historical orders.
 * Version:           1.0.0
 * Requires Plugins:  woocommerce
 * Author:            StandaloneTech
 * Author URI:        https://standalonetech.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       profitpress
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.4
 *
 * @package ProfitPress
 */

defined( 'ABSPATH' ) || exit;

define( 'PROFITPRESS_VERSION', '1.0.0' );
define( 'PROFITPRESS_FILE', __FILE__ );
define( 'PROFITPRESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PROFITPRESS_URL', plugin_dir_url( __FILE__ ) );

// Load the Composer autoloader.
if ( is_readable( PROFITPRESS_PATH . 'vendor/autoload.php' ) ) {
	require PROFITPRESS_PATH . 'vendor/autoload.php';
}

// Bail with an admin notice if WooCommerce is not active.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'ProfitPress requires WooCommerce to be installed and active.', 'profitpress' );
					echo '</p></div>';
				}
			);
			return;
		}

		\ProfitPress\Plugin::instance();
	},
	20
);

register_activation_hook( PROFITPRESS_FILE, array( \ProfitPress\Activator::class, 'activate' ) );
register_deactivation_hook( PROFITPRESS_FILE, array( \ProfitPress\Deactivator::class, 'deactivate' ) );
