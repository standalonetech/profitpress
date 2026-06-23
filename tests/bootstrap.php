<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ProfitPress
 */

declare( strict_types=1 );

// Every class in src/ guards itself with `defined( 'ABSPATH' ) || exit;`.
// Define ABSPATH before the autoloader can load any class under test, otherwise
// requiring a source file would terminate the test run via exit().
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Composer autoloader not found. Run `composer install` first.\n" );
	exit( 1 );
}

require $autoload;
