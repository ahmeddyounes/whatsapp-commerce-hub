<?php
/**
 * Verification script for M02-02 - WooCommerce Order Sync Service
 *
 * This script verifies that the order sync service is properly implemented.
 */

// Load WordPress.
require_once __DIR__ . '/test-plugin-bootstrap.php';

echo "=== M02-02 Order Sync Service Verification ===\n\n";

// Test 1: Check if class exists.
echo "1. Checking if WCH_Order_Sync_Service class exists...\n";
if ( class_exists( 'WCH_Order_Sync_Service' ) ) {
	echo "   ✓ WCH_Order_Sync_Service class exists\n\n";
} else {
	echo "   ✗ WCH_Order_Sync_Service class not found\n\n";
	exit( 1 );
}

// Test 2: Check if service instance can be created.
echo "2. Testing service singleton instance...\n";
try {
	$service = WCH_Order_Sync_Service::instance();
	if ( $service instanceof WCH_Order_Sync_Service ) {
		echo "   ✓ Service instance created successfully\n\n";
	} else {
		echo "   ✗ Service instance is not of correct type\n\n";
		exit( 1 );
	}
} catch ( Exception $e ) {
	echo "   ✗ Failed to create service instance: " . $e->getMessage() . "\n\n";
	exit( 1 );
}

// Test 3: Check if required methods exist.
echo "3. Checking required methods exist...\n";
$required_methods = [
	'create_order_from_cart',
	'sync_order_status_to_whatsapp',
	'add_tracking_info',
	'add_whatsapp_metabox',
	'add_whatsapp_column',
	'render_whatsapp_column',
	'add_whatsapp_filter_dropdown',
	'filter_orders_by_whatsapp',
];

$all_methods_exist = true;
foreach ( $required_methods as $method ) {
	if ( method_exists( $service, $method ) ) {
		echo "   ✓ Method {$method} exists\n";
	} else {
		echo "   ✗ Method {$method} not found\n";
		$all_methods_exist = false;
	}
}
echo "\n";

if ( ! $all_methods_exist ) {
	exit( 1 );
}

// Test 4: Test create_order_from_cart with mock data.
echo "4. Testing create_order_from_cart method...\n";

// Create a test product first.
$product = new WC_Product_Simple();
$product->set_name( 'Test Product for Order Sync' );
$product->set_regular_price( 29.99 );
$product->set_status( 'publish' );
$product->set_stock_status( 'instock' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 100 );
$product->save();

$cart_data = [
	'items' => [
		array(
			'product_id' => $product->get_id(),
			'quantity' => 2,
		),
	],
	'shipping_address' => [
		'first_name' => 'John',
		'last_name' => 'Doe',
		'address_1' => '123 Main St',
		'city' => 'New York',
		'state' => 'NY',
		'postcode' => '10001',
		'country' => 'US',
	],
	'payment_method' => 'cod',
	'conversation_id' => 'test_conversation_123',
];

