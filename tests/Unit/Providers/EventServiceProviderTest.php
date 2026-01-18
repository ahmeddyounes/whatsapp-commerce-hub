<?php
/**
 * Event Service Provider Tests
 *
 * Tests async event processing with wrapped v2 payloads.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

use WhatsAppCommerceHub\Providers\EventServiceProvider;
use WhatsAppCommerceHub\Events\EventBus;
use Mockery;

/**
 * Class EventServiceProviderTest
 *
 * Tests that EventServiceProvider correctly handles wrapped payloads for async events.
 */
class EventServiceProviderTest extends WCH_Unit_Test_Case {

	/**
	 * Test processAsyncEvent handles wrapped v2 payload.
	 */
	public function test_process_async_event_handles_wrapped_payload(): void {
		// Mock the container.
		$container = Mockery::mock( 'WhatsAppCommerceHub\Container\ContainerInterface' );

		// Mock EventBus.
		$eventBus = Mockery::mock( EventBus::class );
		$eventBus->shouldReceive( 'processAsyncEvent' )
			->once()
			->with( 'wch.test.event', [ 'id' => 123, 'data' => 'test' ] )
			->andReturn( null );

		$container->shouldReceive( 'get' )
			->with( EventBus::class )
			->andReturn( $eventBus );

		// Mock wch_get_container() function.
		if ( ! function_exists( 'wch_get_container' ) ) {
			function wch_get_container() {
				return $GLOBALS['test_container'];
			}
		}
		$GLOBALS['test_container'] = $container;

		$provider = new EventServiceProvider();

		// Wrapped v2 payload containing [event_name, event_data].
		$wrappedPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 1,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => [
				'wch.test.event',
				[
					'id'   => 123,
					'data' => 'test',
				],
			],
		];

		// Should not throw exception.
		try {
			$provider->processAsyncEvent( $wrappedPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'processAsyncEvent should handle wrapped payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test processAsyncEvent handles legacy unwrapped payload.
	 */
	public function test_process_async_event_handles_legacy_payload(): void {
		// Mock the container.
		$container = Mockery::mock( 'WhatsAppCommerceHub\Container\ContainerInterface' );

		// Mock EventBus.
		$eventBus = Mockery::mock( EventBus::class );
		$eventBus->shouldReceive( 'processAsyncEvent' )
			->once()
			->with( 'wch.test.event', [ 'id' => 123, 'data' => 'test' ] )
			->andReturn( null );

		$container->shouldReceive( 'get' )
			->with( EventBus::class )
			->andReturn( $eventBus );

		// Mock wch_get_container() function.
		if ( ! function_exists( 'wch_get_container' ) ) {
			function wch_get_container() {
				return $GLOBALS['test_container'];
			}
		}
		$GLOBALS['test_container'] = $container;

		$provider = new EventServiceProvider();

		// Legacy unwrapped payload [event_name, event_data].
		$legacyPayload = [
			'wch.test.event',
			[
				'id'   => 123,
				'data' => 'test',
			],
		];

		// Should not throw exception.
		try {
			$provider->processAsyncEvent( $legacyPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'processAsyncEvent should handle legacy payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test processAsyncEvent logs error on invalid payload structure.
	 */
	public function test_process_async_event_validates_payload_structure(): void {
		$provider = new EventServiceProvider();

		// Invalid payload - missing event_data.
		$invalidPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 1,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => [
				'wch.test.event',
				// Missing second argument.
			],
		];

		$errorLogged = false;
		add_action(
			'wch_log_error',
			function ( $message ) use ( &$errorLogged ) {
				if ( strpos( $message, 'Invalid async event payload structure' ) !== false ) {
					$errorLogged = true;
				}
			}
		);

		// Should not throw exception but should log error.
		try {
			$provider->processAsyncEvent( $invalidPayload );
			$this->assertTrue( $errorLogged, 'Should log error for invalid payload structure' );
		} catch ( \Throwable $e ) {
			$this->fail( 'processAsyncEvent should not throw exception for invalid payload: ' . $e->getMessage() );
		}
	}

	/**
	 * Clean up after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_container'] );
		remove_all_actions( 'wch_log_error' );
		parent::tearDown();
	}
}
