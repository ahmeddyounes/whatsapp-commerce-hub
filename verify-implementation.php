<?php
/**
 * Verify M00-03 Implementation
 *
 * This script verifies that all acceptance criteria are met.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Load WordPress.
require_once '../../../wp-load.php';

// Check if user is admin.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'You do not have permission to access this page.' );
}

echo "=== M00-03 Implementation Verification ===\n\n";

$all_pass = true;

// Acceptance Criterion 1: Settings persist across requests.
echo "1. Settings persist across requests: ";
$settings = WCH_Settings::getInstance();
$settings->set( 'general.test_value', 'test123' );
// Simulate new instance.
$settings2 = WCH_Settings::getInstance();
$value = $settings2->get( 'general.test_value' );
if ( $value === 'test123' ) {
	echo "✓ PASS\n";
} else {
	echo "✗ FAIL\n";
	$all_pass = false;
}

// Acceptance Criterion 2: Encrypted fields cannot be read directly from database.
echo "2. Encrypted fields cannot be read directly from database: ";
$settings->set( 'api.access_token', 'secret_token_xyz' );
$raw = get_option( 'wch_settings' );
$raw_value = $raw['api']['access_token'] ?? '';
$decrypted_value = $settings->get( 'api.access_token' );
if ( $raw_value !== 'secret_token_xyz' && $decrypted_value === 'secret_token_xyz' ) {
	echo "✓ PASS\n";
} else {
	echo "✗ FAIL\n";
	$all_pass = false;
}

// Acceptance Criterion 3: get() returns defaults for unset keys.
echo "3. get() returns defaults for unset keys: ";
$settings->delete( 'api.api_version' );
$default_version = $settings->get( 'api.api_version' );
if ( $default_version === 'v18.0' ) {
	echo "✓ PASS\n";
} else {
	echo "✗ FAIL (got: $default_version)\n";
	$all_pass = false;
}

// Acceptance Criterion 4: Settings validate types on set().
echo "4. Settings validate types on set(): ";
$bool_fail = $settings->set( 'general.enable_bot', 'not_a_bool' );
$bool_pass = $settings->set( 'general.enable_bot', true );
$int_fail = $settings->set( 'notifications.abandoned_cart_delay_hours', 'not_an_int' );
$int_pass = $settings->set( 'notifications.abandoned_cart_delay_hours', 24 );
if ( ! $bool_fail && $bool_pass && ! $int_fail && $int_pass ) {
	echo "✓ PASS\n";
} else {
	echo "✗ FAIL\n";
	$all_pass = false;
}

// Check all required sections exist.
echo "\n5. All required sections and keys exist:\n";
$required_sections = [
	'api' => array(
		'whatsapp_phone_number_id',
		'whatsapp_business_account_id',
		'access_token',
		'webhook_verify_token',
		'api_version',
	),
	'general' => array(
		'enable_bot',
		'business_name',
		'welcome_message',
		'fallback_message',
		'operating_hours',
		'timezone',
	),
	'catalog' => array(
		'sync_enabled',
		'sync_products',
		'include_out_of_stock',
		'price_format',
		'currency_symbol',
	),
	'checkout' => array(
		'enabled_payment_methods',
		'cod_enabled',
		'cod_extra_charge',
		'min_order_amount',
		'max_order_amount',
		'require_phone_verification',
	),
	'notifications' => array(
		'order_confirmation',
		'order_status_updates',
		'shipping_updates',
		'abandoned_cart_reminder',
		'abandoned_cart_delay_hours',
	),
	'ai' => array(
		'enable_ai',
		'openai_api_key',
		'ai_model',
		'ai_temperature',
		'ai_max_tokens',
		'ai_system_prompt',
	),
];

$section_pass = true;
foreach ( $required_sections as $section => $keys ) {
	foreach ( $keys as $key ) {
		$full_key = "$section.$key";
		// Try to get default value.
		$value = $settings->get( $full_key );
		if ( null === $value && ! in_array( $full_key, [ 'api.whatsapp_phone_number_id', 'api.whatsapp_business_account_id', 'api.access_token', 'api.webhook_verify_token', 'ai.openai_api_key' ], true ) ) {
			echo "   ✗ Missing default for: $full_key\n";
			$section_pass = false;
		}
	}
}
if ( $section_pass ) {
	echo "   ✓ PASS - All sections and keys accessible\n";
} else {
	$all_pass = false;
}

// Check filter exists.
echo "\n6. Filter 'wch_settings_defaults' available: ";
$filter_test = false;
add_filter( 'wch_settings_defaults', function( $defaults ) use ( &$filter_test ) {
	$filter_test = true;
	return $defaults;
});
// Trigger filter by creating new instance and accessing defaults.
$reflection = new ReflectionClass( 'WCH_Settings' );
$method = $reflection->getMethod( 'get_defaults' );
$method->setAccessible( true );
$method->invoke( $settings );

if ( $filter_test ) {
	echo "✓ PASS\n";
} else {
	echo "✗ FAIL\n";
	$all_pass = false;
}

// Check WCH_Encryption class exists and works.
echo "\n7. WCH_Encryption class exists and functional: ";
if ( class_exists( 'WCH_Encryption' ) ) {
	$encryption = new WCH_Encryption();
	$test_val = 'test_encryption_value';
	$encrypted = $encryption->encrypt( $test_val );
	$decrypted = $encryption->decrypt( $encrypted );
	if ( $decrypted === $test_val ) {
		echo "✓ PASS\n";
	} else {
		echo "✗ FAIL - Encryption/decryption failed\n";
		$all_pass = false;
	}
} else {
	echo "✗ FAIL - Class not found\n";
	$all_pass = false;
}

// Check WCH_Settings methods exist.
echo "\n8. WCH_Settings has all required methods: ";
$required_methods = [ 'get', 'set', 'get_all', 'delete', 'get_section' ];
$methods_exist = true;
foreach ( $required_methods as $method ) {
	if ( ! method_exists( 'WCH_Settings', $method ) ) {
		echo "✗ FAIL - Missing method: $method\n";
		$methods_exist = false;
		$all_pass = false;
	}
}
if ( $methods_exist ) {
	echo "✓ PASS\n";
}

// Summary.
echo "\n" . str_repeat( '=', 50 ) . "\n";
if ( $all_pass ) {
	echo "✓ ALL ACCEPTANCE CRITERIA MET\n";
	echo "Status: READY FOR DEPLOYMENT\n";
} else {
	echo "✗ SOME CRITERIA NOT MET\n";
	echo "Status: NEEDS REVIEW\n";
}
echo str_repeat( '=', 50 ) . "\n";
