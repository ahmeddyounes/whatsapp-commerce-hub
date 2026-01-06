#!/usr/bin/env php
<?php
/**
 * Standalone verification script for M04-02: Shopping Cart Manager
 *
 * Tests class structure and basic functionality.
 *
 * @package WhatsApp_Commerce_Hub
 */

echo "=== M04-02: Shopping Cart Manager Verification ===\n\n";

// Simulate WordPress environment constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mock WordPress functions.
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $function, $priority = 10 ) {
		// Mock function.
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $function ) {
		// Mock function.
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $function ) {
		// Mock function.
	}
}

// Load the exception classes.
require_once __DIR__ . '/includes/class-wch-exception.php';
require_once __DIR__ . '/includes/class-wch-cart-exception.php';
require_once __DIR__ . '/includes/class-wch-cart-manager.php';

$passed = 0;
$failed = 0;

/**
 * Test helper function.
 *
 * @param string $name Test name.
 * @param callable $test Test function.
 */
function run_test( $name, $test ) {
	global $passed, $failed;
	echo "Testing: {$name}... ";
	try {
		$test();
		echo "✓ PASSED\n";
		$passed++;
	} catch ( Exception $e ) {
		echo "✗ FAILED: " . $e->getMessage() . "\n";
		$failed++;
	}
}

// Test 1: WCH_Cart_Exception class exists.
run_test( 'WCH_Cart_Exception class exists', function() {
	if ( ! class_exists( 'WCH_Cart_Exception' ) ) {
		throw new Exception( 'WCH_Cart_Exception class not found' );
	}
} );

// Test 2: WCH_Cart_Exception extends WCH_Exception.
run_test( 'WCH_Cart_Exception extends WCH_Exception', function() {
	if ( ! is_subclass_of( 'WCH_Cart_Exception', 'WCH_Exception' ) ) {
		throw new Exception( 'WCH_Cart_Exception does not extend WCH_Exception' );
	}
} );

// Test 3: WCH_Cart_Manager class exists.
run_test( 'WCH_Cart_Manager class exists', function() {
	if ( ! class_exists( 'WCH_Cart_Manager' ) ) {
		throw new Exception( 'WCH_Cart_Manager class not found' );
	}
} );

// Test 4: WCH_Cart_Manager has instance method.
run_test( 'WCH_Cart_Manager has instance method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'instance' ) ) {
		throw new Exception( 'instance method not found' );
	}
} );

// Test 5: WCH_Cart_Manager has get_cart method.
run_test( 'WCH_Cart_Manager has get_cart method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'get_cart' ) ) {
		throw new Exception( 'get_cart method not found' );
	}
} );

// Test 6: WCH_Cart_Manager has add_item method.
run_test( 'WCH_Cart_Manager has add_item method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'add_item' ) ) {
		throw new Exception( 'add_item method not found' );
	}
} );

// Test 7: WCH_Cart_Manager has update_quantity method.
run_test( 'WCH_Cart_Manager has update_quantity method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'update_quantity' ) ) {
		throw new Exception( 'update_quantity method not found' );
	}
} );

// Test 8: WCH_Cart_Manager has remove_item method.
run_test( 'WCH_Cart_Manager has remove_item method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'remove_item' ) ) {
		throw new Exception( 'remove_item method not found' );
	}
} );

// Test 9: WCH_Cart_Manager has clear_cart method.
run_test( 'WCH_Cart_Manager has clear_cart method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'clear_cart' ) ) {
		throw new Exception( 'clear_cart method not found' );
	}
} );

// Test 10: WCH_Cart_Manager has apply_coupon method.
run_test( 'WCH_Cart_Manager has apply_coupon method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'apply_coupon' ) ) {
		throw new Exception( 'apply_coupon method not found' );
	}
} );

// Test 11: WCH_Cart_Manager has remove_coupon method.
run_test( 'WCH_Cart_Manager has remove_coupon method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'remove_coupon' ) ) {
		throw new Exception( 'remove_coupon method not found' );
	}
} );

// Test 12: WCH_Cart_Manager has calculate_totals method.
run_test( 'WCH_Cart_Manager has calculate_totals method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'calculate_totals' ) ) {
		throw new Exception( 'calculate_totals method not found' );
	}
} );

// Test 13: WCH_Cart_Manager has get_cart_summary_message method.
run_test( 'WCH_Cart_Manager has get_cart_summary_message method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'get_cart_summary_message' ) ) {
		throw new Exception( 'get_cart_summary_message method not found' );
	}
} );

// Test 14: WCH_Cart_Manager has check_cart_validity method.
run_test( 'WCH_Cart_Manager has check_cart_validity method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'check_cart_validity' ) ) {
		throw new Exception( 'check_cart_validity method not found' );
	}
} );

