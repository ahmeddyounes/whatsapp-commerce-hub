<?php
/**
 * Test Settings Framework
 *
 * This is a standalone test file to verify the settings framework.
 * Access it by navigating to: /wp-content/plugins/whatsapp-commerce-hub/test-settings.php
 *
 * @package WhatsApp_Commerce_Hub
 */

// Load WordPress.
require_once '../../../wp-load.php';

// Check if user is admin.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'You do not have permission to access this page.' );
}

// Load the test class.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wch-settings-test.php';

// Run tests.
$results = WCH_Settings_Test::run_tests();

// Display results.
?>
<!DOCTYPE html>
<html>
<head>
	<title>WCH Settings Test</title>
	<meta charset="utf-8">
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			padding: 20px;
			max-width: 1200px;
			margin: 0 auto;
		}
	</style>
</head>
<body>
	<?php WCH_Settings_Test::display_results( $results ); ?>

	<hr>
	<h3>Manual Testing Examples</h3>
	<pre><?php
	// Example 1: Basic get/set.
	echo "Example 1: Basic get/set\n";
	$settings = WCH_Settings::getInstance();
	$settings->set( 'general.welcome_message', 'Hello from WhatsApp!' );
	$message = $settings->get( 'general.welcome_message' );
	echo "Welcome Message: " . esc_html( $message ) . "\n\n";

	// Example 2: Encrypted field.
	echo "Example 2: Encrypted field\n";
	$settings->set( 'api.access_token', 'EAABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnop' );
	$token = $settings->get( 'api.access_token' );
	echo "Access Token (decrypted): " . esc_html( $token ) . "\n";

	$raw_settings = get_option( 'wch_settings' );
	echo "Access Token (raw from DB): " . esc_html( $raw_settings['api']['access_token'] ?? 'not set' ) . "\n";
	echo "(Notice: raw value is encrypted)\n\n";

	// Example 3: Get section.
	echo "Example 3: Get section\n";
	$api_settings = $settings->get_section( 'api' );
	echo "API Settings:\n";
	print_r( array_map( function( $v ) {
		return is_string( $v ) && strlen( $v ) > 50 ? substr( $v, 0, 30 ) . '...' : $v;
	}, $api_settings ) );
	echo "\n";

	// Example 4: Default values.
	echo "Example 4: Default values\n";
	$api_version = $settings->get( 'api.api_version' );
	echo "API Version (default): " . esc_html( $api_version ) . "\n\n";

	// Example 5: Type validation.
	echo "Example 5: Type validation\n";
	$result = $settings->set( 'general.enable_bot', 'not_a_bool' );
	echo "Setting boolean with string: " . ( $result ? 'SUCCESS (bad!)' : 'FAILED (good!)' ) . "\n";
	$result = $settings->set( 'general.enable_bot', true );
	echo "Setting boolean with bool: " . ( $result ? 'SUCCESS (good!)' : 'FAILED (bad!)' ) . "\n";
	?></pre>
</body>
</html>
