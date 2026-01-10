<?php
/**
 * Phase 3 Catalog Domain Verification Script
 *
 * Verifies Catalog/Product domain migration:
 * - ProductSyncService (application service)
 * - CatalogBrowser (domain service)
 *
 * @package WhatsApp_Commerce_Hub
 */

// Simulate WordPress environment minimally.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
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

echo blue( "=== Phase 3: Catalog Domain Verification ===\n\n" );

$results = array();

// Test 1: ProductSyncService.
echo "Testing ProductSyncService migration...\n";
try {
	$serviceClass = 'WhatsAppCommerceHub\Application\Services\ProductSyncService';
	if ( ! class_exists( $serviceClass ) ) {
		throw new Exception( "Class $serviceClass does not exist" );
	}

	$reflection = new ReflectionClass( $serviceClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Application/Services/ProductSyncService.php' ) === false ) {
		throw new Exception( "ProductSyncService is not in correct location: $filename" );
	}

	// Check for strict types.
	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'ProductSyncService does not declare strict types' );
	}

	// Check for interface implementation.
	if ( ! $reflection->implementsInterface( 'WhatsAppCommerceHub\Contracts\Services\ProductSyncServiceInterface' ) ) {
		throw new Exception( 'ProductSyncService does not implement ProductSyncServiceInterface' );
	}

	echo green( "✓ ProductSyncService: Properly migrated to Application layer\n" );
	$results['product_sync_service'] = true;
} catch ( Exception $e ) {
	echo red( "✗ ProductSyncService: " . $e->getMessage() . "\n" );
	$results['product_sync_service'] = false;
}

// Test 2: CatalogBrowser.
echo "Testing CatalogBrowser migration...\n";
try {
	$browserClass = 'WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser';
	if ( ! class_exists( $browserClass ) ) {
		throw new Exception( "Class $browserClass does not exist" );
	}

	$reflection = new ReflectionClass( $browserClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Domain/Catalog/CatalogBrowser.php' ) === false ) {
		throw new Exception( "CatalogBrowser is not in correct location: $filename" );
	}

	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'CatalogBrowser does not declare strict types' );
	}

	// Check for key methods.
	if ( ! $reflection->hasMethod( 'showMainMenu' ) ) {
		throw new Exception( 'CatalogBrowser missing showMainMenu method' );
	}
	if ( ! $reflection->hasMethod( 'showProduct' ) ) {
		throw new Exception( 'CatalogBrowser missing showProduct method' );
	}

	echo green( "✓ CatalogBrowser: Properly migrated to Domain layer\n" );
	$results['catalog_browser'] = true;
} catch ( Exception $e ) {
	echo red( "✗ CatalogBrowser: " . $e->getMessage() . "\n" );
	$results['catalog_browser'] = false;
}

// Test 3: Legacy class mapper.
echo "\nTesting LegacyClassMapper...\n";
try {
	$mapperClass = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
	if ( ! class_exists( $mapperClass ) ) {
		throw new Exception( "Class $mapperClass does not exist" );
	}

	$mapping = $mapperClass::getMapping();

	$expectedMappings = array(
		'WCH_Product_Sync_Service' => 'WhatsAppCommerceHub\Application\Services\ProductSyncService',
		'WCH_Catalog_Browser'      => 'WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser',
	);

	foreach ( $expectedMappings as $legacy => $modern ) {
		if ( ! isset( $mapping[ $legacy ] ) ) {
			throw new Exception( "Missing mapping for $legacy" );
		}
		if ( $mapping[ $legacy ] !== $modern ) {
			throw new Exception( "Incorrect mapping for $legacy: expected $modern, got {$mapping[$legacy]}" );
		}
	}

	echo green( "✓ LegacyClassMapper: Catalog domain mappings correct\n" );
	$results['legacy_mapper'] = true;
} catch ( Exception $e ) {
	echo red( "✗ LegacyClassMapper: " . $e->getMessage() . "\n" );
	$results['legacy_mapper'] = false;
}

// Summary.
echo "\n" . blue( "=== Summary ===\n" );
$passed = count( array_filter( $results ) );
$total  = count( $results );

if ( $passed === $total ) {
	echo green( "All tests passed! ($passed/$total)\n" );
	echo green( "Catalog domain migration is complete and verified.\n" );
	exit( 0 );
} else {
	echo red( "Some tests failed. ($passed/$total passed)\n" );
	exit( 1 );
}
