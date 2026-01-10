<?php
/**
 * Webhook Integration Tests
 *
 * Tests webhook verification, signature validation, and message processing.
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Class WCH_Webhook_Integration_Test
 *
 * Integration tests for webhook handler.
 */
class WCH_Webhook_Integration_Test extends WCH_Integration_Test_Case {

	/**
	 * Webhook handler instance.
	 *
	 * @var WCH_Webhook_Handler
	 */
	private $webhook_handler;

	/**
	 * Test verify token.
	 *
	 * @var string
	 */
	private $verify_token = 'test_verify_token_123';

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->webhook_handler = new WCH_Webhook_Handler();

		// Set verify token in settings.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.verify_token', $this->verify_token );
		$settings->set( 'api.phone_number_id', 'test_phone_number_id' );
		$settings->set( 'api.access_token', 'test_access_token' );

		// Mock WhatsApp API responses.
		$this->mock_whatsapp_success();
	}

	/**
	 * Test webhook verification with valid token.
	 */
	public function test_webhook_verification_valid_token() {
		// Arrange.
		$challenge = 'test_challenge_' . wp_generate_uuid4();
		$request   = new WP_REST_Request( 'GET', '/wch/v1/webhook' );
		$request->set_query_params(
			[
				'hub.mode'         => 'subscribe',
				'hub.verify_token' => $this->verify_token,
				'hub.challenge'    => $challenge,
			]
		);

		// Act.
		$response = $this->webhook_handler->verify_webhook( $request );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $challenge, $response->get_data() );
	}

	/**
	 * Test webhook verification with invalid token.
	 */
	public function test_webhook_verification_invalid_token() {
		// Arrange.
		$challenge = 'test_challenge_' . wp_generate_uuid4();
		$request   = new WP_REST_Request( 'GET', '/wch/v1/webhook' );
		$request->set_query_params(
			[
				'hub.mode'         => 'subscribe',
				'hub.verify_token' => 'invalid_token',
				'hub.challenge'    => $challenge,
			]
		);

		// Act.
		$response = $this->webhook_handler->verify_webhook( $request );

		// Assert.
		$this->assertEquals( 403, $response->get_status() );
		$this->assertArrayHasKey( 'code', $response->get_data() );
		$this->assertEquals( 'invalid_verify_token', $response->get_data()['code'] );
	}

	/**
	 * Test webhook signature validation success.
	 */
	public function test_webhook_signature_validation_success() {
		// Arrange.
		$payload   = wp_json_encode( [ 'test' => 'data' ] );
		$secret    = 'webhook_secret_123';
		$signature = hash_hmac( 'sha256', $payload, $secret );

		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.app_secret', $secret );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=' . $signature );
		$request->set_body( $payload );

		// Create minimal valid webhook payload.
		$webhook_payload = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => 'test_business_id',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '+1234567890',
									'phone_number_id'      => 'test_phone_number_id',
								],
								'contacts'          => [],
								'messages'          => [],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		$payload = wp_json_encode( $webhook_payload );
		$signature = hash_hmac( 'sha256', $payload, $secret );

		$request->set_body( $payload );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=' . $signature );

		// Act.
		$response = $this->webhook_handler->handle_webhook( $request );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test webhook signature validation failure.
	 */
	public function test_webhook_signature_validation_failure() {
		// Arrange.
		$payload   = wp_json_encode( [ 'test' => 'data' ] );
		$secret    = 'webhook_secret_123';
		$signature = 'invalid_signature';

		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.app_secret', $secret );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=' . $signature );
		$request->set_body( $payload );

		// Act.
		$response = $this->webhook_handler->handle_webhook( $request );

		// Assert.
		$this->assertEquals( 403, $response->get_status() );
		$this->assertArrayHasKey( 'code', $response->get_data() );
		$this->assertEquals( 'invalid_signature', $response->get_data()['code'] );
	}

	/**
	 * Test incoming text message is processed.
	 */
	public function test_incoming_text_message_processed() {
		// Arrange.
		$payload = $this->get_fixture( 'webhook_text_message.json' );

		// Disable signature validation for test.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.app_secret', '' );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		// Act.
		$response = $this->webhook_handler->handle_webhook( $request );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );

		// Verify message was stored in database.
		$this->assertDatabaseHas(
			'wch_messages',
			[
				'message_type' => 'text',
				'direction'    => 'incoming',
			]
		);
	}

	/**
	 * Test incoming interactive response is processed.
	 */
	public function test_incoming_interactive_response_processed() {
		// Arrange.
		$payload = $this->get_fixture( 'webhook_button_reply.json' );

		// Disable signature validation for test.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.app_secret', '' );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		// Act.
		$response = $this->webhook_handler->handle_webhook( $request );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );

		// Verify message was stored in database.
		$this->assertDatabaseHas(
			'wch_messages',
			[
				'message_type' => 'interactive',
				'direction'    => 'incoming',
			]
		);
	}

	/**
	 * Test message status update is processed.
	 */
	public function test_message_status_update_processed() {
		// Arrange - First create a message to update.
		global $wpdb;
		$message_id = 'wamid.test_' . wp_generate_uuid4();

		$wpdb->insert(
			$wpdb->prefix . 'wch_messages',
			[
				'conversation_id' => 1,
				'message_id'      => $message_id,
				'phone_number'    => '+1234567890',
				'message_type'    => 'text',
				'direction'       => 'outgoing',
				'status'          => 'sent',
				'content'         => wp_json_encode( [ 'text' => 'Test' ] ),
				'created_at'      => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$payload = $this->get_fixture( 'webhook_status_update.json' );

		// Update payload with our test message ID.
		$payload['entry'][0]['changes'][0]['value']['statuses'][0]['id'] = $message_id;

		// Disable signature validation for test.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.app_secret', '' );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		// Act.
		$response = $this->webhook_handler->handle_webhook( $request );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );

		// Verify message status was updated in database.
		$updated_message = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_messages WHERE message_id = %s",
				$message_id
			)
		);

		$this->assertNotNull( $updated_message );
		$this->assertContains( $updated_message->status, [ 'delivered', 'read' ] );
	}

	/**
	 * Test duplicate message is ignored.
	 */
	public function test_duplicate_message_ignored() {
		// Arrange.
		$payload = $this->get_fixture( 'webhook_text_message.json' );

		// Disable signature validation for test.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.app_secret', '' );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		// Act - Process same webhook twice.
		$response1 = $this->webhook_handler->handle_webhook( $request );
		$response2 = $this->webhook_handler->handle_webhook( $request );

		// Assert - Both should return 200 (idempotent).
		$this->assertEquals( 200, $response1->get_status() );
		$this->assertEquals( 200, $response2->get_status() );

		// Verify only one message was stored.
		global $wpdb;
		$message_id = $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'];
		$count      = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wch_messages WHERE message_id = %s",
				$message_id
			)
		);

		$this->assertEquals( 1, (int) $count, 'Duplicate message should be ignored' );
	}

	/**
	 * Get test fixture data.
	 *
	 * @param string $filename Fixture filename.
	 * @return array Fixture data.
	 */
	private function get_fixture( string $filename ): array {
		$file_path = dirname( __DIR__ ) . '/fixtures/' . $filename;

		if ( ! file_exists( $file_path ) ) {
			// Return fallback minimal payload.
			return $this->get_fallback_payload( $filename );
		}

		return json_decode( file_get_contents( $file_path ), true );
	}

	/**
	 * Get fallback payload for fixture.
	 *
	 * @param string $filename Fixture filename.
	 * @return array Fallback payload.
	 */
	private function get_fallback_payload( string $filename ): array {
		$base_payload = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => 'test_business_id',
					'changes' => array(
						array(
							'value' => array(
								'messaging_product' => 'whatsapp',
								'metadata'          => array(
									'display_phone_number' => '+1234567890',
									'phone_number_id'      => 'test_phone_number_id',
								),
								'contacts'          => array(
									array(
										'profile' => array(
											'name' => 'Test User',
										),
										'wa_id'   => '1234567890',
									),
								),
							),
							'field' => 'messages',
						),
					),
				],
			],
		];

		if ( 'webhook_text_message.json' === $filename ) {
			$base_payload['entry'][0]['changes'][0]['value']['messages'] = [
				[
					'from'      => '1234567890',
					'id'        => 'wamid.test_' . wp_generate_uuid4(),
					'timestamp' => (string) time(),
					'type'      => 'text',
					'text'      => [
						'body' => 'Hello, I want to browse products',
					],
				],
			];
		} elseif ( 'webhook_button_reply.json' === $filename ) {
			$base_payload['entry'][0]['changes'][0]['value']['messages'] = [
				[
					'from'        => '1234567890',
					'id'          => 'wamid.test_' . wp_generate_uuid4(),
					'timestamp'   => (string) time(),
					'type'        => 'interactive',
					'interactive' => [
						'type'         => 'button_reply',
						'button_reply' => array(
							'id'    => 'btn_view_cart',
							'title' => 'View Cart',
						),
					],
				],
			];
		} elseif ( 'webhook_status_update.json' === $filename ) {
			$base_payload['entry'][0]['changes'][0]['value']['statuses'] = [
				[
					'id'        => 'wamid.test_' . wp_generate_uuid4(),
					'status'    => 'delivered',
					'timestamp' => (string) time(),
					'recipient_id' => '1234567890',
				],
			];
			unset( $base_payload['entry'][0]['changes'][0]['value']['messages'] );
		}

		return $base_payload;
	}
}
