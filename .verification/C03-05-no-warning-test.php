<?php
/**
 * Verification that normal operations don't trigger warnings
 *
 * This script verifies that the override detection only triggers
 * when actual overrides occur, not during normal operations.
 *
 * Run with: php .verification/C03-05-no-warning-test.php
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

// Bootstrap WordPress environment.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_DEBUG', true ); // Enable debug mode.

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use WhatsAppCommerceHub\Container\Container;

echo "=== Container Normal Operations Test ===\n\n";

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

echo "Test 1: First-time bindings (should NOT trigger warnings)\n";
echo "-----------------------------------------------------------\n";
$container->bind( 'service.one', fn() => 'first' );
$container->bind( 'service.two', fn() => 'second' );
$container->singleton( 'service.three', fn() => 'third' );
echo "Registered 3 unique bindings\n";
echo "Warnings triggered: $warning_count\n\n";

echo "Test 2: First-time aliases (should NOT trigger warnings)\n";
echo "---------------------------------------------------------\n";
$container->alias( 'service.one', 'alias.one' );
$container->alias( 'service.two', 'alias.two' );
echo "Registered 2 unique aliases\n";
echo "Warnings triggered: $warning_count\n\n";

echo "Test 3: First-time instances (should NOT trigger warnings)\n";
echo "-----------------------------------------------------------\n";
$container->instance( 'instance.one', new stdClass() );
$container->instance( 'instance.two', new ArrayObject() );
echo "Registered 2 unique instances\n";
echo "Warnings triggered: $warning_count\n\n";

echo "Test 4: Resolving services (should NOT trigger warnings)\n";
echo "---------------------------------------------------------\n";
$container->get( 'service.one' );
$container->get( 'service.two' );
$container->get( 'alias.one' );
echo "Resolved 3 services\n";
echo "Warnings triggered: $warning_count\n\n";

restore_error_handler();

echo "=== Test Complete ===\n\n";

if ( $warning_count === 0 ) {
	echo "✅ SUCCESS: No warnings triggered during normal operations\n";
	exit( 0 );
} else {
	echo "❌ FAILURE: {$warning_count} unexpected warnings were triggered\n";
	exit( 1 );
}
