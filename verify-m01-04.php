<?php
/**
 * Verification script for M01-04 implementation
 *
 * Verifies all acceptance criteria are met.
 */

// Simulate WordPress environment.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mock WordPress functions.
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
	function register_activation_hook( $file, $function ) {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $function ) {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $function, $priority = 10 ) {}
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

// Load plugin.
require_once __DIR__ . '/whatsapp-commerce-hub.php';

echo "\n";
echo "=== M01-04 Acceptance Criteria Verification ===\n\n";

$all_passed = true;

// Acceptance Criterion 1: All WhatsApp message types parsed correctly.
echo "✓ Criterion 1: All WhatsApp message types parsed correctly\n";
$parser = new WCH_Response_Parser();
$message_types = array( 'text', 'interactive', 'location', 'image', 'document', 'order' );
foreach ( $message_types as $type ) {
	$test_data = array( 'type' => $type, 'content' => array() );
	$parsed = $parser->parse( $test_data );
	if ( ! $parsed instanceof WCH_Parsed_Response ) {
		echo "  ✗ Failed to parse message type: $type\n";
		$all_passed = false;
	}
}
echo "  All message types parse successfully\n\n";

// Acceptance Criterion 2: Intents detected accurately for common phrases.
echo "✓ Criterion 2: Intents detected accurately for common phrases\n";
$intent_tests = array(
	array( 'text' => 'hi', 'expected' => WCH_Response_Parser::INTENT_GREETING ),
	array( 'text' => 'hello', 'expected' => WCH_Response_Parser::INTENT_GREETING ),
	array( 'text' => 'order', 'expected' => WCH_Response_Parser::INTENT_ORDER_STATUS ),
	array( 'text' => 'track', 'expected' => WCH_Response_Parser::INTENT_TRACK_SHIPPING ),
	array( 'text' => 'help', 'expected' => WCH_Response_Parser::INTENT_HELP ),
	array( 'text' => 'support', 'expected' => WCH_Response_Parser::INTENT_HELP ),
	array( 'text' => 'cart', 'expected' => WCH_Response_Parser::INTENT_VIEW_CART ),
	array( 'text' => 'basket', 'expected' => WCH_Response_Parser::INTENT_VIEW_CART ),
	array( 'text' => 'checkout', 'expected' => WCH_Response_Parser::INTENT_CHECKOUT ),
	array( 'text' => 'pay', 'expected' => WCH_Response_Parser::INTENT_CHECKOUT ),
);

$intent_passed = true;
foreach ( $intent_tests as $test ) {
	$detected = $parser->detect_intent( $test['text'] );
	if ( $detected !== $test['expected'] ) {
		echo "  ✗ Failed: '{$test['text']}' detected as $detected, expected {$test['expected']}\n";
		$intent_passed = false;
		$all_passed = false;
	}
}

if ( $intent_passed ) {
	echo "  All common phrases detected correctly\n";
}
echo "\n";

// Acceptance Criterion 3: Unknown intents gracefully handled.
echo "✓ Criterion 3: Unknown intents gracefully handled\n";
$unknown_tests = array(
	'xyz123',
	'random gibberish',
	'',
	null,
);

foreach ( $unknown_tests as $test ) {
	$detected = $parser->detect_intent( $test );
	if ( $detected !== WCH_Response_Parser::INTENT_UNKNOWN ) {
		echo "  ✗ Failed: Unknown text should return INTENT_UNKNOWN\n";
		$all_passed = false;
	}
}
echo "  Unknown intents return INTENT_UNKNOWN as expected\n\n";

// Acceptance Criterion 4: Parser is extensible via filters.
echo "✓ Criterion 4: Parser is extensible via filters\n";

// Test wch_parse_response filter.
$filter_test_passed = true;
add_filter( 'wch_parse_response', function( $parsed, $data ) {
	$parsed->parsed_data['custom'] = 'test';
	return $parsed;
}, 10, 2 );

