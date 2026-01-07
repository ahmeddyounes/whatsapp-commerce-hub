<?php
/**
 * Integration tests for WCH_Product_Sync_Service
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Product_Sync_Service integration.
 */
class WCH_Product_Sync_Test extends WCH_Integration_Test_Case {

	/**
	 * Product sync service instance.
	 *
	 * @var WCH_Product_Sync_Service
	 */
	private $sync_service;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sync_service = WCH_Product_Sync_Service::instance();

		// Mock successful API response.
		$this->mock_whatsapp_success();
	}

	/**
	 * Test syncing single product to catalog.
	 */
	public function test_sync_single_product() {
		$product = $this->create_test_product( array(
			'name' => 'Test iPhone',
			'regular_price' => '999.99',
			'sku' => 'IPHONE-001',
		) );

		$result = $this->sync_service->sync_product( $product->get_id() );

		$this->assertTrue( $result );

		// Verify product metadata was updated.
		$synced_at = get_post_meta( $product->get_id(), '_wch_synced_at', true );
		$this->assertNotEmpty( $synced_at );
	}

	/**
	 * Test syncing multiple products.
	 */
	public function test_sync_multiple_products() {
		$product1 = $this->create_test_product( array( 'name' => 'Product 1' ) );
		$product2 = $this->create_test_product( array( 'name' => 'Product 2' ) );
		$product3 = $this->create_test_product( array( 'name' => 'Product 3' ) );

		$result = $this->sync_service->sync_all_products();

		$this->assertTrue( $result );
	}

	/**
	 * Test handling API errors during sync.
	 */
	public function test_handles_api_error() {
		$this->mock_whatsapp_error( 'Catalog not found', 404 );

		$product = $this->create_test_product();

		$result = $this->sync_service->sync_product( $product->get_id() );

		$this->assertFalse( $result );
	}

	/**
	 * Test syncing product with variations.
	 */
	public function test_sync_variable_product() {
		// Create variable product.
		$variable_product = new WC_Product_Variable();
		$variable_product->set_name( 'Variable T-Shirt' );
		$variable_product->set_sku( 'VAR-SHIRT-001' );
		$variable_product->save();

		// Create variations.
		$variation1 = new WC_Product_Variation();
		$variation1->set_parent_id( $variable_product->get_id() );
		$variation1->set_regular_price( '29.99' );
		$variation1->set_sku( 'VAR-SHIRT-001-S' );
		$variation1->save();

		$result = $this->sync_service->sync_product( $variable_product->get_id() );

		$this->assertTrue( $result );
	}

	/**
	 * Test updating synced product.
	 */
	public function test_update_synced_product() {
		$product = $this->create_test_product( array(
			'name' => 'Original Name',
			'regular_price' => '99.99',
		) );

		// Initial sync.
		$this->sync_service->sync_product( $product->get_id() );

		// Update product.
		$product->set_name( 'Updated Name' );
		$product->set_regular_price( '149.99' );
		$product->save();

		// Re-sync.
		$result = $this->sync_service->sync_product( $product->get_id() );

		$this->assertTrue( $result );
	}

	/**
	 * Test deleting product from catalog.
	 */
	public function test_delete_product_from_catalog() {
		$product = $this->create_test_product();
		$this->sync_service->sync_product( $product->get_id() );

		$result = $this->sync_service->delete_product( $product->get_id() );

		$this->assertTrue( $result );
	}

	/**
	 * Test bulk sync with batch processing.
	 */
	public function test_bulk_sync_batch_processing() {
		// Create 50 products.
		for ( $i = 1; $i <= 50; $i++ ) {
			$this->create_test_product( array(
				'name' => "Product $i",
				'sku' => "SKU-$i",
			) );
		}

		$result = $this->sync_service->sync_all_products();

		$this->assertTrue( $result );
	}

	/**
	 * Test syncing only published products.
	 */
	public function test_syncs_only_published_products() {
		$published = $this->create_test_product( array( 'status' => 'publish' ) );
		$draft = $this->create_test_product( array( 'status' => 'draft' ) );

		$this->sync_service->sync_all_products();

		$published_synced = get_post_meta( $published->get_id(), '_wch_synced_at', true );
		$draft_synced = get_post_meta( $draft->get_id(), '_wch_synced_at', true );

		$this->assertNotEmpty( $published_synced );
		$this->assertEmpty( $draft_synced );
	}

	/**
	 * Test sync status tracking.
	 */
	public function test_tracks_sync_status() {
		$product = $this->create_test_product();

		$this->sync_service->sync_product( $product->get_id() );

		$status = get_post_meta( $product->get_id(), '_wch_sync_status', true );
		$this->assertEquals( 'synced', $status );
	}
}
