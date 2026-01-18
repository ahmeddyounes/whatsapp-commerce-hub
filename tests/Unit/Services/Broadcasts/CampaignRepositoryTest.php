<?php
/**
 * Unit tests for CampaignRepository
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

use WhatsAppCommerceHub\Application\Services\Broadcasts\CampaignRepository;

/**
 * Test CampaignRepository class.
 */
class CampaignRepositoryTest extends WCH_Unit_Test_Case {

	/**
	 * Campaign repository instance.
	 *
	 * @var CampaignRepository
	 */
	private CampaignRepository $repository;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Clear campaigns option.
		delete_option( 'wch_broadcast_campaigns' );

		// Get fresh instance.
		$this->repository = new CampaignRepository();
	}

	/**
	 * Teardown after each test.
	 */
	protected function tearDown(): void {
		delete_option( 'wch_broadcast_campaigns' );
		parent::tearDown();
	}

	/**
	 * Test getting all campaigns returns empty array initially.
	 */
	public function test_get_all_returns_empty_array_initially() {
		$campaigns = $this->repository->getAll();
		$this->assertIsArray( $campaigns );
		$this->assertEmpty( $campaigns );
	}

	/**
	 * Test saving a new campaign.
	 */
	public function test_save_creates_new_campaign() {
		$data = [
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [
				'audience_all' => true,
			],
			'audience_size' => 100,
		];

		$campaign = $this->repository->save( $data );

		$this->assertIsArray( $campaign );
		$this->assertArrayHasKey( 'id', $campaign );
		$this->assertGreaterThan( 0, $campaign['id'] );
		$this->assertEquals( 'Test Campaign', $campaign['name'] );
		$this->assertEquals( 'draft', $campaign['status'] );
		$this->assertArrayHasKey( 'created_at', $campaign );
		$this->assertArrayHasKey( 'updated_at', $campaign );
	}

	/**
	 * Test updating an existing campaign.
	 */
	public function test_save_updates_existing_campaign() {
		// Create initial campaign.
		$data = [
			'name'          => 'Original Name',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		];

		$original = $this->repository->save( $data );

		// Update campaign.
		$updated_data = [
			'id'            => $original['id'],
			'name'          => 'Updated Name',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 150,
		];

		$updated = $this->repository->save( $updated_data );

		$this->assertEquals( $original['id'], $updated['id'] );
		$this->assertEquals( 'Updated Name', $updated['name'] );
		$this->assertEquals( 150, $updated['audience_size'] );
		$this->assertEquals( $original['created_at'], $updated['created_at'] );
	}

	/**
	 * Test getting campaign by ID.
	 */
	public function test_get_by_id_returns_campaign() {
		// Create campaign.
		$data = [
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		];

		$created = $this->repository->save( $data );

		// Retrieve by ID.
		$retrieved = $this->repository->getById( $created['id'] );

		$this->assertIsArray( $retrieved );
		$this->assertEquals( $created['id'], $retrieved['id'] );
		$this->assertEquals( $created['name'], $retrieved['name'] );
	}

	/**
	 * Test getting non-existent campaign returns null.
	 */
	public function test_get_by_id_returns_null_for_non_existent() {
		$result = $this->repository->getById( 999999 );
		$this->assertNull( $result );
	}

	/**
	 * Test deleting a campaign.
	 */
	public function test_delete_removes_campaign() {
		// Create campaign.
		$data = [
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		];

		$created = $this->repository->save( $data );

		// Delete campaign.
		$deleted = $this->repository->delete( $created['id'] );

		$this->assertTrue( $deleted );

		// Verify it's gone.
		$retrieved = $this->repository->getById( $created['id'] );
		$this->assertNull( $retrieved );
	}

	/**
	 * Test deleting non-existent campaign returns false.
	 */
	public function test_delete_returns_false_for_non_existent() {
		$result = $this->repository->delete( 999999 );
		$this->assertFalse( $result );
	}

	/**
	 * Test duplicating a campaign.
	 */
	public function test_duplicate_creates_copy() {
		// Create original campaign.
		$data = [
			'name'          => 'Original Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
			'status'        => 'completed',
		];

		$original = $this->repository->save( $data );

		// Duplicate campaign.
		$duplicate = $this->repository->duplicate( $original['id'] );

		$this->assertIsArray( $duplicate );
		$this->assertNotEquals( $original['id'], $duplicate['id'] );
		$this->assertEquals( 'Original Campaign (Copy)', $duplicate['name'] );
		$this->assertEquals( 'draft', $duplicate['status'] );
		$this->assertEquals( $original['template_name'], $duplicate['template_name'] );
		$this->assertArrayNotHasKey( 'sent_at', $duplicate );
		$this->assertArrayNotHasKey( 'scheduled_at', $duplicate );
		$this->assertArrayNotHasKey( 'stats', $duplicate );
	}

	/**
	 * Test duplicating non-existent campaign returns null.
	 */
	public function test_duplicate_returns_null_for_non_existent() {
		$result = $this->repository->duplicate( 999999 );
		$this->assertNull( $result );
	}

	/**
	 * Test updating campaign status.
	 */
	public function test_update_status_changes_status() {
		// Create campaign.
		$data = [
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		];

		$campaign = $this->repository->save( $data );

		// Update status.
		$updated = $this->repository->updateStatus( $campaign['id'], 'sending' );

		$this->assertTrue( $updated );

		// Verify status changed.
		$retrieved = $this->repository->getById( $campaign['id'] );
		$this->assertEquals( 'sending', $retrieved['status'] );
	}

	/**
	 * Test updating status with extra data.
	 */
	public function test_update_status_merges_extra_data() {
		// Create campaign.
		$data = [
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		];

		$campaign = $this->repository->save( $data );

		// Update status with extra data.
		$extra_data = [
			'job_id'  => 'broadcast_123_456',
			'sent_at' => gmdate( 'Y-m-d H:i:s' ),
		];

		$updated = $this->repository->updateStatus( $campaign['id'], 'sending', $extra_data );

		$this->assertTrue( $updated );

		// Verify extra data was merged.
		$retrieved = $this->repository->getById( $campaign['id'] );
		$this->assertEquals( 'sending', $retrieved['status'] );
		$this->assertEquals( 'broadcast_123_456', $retrieved['job_id'] );
		$this->assertArrayHasKey( 'sent_at', $retrieved );
	}

	/**
	 * Test updating status with invalid status returns false.
	 */
	public function test_update_status_returns_false_for_invalid_status() {
		// Create campaign.
		$data = [
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		];

		$campaign = $this->repository->save( $data );

		// Try to update with invalid status.
		$updated = $this->repository->updateStatus( $campaign['id'], 'invalid_status' );

		$this->assertFalse( $updated );
	}

	/**
	 * Test updating stats.
	 */
	public function test_update_stats_updates_campaign_stats() {
		// Create campaign.
		$data = [
			'name'          => 'Test Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		];

		$campaign = $this->repository->save( $data );

		// Update stats.
		$stats = [
			'sent'      => 50,
			'delivered' => 45,
			'read'      => 30,
			'failed'    => 5,
			'total'     => 100,
		];

		$updated = $this->repository->updateStats( $campaign['id'], $stats );

		$this->assertTrue( $updated );

		// Verify stats were updated.
		$retrieved = $this->repository->getById( $campaign['id'] );
		$this->assertEquals( $stats, $retrieved['stats'] );
	}

	/**
	 * Test campaigns are sorted by created_at descending.
	 */
	public function test_get_all_returns_campaigns_sorted_by_created_at() {
		// Create multiple campaigns with different timestamps.
		$campaign1 = $this->repository->save( [
			'name'          => 'Campaign 1',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		] );

		// Wait a moment to ensure different timestamps.
		usleep( 100000 ); // 0.1 seconds.

		$campaign2 = $this->repository->save( [
			'name'          => 'Campaign 2',
			'template_name' => 'welcome_message',
			'audience'      => [ 'audience_all' => true ],
			'audience_size' => 100,
		] );

		$campaigns = $this->repository->getAll();

		$this->assertCount( 2, $campaigns );
		// Most recent should be first.
		$this->assertEquals( 'Campaign 2', $campaigns[0]['name'] );
		$this->assertEquals( 'Campaign 1', $campaigns[1]['name'] );
	}

	/**
	 * Test data sanitization on save.
	 */
	public function test_save_sanitizes_data() {
		$data = [
			'name'          => '<script>alert("XSS")</script>Campaign',
			'template_name' => 'welcome_message',
			'audience'      => [
				'audience_all'             => '1',
				'audience_recent_orders'   => 'yes',
				'recent_orders_days'       => '30abc',
				'audience_category'        => false,
				'category_id'              => '5xyz',
				'audience_cart_abandoners' => '',
				'exclude_recent_broadcast' => true,
				'exclude_broadcast_days'   => '7.5',
			],
			'audience_size' => '100.5',
		];

		$campaign = $this->repository->save( $data );

		// Verify sanitization.
		$this->assertStringNotContainsString( '<script>', $campaign['name'] );
		$this->assertEquals( 100, $campaign['audience_size'] ); // Converted to int.
		$this->assertTrue( $campaign['audience']['audience_all'] );
		$this->assertTrue( $campaign['audience']['audience_recent_orders'] );
		$this->assertEquals( 30, $campaign['audience']['recent_orders_days'] );
		$this->assertFalse( $campaign['audience']['audience_category'] );
		$this->assertEquals( 5, $campaign['audience']['category_id'] );
		$this->assertFalse( $campaign['audience']['audience_cart_abandoners'] );
		$this->assertTrue( $campaign['audience']['exclude_recent_broadcast'] );
		$this->assertEquals( 7, $campaign['audience']['exclude_broadcast_days'] );
	}
}
