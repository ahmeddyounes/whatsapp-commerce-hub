<?php
/**
 * Phase 3 Cart Domain Verification Script
 *
 * Verifies Cart domain migration:
 * - Cart (entity)
 * - CartService (domain service)
 * - CartException (domain exception)
 *
 * @package WhatsApp_Commerce_Hub
 */

// Simulate WordPress environment minimally.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WCH_PLUGIN_DIR' ) ) {
	define( 'WCH_PLUGIN_DIR', __DIR__ . '/' );
}

// Load Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Mock WordPress functions.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

// Colors for terminal output.
function green( $text ) {
	return "\033[32m" . $text . "\033[0m";
}

function red( $text ) {
	return "\033[31m" . $text . "\033[0m";
}

function blue( $text ) {
	return "\033[34m" . $text . "\033[0m";
}

echo blue( "=== Phase 3: Cart Domain Verification ===\n\n" );

$results = array();

// Test 1: Cart entity.
echo "Testing Cart entity migration...\n";
try {
	$cartClass = 'WhatsAppCommerceHub\Domain\Cart\Cart';
	if ( ! class_exists( $cartClass ) ) {
		throw new Exception( "Class $cartClass does not exist" );
	}

	$reflection = new ReflectionClass( $cartClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Domain/Cart/Cart.php' ) === false ) {
		throw new Exception( "Cart is not in correct location: $filename" );
	}

	// Check for strict types.
	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'Cart does not declare strict types' );
	}

	// Check for readonly properties (PHP 8.1+).
	if ( strpos( $contents, 'public readonly' ) === false ) {
		throw new Exception( 'Cart does not use readonly properties' );
	}

	// Check for constants.
	if ( ! $reflection->hasConstant( 'STATUS_ACTIVE' ) ) {
		throw new Exception( 'Cart missing STATUS_ACTIVE constant' );
	}

	echo green( "✓ Cart: Properly migrated to Domain layer\n" );
	$results['cart'] = true;
} catch ( Exception $e ) {
	echo red( "✗ Cart: " . $e->getMessage() . "\n" );
	$results['cart'] = false;
}

// Test 2: CartException.
echo "Testing CartException migration...\n";
try {
	$exceptionClass = 'WhatsAppCommerceHub\Domain\Cart\CartException';
	if ( ! class_exists( $exceptionClass ) ) {
		throw new Exception( "Class $exceptionClass does not exist" );
	}

	$reflection = new ReflectionClass( $exceptionClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Domain/Cart/CartException.php' ) === false ) {
		throw new Exception( "CartException is not in correct location: $filename" );
	}

	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'CartException does not declare strict types' );
	}

	// Check it extends base exception.
	if ( ! $reflection->getParentClass() ) {
		throw new Exception( 'CartException does not extend a parent class' );
	}

	echo green( "✓ CartException: Properly migrated to Domain layer\n" );
	$results['cart_exception'] = true;
} catch ( Exception $e ) {
	echo red( "✗ CartException: " . $e->getMessage() . "\n" );
	$results['cart_exception'] = false;
}

// Test 3: CartService.
echo "Testing CartService migration...\n";
try {
	$serviceClass = 'WhatsAppCommerceHub\Domain\Cart\CartService';
	if ( ! class_exists( $serviceClass ) ) {
		throw new Exception( "Class $serviceClass does not exist" );
	}

	$reflection = new ReflectionClass( $serviceClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Domain/Cart/CartService.php' ) === false ) {
		throw new Exception( "CartService is not in correct location: $filename" );
	}

	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'CartService does not declare strict types' );
	}

	// Check for interface implementation.
	if ( ! $reflection->implementsInterface( 'WhatsAppCommerceHub\Contracts\Services\CartServiceInterface' ) ) {
		throw new Exception( 'CartService does not implement CartServiceInterface' );
	}

	// Check for key methods.
	if ( ! $reflection->hasMethod( 'getCart' ) ) {
		throw new Exception( 'CartService missing getCart method' );
	}
	if ( ! $reflection->hasMethod( 'addItem' ) ) {
		throw new Exception( 'CartService missing addItem method' );
	}

	echo green( "✓ CartService: Properly migrated to Domain layer\n" );
	$results['cart_service'] = true;
} catch ( Exception $e ) {
	echo red( "✗ CartService: " . $e->getMessage() . "\n" );
	$results['cart_service'] = false;
}

// Test 4: Legacy class mapper.
echo "\nTesting LegacyClassMapper...\n";
try {
	$mapperClass = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
	if ( ! class_exists( $mapperClass ) ) {
		throw new Exception( "Class $mapperClass does not exist" );
	}

	$mapping = $mapperClass::getMapping();

	$expectedMappings = array(
		'WCH_Cart_Manager'    => 'WhatsAppCommerceHub\Domain\Cart\CartService',
		'WCH_Cart_Exception'  => 'WhatsAppCommerceHub\Domain\Cart\CartException',
	);

	foreach ( $expectedMappings as $legacy => $modern ) {
		if ( ! isset( $mapping[ $legacy ] ) ) {
			throw new Exception( "Missing mapping for $legacy" );
		}
		if ( $mapping[ $legacy ] !== $modern ) {
			throw new Exception( "Incorrect mapping for $legacy: expected $modern, got {$mapping[$legacy]}" );
		}
	}

	echo green( "✓ LegacyClassMapper: Cart domain mappings correct\n" );
	$results['legacy_mapper'] = true;
} catch ( Exception $e ) {
	echo red( "✗ LegacyClassMapper: " . $e->getMessage() . "\n" );
	$results['legacy_mapper'] = false;
}

// Test 5: References updated.
echo "\nTesting namespace references...\n";
try {
	// Check that contracts use new namespace.
	$interfaceFile = __DIR__ . '/includes/Contracts/Services/CartServiceInterface.php';
	$contents      = file_get_contents( $interfaceFile );
	
	if ( strpos( $contents, 'use WhatsAppCommerceHub\Domain\Cart\Cart;' ) === false ) {
		throw new Exception( 'CartServiceInterface not updated to use Domain\Cart\Cart' );
	}

	echo green( "✓ References: Namespace imports updated correctly\n" );
	$results['references'] = true;
} catch ( Exception $e ) {
	echo red( "✗ References: " . $e->getMessage() . "\n" );
	$results['references'] = false;
}

// Summary.
echo "\n" . blue( "=== Summary ===\n" );
$passed = count( array_filter( $results ) );
$total  = count( $results );

if ( $passed === $total ) {
	echo green( "All tests passed! ($passed/$total)\n" );
	echo green( "Cart domain migration is complete and verified.\n" );
	exit( 0 );
} else {
	echo red( "Some tests failed. ($passed/$total passed)\n" );
	exit( 1 );
}
