<?php
/**
 * Phase 3 Progress Verification Script
 *
 * Verifies all Phase 3 domain migrations completed so far:
 * - Cart Domain (2 classes)
 * - Catalog Domain (2 classes)
 * - Order Domain (1 class)
 * - Customer Domain (2 classes)
 *
 * @package WhatsApp_Commerce_Hub
 */

// Simulate WordPress environment.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/vendor/autoload.php';

// Mock WordPress functions.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

function green( $text ) {
	return "\033[32m" . $text . "\033[0m";
}

function red( $text ) {
	return "\033[31m" . $text . "\033[0m";
}

function blue( $text ) {
	return "\033[34m" . $text . "\033[0m";
}

function yellow( $text ) {
	return "\033[33m" . $text . "\033[0m";
}

echo blue( "╔══════════════════════════════════════════════════════════════════════════════╗\n" );
echo blue( "║           PHASE 3 DOMAIN LAYER - COMPREHENSIVE VERIFICATION                 ║\n" );
echo blue( "╚══════════════════════════════════════════════════════════════════════════════╝\n\n" );

$results = [];

// Cart Domain
echo yellow( "=== CART DOMAIN ===\n" );

$cartClasses = [
	'Cart'          => 'WhatsAppCommerceHub\Domain\Cart\Cart',
	'CartException' => 'WhatsAppCommerceHub\Domain\Cart\CartException',
	'CartService'   => 'WhatsAppCommerceHub\Domain\Cart\CartService',
];

foreach ( $cartClasses as $name => $class ) {
	echo "Testing $name... ";
	try {
		if ( ! class_exists( $class ) ) {
			throw new Exception( "Class not found" );
		}
		$reflection = new ReflectionClass( $class );
		$contents   = file_get_contents( $reflection->getFileName() );
		if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
			throw new Exception( 'Missing strict types' );
		}
		echo green( "✓\n" );
		$results[ $name ] = true;
	} catch ( Exception $e ) {
		echo red( "✗ " . $e->getMessage() . "\n" );
		$results[ $name ] = false;
	}
}

// Catalog Domain
echo "\n" . yellow( "=== CATALOG DOMAIN ===\n" );

$catalogClasses = [
	'ProductSyncService' => 'WhatsAppCommerceHub\Application\Services\ProductSyncService',
	'CatalogBrowser'     => 'WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser',
];

foreach ( $catalogClasses as $name => $class ) {
	echo "Testing $name... ";
	try {
		if ( ! class_exists( $class ) ) {
			throw new Exception( "Class not found" );
		}
		$reflection = new ReflectionClass( $class );
		$contents   = file_get_contents( $reflection->getFileName() );
		if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
			throw new Exception( 'Missing strict types' );
		}
		echo green( "✓\n" );
		$results[ $name ] = true;
	} catch ( Exception $e ) {
		echo red( "✗ " . $e->getMessage() . "\n" );
		$results[ $name ] = false;
	}
}

// Order Domain
echo "\n" . yellow( "=== ORDER DOMAIN ===\n" );

$orderClasses = [
	'OrderSyncService' => 'WhatsAppCommerceHub\Application\Services\OrderSyncService',
];

foreach ( $orderClasses as $name => $class ) {
	echo "Testing $name... ";
	try {
		if ( ! class_exists( $class ) ) {
			throw new Exception( "Class not found" );
		}
		$reflection = new ReflectionClass( $class );
		$contents   = file_get_contents( $reflection->getFileName() );
		if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
			throw new Exception( 'Missing strict types' );
		}
		echo green( "✓\n" );
		$results[ $name ] = true;
	} catch ( Exception $e ) {
		echo red( "✗ " . $e->getMessage() . "\n" );
		$results[ $name ] = false;
	}
}

// Customer Domain
echo "\n" . yellow( "=== CUSTOMER DOMAIN ===\n" );

$customerClasses = [
	'Customer'        => 'WhatsAppCommerceHub\Domain\Customer\Customer',
	'CustomerService' => 'WhatsAppCommerceHub\Domain\Customer\CustomerService',
];

foreach ( $customerClasses as $name => $class ) {
	echo "Testing $name... ";
	try {
		if ( ! class_exists( $class ) ) {
			throw new Exception( "Class not found" );
		}
		$reflection = new ReflectionClass( $class );
		$contents   = file_get_contents( $reflection->getFileName() );
		if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
			throw new Exception( 'Missing strict types' );
		}
		echo green( "✓\n" );
		$results[ $name ] = true;
	} catch ( Exception $e ) {
		echo red( "✗ " . $e->getMessage() . "\n" );
		$results[ $name ] = false;
	}
}

// Legacy Class Mapper
echo "\n" . yellow( "=== LEGACY CLASS MAPPER ===\n" );
echo "Testing mappings... ";

try {
	$mapper  = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
	$mapping = $mapper::getMapping();

	$expectedMappings = [
		'WCH_Cart_Manager'         => 'WhatsAppCommerceHub\Domain\Cart\CartService',
		'WCH_Cart_Exception'       => 'WhatsAppCommerceHub\Domain\Cart\CartException',
		'WCH_Product_Sync_Service' => 'WhatsAppCommerceHub\Application\Services\ProductSyncService',
		'WCH_Catalog_Browser'      => 'WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser',
		'WCH_Order_Sync_Service'   => 'WhatsAppCommerceHub\Application\Services\OrderSyncService',
		'WCH_Customer_Service'     => 'WhatsAppCommerceHub\Domain\Customer\CustomerService',
		'WCH_Customer_Profile'     => 'WhatsAppCommerceHub\Domain\Customer\CustomerProfile',
	];

	foreach ( $expectedMappings as $legacy => $modern ) {
		if ( ! isset( $mapping[ $legacy ] ) || $mapping[ $legacy ] !== $modern ) {
			throw new Exception( "Mapping error for $legacy" );
		}
	}
	
	echo green( "✓\n" );
	$results['LegacyMapper'] = true;
} catch ( Exception $e ) {
	echo red( "✗ " . $e->getMessage() . "\n" );
	$results['LegacyMapper'] = false;
}

// Summary
echo "\n" . blue( "╔══════════════════════════════════════════════════════════════════════════════╗\n" );
echo blue( "║                              SUMMARY                                         ║\n" );
echo blue( "╚══════════════════════════════════════════════════════════════════════════════╝\n\n" );

$passed = count( array_filter( $results ) );
$total  = count( $results );

$domains = [
	'Cart'     => 3,
	'Catalog'  => 2,
	'Order'    => 1,
	'Customer' => 2,
];

echo "Domain Classes Migrated:\n";
foreach ( $domains as $domain => $count ) {
	echo "  • $domain: $count classes\n";
}
echo "  • Total: " . yellow( "8 classes" ) . "\n\n";

echo "Test Results: ";
if ( $passed === $total ) {
	echo green( "ALL PASSED ($passed/$total)\n" );
	echo green( "✅ Phase 3 progress verified successfully!\n" );
	exit( 0 );
} else {
	echo red( "SOME FAILED ($passed/$total)\n" );
	exit( 1 );
}
