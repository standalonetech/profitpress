<?php
/**
 * Plugin Name:       Profitly — Profit Analytics for WooCommerce
 * Plugin URI:        https://standalonetech.com/
 * Description:       Track the real profit of your WooCommerce store by capturing cost of goods (COGS) and snapshotting it onto historical orders.
 * Version:           1.0.1
 * Requires Plugins:  woocommerce
 * Author:            StandaloneTech
 * Author URI:        https://github.com/standalonetech/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       profitly
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.4
 *
 * @package Profitly
 */

defined( 'ABSPATH' ) || exit;

define( 'PROFITLY_VERSION', '1.0.1' );
define( 'PROFITLY_FILE', __FILE__ );
define( 'PROFITLY_PATH', plugin_dir_path( __FILE__ ) );
define( 'PROFITLY_URL', plugin_dir_url( __FILE__ ) );

// Load the Composer autoloader.
if ( is_readable( PROFITLY_PATH . 'vendor/autoload.php' ) ) {
	require PROFITLY_PATH . 'vendor/autoload.php';
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
					echo esc_html__( 'Profitly requires WooCommerce to be installed and active.', 'profitly' );
					echo '</p></div>';
				}
			);
			return;
		}

		\Profitly\Plugin::instance();
	},
	20
);

register_activation_hook( PROFITLY_FILE, array( \Profitly\Activator::class, 'activate' ) );
register_deactivation_hook( PROFITLY_FILE, array( \Profitly\Deactivator::class, 'deactivate' ) );
