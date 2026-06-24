<?php
/**
 * Profitly settings screen renderer.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Settings;

use Profitly\Admin\Menu;
use Profitly\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the tabbed settings screen.
 *
 * It draws the chrome — page title, notices, tab nav, and the form that posts to
 * admin-post.php — then delegates the field markup to the active tab. Saving is
 * handled separately by {@see SettingsHandler}; the option contract lives in
 * {@see SettingsRegistry}. The form posts only the current tab's fields, so each
 * tab saves independently without disturbing the others.
 */
final class SettingsPage {

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Constants::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to change Profitly settings.', 'profitly' ) );
		}

		$tabs = SettingsRegistry::get_tabs();

		if ( empty( $tabs ) ) {
			return;
		}

		$default = (string) array_key_first( $tabs );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection, no state change.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( ! isset( $tabs[ $current ] ) ) {
			$current = $default;
		}

		$active_tab = $tabs[ $current ];

		echo '<div class="wrap profitly-settings">';
		echo '<h1>' . esc_html__( 'Profitly Settings', 'profitly' ) . '</h1>';

		settings_errors( Constants::OPTION );

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $tab ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( Menu::settings_url( $id ) ),
				$id === $current ? ' nav-tab-active' : '',
				esc_html( $tab->get_label() )
			);
		}
		echo '</nav>';

		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( SettingsHandler::ACTION ) );
		printf( '<input type="hidden" name="tab" value="%s" />', esc_attr( $current ) );
		wp_nonce_field( 'profitly_save_settings', '_profitly_nonce' );

		$active_tab->render( SettingsRegistry::get_settings() );

		submit_button();

		echo '</form>';
		echo '</div>';
	}
}
