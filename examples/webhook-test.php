<?php
/**
 * WhatsApp Webhook Test Script
 *
 * This script demonstrates how to test the webhook handler locally
 * before configuring it with Meta.
 *
 * Usage:
 * 1. Set the webhook secret and verify token in WordPress admin
 * 2. Run this script to test webhook verification and event handling
 *
 * @package WhatsApp_Commerce_Hub
 */

// WordPress environment bootstrap for standalone testing.
// Adjust this path if needed.
$wp_load_path = dirname( __FILE__, 5 ) . '/wp-load.php';

if ( ! file_exists( $wp_load_path ) ) {
	die( "Error: Could not find WordPress. Please adjust the path to wp-load.php\n" );
}

require_once $wp_load_path;

// Ensure plugin is active.
if ( ! class_exists( 'WCH_Settings' ) ) {
	die( "Error: WhatsApp Commerce Hub plugin is not active.\n" );
}

echo "WhatsApp Webhook Test Script\n";
echo "=============================\n\n";

// Get settings.
$settings = WCH_Settings::getInstance();

// Test 1: Webhook Verification (GET request).
echo "Test 1: Webhook Verification\n";
echo "-----------------------------\n";

$verify_token = 'test_verify_token_123';
$challenge    = '1234567890';

// Set verify token in settings.
$settings->set( 'api.webhook_verify_token', $verify_token );

echo "Set verify token: " . substr( $verify_token, 0, 10 ) . "...\n";

// Simulate GET request to webhook endpoint.
$verify_url = rest_url( 'wch/v1/webhook' );
$verify_url = add_query_arg(
	[
		'hub_mode'         => 'subscribe',
		'hub_verify_token' => $verify_token,
		'hub_challenge'    => $challenge,
	],
	$verify_url
);

echo "Verification URL: " . $verify_url . "\n";
echo "Expected response: " . $challenge . "\n\n";

// Test 2: Webhook Signature Validation.
echo "Test 2: Signature Validation\n";
echo "-----------------------------\n";

$webhook_secret = 'test_webhook_secret_456';
$settings->set( 'api.webhook_secret', $webhook_secret );

echo "Set webhook secret: " . substr( $webhook_secret, 0, 10 ) . "...\n";

// Create test payload.
$test_payload = [
	'object' => 'whatsapp_business_account',
	'entry'  => array(
		array(
			'id'      => '123456789',
			'changes' => array(
				array(
					'field' => 'messages',
					'value' => array(
						'messaging_product' => 'whatsapp',
						'metadata'          => array(
							'display_phone_number' => '15551234567',
							'phone_number_id'      => '123456789',
						),
						'messages'          => array(
							array(
								'id'        => 'wamid.test123',
								'from'      => '15559876543',
								'timestamp' => time(),
								'type'      => 'text',
								'text'      => array(
									'body' => 'Hello, this is a test message!',
								),
							),
						),
					),
				),
			),
		),
	),
];

$payload_json = wp_json_encode( $test_payload );
$signature    = 'sha256=' . hash_hmac( 'sha256', $payload_json, $webhook_secret );

echo "Test payload created\n";
echo "Signature: " . substr( $signature, 0, 20 ) . "...\n\n";

// Test 3: Message Event Processing.
echo "Test 3: Message Event Processing\n";
echo "----------------------------------\n";

// Manually trigger the webhook handler to test event processing.
$webhook_handler = new WCH_Webhook_Handler();

// Create a mock request object.
$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
$request->set_body( $payload_json );
$request->set_header( 'X-Hub-Signature-256', $signature );

echo "Sending test webhook request...\n";

// Process the webhook.
$response = $webhook_handler->handle_webhook( $request );

if ( is_wp_error( $response ) ) {
	echo "Error: " . $response->get_error_message() . "\n";
} else {
	$data = $response->get_data();
	echo "Success: " . wp_json_encode( $data ) . "\n";
}

echo "\n";

// Test 4: Status Event.
echo "Test 4: Status Event Processing\n";
echo "--------------------------------\n";