$test_data = array(
	'type' => 'text',
	'content' => array( 'body' => 'test' ),
);
$parsed = $parser->parse( $test_data );

if ( ! isset( $parsed->get_parsed_data()['custom'] ) || $parsed->get_parsed_data()['custom'] !== 'test' ) {
	echo "  ✗ wch_parse_response filter not working\n";
	$filter_test_passed = false;
	$all_passed = false;
}

// Test wch_detected_intent filter.
$GLOBALS['wch_filters'] = array(); // Reset filters
add_filter( 'wch_detected_intent', function( $intent, $text, $keyword ) {
	// Override GREETING intent to HELP for testing
	if ( $intent === WCH_Response_Parser::INTENT_GREETING ) {
		return WCH_Response_Parser::INTENT_HELP;
	}
	return $intent;
}, 10, 3 );

// Create new parser instance after adding filter
$parser2 = new WCH_Response_Parser();
$detected = $parser2->detect_intent( 'hello there' );
if ( $detected !== WCH_Response_Parser::INTENT_HELP ) {
	echo "  ✗ wch_detected_intent filter not working (got: $detected)\n";
	$filter_test_passed = false;
	$all_passed = false;
}

if ( $filter_test_passed ) {
	echo "  Filters wch_parse_response and wch_detected_intent work correctly\n";
}
echo "\n";

// Additional checks.
echo "Additional Checks:\n";

// Check WCH_Parsed_Response class exists.
if ( class_exists( 'WCH_Parsed_Response' ) ) {
	echo "  ✓ WCH_Parsed_Response class exists\n";
} else {
	echo "  ✗ WCH_Parsed_Response class not found\n";
	$all_passed = false;
}

// Check WCH_Response_Parser class exists.
if ( class_exists( 'WCH_Response_Parser' ) ) {
	echo "  ✓ WCH_Response_Parser class exists\n";
} else {
	echo "  ✗ WCH_Response_Parser class not found\n";
	$all_passed = false;
}

// Check parse method exists.
if ( method_exists( 'WCH_Response_Parser', 'parse' ) ) {
	echo "  ✓ parse() method exists\n";
} else {
	echo "  ✗ parse() method not found\n";
	$all_passed = false;
}

// Check detect_intent method exists.
if ( method_exists( 'WCH_Response_Parser', 'detect_intent' ) ) {
	echo "  ✓ detect_intent() method exists\n";
} else {
	echo "  ✗ detect_intent() method not found\n";
	$all_passed = false;
}

// Check all intent constants are defined.
$expected_intents = array(
	'INTENT_GREETING',
	'INTENT_BROWSE_CATALOG',
	'INTENT_VIEW_CATEGORY',
	'INTENT_SEARCH_PRODUCT',
	'INTENT_VIEW_PRODUCT',
	'INTENT_ADD_TO_CART',
	'INTENT_VIEW_CART',
	'INTENT_MODIFY_CART',
	'INTENT_CHECKOUT',
	'INTENT_APPLY_COUPON',
	'INTENT_ORDER_STATUS',
	'INTENT_TRACK_SHIPPING',
	'INTENT_HELP',
	'INTENT_TALK_TO_HUMAN',
	'INTENT_UNKNOWN',
);

$all_intents_defined = true;
foreach ( $expected_intents as $intent_const ) {
	if ( ! defined( "WCH_Response_Parser::$intent_const" ) ) {
		echo "  ✗ Constant $intent_const not defined\n";
		$all_intents_defined = false;
		$all_passed = false;
	}
}

if ( $all_intents_defined ) {
	echo "  ✓ All 15 intent constants defined\n";
}

echo "\n";

// Final result.
if ( $all_passed ) {
	echo "=== ✓ ALL ACCEPTANCE CRITERIA PASSED ===\n";
	exit( 0 );
} else {
	echo "=== ✗ SOME ACCEPTANCE CRITERIA FAILED ===\n";
	exit( 1 );
}
