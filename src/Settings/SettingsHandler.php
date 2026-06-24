<?php
/**
 * Settings form submission handler.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Settings;

use Profitly\Admin\Menu;
use Profitly\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Processes the settings form posted to admin-post.php.
 *
 * The settings page posts one tab at a time. This handler verifies the request,
 * delegates sanitisation to the submitted tab, merges that single slice into the
 * stored option (leaving the other tabs' data untouched), and redirects back with
 * a success notice carried across the redirect via a short-lived transient.
 */
final class SettingsHandler {

	/**
	 * The admin-post action this handler answers to.
	 */
	public const ACTION = 'profitly_save_settings';

	/**
	 * The nonce action/name pair used by the settings form.
	 */
	private const NONCE_ACTION = 'profitly_save_settings';
	private const NONCE_NAME   = '_profitly_nonce';

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ), 10 );
	}

	/**
	 * Handle the settings form submission.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Constants::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to change Profitly settings.', 'profitly' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$tabs = SettingsRegistry::get_tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer above.
		$tab_id = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$tab    = $tabs[ $tab_id ] ?? null;

		if ( null === $tab ) {
			wp_safe_redirect( Menu::settings_url() );
			exit;
		}

		$existing = get_option( Constants::OPTION, SettingsRegistry::get_defaults() );
		$existing = is_array( $existing ) ? $existing : SettingsRegistry::get_defaults();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce verified above; the tab sanitises every field it reads.
		$slice = $tab->sanitize( wp_unslash( $_POST ), $existing );

		// Each tab owns exactly one top-level key, so a shallow replace swaps only
		// that tab's slice and leaves the other tabs' stored data intact.
		$merged             = array_replace( $existing, $slice );
		$merged['_version'] = PROFITLY_VERSION;

		update_option( Constants::OPTION, $merged );

		add_settings_error( Constants::OPTION, 'settings_updated', __( 'Settings saved.', 'profitly' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		// `settings-updated` is what makes get_settings_errors() read the transient
		// back after the redirect, so the saved notice actually renders.
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', Menu::settings_url( $tab_id ) ) );
		exit;
	}

	/**
	 * Fallback sanitiser registered with the option.
	 *
	 * Our own handler writes the option directly, so this only runs if the option
	 * is ever saved through the Settings API from elsewhere. It is a safe no-op:
	 * pass arrays through, fall back to the stored value for anything malformed.
	 *
	 * @param mixed $input Raw value being saved.
	 * @return array<string, mixed>
	 */
	public static function sanitize_callback( $input ): array {
		if ( is_array( $input ) ) {
			return $input;
		}

		$existing = get_option( Constants::OPTION, SettingsRegistry::get_defaults() );

		return is_array( $existing ) ? $existing : SettingsRegistry::get_defaults();
	}
}
