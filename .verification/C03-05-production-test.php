<?php
/**
 * Verification that override detection is disabled in production
 *
 * This script verifies that override detection does NOT trigger
 * when WP_DEBUG is disabled (production mode).
 *
 * Run with: php .verification/C03-05-production-test.php
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

// Bootstrap WordPress environment.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_DEBUG', false ); // Disable debug mode (production).

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use WhatsAppCommerceHub\Container\Container;

echo "=== Container Production Mode Test (WP_DEBUG = false) ===\n\n";

// Track if any warnings occurred.
$warning_count = 0;
set_error_handler(
	function ( $errno, $errstr ) use ( &$warning_count ) {
		if ( $errno === E_USER_WARNING ) {
			$warning_count++;
			echo "WARNING DETECTED: $errstr\n";
		}
		return true;
	}
);

// Create a fresh container.
$container = new Container();

echo "Test: Override bindings (should NOT trigger warnings in production)\n";
echo "--------------------------------------------------------------------\n";
$container->bind( 'service', fn() => 'first' );
echo "Registered first binding\n";
$container->bind( 'service', fn() => 'second' );
echo "Registered second binding (override)\n";
echo "Warnings triggered: $warning_count\n\n";

echo "Test: Override aliases (should NOT trigger warnings in production)\n";
echo "-------------------------------------------------------------------\n";
$container->alias( 'service', 'alias' );
echo "Registered first alias\n";
$container->alias( 'other', 'alias' );
echo "Registered second alias (override)\n";
echo "Warnings triggered: $warning_count\n\n";

echo "Test: Override instances (should NOT trigger warnings in production)\n";
echo "---------------------------------------------------------------------\n";
$container->instance( 'instance', new stdClass() );
echo "Registered first instance\n";
$container->instance( 'instance', new ArrayObject() );
echo "Registered second instance (override)\n";
echo "Warnings triggered: $warning_count\n\n";

restore_error_handler();

echo "=== Test Complete ===\n\n";

if ( $warning_count === 0 ) {
	echo "✅ SUCCESS: No warnings triggered in production mode\n";
	echo "Override detection is correctly disabled when WP_DEBUG = false\n";
	exit( 0 );
} else {
	echo "❌ FAILURE: {$warning_count} warnings were triggered in production mode\n";
	echo "Override detection should be disabled when WP_DEBUG = false\n";
	exit( 1 );
}
