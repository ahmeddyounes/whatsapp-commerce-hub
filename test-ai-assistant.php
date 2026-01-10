<?php
/**
 * Test file for WCH_AI_Assistant
 *
 * Run: php test-ai-assistant.php
 */

// Mock WordPress functions for testing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mock functions.
function get_bloginfo( $key ) {
	return 'Test Store';
}

function get_option( $key, $default = null ) {
	$options = [
		'wch_settings' => [
			'ai' => array(
				'enable_ai'          => true,
				'openai_api_key'     => 'test-key',
				'ai_model'           => 'gpt-4',
				'ai_temperature'     => 0.7,
				'ai_max_tokens'      => 500,
				'ai_system_prompt'   => 'You are a helpful assistant.',
				'monthly_budget_cap' => 100.0,
			),
			'general' => array(
				'business_name' => 'Test Store',
			),
		],
	];
	return $options[ $key ] ?? $default;
}

function update_option( $key, $value ) {
	return true;
}

function wp_cache_get( $key, $group ) {
	return false;
}

function wp_cache_set( $key, $value, $group, $expire ) {
	return true;
}

function current_time( $type ) {
	return gmdate( 'Y-m-d H:i:s' );
}

if ( ! function_exists( 'gmdate' ) ) {
	function gmdate( $format, $timestamp = null ) {
		return date( $format, $timestamp ?? time() );
	}
}

function apply_filters( $tag, $value ) {
	return $value;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

// Mock WCH classes.
class WCH_Settings {
	private static $instance = null;

	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get( $key, $default = null ) {
		$parts = explode( '.', $key );
		$settings = get_option( 'wch_settings', [] );

		if ( count( $parts ) === 2 ) {
			list( $section, $setting_key ) = $parts;
			return $settings[ $section ][ $setting_key ] ?? $default;
		}

		return $default;
	}
}

class WCH_Logger {
	public static function info( $message, $context = [] ) {
		echo "[INFO] $message\n";
	}

	public static function error( $message, $context = [] ) {
		echo "[ERROR] $message\n";
	}

	public static function warning( $message, $context = [] ) {
		echo "[WARNING] $message\n";
	}

	public static function critical( $message, $context = [] ) {
		echo "[CRITICAL] $message\n";
	}
}

function get_terms( $args ) {
	return [];
}

function wc_get_product_ids_on_sale() {
	return [ 1, 2, 3 ];
}

function wc_get_products( $args ) {
	return [];
}

function wc_get_product( $id ) {
	return null;
}

function wc_price( $price ) {
	return '$' . number_format( $price, 2 );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_remote_post( $url, $args ) {
	return new WP_Error( 'test_mode', 'Test mode - no actual API calls' );
}

class WC_Coupon {
	private $id = 0;

	public function __construct( $code ) {
		// Mock
	}

	public function get_id() {
		return $this->id;
	}
}

// Load the class.
require_once __DIR__ . '/includes/class-wch-ai-assistant.php';

// Run tests.
echo "=== WCH_AI_Assistant Test Suite ===\n\n";

// Test 1: Instantiation.
echo "Test 1: Instantiation\n";
$ai = new WCH_AI_Assistant();
echo "✓ Class instantiated successfully\n\n";

// Test 2: Configuration.
echo "Test 2: Configuration\n";
$ai_custom = new WCH_AI_Assistant(
	[
		'api_key'      => 'custom-key',
		'model'        => 'gpt-3.5-turbo',
		'temperature'  => 0.5,
		'max_tokens'   => 300,
		'system_prompt' => 'Custom prompt',
	]
);
echo "✓ Custom configuration accepted\n\n";

// Test 3: Rate limit check.
echo "Test 3: Rate limit check (no actual API call)\n";
$response = $ai->generate_response(
	'Hello, I need help finding a product',
	[
		'conversation_id' => 123,
		'current_state'   => 'BROWSING',
	]
);
echo "Response error (expected in test mode): " . ( $response['error'] ?? 'none' ) . "\n\n";

// Test 4: Function call processing.
echo "Test 4: Function call processing\n";
$result = $ai->process_function_call(
	'suggest_products',
	[
		'query' => 'laptop',
		'limit' => 5,
	]
);
echo "Function result: " . ( $result['success'] ? 'Success' : 'Failed' ) . "\n";
echo "Response text: " . ( $result['text'] ?? 'none' ) . "\n\n";

// Test 5: Monthly usage tracking.
echo "Test 5: Monthly usage tracking\n";
$usage = $ai->get_monthly_usage();
echo "Monthly usage: " . print_r( $usage, true ) . "\n";

// Test 6: Product details function.
echo "Test 6: Get product details function\n";
$result = $ai->process_function_call(
	'get_product_details',
	[ 'product_id' => 999 ]
);
echo "Function result: " . ( $result['success'] ? 'Success' : 'Failed' ) . "\n";
echo "Error (expected): " . ( $result['error'] ?? 'none' ) . "\n\n";

// Test 7: Add to cart function.
echo "Test 7: Add to cart function\n";
$result = $ai->process_function_call(
	'add_to_cart',
	[
		'product_id' => 999,
		'quantity'   => 2,
	],
	[ 'conversation_id' => 123 ]
);
echo "Function result: " . ( $result['success'] ? 'Success' : 'Failed' ) . "\n";
echo "Action: " . ( $result['action'] ?? 'none' ) . "\n\n";

// Test 8: Escalate to human function.
echo "Test 8: Escalate to human function\n";
$result = $ai->process_function_call(
	'escalate_to_human',
	[ 'reason' => 'Customer request' ],
	[ 'conversation_id' => 123 ]
);
echo "Function result: " . ( $result['success'] ? 'Success' : 'Failed' ) . "\n";
echo "Action: " . ( $result['action'] ?? 'none' ) . "\n\n";

echo "=== All Tests Complete ===\n";
