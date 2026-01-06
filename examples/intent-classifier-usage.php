<?php
/**
 * Intent Classifier Usage Examples
 *
 * Demonstrates how to use the WCH_Intent_Classifier.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example 1: Basic intent classification
 */
function wch_example_basic_classification() {
	$classifier = new WCH_Intent_Classifier();

	// Classify a greeting
	$intent = $classifier->classify( 'Hello! Good morning' );
	echo "Intent: {$intent->intent_name}\n";
	echo "Confidence: {$intent->confidence}\n";
	// Output: Intent: GREETING, Confidence: 0.95

	// Classify a search query
	$intent = $classifier->classify( 'I am looking for a blue shirt' );
	echo "Intent: {$intent->intent_name}\n";
	echo "Confidence: {$intent->confidence}\n";
	// Check for extracted entities
	if ( $intent->has_entity( 'PRODUCT_NAME' ) ) {
		$product = $intent->get_entity( 'PRODUCT_NAME' );
		echo "Product: {$product['value']}\n";
		// Output: Product: a blue shirt
	}

	// Classify checkout intent
	$intent = $classifier->classify( 'I want to checkout now' );
	echo "Intent: {$intent->intent_name}\n";
	// Output: Intent: CHECKOUT
}

/**
 * Example 2: Classification with context
 */
function wch_example_classification_with_context() {
	$classifier = new WCH_Intent_Classifier();

	// Provide context about current conversation state
	$context = array(
		'current_state'  => 'VIEWING_PRODUCT',
		'customer_phone' => '+1234567890',
	);

	$intent = $classifier->classify( 'Add 2 to cart', $context );
	echo "Intent: {$intent->intent_name}\n";

	// Check for quantity entity
	if ( $intent->has_entity( 'QUANTITY' ) ) {
		$quantity = $intent->get_entity( 'QUANTITY' );
		echo "Quantity: {$quantity['value']}\n";
		// Output: Quantity: 2
	}
}

/**
 * Example 3: Entity extraction
 */
function wch_example_entity_extraction() {
	$classifier = new WCH_Intent_Classifier();

	// Message with multiple entities
	$intent = $classifier->classify( 'Check status of order #12345' );

	echo "Intent: {$intent->intent_name}\n";
	// Output: Intent: ORDER_STATUS

	// Extract order number
	if ( $intent->has_entity( 'ORDER_NUMBER' ) ) {
		$order_number = $intent->get_entity( 'ORDER_NUMBER' );
		echo "Order Number: {$order_number['value']}\n";
		// Output: Order Number: 12345
	}

	// Message with contact information
	$intent = $classifier->classify( 'My email is john@example.com and phone is +1-234-567-8900' );

	foreach ( $intent->entities as $entity ) {
		echo "{$entity['type']}: {$entity['value']}\n";
		// Output: EMAIL: john@example.com
		// Output: PHONE: +12345678900
	}
}

/**
 * Example 4: Register custom intents
 */
function wch_example_custom_intents() {
	// Add custom intents via filter
	add_filter(
		'wch_custom_intents',
		function( $intents ) {
			$intents['REFUND_REQUEST'] = array(
				'regex'      => '/(refund|money back|return)/i',
				'confidence' => 0.9,
			);
			$intents['COMPLAINT'] = array(
				'regex'      => '/(complain|complaint|not happy|dissatisfied)/i',
				'confidence' => 0.85,
			);
			return $intents;
		}
	);

	$classifier = new WCH_Intent_Classifier();

	$intent = $classifier->classify( 'I want a refund for my order' );
	echo "Intent: {$intent->intent_name}\n";
	// Output: Intent: REFUND_REQUEST
}

/**
 * Example 5: Working with intent results
 */
function wch_example_working_with_results() {
	$classifier = new WCH_Intent_Classifier();

	$intent = $classifier->classify( 'Find me 3 red shoes size 42' );

	// Convert to array
	$intent_array = $intent->to_array();
	print_r( $intent_array );

	// Convert to JSON
	$intent_json = $intent->to_json();
	echo $intent_json . "\n";

	// Get all entities of a specific type
	$quantities = $intent->get_entities_by_type( 'QUANTITY' );
	foreach ( $quantities as $qty ) {
		echo "Found quantity: {$qty['value']}\n";
	}
}

/**
 * Example 6: Integration with conversation flow
 */
function wch_example_conversation_integration( $user_message, $conversation_context ) {
	$classifier = new WCH_Intent_Classifier();

	// Classify the user's intent
	$intent = $classifier->classify( $user_message, $conversation_context->to_array() );

	// Handle different intents
	switch ( $intent->intent_name ) {
		case WCH_Intent::INTENT_GREETING:
			return 'Hello! Welcome to our store. How can I help you today?';

		case WCH_Intent::INTENT_BROWSE:
			return wch_show_product_categories();

		case WCH_Intent::INTENT_SEARCH:
			if ( $intent->has_entity( 'PRODUCT_NAME' ) ) {
				$product_name = $intent->get_entity( 'PRODUCT_NAME' )['value'];
				return wch_search_products( $product_name );
			}
			return 'What would you like to search for?';

		case WCH_Intent::INTENT_VIEW_CART:
			return wch_show_cart();

		case WCH_Intent::INTENT_CHECKOUT:
			return wch_start_checkout();

		case WCH_Intent::INTENT_ORDER_STATUS:
			if ( $intent->has_entity( 'ORDER_NUMBER' ) ) {
				$order_number = $intent->get_entity( 'ORDER_NUMBER' )['value'];
				return wch_get_order_status( $order_number );
			}
			return 'Please provide your order number.';

		case WCH_Intent::INTENT_HELP:
			return wch_transfer_to_human_agent();

		case WCH_Intent::INTENT_UNKNOWN:
		default:
			return "I'm not sure I understand. Could you please rephrase that?";
	}
}

/**
 * Example 7: Get classifier statistics
 */
function wch_example_get_statistics() {
	$classifier = new WCH_Intent_Classifier();
	$stats = $classifier->get_statistics();

	echo "Total Patterns: {$stats['patterns_count']}\n";
	echo "Custom Intents: {$stats['custom_intents_count']}\n";
	echo "AI Enabled: " . ( $stats['ai_enabled'] ? 'Yes' : 'No' ) . "\n";
	echo "Cache Expiration: {$stats['cache_expiration']} seconds\n";
}

// Placeholder functions for the example
function wch_show_product_categories() {
	return 'Showing categories...';
}

function wch_search_products( $query ) {
	return "Searching for: {$query}...";
}

function wch_show_cart() {
	return 'Showing your cart...';
}

function wch_start_checkout() {
	return 'Starting checkout...';
}

function wch_get_order_status( $order_number ) {
	return "Getting status for order #{$order_number}...";
}

function wch_transfer_to_human_agent() {
	return 'Transferring you to a human agent...';
}
