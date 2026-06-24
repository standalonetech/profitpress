<?php
/**
 * Centralised admin asset enqueuing.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for loading Profitly's admin CSS/JS.
 *
 * Each admin surface receives only the assets it needs, keyed off the current
 * page's hook suffix. Keeping every wp_enqueue_* call here means no component
 * ever emits an inline style or script tag, which WordPress.org disallows.
 */
final class Assets {

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the assets required by the current admin page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		switch ( $hook_suffix ) {
			case 'toplevel_page_' . Menu::SLUG:
				$this->enqueue_reports();
				break;

			case Menu::SLUG . '_page_' . Menu::SETTINGS_SLUG:
				$this->enqueue_settings();
				break;

			case 'post.php':
			case 'post-new.php':
				$this->enqueue_product_editor();
				break;
		}
	}

	/**
	 * Reports page: the report stylesheet.
	 *
	 * @return void
	 */
	private function enqueue_reports(): void {
		wp_enqueue_style(
			'profitly-reports',
			PROFITLY_URL . 'assets/css/reports.css',
			array( 'woocommerce_admin_styles' ),
			$this->asset_version( 'assets/css/reports.css' )
		);
	}

	/**
	 * Settings page: the settings stylesheet plus the per-zone toggle script.
	 *
	 * @return void
	 */
	private function enqueue_settings(): void {
		wp_enqueue_style(
			'profitly-settings',
			PROFITLY_URL . 'assets/css/settings.css',
			array(),
			$this->asset_version( 'assets/css/settings.css' )
		);

		wp_enqueue_script(
			'profitly-settings',
			PROFITLY_URL . 'assets/js/settings.js',
			array(),
			$this->asset_version( 'assets/js/settings.js' ),
			true
		);
	}

	/**
	 * Product editor: the cost-of-goods field styles and script.
	 *
	 * @return void
	 */
	private function enqueue_product_editor(): void {
		wp_enqueue_style(
			'profitly-admin',
			PROFITLY_URL . 'assets/css/admin.css',
			array(),
			PROFITLY_VERSION
		);

		wp_enqueue_script(
			'profitly-admin',
			PROFITLY_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			PROFITLY_VERSION,
			true
		);
	}

	/**
	 * Build a cache-busting version string from a bundled file's mtime.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string Version string for wp_enqueue_*.
	 */
	private function asset_version( string $relative_path ): string {
		$file  = PROFITLY_PATH . $relative_path;
		$mtime = is_readable( $file ) ? (string) filemtime( $file ) : '';

		return '' !== $mtime ? PROFITLY_VERSION . '.' . $mtime : PROFITLY_VERSION;
	}
}
