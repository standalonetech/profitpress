<?php
/**
 * Plugin deactivation routine.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated.
 *
 * Its single responsibility is lightweight cleanup of transient runtime state.
 * It deliberately never deletes stored COGS data — that only happens on
 * uninstall, and only when the store owner has opted in.
 */
final class Deactivator {

	/**
	 * Perform deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// No scheduled events or caches to clear yet. Intentionally a no-op
		// beyond this so historical financial data is always preserved.
	}
}