$status_payload = [
	'object' => 'whatsapp_business_account',
	'entry'  => array(
		array(
			'id'      => '123456789',
			'changes' => array(
				array(
					'field' => 'statuses',
					'value' => array(
						'messaging_product' => 'whatsapp',
						'metadata'          => array(
							'display_phone_number' => '15551234567',
							'phone_number_id'      => '123456789',
						),
						'statuses'          => array(
							array(
								'id'           => 'wamid.test123',
								'status'       => 'delivered',
								'timestamp'    => time(),
								'recipient_id' => '15559876543',
							),
						),
					),
				),
			),
		),
	),
];

$status_json      = wp_json_encode( $status_payload );
$status_signature = 'sha256=' . hash_hmac( 'sha256', $status_json, $webhook_secret );

$status_request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
$status_request->set_body( $status_json );
$status_request->set_header( 'X-Hub-Signature-256', $status_signature );

echo "Sending status update webhook...\n";

$status_response = $webhook_handler->handle_webhook( $status_request );

if ( is_wp_error( $status_response ) ) {
	echo "Error: " . $status_response->get_error_message() . "\n";
} else {
	$data = $status_response->get_data();
	echo "Success: " . wp_json_encode( $data ) . "\n";
}

echo "\n";

// Test 5: Error Event.
echo "Test 5: Error Event Processing\n";
echo "-------------------------------\n";

$error_payload = [
	'object' => 'whatsapp_business_account',
	'entry'  => array(
		array(
			'id'      => '123456789',
			'changes' => array(
				array(
					'field' => 'errors',
					'value' => array(
						'code'       => 131047,
						'title'      => 'Message failed to send',
						'message'    => 'Re-engagement message cannot be sent more than once in a 24 hour period',
						'error_data' => array(
							'details' => 'Rate limit exceeded',
						),
					),
				),
			),
		),
	),
];

$error_json      = wp_json_encode( $error_payload );
$error_signature = 'sha256=' . hash_hmac( 'sha256', $error_json, $webhook_secret );

$error_request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
$error_request->set_body( $error_json );
$error_request->set_header( 'X-Hub-Signature-256', $error_signature );

echo "Sending error webhook...\n";

$error_response = $webhook_handler->handle_webhook( $error_request );

if ( is_wp_error( $error_response ) ) {
	echo "Error: " . $error_response->get_error_message() . "\n";
} else {
	$data = $error_response->get_data();
	echo "Success: " . wp_json_encode( $data ) . "\n";
}

echo "\n";

// Test 6: Idempotency Check.
echo "Test 6: Idempotency Check\n";
echo "-------------------------\n";

echo "Sending duplicate message (should be ignored)...\n";

$duplicate_request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
$duplicate_request->set_body( $payload_json );
$duplicate_request->set_header( 'X-Hub-Signature-256', $signature );

$duplicate_response = $webhook_handler->handle_webhook( $duplicate_request );

if ( is_wp_error( $duplicate_response ) ) {
	echo "Error: " . $duplicate_response->get_error_message() . "\n";
} else {
	$data = $duplicate_response->get_data();
	echo "Success (duplicate should be logged but not processed): " . wp_json_encode( $data ) . "\n";
}

echo "\n";

// Test 7: Invalid Signature.
echo "Test 7: Invalid Signature\n";
echo "-------------------------\n";

$invalid_request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
$invalid_request->set_body( $payload_json );
$invalid_request->set_header( 'X-Hub-Signature-256', 'sha256=invalid_signature_here' );

echo "Sending webhook with invalid signature...\n";

$invalid_response = $webhook_handler->handle_webhook( $invalid_request );

if ( is_wp_error( $invalid_response ) ) {
	echo "Expected error: " . $invalid_response->get_error_message() . "\n";
} else {
	echo "Unexpected: Request should have been rejected!\n";
}

echo "\n";
echo "=============================\n";
echo "All tests completed!\n";
echo "Check the WCH logs (WP Admin > WCH > Logs) for detailed processing information.\n";
