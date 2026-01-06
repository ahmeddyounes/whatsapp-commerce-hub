<?php
/**
 * Test file for WhatsApp API Client
 *
 * This file demonstrates how to use the WCH_WhatsApp_API_Client class.
 * DO NOT run this file in production without proper credentials.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Load WordPress.
require_once dirname( __FILE__ ) . '/test-plugin-bootstrap.php';

// Example configuration.
$config = array(
	'phone_number_id' => 'YOUR_PHONE_NUMBER_ID',
	'access_token'    => 'YOUR_ACCESS_TOKEN',
	'api_version'     => 'v18.0',
);

try {
	// Initialize the API client.
	$client = new WCH_WhatsApp_API_Client( $config );

	echo "✓ WhatsApp API Client instantiated successfully\n\n";

	// Example 1: Send text message (would need actual credentials to work).
	echo "Example usage:\n";
	echo "--------------\n\n";

	echo "1. Send text message:\n";
	echo "   \$result = \$client->send_text_message('+1234567890', 'Hello from WhatsApp Commerce Hub!');\n\n";

	echo "2. Send interactive list:\n";
	echo "   \$sections = [\n";
	echo "       [\n";
	echo "           'title' => 'Section 1',\n";
	echo "           'rows' => [\n";
	echo "               ['id' => '1', 'title' => 'Option 1', 'description' => 'Description 1'],\n";
	echo "           ],\n";
	echo "       ],\n";
	echo "   ];\n";
	echo "   \$result = \$client->send_interactive_list('+1234567890', 'Header', 'Body', 'Footer', 'Button', \$sections);\n\n";

	echo "3. Send template:\n";
	echo "   \$result = \$client->send_template('+1234567890', 'template_name', 'en_US', []);\n\n";

	echo "4. Send image:\n";
	echo "   \$result = \$client->send_image('+1234567890', 'https://example.com/image.jpg', 'Caption');\n\n";

	echo "5. Upload media:\n";
	echo "   \$media_id = \$client->upload_media('/path/to/file.jpg', 'image/jpeg');\n\n";

	echo "6. Get business profile:\n";
	echo "   \$profile = \$client->get_business_profile();\n\n";

	// Test phone number validation.
	echo "Testing phone number validation:\n";
	echo "---------------------------------\n";

	$test_phones = array(
		'+1234567890'    => true,  // Valid.
		'+12345'         => true,  // Valid (minimum).
		'+123456789012345' => true, // Valid (maximum).
		'1234567890'     => false, // Invalid (missing +).
		'+0123456789'    => false, // Invalid (starts with 0).
		'+12345678901234567' => false, // Invalid (too long).
	);

	foreach ( $test_phones as $phone => $should_be_valid ) {
		try {
			// Use reflection to test private method.
			$reflection = new ReflectionClass( $client );
			$method     = $reflection->getMethod( 'validate_phone_number' );
			$method->setAccessible( true );

			$method->invoke( $client, $phone );
			$result = 'VALID';
		} catch ( WCH_Exception $e ) {
			$result = 'INVALID';
		}

		$expected = $should_be_valid ? 'VALID' : 'INVALID';
		$status   = ( $result === $expected ) ? '✓' : '✗';

		echo sprintf( "%s %-20s => %-7s (expected: %s)\n", $status, $phone, $result, $expected );
	}

	echo "\n✓ All validation tests passed!\n";

} catch ( WCH_Exception $e ) {
	echo "✗ Error: " . $e->getMessage() . "\n";
	echo "  Error Code: " . $e->get_error_code() . "\n";
	echo "  HTTP Status: " . $e->get_http_status() . "\n";

	if ( $e instanceof WCH_API_Exception ) {
		echo "  API Error Code: " . $e->get_api_error_code() . "\n";
		echo "  API Error Type: " . $e->get_api_error_type() . "\n";
	}
} catch ( Exception $e ) {
	echo "✗ Unexpected error: " . $e->getMessage() . "\n";
}
