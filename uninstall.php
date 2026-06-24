<?php
/**
 * Profitly uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress admin. By default, no data
 * is removed — financial data should never silently vanish. Removal only happens
 * when the store owner has explicitly opted in via the General settings tab
 * (stored under `profitly_settings['general']['delete_on_uninstall']`).
 *
 * @package Profitly
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect the opt-in flag. Default is false: leave all data intact.
$profitly_settings = get_option( 'profitly_settings', array() );

if ( empty( $profitly_settings['general']['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Remove all Profitly product/variation meta.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_profitly\_%'"
);

// Remove all Profitly order line item meta.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key LIKE '\_profitly\_%'"
);

// Remove the plugin's own options.
delete_option( 'profitly_settings' );
delete_option( 'profitly_version' );
delete_option( 'profitly_report_cache_version' );
