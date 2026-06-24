<?php
/**
 * Transient-backed cache for report aggregations.
 *
 * @package Profitly
 */

declare( strict_types=1 );

namespace Profitly\Reports;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Caches expensive report aggregations and invalidates them in O(1).
 *
 * Its single responsibility is the report cache contract: build namespaced keys,
 * read/write transients, and flush everything cheaply. Flushing does not delete
 * individual transients (which would be slow and risky on high-traffic stores);
 * instead it increments a stored "cache version" that is embedded in every key,
 * so all previously cached entries become unreachable at once and expire on their
 * own TTL.
 */
final class ReportCache {

	/**
	 * Option holding the monotonically increasing cache version.
	 */
	private const VERSION_OPTION = 'profitly_report_cache_version';

	/**
	 * Default time-to-live, in seconds (15 minutes).
	 */
	public const DEFAULT_TTL = 900;

	/**
	 * Register cache-invalidation hooks.
	 *
	 * Each of these order events can change a report's numbers, so we flush the
	 * whole cache rather than trying to work out which ranges are affected.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_new_order', array( self::class, 'flush' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'flush' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'flush' ) );
		add_action( 'woocommerce_update_order', array( self::class, 'flush' ) );
	}

	/**
	 * Read a cached value.
	 *
	 * @param string $key Logical cache key (will be namespaced internally).
	 * @return mixed The cached value, or false when absent.
	 */
	public static function get( string $key ) {
		return get_transient( self::build_key( $key ) );
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Logical cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	public static function set( string $key, $value, int $ttl = self::DEFAULT_TTL ): void {
		set_transient( self::build_key( $key ), $value, max( 60, $ttl ) );
	}

	/**
	 * Invalidate every cached report in O(1) by bumping the cache version.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$version = (int) get_option( self::VERSION_OPTION, 0 );
		update_option( self::VERSION_OPTION, $version + 1, false );
	}

	/**
	 * Invalidate caches affected by activity on a given date.
	 *
	 * With the versioned-key strategy there is no cheaper per-date path than a
	 * full flush, so this is an alias for {@see flush()}; it exists so callers can
	 * express intent (and so a future, more granular implementation can slot in).
	 *
	 * @param DateTimeImmutable $date The date whose reports changed (unused).
	 * @return void
	 */
	public static function flush_for_date( DateTimeImmutable $date ): void {
		unset( $date );
		self::flush();
	}

	/**
	 * Build the fully namespaced transient key.
	 *
	 * The key embeds the cache version (for O(1) invalidation) and the blog id
	 * (so multisite sub-sites never collide), then hashes the logical key to stay
	 * within the transient name length limit.
	 *
	 * @param string $key Logical cache key.
	 * @return string The transient name.
	 */
	private static function build_key( string $key ): string {
		$version = (int) get_option( self::VERSION_OPTION, 0 );
		$site_id = (int) get_current_blog_id();

		return 'pp_rpt_' . $version . '_' . $site_id . '_' . md5( $key );
	}
}
