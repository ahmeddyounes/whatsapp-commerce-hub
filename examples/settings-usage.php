<?php
/**
 * Settings Framework Usage Examples
 *
 * This file demonstrates how to use the WCH Settings Framework.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example 1: Basic Get/Set Operations
 */
function wch_example_basic_usage() {
	$settings = WCH_Settings::getInstance();

	// Set a simple value.
	$settings->set( 'general.business_name', 'My Awesome Store' );

	// Get the value.
	$business_name = $settings->get( 'general.business_name' );
	echo "Business Name: $business_name\n";

	// Get with default fallback.
	$custom_setting = $settings->get( 'general.custom_setting', 'default_value' );
	echo "Custom Setting: $custom_setting\n";
}

/**
 * Example 2: Working with Encrypted Fields
 */
function wch_example_encryption() {
	$settings = WCH_Settings::getInstance();

	// Set an encrypted field (automatically encrypted).
	$settings->set( 'api.access_token', 'EAABCDEFGHIJKLMNOPQRSTUVWXYZabcdefg' );

	// Get decrypted value.
	$token = $settings->get( 'api.access_token' );
	echo "Access Token (decrypted): $token\n";

	// Direct database access shows encrypted value.
	$raw_settings = get_option( 'wch_settings' );
	$encrypted_token = $raw_settings['api']['access_token'];
	echo "Access Token (raw/encrypted): $encrypted_token\n";
	echo "Notice: They are different!\n";
}

/**
 * Example 3: Section Operations
 */
function wch_example_sections() {
	$settings = WCH_Settings::getInstance();

	// Configure API settings.
	$settings->set( 'api.whatsapp_phone_number_id', '123456789' );
	$settings->set( 'api.whatsapp_business_account_id', '987654321' );
	$settings->set( 'api.access_token', 'secret_token' );
	$settings->set( 'api.webhook_verify_token', 'verify_token_123' );

	// Get all API settings at once.
	$api_settings = $settings->get_section( 'api' );

	echo "API Settings:\n";
	foreach ( $api_settings as $key => $value ) {
		// Truncate long values for display.
		$display_value = strlen( $value ) > 50 ? substr( $value, 0, 30 ) . '...' : $value;
		echo "  - $key: $display_value\n";
	}
}

/**
 * Example 4: Type Validation
 */
function wch_example_validation() {
	$settings = WCH_Settings::getInstance();

	// Boolean validation.
	$result = $settings->set( 'general.enable_bot', true );
	echo "Setting boolean (correct): " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";

	$result = $settings->set( 'general.enable_bot', 'yes' );
	echo "Setting boolean (wrong type): " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";

	// Integer validation.
	$result = $settings->set( 'notifications.abandoned_cart_delay_hours', 24 );
	echo "Setting integer (correct): " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";

	$result = $settings->set( 'notifications.abandoned_cart_delay_hours', '24' );
	echo "Setting integer (wrong type): " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";

	// Float validation.
	$result = $settings->set( 'checkout.cod_extra_charge', 5.99 );
	echo "Setting float (correct): " . ( $result ? 'SUCCESS' : 'FAILED' ) . "\n";
}

/**
 * Example 5: Delete Operations
 */
function wch_example_delete() {
	$settings = WCH_Settings::getInstance();

	// Set a value.
	$settings->set( 'general.business_name', 'Temporary Name' );
	echo "Before delete: " . $settings->get( 'general.business_name' ) . "\n";

	// Delete the value.
	$settings->delete( 'general.business_name' );

	// After deletion, returns default.
	echo "After delete: " . $settings->get( 'general.business_name' ) . "\n";
}

/**
 * Example 6: Using the Filter
 */
function wch_example_filter() {
	// Modify defaults before they are used.
	add_filter( 'wch_settings_defaults', function( $defaults ) {
		// Change default welcome message.
		$defaults['general']['welcome_message'] = 'Custom Welcome Message!';

		// Add custom section and defaults.
		$defaults['custom'] = array(
			'custom_key' => 'custom_value',
		);

		return $defaults;
	});

	$settings = WCH_Settings::getInstance();

	// This will use the filtered default.
	$welcome = $settings->get( 'general.welcome_message' );
	echo "Welcome Message: $welcome\n";

	// Custom section is also available.
	$custom = $settings->get( 'custom.custom_key' );
	echo "Custom Key: $custom\n";
}

/**
 * Example 7: Complete Configuration
 */
function wch_example_complete_config() {
	$settings = WCH_Settings::getInstance();

	// Configure all API settings.
	$api_config = array(
		'whatsapp_phone_number_id'     => '123456789',
		'whatsapp_business_account_id' => '987654321',
		'access_token'                 => 'EAABCDEFGHIJKLMNOPQRSTUVWXYZabcdefg',
		'webhook_verify_token'         => 'my_webhook_verify_token',
		'api_version'                  => 'v18.0',
	);

	foreach ( $api_config as $key => $value ) {
		$settings->set( "api.$key", $value );
	}

	// Configure general settings.
	$settings->set( 'general.enable_bot', true );
	$settings->set( 'general.business_name', 'My E-Commerce Store' );
	$settings->set( 'general.welcome_message', 'Hello! Welcome to our WhatsApp store.' );
	$settings->set( 'general.timezone', 'America/New_York' );

	// Configure catalog settings.
	$settings->set( 'catalog.sync_enabled', true );
	$settings->set( 'catalog.sync_products', 'all' );
	$settings->set( 'catalog.include_out_of_stock', false );

	// Configure checkout settings.
	$settings->set( 'checkout.cod_enabled', true );
	$settings->set( 'checkout.cod_extra_charge', 2.99 );
	$settings->set( 'checkout.min_order_amount', 10.0 );

	// Configure notifications.
	$settings->set( 'notifications.order_confirmation', true );
	$settings->set( 'notifications.order_status_updates', true );
	$settings->set( 'notifications.abandoned_cart_reminder', true );
	$settings->set( 'notifications.abandoned_cart_delay_hours', 24 );

	echo "Complete configuration saved!\n";

	// Verify all settings.
	$all_settings = $settings->get_all();
	echo "Total sections configured: " . count( $all_settings ) . "\n";
}

/**
 * Example 8: Integration with Plugin
 */
function wch_example_integration() {
	$settings = WCH_Settings::getInstance();

	// Example: Use settings in a WhatsApp API client.
	$phone_number_id = $settings->get( 'api.whatsapp_phone_number_id' );
	$access_token    = $settings->get( 'api.access_token' );
	$api_version     = $settings->get( 'api.api_version', 'v18.0' );

	if ( $phone_number_id && $access_token ) {
		$api_url = "https://graph.facebook.com/$api_version/$phone_number_id/messages";
		echo "API URL: $api_url\n";
		echo "Ready to send messages!\n";
	} else {
		echo "API not configured yet.\n";
	}

	// Example: Use bot settings.
	if ( $settings->get( 'general.enable_bot', false ) ) {
		$welcome_msg = $settings->get( 'general.welcome_message' );
		echo "Bot is enabled. Welcome message: $welcome_msg\n";
	} else {
		echo "Bot is disabled.\n";
	}
}
