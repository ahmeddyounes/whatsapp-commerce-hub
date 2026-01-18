<?php
/**
 * Webhook Flow Integration Tests
 *
 * End-to-end tests for the complete webhook ingestion pipeline:
 * REST controller → queue → processors → actions.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Application\Services\QueueService;
use WhatsAppCommerceHub\Queue\IdempotencyService;

/**
 * Class WebhookFlowTest
 *
 * Integration tests for complete webhook processing flow.
 */
class WebhookFlowTest extends WCH_Integration_Test_Case {

	/**
	 * Queue service instance.
	 *
	 * @var QueueService
	 */
	private $queue_service;

	/**
	 * Idempotency service instance.
	 *
	 * @var IdempotencyService
	 */
	private $idempotency_service;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->queue_service        = container()->get( QueueService::class );
		$this->idempotency_service  = container()->get( IdempotencyService::class );

		// Configure test settings.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'webhook.verify_token', 'test_token_123' );
		$settings->set( 'api.phone_number_id', 'test_phone_123' );
		$settings->set( 'webhook.app_secret', '' ); // Disable signature validation for tests.

		// Mock WhatsApp API responses.
		$this->mock_whatsapp_success();
	}

	/**
	 * Test complete message flow: webhook → queue → processor → action → response.
	 */
	public function test_complete_message_flow() {
		// Arrange: Create webhook payload.
		$message_id = 'wamid.test_' . wp_generate_uuid4();
		$payload    = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => 'business_123',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '+15551234567',
									'phone_number_id'      => 'test_phone_123',
								],
								'contacts'          => [
									[
										'profile' => [ 'name' => 'John Doe' ],
										'wa_id'   => '1234567890',
									],
								],
								'messages'          => [
									[
										'from'      => '+1234567890',
										'id'        => $message_id,
										'timestamp' => (string) time(),
										'type'      => 'text',
										'text'      => [ 'body' => 'Hello' ],
									],
								],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		// Act: Send webhook request.
		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		$controller = container()->get( 'WhatsAppCommerceHub\Controllers\WebhookController' );
		$response   = $controller->handleWebhook( $request );

		// Assert: Webhook accepted.
		$this->assertEquals( 200, $response->get_status(), 'Webhook should return 200 OK' );

		// Assert: Job was scheduled.
		$pending_jobs = $this->queue_service->getPendingJobs( 'wch_process_webhook_messages', 10 );
		$this->assertNotEmpty( $pending_jobs, 'Job should be scheduled in queue' );

		// Process the queue job (simulate cron).
		$this->process_queue_jobs();

		// Assert: Message stored in database.
		$this->assertDatabaseHas(
			'wch_messages',
			[
				'wa_message_id' => $message_id,
				'direction'     => 'incoming',
				'type'          => 'text',
			],
			'Message should be stored in database'
		);

		// Assert: Idempotency record created.
		$claimed = $this->idempotency_service->claim( $message_id, IdempotencyService::SCOPE_WEBHOOK );
		$this->assertFalse( $claimed, 'Message should be marked as processed (idempotency)' );

		// Assert: Conversation created.
		$this->assertDatabaseHas(
			'wch_conversations',
			[
				'customer_phone' => '+1234567890',
			],
			'Conversation should be created'
		);
	}

	/**
	 * Test status update flow: webhook → queue → processor → status update.
	 */
	public function test_status_update_flow() {
		global $wpdb;

		// Arrange: Create existing message.
		$message_id = 'wamid.test_' . wp_generate_uuid4();

		$wpdb->insert(
			$wpdb->prefix . 'wch_messages',
			[
				'conversation_id' => 1,
				'wa_message_id'   => $message_id,
				'customer_phone'  => '+1234567890',
				'type'            => 'text',
				'direction'       => 'outgoing',
				'status'          => 'sent',
				'content'         => wp_json_encode( [ 'text' => 'Test' ] ),
				'created_at'      => current_time( 'mysql' ),
			]
		);

		// Arrange: Create status update webhook.
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => 'business_123',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '+15551234567',
									'phone_number_id'      => 'test_phone_123',
								],
								'statuses'          => [
									[
										'id'           => $message_id,
										'status'       => 'delivered',
										'timestamp'    => (string) time(),
										'recipient_id' => '1234567890',
									],
								],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		// Act: Send webhook request.
		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		$controller = container()->get( 'WhatsAppCommerceHub\Controllers\WebhookController' );
		$response   = $controller->handleWebhook( $request );

		// Assert: Webhook accepted.
		$this->assertEquals( 200, $response->get_status() );

		// Process the queue job.
		$this->process_queue_jobs();

		// Assert: Message status updated.
		$updated_message = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_messages WHERE wa_message_id = %s",
				$message_id
			)
		);

		$this->assertNotNull( $updated_message );
		$this->assertEquals( 'delivered', $updated_message->status, 'Status should be updated to delivered' );
	}

	/**
	 * Test error webhook flow: webhook → queue → processor → error logging.
	 */
	public function test_error_webhook_flow() {
		// Arrange: Create error webhook.
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => 'business_123',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '+15551234567',
									'phone_number_id'      => 'test_phone_123',
								],
								'errors'            => [
									[
										'code'    => 131047,
										'title'   => 'Message failed to send',
										'message' => 'Re-engagement message failed',
										'details' => 'Invalid phone number',
									],
								],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		// Act: Send webhook request.
		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		$controller = container()->get( 'WhatsAppCommerceHub\Controllers\WebhookController' );
		$response   = $controller->handleWebhook( $request );

		// Assert: Webhook accepted.
		$this->assertEquals( 200, $response->get_status() );

		// Assert: Error job scheduled.
		$pending_jobs = $this->queue_service->getPendingJobs( 'wch_process_webhook_errors', 10 );
		$this->assertNotEmpty( $pending_jobs, 'Error job should be scheduled' );

		// Process the queue job.
		$this->process_queue_jobs();

		// Note: Error processing typically just logs, so we verify job completed.
		$failed_jobs = $this->queue_service->getFailedJobs( 'wch_process_webhook_errors', 10 );
		$this->assertEmpty( $failed_jobs, 'Error job should complete successfully' );
	}

	/**
	 * Test queue payload wrapping and unwrapping.
	 */
	public function test_queue_payload_wrapping() {
		// Arrange: User payload.
		$user_args = [
			'message_id' => 'wamid.test_123',
			'from'       => '+1234567890',
			'type'       => 'text',
		];

		// Act: Schedule job (wraps payload).
		$action_id = $this->queue_service->dispatch(
			'wch_process_test_job',
			$user_args,
			QueueService::PRIORITY_NORMAL
		);

		$this->assertGreaterThan( 0, $action_id, 'Job should be scheduled' );

		// Assert: Job scheduled with wrapped payload.
		$jobs = $this->queue_service->getPendingJobs( 'wch_process_test_job', 1 );
		$this->assertNotEmpty( $jobs );

		$job     = $jobs[0];
		$payload = $job->get_args();

		// Verify v2 format.
		$this->assertArrayHasKey( '_wch_version', $payload );
		$this->assertEquals( 2, $payload['_wch_version'] );
		$this->assertArrayHasKey( '_wch_meta', $payload );
		$this->assertArrayHasKey( 'args', $payload );

		// Verify metadata.
		$this->assertArrayHasKey( 'priority', $payload['_wch_meta'] );
		$this->assertArrayHasKey( 'scheduled_at', $payload['_wch_meta'] );
		$this->assertArrayHasKey( 'attempt', $payload['_wch_meta'] );

		// Verify args preserved.
		$this->assertEquals( $user_args, $payload['args'] );

		// Act: Unwrap payload.
		$unwrapped = PriorityQueue::unwrapPayload( $payload );

		// Assert: Unwrapped correctly.
		$this->assertArrayHasKey( 'args', $unwrapped );
		$this->assertArrayHasKey( 'meta', $unwrapped );
		$this->assertEquals( $user_args, $unwrapped['args'] );
		$this->assertEquals( $payload['_wch_meta'], $unwrapped['meta'] );
	}

	/**
	 * Test idempotency prevents duplicate processing.
	 */
	public function test_idempotency_prevents_duplicates() {
		// Arrange: Create webhook payload.
		$message_id = 'wamid.test_' . wp_generate_uuid4();
		$payload    = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => 'business_123',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '+15551234567',
									'phone_number_id'      => 'test_phone_123',
								],
								'contacts'          => [
									[
										'profile' => [ 'name' => 'John Doe' ],
										'wa_id'   => '1234567890',
									],
								],
								'messages'          => [
									[
										'from'      => '+1234567890',
										'id'        => $message_id,
										'timestamp' => (string) time(),
										'type'      => 'text',
										'text'      => [ 'body' => 'Hello' ],
									],
								],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		// Act: Send webhook twice.
		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		$controller = container()->get( 'WhatsAppCommerceHub\Controllers\WebhookController' );

		$response1 = $controller->handleWebhook( $request );
		$response2 = $controller->handleWebhook( $request );

		// Assert: Both return 200 (idempotent).
		$this->assertEquals( 200, $response1->get_status() );
		$this->assertEquals( 200, $response2->get_status() );

		// Process all queue jobs.
		$this->process_queue_jobs();

		// Assert: Only one message stored.
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wch_messages WHERE wa_message_id = %s",
				$message_id
			)
		);

		$this->assertEquals( 1, (int) $count, 'Only one message should be stored (idempotency)' );
	}

	/**
	 * Test priority levels are respected.
	 */
	public function test_priority_levels_respected() {
		// Arrange: Schedule jobs with different priorities.
		$critical_id = $this->queue_service->dispatch(
			'wch_test_critical',
			[ 'priority' => 'critical' ],
			QueueService::PRIORITY_CRITICAL
		);

		$normal_id = $this->queue_service->dispatch(
			'wch_test_normal',
			[ 'priority' => 'normal' ],
			QueueService::PRIORITY_NORMAL
		);

		$maintenance_id = $this->queue_service->dispatch(
			'wch_test_maintenance',
			[ 'priority' => 'maintenance' ],
			QueueService::PRIORITY_MAINTENANCE
		);

		// Assert: All jobs scheduled.
		$this->assertGreaterThan( 0, $critical_id );
		$this->assertGreaterThan( 0, $normal_id );
		$this->assertGreaterThan( 0, $maintenance_id );

		// Assert: Stats reflect priority distribution.
		$stats = $this->queue_service->getStatsByPriority();

		$this->assertArrayHasKey( 'critical', $stats );
		$this->assertArrayHasKey( 'normal', $stats );
		$this->assertArrayHasKey( 'maintenance', $stats );

		$this->assertGreaterThan( 0, $stats['critical']['pending'] );
		$this->assertGreaterThan( 0, $stats['normal']['pending'] );
		$this->assertGreaterThan( 0, $stats['maintenance']['pending'] );
	}

	/**
	 * Test queue health check.
	 */
	public function test_queue_health_check() {
		// Arrange: Schedule some jobs.
		$this->queue_service->dispatch(
			'wch_test_health',
			[ 'test' => 'data' ],
			QueueService::PRIORITY_NORMAL
		);

		// Act: Run health check.
		$health = $this->queue_service->healthCheck();

		// Assert: Health check returns expected structure.
		$this->assertArrayHasKey( 'status', $health );
		$this->assertArrayHasKey( 'pending_jobs', $health );
		$this->assertArrayHasKey( 'failed_jobs', $health );

		// Assert: Status is healthy (for fresh queue).
		$this->assertContains( $health['status'], [ 'healthy', 'degraded', 'unhealthy' ] );
	}

	/**
	 * Test extension hook: wch_webhook_received.
	 */
	public function test_webhook_received_hook_fires() {
		$hook_fired = false;
		$hook_type  = null;

		// Arrange: Add hook listener.
		add_action(
			'wch_webhook_received',
			function ( $payload, $type ) use ( &$hook_fired, &$hook_type ) {
				$hook_fired = true;
				$hook_type  = $type;
			},
			10,
			2
		);

		// Act: Send webhook.
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => 'business_123',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '+15551234567',
									'phone_number_id'      => 'test_phone_123',
								],
								'messages'          => [
									[
										'from'      => '+1234567890',
										'id'        => 'wamid.test_hook',
										'timestamp' => (string) time(),
										'type'      => 'text',
										'text'      => [ 'body' => 'Hello' ],
									],
								],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		$controller = container()->get( 'WhatsAppCommerceHub\Controllers\WebhookController' );
		$controller->handleWebhook( $request );

		// Assert: Hook fired.
		$this->assertTrue( $hook_fired, 'wch_webhook_received hook should fire' );
		$this->assertEquals( 'message', $hook_type, 'Hook should identify type as message' );
	}

	/**
	 * Helper: Process all pending queue jobs.
	 */
	private function process_queue_jobs(): void {
		// Simulate cron by running scheduled jobs.
		// Note: In real tests, this would trigger actual job processing.
		// For now, we assume jobs are processed via action scheduler.

		// Run action scheduler.
		if ( function_exists( 'as_run_queue' ) ) {
			as_run_queue();
		}
	}

	/**
	 * Clean up after tests.
	 */
	protected function tearDown(): void {
		remove_all_actions( 'wch_webhook_received' );
		parent::tearDown();
	}
}
