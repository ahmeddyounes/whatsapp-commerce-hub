<?php
/**
 * Webhook Processor Tests
 *
 * Tests webhook job processing with wrapped v2 payloads.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

use WhatsAppCommerceHub\Queue\Processors\WebhookMessageProcessor;
use WhatsAppCommerceHub\Queue\Processors\WebhookStatusProcessor;
use WhatsAppCommerceHub\Queue\Processors\WebhookErrorProcessor;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use Mockery;

/**
 * Class WebhookProcessorTest
 *
 * Tests that webhook processors correctly handle wrapped payloads.
 */
class WebhookProcessorTest extends WCH_Unit_Test_Case {

	/**
	 * Test WebhookMessageProcessor handles wrapped v2 payload.
	 */
	public function test_message_processor_handles_wrapped_payload(): void {
		$processor = new WebhookMessageProcessor();

		$userArgs = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => '123456789',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '1234567890',
									'phone_number_id'      => 'test_phone_id',
								],
								'contacts'          => [
									[
										'profile' => [ 'name' => 'Test User' ],
										'wa_id'   => '1234567890',
									],
								],
								'messages'          => [
									[
										'from'      => '1234567890',
										'id'        => 'wamid.test123',
										'timestamp' => '1234567890',
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

		$wrappedPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 1,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => $userArgs,
		];

		// Mock the container and dependencies.
		add_filter(
			'wch_container_get',
			function ( $class ) {
				if ( $class === 'WhatsAppCommerceHub\Controllers\WebhookController' ) {
					$mock = Mockery::mock( 'WhatsAppCommerceHub\Controllers\WebhookController' );
					$mock->shouldReceive( 'handleWebhook' )->once()->andReturn( null );
					return $mock;
				}
				return null;
			}
		);

		// Should not throw exception.
		try {
			$processor->execute( $wrappedPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'Processor should handle wrapped payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test WebhookMessageProcessor handles legacy unwrapped payload.
	 */
	public function test_message_processor_handles_legacy_payload(): void {
		$processor = new WebhookMessageProcessor();

		$legacyPayload = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => '123456789',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '1234567890',
									'phone_number_id'      => 'test_phone_id',
								],
								'contacts'          => [
									[
										'profile' => [ 'name' => 'Test User' ],
										'wa_id'   => '1234567890',
									],
								],
								'messages'          => [
									[
										'from'      => '1234567890',
										'id'        => 'wamid.test123',
										'timestamp' => '1234567890',
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

		// Mock the container and dependencies.
		add_filter(
			'wch_container_get',
			function ( $class ) {
				if ( $class === 'WhatsAppCommerceHub\Controllers\WebhookController' ) {
					$mock = Mockery::mock( 'WhatsAppCommerceHub\Controllers\WebhookController' );
					$mock->shouldReceive( 'handleWebhook' )->once()->andReturn( null );
					return $mock;
				}
				return null;
			}
		);

		// Should not throw exception.
		try {
			$processor->execute( $legacyPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'Processor should handle legacy payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test WebhookStatusProcessor handles wrapped v2 payload.
	 */
	public function test_status_processor_handles_wrapped_payload(): void {
		$processor = new WebhookStatusProcessor();

		$userArgs = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => '123456789',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '1234567890',
									'phone_number_id'      => 'test_phone_id',
								],
								'statuses'          => [
									[
										'id'        => 'wamid.test123',
										'status'    => 'delivered',
										'timestamp' => '1234567890',
									],
								],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		$wrappedPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 2,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => $userArgs,
		];

		// Mock the container and dependencies.
		add_filter(
			'wch_container_get',
			function ( $class ) {
				if ( $class === 'WhatsAppCommerceHub\Controllers\WebhookController' ) {
					$mock = Mockery::mock( 'WhatsAppCommerceHub\Controllers\WebhookController' );
					$mock->shouldReceive( 'handleWebhook' )->once()->andReturn( null );
					return $mock;
				}
				return null;
			}
		);

		// Should not throw exception.
		try {
			$processor->execute( $wrappedPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'Status processor should handle wrapped payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test WebhookErrorProcessor handles wrapped v2 payload.
	 */
	public function test_error_processor_handles_wrapped_payload(): void {
		$processor = new WebhookErrorProcessor();

		$userArgs = [
			'object' => 'whatsapp_business_account',
			'entry'  => [
				[
					'id'      => '123456789',
					'changes' => [
						[
							'value' => [
								'messaging_product' => 'whatsapp',
								'metadata'          => [
									'display_phone_number' => '1234567890',
									'phone_number_id'      => 'test_phone_id',
								],
								'errors'            => [
									[
										'code'    => 131047,
										'title'   => 'Message failed to send',
										'message' => 'Re-engagement message failed to send',
										'details' => 'Test error details',
									],
								],
							],
							'field' => 'messages',
						],
					],
				],
			],
		];

		$wrappedPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 0,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => $userArgs,
		];

		// Mock the container and dependencies.
		add_filter(
			'wch_container_get',
			function ( $class ) {
				if ( $class === 'WhatsAppCommerceHub\Controllers\WebhookController' ) {
					$mock = Mockery::mock( 'WhatsAppCommerceHub\Controllers\WebhookController' );
					$mock->shouldReceive( 'handleWebhook' )->once()->andReturn( null );
					return $mock;
				}
				return null;
			}
		);

		// Should not throw exception.
		try {
			$processor->execute( $wrappedPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'Error processor should handle wrapped payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test PriorityQueue wraps payloads correctly.
	 */
	public function test_priority_queue_wraps_payloads(): void {
		// Mock the queue to test wrapping logic.
		$queue = Mockery::mock( PriorityQueue::class )->makePartial();

		$userArgs = [
			'test_key'   => 'test_value',
			'test_array' => [ 1, 2, 3 ],
		];

		// Use reflection to access private wrapPayload method.
		$reflection = new ReflectionClass( PriorityQueue::class );
		$method     = $reflection->getMethod( 'wrapPayload' );
		$method->setAccessible( true );

		$wrapped = $method->invoke( $queue, $userArgs, 1, 1 );

		// Verify wrapped structure.
		$this->assertIsArray( $wrapped );
		$this->assertArrayHasKey( '_wch_version', $wrapped );
		$this->assertEquals( 2, $wrapped['_wch_version'] );
		$this->assertArrayHasKey( '_wch_meta', $wrapped );
		$this->assertArrayHasKey( 'args', $wrapped );
		$this->assertEquals( $userArgs, $wrapped['args'] );

		// Verify metadata.
		$this->assertArrayHasKey( 'priority', $wrapped['_wch_meta'] );
		$this->assertArrayHasKey( 'scheduled_at', $wrapped['_wch_meta'] );
		$this->assertArrayHasKey( 'attempt', $wrapped['_wch_meta'] );
		$this->assertEquals( 1, $wrapped['_wch_meta']['priority'] );
		$this->assertEquals( 1, $wrapped['_wch_meta']['attempt'] );
	}

	/**
	 * Test PriorityQueue unwraps payloads correctly.
	 */
	public function test_priority_queue_unwraps_payloads(): void {
		$userArgs = [
			'test_key'   => 'test_value',
			'test_array' => [ 1, 2, 3 ],
		];

		$wrappedPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 1,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => $userArgs,
		];

		$unwrapped = PriorityQueue::unwrapPayload( $wrappedPayload );

		// Verify unwrapped structure.
		$this->assertIsArray( $unwrapped );
		$this->assertArrayHasKey( 'args', $unwrapped );
		$this->assertArrayHasKey( 'meta', $unwrapped );
		$this->assertEquals( $userArgs, $unwrapped['args'] );
		$this->assertEquals( $wrappedPayload['_wch_meta'], $unwrapped['meta'] );
	}

	/**
	 * Clean up after each test.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wch_container_get' );
		parent::tearDown();
	}
}
