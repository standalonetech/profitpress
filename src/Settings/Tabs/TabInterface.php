<?php
/**
 * Contract every settings tab implements.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Settings\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * A self-contained settings tab.
 *
 * Each tab knows how to render itself given the full settings array, and how to
 * sanitise its own slice of submitted input back into a partial settings array.
 * Tabs own neither persistence nor navigation — {@see \Profitly\Settings\SettingsPage}
 * renders the chrome and {@see \Profitly\Settings\SettingsHandler} writes the option.
 */
interface TabInterface {

	/**
	 * The tab's stable id, used in the URL and as the array key.
	 *
	 * @return string e.g. 'gateway-fees'.
	 */
	public function get_id(): string;

	/**
	 * The tab's human-readable, translated label.
	 *
	 * @return string e.g. __( 'Gateway Fees', 'profitly' ).
	 */
	public function get_label(): string;

	/**
	 * Render the tab's fields.
	 *
	 * @param array<string, mixed> $settings The full settings array.
	 * @return void
	 */
	public function render( array $settings ): void;

	/**
	 * Sanitise this tab's slice of submitted input.
	 *
	 * @param array<string, mixed> $input    Raw, unslashed submitted data.
	 * @param array<string, mixed> $existing The current full settings array.
	 * @return array<string, mixed> The sanitised slice (only the keys this tab owns).
	 */
	public function sanitize( array $input, array $existing ): array;
}
