<?php
/**
 * Inventory Sync Service Tests
 *
 * Tests stock sync job processing with wrapped v2 payloads.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

use WhatsAppCommerceHub\Application\Services\InventorySyncService;
use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use Mockery;

/**
 * Class InventorySyncServiceTest
 *
 * Tests that InventorySyncService correctly handles wrapped payloads for stock sync jobs.
 */
class InventorySyncServiceTest extends WCH_Unit_Test_Case {

	/**
	 * Test processStockSync handles wrapped v2 payload.
	 */
	public function test_process_stock_sync_handles_wrapped_payload(): void {
		// Create a test product.
		$product = $this->create_test_product(
			[
				'stock_quantity' => 10,
			]
		);

		// Mock dependencies.
		$settings  = Mockery::mock( SettingsManager::class );
		$logger    = Mockery::mock( Logger::class );
		$apiClient = Mockery::mock( WhatsAppApiClient::class );

		$logger->shouldReceive( 'debug' )->andReturn( null );
		$logger->shouldReceive( 'info' )->andReturn( null );
		$logger->shouldReceive( 'warning' )->once()->andReturn( null );

		$service = new InventorySyncService( $settings, $logger, $apiClient );

		// Set transient for debounce data.
		set_transient(
			'wch_stock_sync_debounce_' . $product->get_id(),
			[
				'product_id'        => $product->get_id(),
				'new_availability'  => 'in_stock',
				'low_stock_reached' => false,
				'timestamp'         => time(),
			],
			10
		);

		// Wrapped v2 payload.
		$wrappedPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 1,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => [
				'product_id' => $product->get_id(),
			],
		];

		// Should not throw exception.
		try {
			$service->processStockSync( $wrappedPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'processStockSync should handle wrapped payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test processStockSync handles legacy unwrapped payload.
	 */
	public function test_process_stock_sync_handles_legacy_payload(): void {
		// Create a test product.
		$product = $this->create_test_product(
			[
				'stock_quantity' => 10,
			]
		);

		// Mock dependencies.
		$settings = Mockery::mock( SettingsManager::class );
		$logger   = Mockery::mock( Logger::class );

		$logger->shouldReceive( 'info' )->andReturn( null );
		$logger->shouldReceive( 'warning' )->once()->andReturn( null );

		$service = new InventorySyncService( $settings, $logger );

		// Set transient for debounce data.
		set_transient(
			'wch_stock_sync_debounce_' . $product->get_id(),
			[
				'product_id'        => $product->get_id(),
				'new_availability'  => 'in_stock',
				'low_stock_reached' => false,
				'timestamp'         => time(),
			],
			10
		);

		// Legacy unwrapped payload.
		$legacyPayload = [
			'product_id' => $product->get_id(),
		];

		// Should not throw exception.
		try {
			$service->processStockSync( $legacyPayload );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( 'processStockSync should handle legacy payload without error: ' . $e->getMessage() );
		}
	}

	/**
	 * Test processStockSync logs error on missing product_id.
	 */
	public function test_process_stock_sync_validates_product_id(): void {
		// Mock dependencies.
		$settings = Mockery::mock( SettingsManager::class );
		$logger   = Mockery::mock( Logger::class );

		$errorLogged = false;
		$logger->shouldReceive( 'error' )
			->once()
			->with( 'Missing product_id in stock sync payload', Mockery::any() )
			->andReturnUsing(
				function () use ( &$errorLogged ) {
					$errorLogged = true;
				}
			);

		$service = new InventorySyncService( $settings, $logger );

		// Invalid payload - missing product_id.
		$invalidPayload = [
			'_wch_version' => 2,
			'_wch_meta'    => [
				'priority'     => 1,
				'scheduled_at' => time(),
				'attempt'      => 1,
			],
			'args'         => [
				// Missing product_id.
			],
		];

		// Should not throw exception but should log error.
		try {
			$service->processStockSync( $invalidPayload );
			$this->assertTrue( $errorLogged, 'Should log error for missing product_id' );
		} catch ( \Throwable $e ) {
			$this->fail( 'processStockSync should not throw exception for invalid payload: ' . $e->getMessage() );
		}
	}
}
