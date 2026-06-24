<?php
/**
 * Main plugin container.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly;

use Profitly\Admin\Assets;
use Profitly\Admin\Menu;
use Profitly\Admin\OrderProfitMetaBox;
use Profitly\Admin\ProductFields;
use Profitly\Admin\ProductListColumn;
use Profitly\COGS\OrderLineCOGS;
use Profitly\COGS\ProductCOGS;
use Profitly\Compatibility\HPOS;
use Profitly\Dashboard\DashboardWidget;
use Profitly\Export\CsvExporter;
use Profitly\Profit\OrderSnapshot;
use Profitly\Reports\ReportCache;
use Profitly\Settings\SettingsHandler;
use Profitly\Settings\SettingsRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton responsible for wiring the plugin's components together.
 *
 * Its single responsibility is bootstrapping: it instantiates each feature
 * class and asks it to register its own hooks. It contains no feature logic
 * itself.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Retrieve (and lazily create) the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to enforce the singleton.
	 */
	private function __construct() {}

	/**
	 * Instantiate components and register their hooks.
	 *
	 * @return void
	 */
	private function boot(): void {
		( new HPOS() )->register_hooks();
		( new ProductCOGS() )->register_hooks();
		( new OrderLineCOGS() )->register_hooks();
		( new ProductFields() )->register_hooks();

		// Profit calculation layer.
		( new OrderSnapshot() )->register_hooks();
		( new OrderProfitMetaBox() )->register_hooks();
		( new ProductListColumn() )->register_hooks();

		// Admin menu + settings.
		( new Menu() )->register_hooks();
		( new Assets() )->register_hooks();
		( new SettingsRegistry() )->register_hooks();
		( new SettingsHandler() )->register_hooks();

		// Reporting layer.
		( new ReportCache() )->register_hooks();
		( new DashboardWidget() )->register_hooks();
		( new CsvExporter() )->register_hooks();

		// Translations load automatically on WordPress.org (since WP 4.6), and
		// this plugin requires WP 6.4+, so no manual load_plugin_textdomain() call
		// is needed.
	}
}
