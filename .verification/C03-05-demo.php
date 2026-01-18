<?php
/**
 * Demo script for C03-05: Container override detection
 *
 * This script demonstrates the container's ability to detect accidental
 * binding/alias overrides during provider registration in development mode.
 *
 * Run with: php .verification/C03-05-demo.php
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

// Bootstrap WordPress environment.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_DEBUG', true ); // Enable debug mode to see warnings.

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use WhatsAppCommerceHub\Container\Container;

echo "=== Container Override Detection Demo ===\n\n";

// Create a fresh container.
$container = new Container();

// Test 1: Binding override detection.
echo "Test 1: Binding override detection\n";
echo "------------------------------------\n";
echo "Registering first binding for 'test.service'...\n";
$container->bind( 'test.service', fn() => 'first implementation' );

echo "Attempting to override 'test.service' (this should trigger a warning)...\n";
$container->bind( 'test.service', fn() => 'second implementation' );
echo "\n";

// Test 2: Alias override detection.
echo "Test 2: Alias override detection\n";
echo "---------------------------------\n";
echo "Creating first alias 'my.alias' -> 'original.service'...\n";
$container->bind( 'original.service', stdClass::class );
$container->alias( 'original.service', 'my.alias' );

echo "Attempting to override 'my.alias' (this should trigger a warning)...\n";
$container->alias( 'other.service', 'my.alias' );
echo "\n";

// Test 3: Instance override detection.
echo "Test 3: Instance override detection\n";
echo "------------------------------------\n";
echo "Registering first instance for 'test.instance'...\n";
$container->instance( 'test.instance', new stdClass() );

echo "Attempting to override 'test.instance' (this should trigger a warning)...\n";
$container->instance( 'test.instance', new ArrayObject() );
echo "\n";

// Test 4: Singleton override detection.
echo "Test 4: Singleton override detection\n";
echo "-------------------------------------\n";
echo "Registering singleton for 'singleton.service'...\n";
$container->singleton( 'singleton.service', fn() => new stdClass() );

echo "Attempting to override singleton with transient binding (this should trigger a warning)...\n";
$container->bind( 'singleton.service', fn() => new ArrayObject(), false );
echo "\n";

// Test 5: Interface binding override.
echo "Test 5: Interface binding override detection\n";
echo "---------------------------------------------\n";
echo "Registering Iterator interface -> ArrayIterator...\n";
$container->bind( Iterator::class, ArrayIterator::class );

echo "Attempting to override Iterator binding (this should trigger a warning)...\n";
$container->bind( Iterator::class, EmptyIterator::class );
echo "\n";

echo "=== Demo Complete ===\n";
echo "\nNOTE: When WP_DEBUG is enabled, the container will emit E_USER_WARNING\n";
echo "for each override, helping developers catch accidental duplicate registrations\n";
echo "during service provider setup.\n";
