<?php
/**
 * Customer Service Usage Examples
 *
 * Demonstrates how to use the WCH_Customer_Service in your code.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Example 1: Get or create a customer profile.
$customer_service = WCH_Customer_Service::instance();
$phone            = '+14155552671';
$profile          = $customer_service->get_or_create_profile( $phone );

// Example 2: Link to WooCommerce customer.
$wc_customer_id = 123;
$customer_service->link_to_wc_customer( $phone, $wc_customer_id );

// Example 3: Find WooCommerce customer by phone.
$found_customer_id = $customer_service->find_wc_customer_by_phone( $phone );
if ( $found_customer_id ) {
	// Link them automatically.
	$customer_service->link_to_wc_customer( $phone, $found_customer_id );
}

// Example 4: Save customer address.
$address = [
	'address_1' => '123 Market Street',
	'address_2' => 'Suite 400',
	'city'      => 'San Francisco',
	'state'     => 'CA',
	'postcode'  => '94103',
	'country'   => 'US',
];
$customer_service->save_address( $phone, $address, true ); // true = set as default

// Example 5: Get default address.
$default_address = $customer_service->get_default_address( $phone );
if ( $default_address ) {
	echo "Shipping to: {$default_address['address_1']}, {$default_address['city']}";
}

// Example 6: Update customer preferences.
$preferences = [
	'language' => 'es',
	'currency' => 'EUR',
];
$customer_service->update_preferences( $phone, $preferences );

// Example 7: Get order history.
$orders = $customer_service->get_order_history( $phone );
foreach ( $orders as $order ) {
	echo "Order #{$order['order_number']}: \${$order['total']} ({$order['status']})\n";
}

// Example 8: Calculate customer statistics.
$stats = $customer_service->calculate_customer_stats( $phone );
echo "Total Orders: {$stats['total_orders']}\n";
echo "Total Spent: \${$stats['total_spent']}\n";
echo "Average Order Value: \${$stats['average_order_value']}\n";
if ( $stats['days_since_last_order'] !== null ) {
	echo "Days Since Last Order: {$stats['days_since_last_order']}\n";
}

// Example 9: GDPR - Export customer data.
$export = $customer_service->export_customer_data( $phone );
// Returns array with profile, orders, conversations, stats.
// Can be JSON encoded and provided to customer.
header( 'Content-Type: application/json' );
echo json_encode( $export, JSON_PRETTY_PRINT );

// Example 10: GDPR - Delete customer data.
// WARNING: This permanently deletes the customer profile and anonymizes conversations!
// $customer_service->delete_customer_data( $phone );

// Example 11: Using profile properties.
$profile = $customer_service->get_or_create_profile( $phone );
echo "Customer: {$profile->name}\n";
echo "Phone: {$profile->phone}\n";
echo "WC Customer ID: " . ( $profile->wc_customer_id ? $profile->wc_customer_id : 'Not linked' ) . "\n";
echo "Total Orders: {$profile->total_orders}\n";
echo "Lifetime Value: \${$profile->lifetime_value}\n";
echo "Marketing Opt-in: " . ( $profile->opt_in_marketing ? 'Yes' : 'No' ) . "\n";

// Example 12: Phone number variations (all resolve to same profile).
$variations = [
	'+14155552671',
	'14155552671',
	'(415) 555-2671',
	'415-555-2671',
];
foreach ( $variations as $variation ) {
	$profile = $customer_service->get_or_create_profile( $variation );
	// All return same profile with phone: +14155552671
}