// Test 15: WCH_Cart_Manager has get_abandoned_carts method.
run_test( 'WCH_Cart_Manager has get_abandoned_carts method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'get_abandoned_carts' ) ) {
		throw new Exception( 'get_abandoned_carts method not found' );
	}
} );

// Test 16: WCH_Cart_Manager has mark_reminder_sent method.
run_test( 'WCH_Cart_Manager has mark_reminder_sent method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'mark_reminder_sent' ) ) {
		throw new Exception( 'mark_reminder_sent method not found' );
	}
} );

// Test 17: WCH_Cart_Manager has cleanup_expired_carts method.
run_test( 'WCH_Cart_Manager has cleanup_expired_carts method', function() {
	if ( ! method_exists( 'WCH_Cart_Manager', 'cleanup_expired_carts' ) ) {
		throw new Exception( 'cleanup_expired_carts method not found' );
	}
} );

// Test 18: CART_EXPIRY_HOURS constant is set to 72.
run_test( 'CART_EXPIRY_HOURS constant is set to 72', function() {
	$reflection = new ReflectionClass( 'WCH_Cart_Manager' );
	$constant = $reflection->getConstant( 'CART_EXPIRY_HOURS' );
	if ( $constant !== 72 ) {
		throw new Exception( "CART_EXPIRY_HOURS is {$constant}, expected 72" );
	}
} );

// Test 19: WCH_Cart_Exception can be instantiated.
run_test( 'WCH_Cart_Exception can be instantiated', function() {
	$exception = new WCH_Cart_Exception( 'Test message', 'test_error' );
	if ( ! $exception instanceof WCH_Cart_Exception ) {
		throw new Exception( 'Failed to instantiate WCH_Cart_Exception' );
	}
	if ( $exception->get_error_code() !== 'test_error' ) {
		throw new Exception( 'Error code not set correctly' );
	}
} );

// Test 20: Verify cart item structure documentation.
run_test( 'Cart item structure includes required fields', function() {
	$reflection = new ReflectionMethod( 'WCH_Cart_Manager', 'add_item' );
	$doc = $reflection->getDocComment();
	if ( strpos( $doc, 'product_id' ) === false ||
	     strpos( $doc, 'variation_id' ) === false ||
	     strpos( $doc, 'quantity' ) === false ) {
		throw new Exception( 'Missing required parameters in add_item method' );
	}
} );

// Test 21: Verify add_item method signature.
run_test( 'add_item method has correct signature', function() {
	$reflection = new ReflectionMethod( 'WCH_Cart_Manager', 'add_item' );
	$params = $reflection->getParameters();
	if ( count( $params ) !== 4 ) {
		throw new Exception( 'add_item should have 4 parameters' );
	}
	$param_names = array_map( function( $p ) {
		return $p->getName();
	}, $params );
	$expected = array( 'phone', 'product_id', 'variation_id', 'quantity' );
	if ( $param_names !== $expected ) {
		throw new Exception( 'add_item parameters do not match expected: ' . implode( ', ', $param_names ) );
	}
} );

// Test 22: Verify update_quantity method signature.
run_test( 'update_quantity method has correct signature', function() {
	$reflection = new ReflectionMethod( 'WCH_Cart_Manager', 'update_quantity' );
	$params = $reflection->getParameters();
	if ( count( $params ) !== 3 ) {
		throw new Exception( 'update_quantity should have 3 parameters' );
	}
} );

// Test 23: Verify apply_coupon method signature.
run_test( 'apply_coupon method has correct signature', function() {
	$reflection = new ReflectionMethod( 'WCH_Cart_Manager', 'apply_coupon' );
	$params = $reflection->getParameters();
	if ( count( $params ) !== 2 ) {
		throw new Exception( 'apply_coupon should have 2 parameters' );
	}
} );

// Test 24: Verify calculate_totals returns required fields.
run_test( 'calculate_totals method exists and is public', function() {
	$reflection = new ReflectionMethod( 'WCH_Cart_Manager', 'calculate_totals' );
	if ( ! $reflection->isPublic() ) {
		throw new Exception( 'calculate_totals should be public' );
	}
} );

// Test 25: Verify singleton pattern implementation.
run_test( 'Singleton pattern is correctly implemented', function() {
	$reflection = new ReflectionClass( 'WCH_Cart_Manager' );
	$constructor = $reflection->getConstructor();
	if ( ! $constructor->isPrivate() ) {
		throw new Exception( 'Constructor should be private for singleton' );
	}
} );

echo "\n=== Test Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total: " . ( $passed + $failed ) . "\n";

if ( $failed === 0 ) {
	echo "\n✓ All structural tests passed!\n";
	echo "\nNote: Full integration tests require WordPress and WooCommerce.\n";
	echo "Run verify-m04-02.php in a WordPress environment for complete testing.\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed.\n";
	exit( 1 );
}
