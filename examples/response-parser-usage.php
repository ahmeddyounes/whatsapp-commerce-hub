<?php
/**
 * WCH Response Parser Usage Examples
 *
 * This file demonstrates how to use the WCH_Response_Parser class
 * to parse WhatsApp webhook messages.
 *
 * @package WhatsApp_Commerce_Hub
 */

// This example assumes WordPress is loaded.
require_once __DIR__ . '/../whatsapp-commerce-hub.php';

/**
 * Example 1: Basic Text Message Parsing
 */
function example_basic_text_parsing() {
	$parser = new WCH_Response_Parser();

	// Simulate webhook message data for a text message.
	$webhook_data = [
		'type'    => 'text',
		'content' => array(
			'body' => 'Hello! I want to see your products',
		),
	];

	$parsed = $parser->parse( $webhook_data );

	echo "Type: " . $parsed->get_type() . "\n";
	echo "Intent: " . $parsed->get_intent() . "\n";
	echo "Text: " . $parsed->get_parsed_data()['text'] . "\n";

	// Check for specific intent.
	if ( $parsed->has_intent( WCH_Response_Parser::INTENT_BROWSE_CATALOG ) ) {
		echo "Customer wants to browse the catalog!\n";
	}
}

/**
 * Example 2: Button Reply Parsing
 */
function example_button_reply_parsing() {
	$parser = new WCH_Response_Parser();

	$webhook_data = [
		'type'    => 'interactive',
		'content' => array(
			'type'  => 'button_reply',
			'id'    => 'btn_checkout',
			'title' => 'Checkout',
		),
	];

	$parsed = $parser->parse( $webhook_data );

	echo "Button ID: " . $parsed->get_parsed_data()['button_id'] . "\n";
	echo "Button Title: " . $parsed->get_parsed_data()['button_title'] . "\n";
	echo "Intent: " . $parsed->get_intent() . "\n";
}

/**
 * Example 3: List Reply Parsing
 */
function example_list_reply_parsing() {
	$parser = new WCH_Response_Parser();

	$webhook_data = [
		'type'    => 'interactive',
		'content' => array(
			'type'        => 'list_reply',
			'id'          => 'product_123',
			'title'       => 'Blue T-Shirt',
			'description' => 'Size: M, Color: Blue',
		),
	];

	$parsed = $parser->parse( $webhook_data );

	echo "Selected Item: " . $parsed->get_parsed_data()['title'] . "\n";
	echo "Description: " . $parsed->get_parsed_data()['description'] . "\n";
}

/**
 * Example 4: Product Inquiry Parsing
 */
function example_product_inquiry_parsing() {
	$parser = new WCH_Response_Parser();

	$webhook_data = [
		'type'    => 'interactive',
		'content' => array(
			'type'                => 'nfm_reply',
			'product_retailer_id' => 'woo_product_789',
			'catalog_id'          => 'my_catalog',
		),
	];

	$parsed = $parser->parse( $webhook_data );

	echo "Product ID: " . $parsed->get_parsed_data()['product_retailer_id'] . "\n";
	echo "Catalog ID: " . $parsed->get_parsed_data()['catalog_id'] . "\n";
	echo "Intent: " . $parsed->get_intent() . "\n"; // Should be VIEW_PRODUCT
}

/**
 * Example 5: Using Custom Filters
 */
function example_custom_filters() {
	// Add custom intent keywords.
	add_filter( 'wch_intent_keywords', function( $keywords ) {
		// Add custom keywords for the HELP intent.
		$keywords[ WCH_Response_Parser::INTENT_HELP ][] = 'stuck';
		$keywords[ WCH_Response_Parser::INTENT_HELP ][] = 'confused';
		return $keywords;
	});

	// Modify parsed response with custom logic.
	add_filter( 'wch_parse_response', function( $parsed_response, $webhook_data ) {
		// Add custom metadata.
		if ( $parsed_response->get_type() === 'text' ) {
			$parsed_response->parsed_data['timestamp'] = time();
			$parsed_response->parsed_data['source'] = 'whatsapp';
		}
		return $parsed_response;
	}, 10, 2 );

	$parser = new WCH_Response_Parser();

	$webhook_data = [
		'type'    => 'text',
		'content' => array(
			'body' => 'I am stuck on the checkout page',
		),
	];

	$parsed = $parser->parse( $webhook_data );

	echo "Intent: " . $parsed->get_intent() . "\n"; // Should be HELP
	echo "Has custom timestamp: " . ( isset( $parsed->get_parsed_data()['timestamp'] ) ? 'Yes' : 'No' ) . "\n";
}

