<?php
/**
 * Customer Service Test Script
 *
 * Run this from WordPress root or via WP-CLI to test customer service functionality.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Load WordPress.
require_once __DIR__ . '/../../wp-load.php';

// Load required classes.
require_once __DIR__ . '/includes/class-wch-database-manager.php';
require_once __DIR__ . '/includes/class-wch-logger.php';
require_once __DIR__ . '/includes/class-wch-customer-profile.php';
require_once __DIR__ . '/includes/class-wch-customer-service.php';

echo "WhatsApp Commerce Hub - Customer Service Test\n";
echo str_repeat( '=', 60 ) . "\n\n";

// Get customer service instance.
$customer_service = WCH_Customer_Service::instance();

// Test 1: Create a new customer profile.
echo "Test 1: Creating new customer profile\n";
echo str_repeat( '-', 60 ) . "\n";
$test_phone = '+1234567890';
$profile = $customer_service->get_or_create_profile( $test_phone );
if ( $profile ) {
	echo "✓ Profile created successfully\n";
	echo "  Phone: {$profile->phone}\n";
	echo "  Name: {$profile->name}\n";
	echo "  Created at: {$profile->created_at}\n";
} else {
	echo "✗ Failed to create profile\n";
}
echo "\n";

// Test 2: Save an address.
echo "Test 2: Saving customer address\n";
echo str_repeat( '-', 60 ) . "\n";
$address_data = [
	'address_1' => '123 Main St',
	'address_2' => 'Apt 4B',
	'city'      => 'New York',
	'state'     => 'NY',
	'postcode'  => '10001',
	'country'   => 'US',
];
$result = $customer_service->save_address( $test_phone, $address_data, true );
if ( $result ) {
	echo "✓ Address saved successfully\n";
	$default_address = $customer_service->get_default_address( $test_phone );
	if ( $default_address ) {
		echo "  Default address: {$default_address['address_1']}, {$default_address['city']}\n";
	}
} else {
	echo "✗ Failed to save address\n";
}
echo "\n";

// Test 3: Update preferences.
echo "Test 3: Updating customer preferences\n";
echo str_repeat( '-', 60 ) . "\n";
$preferences = [
	'language' => 'en',
	'currency' => 'USD',
];
$result = $customer_service->update_preferences( $test_phone, $preferences );
if ( $result ) {
	echo "✓ Preferences updated successfully\n";
	$profile = $customer_service->get_or_create_profile( $test_phone );
	echo "  Language: {$profile->preferences['language']}\n";
	echo "  Currency: {$profile->preferences['currency']}\n";
} else {
	echo "✗ Failed to update preferences\n";
}
echo "\n";

// Test 4: Calculate customer stats.
echo "Test 4: Calculating customer statistics\n";
echo str_repeat( '-', 60 ) . "\n";
$stats = $customer_service->calculate_customer_stats( $test_phone );
echo "✓ Stats calculated\n";
echo "  Total orders: {$stats['total_orders']}\n";
echo "  Total spent: \${$stats['total_spent']}\n";
echo "  Average order value: \${$stats['average_order_value']}\n";
if ( $stats['days_since_last_order'] !== null ) {
	echo "  Days since last order: {$stats['days_since_last_order']}\n";
} else {
	echo "  Days since last order: No orders yet\n";
}
echo "\n";

// Test 5: Find WC customer by phone (with variations).
echo "Test 5: Finding WooCommerce customer by phone\n";
echo str_repeat( '-', 60 ) . "\n";
$wc_customer_id = $customer_service->find_wc_customer_by_phone( $test_phone );
if ( $wc_customer_id ) {
	echo "✓ Found WC customer: ID {$wc_customer_id}\n";
} else {
	echo "✓ No WC customer found (expected if none exists)\n";
}
echo "\n";

// Test 6: Export customer data (GDPR).
echo "Test 6: Exporting customer data for GDPR\n";
echo str_repeat( '-', 60 ) . "\n";
$export_data = $customer_service->export_customer_data( $test_phone );
if ( ! empty( $export_data ) ) {
	echo "✓ Customer data exported successfully\n";
	echo "  Profile phone: {$export_data['profile']['phone']}\n";
	echo "  Total conversations: " . count( $export_data['conversations'] ) . "\n";
	echo "  Total orders: " . count( $export_data['orders'] ) . "\n";
	echo "  Exported at: {$export_data['exported_at']}\n";
} else {
	echo "✗ Failed to export data\n";
}
echo "\n";

// Test 7: Phone normalization variations.
echo "Test 7: Testing phone normalization\n";
echo str_repeat( '-', 60 ) . "\n";
$phone_variations = [
	'1234567890',
	'+1234567890',
	'(123) 456-7890',
	'123-456-7890',
];
echo "Testing variations for same profile:\n";
foreach ( $phone_variations as $variation ) {
	$profile = $customer_service->get_or_create_profile( $variation );
	if ( $profile && $profile->phone === $test_phone ) {
		echo "  ✓ {$variation} => {$profile->phone}\n";
	} else {
		echo "  ✗ {$variation} => Failed or different profile\n";
	}
}
echo "\n";

// Test 8: Clean up - Delete customer data (GDPR).
echo "Test 8: Deleting customer data for GDPR\n";
echo str_repeat( '-', 60 ) . "\n";
$result = $customer_service->delete_customer_data( $test_phone );
if ( $result ) {
	echo "✓ Customer data deleted successfully\n";
	// Verify deletion.
	$profile = $customer_service->get_or_create_profile( $test_phone );
	if ( $profile && empty( $profile->name ) && empty( $profile->wc_customer_id ) ) {
		echo "  ✓ Profile no longer exists (new empty profile created)\n";
	}
} else {
	echo "✗ Failed to delete customer data\n";
}
echo "\n";

echo str_repeat( '=', 60 ) . "\n";
echo "All tests completed!\n";