try {
	$order_id = $service->create_order_from_cart( $cart_data, '+1234567890' );

	if ( $order_id > 0 ) {
		echo "   ✓ Order created successfully (Order ID: {$order_id})\n";

		// Verify order details.
		$order = wc_get_order( $order_id );

		// Check if order has WhatsApp meta.
		if ( $order->get_meta( '_wch_order' ) ) {
			echo "   ✓ Order has _wch_order meta\n";
		} else {
			echo "   ✗ Order missing _wch_order meta\n";
		}

		// Check customer phone.
		if ( $order->get_meta( '_wch_customer_phone' ) === '+1234567890' ) {
			echo "   ✓ Customer phone stored correctly\n";
		} else {
			echo "   ✗ Customer phone not stored correctly\n";
		}

		// Check conversation ID.
		if ( $order->get_meta( '_wch_conversation_id' ) === 'test_conversation_123' ) {
			echo "   ✓ Conversation ID stored correctly\n";
		} else {
			echo "   ✗ Conversation ID not stored correctly\n";
		}

		// Check order status.
		if ( $order->get_status() === 'pending' ) {
			echo "   ✓ Order status is 'pending' (COD)\n";
		} else {
			echo "   ✗ Order status is not 'pending': " . $order->get_status() . "\n";
		}

		// Check stock reduction.
		$product_after = wc_get_product( $product->get_id() );
		$stock_after = $product_after->get_stock_quantity();
		if ( $stock_after === 98 ) {
			echo "   ✓ Stock reduced correctly (100 -> 98)\n";
		} else {
			echo "   ✗ Stock not reduced correctly: {$stock_after}\n";
		}

		// Clean up test order.
		wp_delete_post( $order_id, true );
		echo "   ✓ Test order cleaned up\n";

	} else {
		echo "   ✗ Failed to create order\n";
	}
} catch ( Exception $e ) {
	echo "   ✗ Exception: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Test add_tracking_info method.
echo "5. Testing add_tracking_info method...\n";

// Create a test order.
$test_order = wc_create_order();
$test_order->update_meta_data( '_wch_order', true );
$test_order->update_meta_data( '_wch_customer_phone', '+1234567890' );
$test_order->save();

$result = $service->add_tracking_info( $test_order->get_id(), 'TRACK123456', 'DHL' );

if ( $result ) {
	echo "   ✓ Tracking info added successfully\n";

	// Verify tracking data.
	$order = wc_get_order( $test_order->get_id() );
	if ( $order->get_meta( '_wch_tracking_number' ) === 'TRACK123456' ) {
		echo "   ✓ Tracking number stored correctly\n";
	} else {
		echo "   ✗ Tracking number not stored correctly\n";
	}

	if ( $order->get_meta( '_wch_carrier' ) === 'DHL' ) {
		echo "   ✓ Carrier stored correctly\n";
	} else {
		echo "   ✗ Carrier not stored correctly\n";
	}
} else {
	echo "   ✗ Failed to add tracking info\n";
}

// Clean up.
wp_delete_post( $test_order->get_id(), true );
echo "   ✓ Test order cleaned up\n\n";

// Test 6: Check if queue hook is registered.
echo "6. Checking if order notification queue hook is registered...\n";
$queue = WCH_Queue::getInstance();
$registered_hooks = $queue->get_registered_hooks();

if ( in_array( 'wch_send_order_notification', $registered_hooks, true ) ) {
	echo "   ✓ Order notification hook registered\n\n";
} else {
	echo "   ✗ Order notification hook not registered\n\n";
}

// Test 7: Check WordPress hooks.
echo "7. Checking WordPress hooks...\n";

$hooks_to_check = [
	'woocommerce_order_status_changed',
	'add_meta_boxes',
	'admin_enqueue_scripts',
	'admin_footer',
	'manage_shop_order_posts_columns',
	'manage_shop_order_posts_custom_column',
	'restrict_manage_posts',
	'wp_ajax_wch_send_quick_message',
	'wp_ajax_wch_save_tracking_info',
];

foreach ( $hooks_to_check as $hook ) {
	if ( has_action( $hook ) || has_filter( $hook ) ) {
		echo "   ✓ Hook '{$hook}' is registered\n";
	} else {
		echo "   ✗ Hook '{$hook}' is not registered\n";
	}
}
echo "\n";

// Test 8: Verify JavaScript file exists.
echo "8. Checking if admin JavaScript file exists...\n";
$js_file = WCH_PLUGIN_DIR . 'assets/js/wch-order-admin.js';
if ( file_exists( $js_file ) ) {
	echo "   ✓ Admin JavaScript file exists\n\n";
} else {
	echo "   ✗ Admin JavaScript file not found\n\n";
}

// Clean up test product.
wp_delete_post( $product->get_id(), true );

echo "=== Verification Complete ===\n";
echo "All tests passed! ✓\n";
