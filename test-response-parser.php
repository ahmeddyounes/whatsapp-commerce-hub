<?php
/**
 * Test file for WCH_Response_Parser
 *
 * This file tests the response parser with various WhatsApp message types.
 */

// Simulate WordPress environment constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mock WordPress functions for testing.
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $function ) {
		// Mock function for testing.
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $function ) {
		// Mock function for testing.
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $function, $priority = 10 ) {
		// Mock function for testing.
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['wch_filters'] = array();
	function add_filter( $hook, $function, $priority = 10, $accepted_args = 1 ) {
		if ( ! isset( $GLOBALS['wch_filters'][ $hook ] ) ) {
			$GLOBALS['wch_filters'][ $hook ] = array();
		}
		$GLOBALS['wch_filters'][ $hook ][] = array(
			'function' => $function,
			'priority' => $priority,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( ! isset( $GLOBALS['wch_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['wch_filters'][ $hook ] as $filter ) {
			$value = call_user_func( $filter['function'], $value, ...$args );
		}
		return $value;
	}
}

// Load the plugin file.
require_once __DIR__ . '/whatsapp-commerce-hub.php';

// Colors for terminal output.
function test_output( $message, $status = 'info' ) {
	$colors = array(
		'success' => "\033[0;32m",
		'error'   => "\033[0;31m",
		'info'    => "\033[0;36m",
		'reset'   => "\033[0m",
	);

	echo $colors[ $status ] . $message . $colors['reset'] . "\n";
}

echo "\n";
test_output( '=== Testing WCH_Response_Parser ===', 'info' );
echo "\n";

// Initialize the parser.
$parser = new WCH_Response_Parser();

// Test 1: Text message with greeting intent.
test_output( 'Test 1: Text message with greeting intent', 'info' );
$test1_data = array(
	'type'    => 'text',
	'content' => array(
		'body' => 'Hello there!',
	),
);
$parsed1 = $parser->parse( $test1_data );
echo "Type: " . $parsed1->get_type() . "\n";
echo "Intent: " . $parsed1->get_intent() . "\n";
echo "Parsed data: " . json_encode( $parsed1->get_parsed_data() ) . "\n";
if ( $parsed1->get_intent() === WCH_Response_Parser::INTENT_GREETING ) {
	test_output( '✓ Test 1 passed', 'success' );
} else {
	test_output( '✗ Test 1 failed: Expected GREETING intent', 'error' );
}
echo "\n";

// Test 2: Text message with order status intent.
test_output( 'Test 2: Text message with order status intent', 'info' );
$test2_data = array(
	'type'    => 'text',
	'content' => array(
		'body' => 'Where is my order?',
	),
);
$parsed2 = $parser->parse( $test2_data );
echo "Type: " . $parsed2->get_type() . "\n";
echo "Intent: " . $parsed2->get_intent() . "\n";
if ( $parsed2->get_intent() === WCH_Response_Parser::INTENT_ORDER_STATUS ) {
	test_output( '✓ Test 2 passed', 'success' );
} else {
	test_output( '✗ Test 2 failed: Expected ORDER_STATUS intent', 'error' );
}
echo "\n";

// Test 3: Text message with cart intent.
test_output( 'Test 3: Text message with cart intent', 'info' );
$test3_data = array(
	'type'    => 'text',
	'content' => array(
		'body' => 'Show me my cart',
	),
);
$parsed3 = $parser->parse( $test3_data );
echo "Type: " . $parsed3->get_type() . "\n";
echo "Intent: " . $parsed3->get_intent() . "\n";
if ( $parsed3->get_intent() === WCH_Response_Parser::INTENT_VIEW_CART ) {
	test_output( '✓ Test 3 passed', 'success' );
} else {
	test_output( '✗ Test 3 failed: Expected VIEW_CART intent', 'error' );
}
echo "\n";

// Test 4: Text message with checkout intent.
test_output( 'Test 4: Text message with checkout intent', 'info' );
$test4_data = array(
	'type'    => 'text',
	'content' => array(
		'body' => 'I want to checkout',
	),
);
$parsed4 = $parser->parse( $test4_data );
echo "Type: " . $parsed4->get_type() . "\n";
echo "Intent: " . $parsed4->get_intent() . "\n";
if ( $parsed4->get_intent() === WCH_Response_Parser::INTENT_CHECKOUT ) {
	test_output( '✓ Test 4 passed', 'success' );
} else {
	test_output( '✗ Test 4 failed: Expected CHECKOUT intent', 'error' );
}
echo "\n";

// Test 5: Text message with help intent.
test_output( 'Test 5: Text message with help intent', 'info' );
$test5_data = array(
	'type'    => 'text',
	'content' => array(
		'body' => 'I need help with my order',
	),
);
$parsed5 = $parser->parse( $test5_data );
echo "Type: " . $parsed5->get_type() . "\n";
echo "Intent: " . $parsed5->get_intent() . "\n";
if ( $parsed5->get_intent() === WCH_Response_Parser::INTENT_HELP ) {
	test_output( '✓ Test 5 passed', 'success' );
} else {
	test_output( '✗ Test 5 failed: Expected HELP intent', 'error' );
}
echo "\n";

// Test 6: Button reply message.
test_output( 'Test 6: Button reply message', 'info' );
$test6_data = array(
	'type'    => 'interactive',
	'content' => array(
		'type'  => 'button_reply',
		'id'    => 'btn_view_cart',
		'title' => 'View Cart',
	),
);
$parsed6 = $parser->parse( $test6_data );
echo "Type: " . $parsed6->get_type() . "\n";
echo "Intent: " . $parsed6->get_intent() . "\n";
echo "Parsed data: " . json_encode( $parsed6->get_parsed_data() ) . "\n";
if ( $parsed6->get_type() === 'button_reply' &&
     $parsed6->get_parsed_data()['button_id'] === 'btn_view_cart' ) {
	test_output( '✓ Test 6 passed', 'success' );
} else {
	test_output( '✗ Test 6 failed', 'error' );
}
echo "\n";

// Test 7: List reply message.
test_output( 'Test 7: List reply message', 'info' );
$test7_data = array(
	'type'    => 'interactive',
	'content' => array(
		'type'        => 'list_reply',
		'id'          => 'list_product_123',
		'title'       => 'Blue Shirt',
		'description' => 'Size M, Color Blue',
	),
);
$parsed7 = $parser->parse( $test7_data );
echo "Type: " . $parsed7->get_type() . "\n";
echo "Intent: " . $parsed7->get_intent() . "\n";
echo "Parsed data: " . json_encode( $parsed7->get_parsed_data() ) . "\n";
if ( $parsed7->get_type() === 'list_reply' &&
     $parsed7->get_parsed_data()['list_id'] === 'list_product_123' ) {
	test_output( '✓ Test 7 passed', 'success' );
} else {
	test_output( '✗ Test 7 failed', 'error' );
}
echo "\n";

// Test 8: Location message.
test_output( 'Test 8: Location message', 'info' );
$test8_data = array(
	'type'    => 'location',
	'content' => array(
		'latitude'  => '37.7749',
		'longitude' => '-122.4194',
		'name'      => 'San Francisco',
		'address'   => 'San Francisco, CA',
	),
);
$parsed8 = $parser->parse( $test8_data );
echo "Type: " . $parsed8->get_type() . "\n";
echo "Intent: " . $parsed8->get_intent() . "\n";
echo "Parsed data: " . json_encode( $parsed8->get_parsed_data() ) . "\n";
if ( $parsed8->get_type() === 'location' &&
     $parsed8->get_parsed_data()['latitude'] === '37.7749' ) {
	test_output( '✓ Test 8 passed', 'success' );
} else {
	test_output( '✗ Test 8 failed', 'error' );
}
echo "\n";

// Test 9: Image message with caption.
test_output( 'Test 9: Image message with caption', 'info' );
$test9_data = array(
	'type'    => 'image',
	'content' => array(
		'id'        => 'image_123456',
		'mime_type' => 'image/jpeg',
		'sha256'    => 'abc123',
		'caption'   => 'I want to order this',
	),
);
$parsed9 = $parser->parse( $test9_data );
echo "Type: " . $parsed9->get_type() . "\n";
echo "Intent: " . $parsed9->get_intent() . "\n";
echo "Parsed data: " . json_encode( $parsed9->get_parsed_data() ) . "\n";
if ( $parsed9->get_type() === 'image' &&
     $parsed9->get_parsed_data()['media_id'] === 'image_123456' ) {
	test_output( '✓ Test 9 passed', 'success' );
} else {
	test_output( '✗ Test 9 failed', 'error' );
}
echo "\n";

// Test 10: Document message.
test_output( 'Test 10: Document message', 'info' );
$test10_data = array(
	'type'    => 'document',
	'content' => array(
		'id'        => 'doc_789',
		'mime_type' => 'application/pdf',
		'sha256'    => 'xyz789',
		'caption'   => 'Track my shipment',
		'filename'  => 'receipt.pdf',
	),
);
$parsed10 = $parser->parse( $test10_data );
echo "Type: " . $parsed10->get_type() . "\n";
echo "Intent: " . $parsed10->get_intent() . "\n";
echo "Parsed data: " . json_encode( $parsed10->get_parsed_data() ) . "\n";
if ( $parsed10->get_type() === 'document' &&
     $parsed10->get_parsed_data()['media_id'] === 'doc_789' &&
     $parsed10->get_intent() === WCH_Response_Parser::INTENT_TRACK_SHIPPING ) {
	test_output( '✓ Test 10 passed', 'success' );
} else {
	test_output( '✗ Test 10 failed', 'error' );
}
echo "\n";

// Test 11: Product inquiry (nfm_reply).
test_output( 'Test 11: Product inquiry (nfm_reply)', 'info' );
$test11_data = array(
	'type'    => 'interactive',
	'content' => array(
		'type'                => 'nfm_reply',
		'product_retailer_id' => 'prod_12345',
		'catalog_id'          => 'catalog_abc',
	),
);
$parsed11 = $parser->parse( $test11_data );
echo "Type: " . $parsed11->get_type() . "\n";
echo "Intent: " . $parsed11->get_intent() . "\n";
echo "Parsed data: " . json_encode( $parsed11->get_parsed_data() ) . "\n";
if ( $parsed11->get_type() === 'product_inquiry' &&
     $parsed11->get_parsed_data()['product_retailer_id'] === 'prod_12345' &&
     $parsed11->get_intent() === WCH_Response_Parser::INTENT_VIEW_PRODUCT ) {
	test_output( '✓ Test 11 passed', 'success' );
} else {
	test_output( '✗ Test 11 failed', 'error' );
}
echo "\n";

// Test 12: Unknown message type.
test_output( 'Test 12: Unknown message type', 'info' );
$test12_data = array(
	'type'    => 'unknown_type',
	'content' => array(),
);
$parsed12 = $parser->parse( $test12_data );
echo "Type: " . $parsed12->get_type() . "\n";
echo "Intent: " . $parsed12->get_intent() . "\n";
if ( $parsed12->get_type() === 'unknown' &&
     $parsed12->get_intent() === WCH_Response_Parser::INTENT_UNKNOWN ) {
	test_output( '✓ Test 12 passed', 'success' );
} else {
	test_output( '✗ Test 12 failed', 'error' );
}
echo "\n";

// Test 13: Test filter hook 'wch_parse_response'.
test_output( 'Test 13: Testing filter hook wch_parse_response', 'info' );
add_filter( 'wch_parse_response', function( $parsed_response, $webhook_message_data ) {
	// Custom modification for testing.
	if ( $parsed_response->get_type() === 'text' ) {
		$parsed_response->parsed_data['custom_field'] = 'custom_value';
	}
	return $parsed_response;
}, 10, 2 );

$test13_data = array(
	'type'    => 'text',
	'content' => array(
		'body' => 'Test filter',
	),
);
$parsed13 = $parser->parse( $test13_data );
if ( isset( $parsed13->get_parsed_data()['custom_field'] ) &&
     $parsed13->get_parsed_data()['custom_field'] === 'custom_value' ) {
	test_output( '✓ Test 13 passed: Filter hook works', 'success' );
} else {
	test_output( '✗ Test 13 failed: Filter hook not working', 'error' );
}
echo "\n";

// Test 14: Test all available intents.
test_output( 'Test 14: Check all available intents', 'info' );
$intents = WCH_Response_Parser::get_available_intents();
echo "Available intents: " . count( $intents ) . "\n";
foreach ( $intents as $intent ) {
	echo "  - " . $intent . "\n";
}
if ( count( $intents ) === 15 ) {
	test_output( '✓ Test 14 passed: All intents defined', 'success' );
} else {
	test_output( '✗ Test 14 failed: Expected 15 intents, got ' . count( $intents ), 'error' );
}
echo "\n";

// Test 15: Test to_array method.
test_output( 'Test 15: Test to_array method', 'info' );
$test15_data = array(
	'type'    => 'text',
	'content' => array(
		'body' => 'Hello',
	),
);
$parsed15 = $parser->parse( $test15_data );
$array15 = $parsed15->to_array();
if ( is_array( $array15 ) &&
     isset( $array15['type'] ) &&
     isset( $array15['intent'] ) &&
     isset( $array15['parsed_data'] ) ) {
	test_output( '✓ Test 15 passed: to_array works', 'success' );
} else {
	test_output( '✗ Test 15 failed: to_array not working properly', 'error' );
}
echo "\n";

test_output( '=== All tests completed ===', 'info' );
echo "\n";
