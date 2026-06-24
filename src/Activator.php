<?php
/**
 * Plugin activation routine.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 *
 * Its single responsibility is to record the installed version. The uninstall
 * data-retention choice lives inside the single `profitly_settings` option
 * (see SettingsRegistry::get_defaults()), so there is nothing else to seed here.
 */
final class Activator {

	/**
	 * Perform activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		update_option( 'profitly_version', PROFITLY_VERSION );
	}
}
