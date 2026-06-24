<?php
/**
 * Top-level Profitly admin menu.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Admin;

use Profitly\Constants;
use Profitly\Reports\ReportsPage;
use Profitly\Settings\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for Profitly admin navigation.
 *
 * It registers the top-level "Profitly" menu and its Reports and Settings
 * sub-pages, and exposes the canonical URL helpers every other component uses to
 * link to those pages. No other code should construct these URLs by hand.
 */
final class Menu {

	/**
	 * Menu slug of the top-level page (also the Reports sub-page slug).
	 */
	public const SLUG = 'profitly';

	/**
	 * Menu slug of the Settings sub-page.
	 */
	public const SETTINGS_SLUG = 'profitly-settings';

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 10 );
	}

	/**
	 * Register the top-level menu and its sub-pages.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Profitly', 'profitly' ),
			__( 'Profitly', 'profitly' ),
			Constants::CAP_VIEW_REPORTS,
			self::SLUG,
			static function (): void {
				( new ReportsPage() )->render();
			},
			'dashicons-chart-area',
			56
		);

		// Rename the auto-created first sub-item from "Profitly" to "Reports"
		// by re-registering it against the same slug as the parent. The callback
		// is intentionally empty: this submenu shares the parent's page hook
		// (toplevel_page_profitly), and the parent's callback already renders
		// it. Passing a second (distinct) callback here would hook the same page
		// twice and render the report — stats and all — twice.
		add_submenu_page(
			self::SLUG,
			__( 'Reports', 'profitly' ),
			__( 'Reports', 'profitly' ),
			Constants::CAP_VIEW_REPORTS,
			self::SLUG,
			''
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'profitly' ),
			__( 'Settings', 'profitly' ),
			Constants::CAP_MANAGE,
			self::SETTINGS_SLUG,
			array( SettingsPage::class, 'render' )
		);
	}

	/**
	 * Canonical URL of the Reports page.
	 *
	 * @return string
	 */
	public static function reports_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Canonical URL of the Settings page, optionally for a specific tab.
	 *
	 * @param string $tab Optional tab id.
	 * @return string
	 */
	public static function settings_url( string $tab = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		if ( '' !== $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}

		return $url;
	}
}
