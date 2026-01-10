<?php
/**
 * Verification script for M04-02: Shopping Cart Manager
 *
 * Tests all cart management functionality.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Load WordPress.
require_once __DIR__ . '/test-plugin-bootstrap.php';

echo "=== M04-02: Shopping Cart Manager Verification ===\n\n";

$test_phone = '+1234567890';
$manager = WCH_Cart_Manager::instance();
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

// Clean up before testing.
global $wpdb;
$table_name = $wpdb->prefix . 'wch_carts';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE customer_phone = %s", $test_phone ) );

// Test 1: Get cart creates new empty cart.
run_test( 'Get cart creates new empty cart', function() use ( $manager, $test_phone ) {
	$cart = $manager->get_cart( $test_phone );
	if ( empty( $cart['items'] ) && $cart['customer_phone'] === $test_phone ) {
		return;
	}
	throw new Exception( 'Cart not created correctly' );
} );

// Test 2: Get cart returns existing cart.
run_test( 'Get cart returns existing cart', function() use ( $manager, $test_phone ) {
	$cart1 = $manager->get_cart( $test_phone );
	$cart2 = $manager->get_cart( $test_phone );
	if ( $cart1['id'] === $cart2['id'] ) {
		return;
	}
	throw new Exception( 'Did not return existing cart' );
} );

// Test 3: Add item to cart.
run_test( 'Add item to cart', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product' );
	$product->set_regular_price( 29.99 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	$cart = $manager->add_item( $test_phone, $product_id, null, 2 );

	if ( count( $cart['items'] ) === 1 && $cart['items'][0]['quantity'] === 2 ) {
		// Clean up.
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Item not added correctly' );
} );

// Test 4: Add same item increments quantity.
run_test( 'Add same item increments quantity', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 2' );
	$product->set_regular_price( 19.99 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart first.
	$manager->clear_cart( $test_phone );

	// Add item twice.
	$manager->add_item( $test_phone, $product_id, null, 1 );
	$cart = $manager->add_item( $test_phone, $product_id, null, 1 );

	if ( count( $cart['items'] ) === 1 && $cart['items'][0]['quantity'] === 2 ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Quantity not incremented' );
} );

// Test 5: Add item validates product exists.
run_test( 'Add item validates product exists', function() use ( $manager, $test_phone ) {
	try {
		$manager->add_item( $test_phone, 999999, null, 1 );
		throw new Exception( 'Should have thrown exception for non-existent product' );
	} catch ( WCH_Cart_Exception $e ) {
		if ( $e->get_error_code() === 'product_not_found' ) {
			return;
		}
		throw new Exception( 'Wrong error code: ' . $e->get_error_code() );
	}
} );

// Test 6: Add item validates stock.
run_test( 'Add item validates stock', function() use ( $manager, $test_phone ) {
	// Create out of stock product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Out of Stock Product' );
	$product->set_regular_price( 15.99 );
	$product->set_stock_status( 'outofstock' );
	$product_id = $product->save();

	try {
		$manager->add_item( $test_phone, $product_id, null, 1 );
		wp_delete_post( $product_id, true );
		throw new Exception( 'Should have thrown exception for out of stock product' );
	} catch ( WCH_Cart_Exception $e ) {
		wp_delete_post( $product_id, true );
		if ( $e->get_error_code() === 'out_of_stock' ) {
			return;
		}
		throw new Exception( 'Wrong error code: ' . $e->get_error_code() );
	}
} );

// Test 7: Update quantity.
run_test( 'Update quantity', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 3' );
	$product->set_regular_price( 25.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 1 );

	// Update quantity.
	$cart = $manager->update_quantity( $test_phone, 0, 5 );

	if ( $cart['items'][0]['quantity'] === 5 ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Quantity not updated' );
} );

// Test 8: Update quantity to 0 removes item.
run_test( 'Update quantity to 0 removes item', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 4' );
	$product->set_regular_price( 30.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 1 );

	// Update quantity to 0.
	$cart = $manager->update_quantity( $test_phone, 0, 0 );

	if ( empty( $cart['items'] ) ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Item not removed when quantity set to 0' );
} );

// Test 9: Remove item.
run_test( 'Remove item', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 5' );
	$product->set_regular_price( 20.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 2 );

	// Remove item.
	$cart = $manager->remove_item( $test_phone, 0 );

	if ( empty( $cart['items'] ) ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Item not removed' );
} );

// Test 10: Clear cart.
run_test( 'Clear cart', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 6' );
	$product->set_regular_price( 18.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Add items.
	$manager->add_item( $test_phone, $product_id, null, 3 );

	// Clear cart.
	$cart = $manager->clear_cart( $test_phone );

	if ( empty( $cart['items'] ) && $cart['total'] == 0 ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Cart not cleared' );
} );

// Test 11: Apply valid coupon.
run_test( 'Apply valid coupon', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 7' );
	$product->set_regular_price( 100.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Create a coupon.
	$coupon = new WC_Coupon();
	$coupon->set_code( 'TEST10' );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 10 );
	$coupon_id = $coupon->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 1 );

	// Apply coupon.
	$result = $manager->apply_coupon( $test_phone, 'TEST10' );

	if ( $result['discount'] == 10.00 && $result['cart']['coupon_code'] === 'TEST10' ) {
		wp_delete_post( $product_id, true );
		wp_delete_post( $coupon_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	wp_delete_post( $coupon_id, true );
	throw new Exception( 'Coupon not applied correctly' );
} );

// Test 12: Apply invalid coupon throws exception.
run_test( 'Apply invalid coupon throws exception', function() use ( $manager, $test_phone ) {
	try {
		$manager->apply_coupon( $test_phone, 'INVALID' );
		throw new Exception( 'Should have thrown exception for invalid coupon' );
	} catch ( WCH_Cart_Exception $e ) {
		if ( $e->get_error_code() === 'invalid_coupon' ) {
			return;
		}
		throw new Exception( 'Wrong error code: ' . $e->get_error_code() );
	}
} );

// Test 13: Remove coupon.
run_test( 'Remove coupon', function() use ( $manager, $test_phone ) {
	// Create a test product and coupon.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 8' );
	$product->set_regular_price( 50.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	$coupon = new WC_Coupon();
	$coupon->set_code( 'TEST20' );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 5 );
	$coupon_id = $coupon->save();

	// Clear cart, add item, and apply coupon.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 1 );
	$manager->apply_coupon( $test_phone, 'TEST20' );

	// Remove coupon.
	$cart = $manager->remove_coupon( $test_phone );

	if ( $cart['coupon_code'] === null ) {
		wp_delete_post( $product_id, true );
		wp_delete_post( $coupon_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	wp_delete_post( $coupon_id, true );
	throw new Exception( 'Coupon not removed' );
} );

// Test 14: Calculate totals.
run_test( 'Calculate totals', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 9' );
	$product->set_regular_price( 100.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 2 );

	$cart = $manager->get_cart( $test_phone );
	$totals = $manager->calculate_totals( $cart );

	if ( isset( $totals['subtotal'] ) && isset( $totals['discount'] ) &&
	     isset( $totals['tax'] ) && isset( $totals['shipping_estimate'] ) &&
	     isset( $totals['total'] ) && $totals['subtotal'] == 200.00 ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Totals not calculated correctly' );
} );

// Test 15: Get cart summary message.
run_test( 'Get cart summary message', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 10' );
	$product->set_regular_price( 45.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 1 );

	$message = $manager->get_cart_summary_message( $test_phone );

	if ( strpos( $message, 'Test Product 10' ) !== false && strpos( $message, 'Your Cart' ) !== false ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Cart summary message not formatted correctly' );
} );

// Test 16: Check cart validity.
run_test( 'Check cart validity', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 11' );
	$product->set_regular_price( 35.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 1 );

	$result = $manager->check_cart_validity( $test_phone );

	if ( isset( $result['is_valid'] ) && isset( $result['issues'] ) && $result['is_valid'] === true ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Cart validity check failed' );
} );

// Test 17: Check cart validity detects out of stock.
run_test( 'Check cart validity detects out of stock', function() use ( $manager, $test_phone ) {
	// Create a test product.
	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product 12' );
	$product->set_regular_price( 25.00 );
	$product->set_stock_status( 'instock' );
	$product_id = $product->save();

	// Clear cart and add item.
	$manager->clear_cart( $test_phone );
	$manager->add_item( $test_phone, $product_id, null, 1 );

	// Change product to out of stock.
	$product->set_stock_status( 'outofstock' );
	$product->save();

	$result = $manager->check_cart_validity( $test_phone );

	if ( $result['is_valid'] === false && count( $result['issues'] ) > 0 &&
	     $result['issues'][0]['issue'] === 'out_of_stock' ) {
		wp_delete_post( $product_id, true );
		return;
	}

	wp_delete_post( $product_id, true );
	throw new Exception( 'Did not detect out of stock product' );
} );

// Test 18: Get abandoned carts.
run_test( 'Get abandoned carts', function() use ( $manager, $test_phone ) {
	// Create abandoned cart by updating timestamp.
	global $wpdb;
	$table_name = $wpdb->prefix . 'wch_carts';

	$wpdb->update(
		$table_name,
		[ 'updated_at' => date( 'Y-m-d H:i:s', strtotime( '-48 hours' ) ) ],
		[ 'customer_phone' => $test_phone ],
		[ '%s' ],
		[ '%s' ]
	);

	$abandoned = $manager->get_abandoned_carts( 24 );

	if ( count( $abandoned ) > 0 ) {
		return;
	}

	throw new Exception( 'Did not find abandoned cart' );
} );

// Test 19: Clean up expired carts.
run_test( 'Clean up expired carts', function() use ( $manager, $test_phone ) {
	// Create expired cart.
	global $wpdb;
	$table_name = $wpdb->prefix . 'wch_carts';

	// Insert a cart that's expired.
	$wpdb->insert(
		$table_name,
		[
			'customer_phone' => '+9999999999',
			'items'          => wp_json_encode( [] ),
			'total'          => 0.00,
			'status'         => 'active',
			'expires_at'     => date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		],
		[ '%s', '%s', '%f', '%s', '%s', '%s', '%s' ]
	);

	$cleaned = $manager->cleanup_expired_carts();

	if ( $cleaned > 0 ) {
		return;
	}

	throw new Exception( 'Did not clean up expired carts' );
} );

// Clean up after testing.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE customer_phone = %s", $test_phone ) );

echo "\n=== Test Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total: " . ( $passed + $failed ) . "\n";

if ( $failed === 0 ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed.\n";
	exit( 1 );
}
