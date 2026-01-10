<?php
/**
 * Standalone test for WhatsApp API Client classes
 *
 * This test verifies the classes work without WordPress dependencies.
 */

// Mock WordPress functions needed by our classes.
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true ) {
		return bin2hex( random_bytes( $length / 2 ) );
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Load required classes.
require_once __DIR__ . '/includes/class-wch-exception.php';
require_once __DIR__ . '/includes/class-wch-api-exception.php';

// Mock logger for testing.
class WCH_Logger {
	public static function debug( $message, $context = [] ) {
		// Silent for tests.
	}
	public static function warning( $message, $context = [] ) {
		echo "⚠ Warning: $message\n";
	}
}

require_once __DIR__ . '/includes/class-wch-whatsapp-api-client.php';

echo "=== WhatsApp API Client Standalone Test ===\n\n";

// Test 1: Class instantiation.
echo "Test 1: Instantiate WhatsApp API Client\n";
echo "----------------------------------------\n";

$config = [
	'phone_number_id' => 'TEST_PHONE_ID_123',
	'access_token'    => 'TEST_ACCESS_TOKEN_XYZ',
	'api_version'     => 'v18.0',
];

try {
	$client = new WCH_WhatsApp_API_Client( $config );
	echo "✓ Client instantiated successfully\n";
} catch ( Exception $e ) {
	echo "✗ Failed to instantiate: " . $e->getMessage() . "\n";
	exit( 1 );
}

// Test 2: Missing configuration.
echo "\nTest 2: Test missing configuration validation\n";
echo "-----------------------------------------------\n";

try {
	$bad_client = new WCH_WhatsApp_API_Client( [] );
	echo "✗ Should have thrown exception for missing phone_number_id\n";
} catch ( WCH_Exception $e ) {
	echo "✓ Correctly threw exception: " . $e->getMessage() . "\n";
	echo "  Error code: " . $e->get_error_code() . "\n";
}

try {
	$bad_client = new WCH_WhatsApp_API_Client( [ 'phone_number_id' => 'test' ] );
	echo "✗ Should have thrown exception for missing access_token\n";
} catch ( WCH_Exception $e ) {
	echo "✓ Correctly threw exception: " . $e->getMessage() . "\n";
	echo "  Error code: " . $e->get_error_code() . "\n";
}

// Test 3: Phone number validation.
echo "\nTest 3: Phone number validation\n";
echo "--------------------------------\n";

$test_phones = [
	'+1234567890'          => true,  // Valid.
	'+12345'               => true,  // Valid (minimum).
	'+123456789012345'     => true,  // Valid (maximum 15 digits).
	'1234567890'           => false, // Invalid (missing +).
	'+0123456789'          => false, // Invalid (starts with 0).
	'+12345678901234567'   => false, // Invalid (too long, 16 digits).
	'+12 345 678 90'       => false, // Invalid (contains spaces).
	'+1-234-567-890'       => false, // Invalid (contains dashes).
];

$passed = 0;
$failed = 0;

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

	if ( $result === $expected ) {
		++$passed;
	} else {
		++$failed;
	}

	printf( "%s %-22s => %-7s (expected: %s)\n", $status, $phone, $result, $expected );
}

// Test 4: API Exception.
echo "\nTest 4: API Exception creation and methods\n";
echo "-------------------------------------------\n";

try {
	throw new WCH_API_Exception(
		'Test API error message',
		100,
		'OAuthException',
		190,
		400,
		[ 'test_context' => 'value' ]
	);
} catch ( WCH_API_Exception $e ) {
	echo "✓ API Exception caught\n";
	echo "  Message: " . $e->getMessage() . "\n";
	echo "  API Error Code: " . $e->get_api_error_code() . "\n";
	echo "  API Error Type: " . $e->get_api_error_type() . "\n";
	echo "  API Error Subcode: " . $e->get_api_error_subcode() . "\n";
	echo "  HTTP Status: " . $e->get_http_status() . "\n";
	echo "  Error Code: " . $e->get_error_code() . "\n";

	$array_data = $e->to_array();
	if ( isset( $array_data['api_error_code'] ) && $array_data['api_error_code'] === 100 ) {
		echo "✓ to_array() method works correctly\n";
	}
}

// Summary.
echo "\n=== Test Summary ===\n";
echo "Phone validation tests: $passed passed, $failed failed\n";

if ( $failed === 0 ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed!\n";
	exit( 1 );
}
