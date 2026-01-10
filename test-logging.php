<?php
/**
 * Test script for logging and error handling system.
 *
 * This script tests the WCH_Logger, WCH_Exception, and WCH_Error_Handler classes.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Define WordPress constants for standalone execution.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Define plugin constants.
define( 'WCH_VERSION', '1.0.0' );
define( 'WCH_PLUGIN_DIR', __DIR__ . '/' );

// Mock WordPress functions for standalone testing.
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [ 'basedir' => __DIR__ . '/test-uploads' ];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		return mkdir( $target, 0755, true );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		return @unlink( $file );
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return null;
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min, $max ) {
		return rand( $min, $max );
	}
}

if ( ! function_exists( 'gmdate' ) ) {
	function gmdate( $format, $timestamp = null ) {
		return date( $format, $timestamp ?? time() );
	}
}

// Load the classes.
require_once __DIR__ . '/includes/class-wch-logger.php';
require_once __DIR__ . '/includes/class-wch-exception.php';
require_once __DIR__ . '/includes/class-wch-error-handler.php';

echo "=== Testing WCH Logging & Error Handling System ===\n\n";

// Test 1: Basic logging.
echo "Test 1: Basic Logging\n";
echo "----------------------\n";

WCH_Logger::debug( 'This is a debug message', [ 'test' => 'debug' ] );
WCH_Logger::info( 'This is an info message', [ 'test' => 'info' ] );
WCH_Logger::warning( 'This is a warning message', [ 'test' => 'warning' ] );
WCH_Logger::error( 'This is an error message', [ 'test' => 'error' ] );
WCH_Logger::critical( 'This is a critical message', [ 'test' => 'critical' ] );

echo "✓ Logged 5 messages at different levels\n\n";

// Test 2: Sensitive data sanitization.
echo "Test 2: Sensitive Data Sanitization\n";
echo "------------------------------------\n";

WCH_Logger::info(
	'User authentication',
	[
		'username'     => 'testuser',
		'password'     => 'secret123',
		'access_token' => 'abc123xyz',
		'api_key'      => 'key_12345',
		'email'        => 'test@example.com',
	]
);

echo "✓ Logged message with sensitive data (should be redacted)\n\n";

// Test 3: Context with conversation data.
echo "Test 3: Contextual Logging\n";
echo "---------------------------\n";

WCH_Logger::info(
	'Order created from WhatsApp',
	[
		'conversation_id' => 'conv_123',
		'customer_phone'  => '+1234567890',
		'order_id'        => 'WC-12345',
	]
);

echo "✓ Logged message with conversation context\n\n";

// Test 4: WCH_Exception.
echo "Test 4: Custom Exception\n";
echo "-------------------------\n";

try {
	throw new WCH_Exception(
		'Product not found',
		'product_not_found',
		404,
		[
			'product_id' => 123,
			'search_term' => 'laptop',
		]
	);
} catch ( WCH_Exception $e ) {
	echo "✓ Caught WCH_Exception\n";
	echo "  Error Code: " . $e->get_error_code() . "\n";
	echo "  HTTP Status: " . $e->get_http_status() . "\n";
	echo "  Message: " . $e->getMessage() . "\n";

	// Log the exception.
	$e->log();
	echo "✓ Exception logged\n\n";
}

// Test 5: Get log files.
echo "Test 5: Log File Management\n";
echo "----------------------------\n";

$log_files = WCH_Logger::get_log_files();
echo "✓ Found " . count( $log_files ) . " log file(s)\n";

if ( ! empty( $log_files ) ) {
	foreach ( $log_files as $file ) {
		echo "  - {$file['name']} (" . round( $file['size'] / 1024, 2 ) . " KB)\n";
	}
}

echo "\n";

// Test 6: Read log file.
echo "Test 6: Reading Log Entries\n";
echo "----------------------------\n";

if ( ! empty( $log_files ) ) {
	$entries = WCH_Logger::read_log( $log_files[0]['name'], null, 10 );
	echo "✓ Read " . count( $entries ) . " log entries\n";

	if ( ! empty( $entries ) ) {
		echo "\nRecent log entries:\n";
		foreach ( array_slice( $entries, 0, 3 ) as $entry ) {
			echo "  " . substr( $entry, 0, 100 ) . "...\n";
		}
	}
}

echo "\n";

// Test 7: Filter by log level.
echo "Test 7: Filter Logs by Level\n";
echo "-----------------------------\n";

if ( ! empty( $log_files ) ) {
	$error_entries = WCH_Logger::read_log( $log_files[0]['name'], 'ERROR' );
	$critical_entries = WCH_Logger::read_log( $log_files[0]['name'], 'CRITICAL' );

	echo "✓ Found " . count( $error_entries ) . " ERROR entries\n";
	echo "✓ Found " . count( $critical_entries ) . " CRITICAL entries\n";
}

echo "\n";

// Test 8: Exception conversion.
echo "Test 8: Exception Conversion\n";
echo "-----------------------------\n";

$exception = new WCH_Exception(
	'Payment failed',
	'payment_failed',
	402,
	[
		'order_id' => 'WC-67890',
		'amount'   => 99.99,
	]
);

$array_data = $exception->to_array( false );
echo "✓ Converted to array: " . json_encode( array_keys( $array_data ) ) . "\n";

$json_data = $exception->to_json( false );
echo "✓ Converted to JSON: " . strlen( $json_data ) . " bytes\n\n";

// Summary.
echo "=== Test Summary ===\n";
echo "All tests completed successfully!\n\n";

echo "Log files are stored in: " . WCH_Logger::get_log_files()[0]['path'] . "\n";
echo "\nYou can verify the logs by checking the file contents.\n";