/**
 * Example 6: Storing and Retrieving Conversation Context
 */
function example_conversation_context() {
	$parser = new WCH_Response_Parser();

	$phone_number = '+1234567890';

	// Parse a message.
	$webhook_data = [
		'type'    => 'text',
		'content' => array(
			'body' => 'Show me my cart',
		),
	];

	$parsed = $parser->parse( $webhook_data );

	// Store in conversation context.
	$parser->store_in_context( $phone_number, $parsed );

	// Later, retrieve the context.
	$context = $parser->get_context( $phone_number );

	if ( $context && isset( $context['last_intent'] ) ) {
		echo "Last Intent: " . $context['last_intent'] . "\n";
		echo "Conversation History: " . count( $context['parsed_responses'] ) . " messages\n";
	}
}

/**
 * Example 7: Handling All Message Types in a Webhook Handler
 */
function example_webhook_integration( $webhook_message_data ) {
	$parser = new WCH_Response_Parser();
	$parsed = $parser->parse( $webhook_message_data );

	// Handle different message types.
	switch ( $parsed->get_type() ) {
		case 'text':
			handle_text_message( $parsed );
			break;

		case 'button_reply':
			handle_button_reply( $parsed );
			break;

		case 'list_reply':
			handle_list_reply( $parsed );
			break;

		case 'product_inquiry':
			handle_product_inquiry( $parsed );
			break;

		case 'location':
			handle_location( $parsed );
			break;

		case 'image':
		case 'document':
			handle_media( $parsed );
			break;

		default:
			handle_unknown_message( $parsed );
			break;
	}
}

function handle_text_message( $parsed ) {
	// Handle based on detected intent.
	switch ( $parsed->get_intent() ) {
		case WCH_Response_Parser::INTENT_GREETING:
			// Send welcome message.
			break;

		case WCH_Response_Parser::INTENT_VIEW_CART:
			// Send cart summary.
			break;

		case WCH_Response_Parser::INTENT_CHECKOUT:
			// Initiate checkout flow.
			break;

		case WCH_Response_Parser::INTENT_ORDER_STATUS:
			// Show order status.
			break;

		case WCH_Response_Parser::INTENT_HELP:
			// Show help menu.
			break;

		default:
			// Default response or AI fallback.
			break;
	}
}

function handle_button_reply( $parsed ) {
	$button_id = $parsed->get_parsed_data()['button_id'];
	// Process button action based on ID.
	echo "Processing button: " . $button_id . "\n";
}

function handle_list_reply( $parsed ) {
	$selected_id = $parsed->get_parsed_data()['list_id'];
	// Process list selection.
	echo "Processing selection: " . $selected_id . "\n";
}

function handle_product_inquiry( $parsed ) {
	$product_id = $parsed->get_parsed_data()['product_retailer_id'];
	// Show product details.
	echo "Showing product: " . $product_id . "\n";
}

function handle_location( $parsed ) {
	$lat = $parsed->get_parsed_data()['latitude'];
	$lng = $parsed->get_parsed_data()['longitude'];
	// Process location.
	echo "Received location: " . $lat . ", " . $lng . "\n";
}

function handle_media( $parsed ) {
	$media_id = $parsed->get_parsed_data()['media_id'];
	// Download and process media.
	echo "Processing media: " . $media_id . "\n";
}

function handle_unknown_message( $parsed ) {
	// Log unknown message type.
	echo "Unknown message type: " . $parsed->get_type() . "\n";
}

// Run examples if called directly.
if ( php_sapi_name() === 'cli' && basename( __FILE__ ) === basename( $_SERVER['PHP_SELF'] ) ) {
	echo "=== WCH Response Parser Usage Examples ===\n\n";

	echo "Example 1: Basic Text Parsing\n";
	example_basic_text_parsing();
	echo "\n";

	echo "Example 2: Button Reply Parsing\n";
	example_button_reply_parsing();
	echo "\n";

	echo "Example 3: List Reply Parsing\n";
	example_list_reply_parsing();
	echo "\n";

	echo "Example 4: Product Inquiry Parsing\n";
	example_product_inquiry_parsing();
	echo "\n";

	echo "Example 5: Custom Filters\n";
	example_custom_filters();
	echo "\n";
}
