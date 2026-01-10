<?php
/**
 * Integration tests for WCH_Webhook_Handler
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Webhook_Handler integration.
 */
class WCH_Webhook_Test extends WCH_Integration_Test_Case {

	/**
	 * Webhook handler instance.
	 *
	 * @var WCH_Webhook_Handler
	 */
	private $webhook_handler;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->webhook_handler = new WCH_Webhook_Handler();
		$this->mock_whatsapp_success();
	}

	/**
	 * Test processing incoming text message.
	 */
	public function test_process_incoming_text_message() {
		$payload = $this->get_fixture( 'webhook_text_message.json' );

		$result = $this->webhook_handler->process( $payload );

		$this->assertTrue( $result );

		// Verify message was stored.
		$this->assertDatabaseHas( 'wch_messages', [
			'message_type' => 'text',
			'direction' => 'incoming',
		] );
	}

	/**
	 * Test processing button reply.
	 */
	public function test_process_button_reply() {
		$payload = $this->get_fixture( 'webhook_button_reply.json' );

		$result = $this->webhook_handler->process( $payload );

		$this->assertTrue( $result );
	}

	/**
	 * Test processing list reply.
	 */
	public function test_process_list_reply() {
		$payload = $this->get_fixture( 'webhook_list_reply.json' );

		$result = $this->webhook_handler->process( $payload );

		$this->assertTrue( $result );
	}

	/**
	 * Test webhook signature verification.
	 */
	public function test_verify_webhook_signature() {
		$payload = json_encode( [ 'test' => 'data' ] );
		$secret = 'test_secret_key';

		$signature = hash_hmac( 'sha256', $payload, $secret );

		$result = $this->webhook_handler->verify_signature( $payload, $signature, $secret );

		$this->assertTrue( $result );
	}

	/**
	 * Test rejecting invalid signature.
	 */
	public function test_reject_invalid_signature() {
		$payload = json_encode( [ 'test' => 'data' ] );
		$secret = 'test_secret_key';
		$invalid_signature = 'invalid_signature';

		$result = $this->webhook_handler->verify_signature( $payload, $invalid_signature, $secret );

		$this->assertFalse( $result );
	}

	/**
	 * Test handling message status update.
	 */
	public function test_process_status_update() {
		$payload = $this->get_fixture( 'webhook_status_update.json' );

		$result = $this->webhook_handler->process( $payload );

		$this->assertTrue( $result );
	}

	/**
	 * Test creating conversation from new contact.
	 */
	public function test_create_conversation_from_new_contact() {
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry' => [
				array(
					'changes' => array(
						array(
							'value' => array(
								'messages' => array(
									array(
										'from' => '+9876543210',
										'id' => 'wamid.test123',
										'timestamp' => time(),
										'type' => 'text',
										'text' => array( 'body' => 'Hello' ),
									),
								),
							),
						),
					),
				),
			],
		];

		$this->webhook_handler->process( $payload );

		$this->assertDatabaseHas( 'wch_conversations', [
			'customer_phone' => '+9876543210',
		] );
	}

	/**
	 * Test processing image message.
	 */
	public function test_process_image_message() {
		$payload = $this->get_fixture( 'webhook_image_message.json' );

		$result = $this->webhook_handler->process( $payload );

		$this->assertTrue( $result );
	}

	/**
	 * Test processing location message.
	 */
	public function test_process_location_message() {
		$payload = $this->get_fixture( 'webhook_location_message.json' );

		$result = $this->webhook_handler->process( $payload );

		$this->assertTrue( $result );
	}

	/**
	 * Test duplicate message detection.
	 */
	public function test_detect_duplicate_messages() {
		$payload = $this->get_fixture( 'webhook_text_message.json' );

		// Process first time.
		$result1 = $this->webhook_handler->process( $payload );
		$this->assertTrue( $result1 );

		// Process same message again.
		$result2 = $this->webhook_handler->process( $payload );
		$this->assertFalse( $result2 ); // Should be rejected as duplicate
	}

	/**
	 * Test webhook verification challenge.
	 */
	public function test_handle_verification_challenge() {
		$_GET['hub_mode'] = 'subscribe';
		$_GET['hub_verify_token'] = 'test_token';
		$_GET['hub_challenge'] = 'challenge_string';

		$result = $this->webhook_handler->handle_verification( 'test_token' );

		$this->assertEquals( 'challenge_string', $result );
	}

	/**
	 * Helper to get fixture data.
	 *
	 * @param string $filename Fixture filename.
	 * @return array
	 */
	private function get_fixture( string $filename ): array {
		$file_path = dirname( __DIR__ ) . '/fixtures/' . $filename;

		if ( ! file_exists( $file_path ) ) {
			// Return minimal valid payload.
			return [
				'object' => 'whatsapp_business_account',
				'entry' => [
					array(
						'changes' => array(
							array(
								'value' => array(
									'messages' => array(
										array(
											'from' => '+1234567890',
											'id' => 'wamid.' . wp_generate_uuid4(),
											'timestamp' => time(),
											'type' => 'text',
											'text' => array( 'body' => 'Test message' ),
										),
									),
								),
							),
						),
					),
				],
			];
		}

		return json_decode( file_get_contents( $file_path ), true );
	}
}
