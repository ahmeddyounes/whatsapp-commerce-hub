<?php
/**
 * DatabaseManager Uninstall Test
 *
 * Tests for the DatabaseManager uninstall functionality.
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Tests\Unit\Infrastructure\Database;

use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;
use WCH_Unit_Test_Case;

/**
 * Class DatabaseManagerUninstallTest
 *
 * Test DatabaseManager uninstall system to ensure complete cleanup.
 */
class DatabaseManagerUninstallTest extends WCH_Unit_Test_Case {

	/**
	 * DatabaseManager instance.
	 *
	 * @var DatabaseManager
	 */
	protected $db_manager;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->db_manager = new DatabaseManager();
	}

	/**
	 * Test that all plugin tables are dropped on uninstall.
	 */
	public function test_uninstall_drops_all_tables() {
		// First install tables.
		$this->db_manager->install();

		// Verify some tables exist.
		$this->assertTrue( $this->db_manager->tableExists( 'conversations' ) );
		$this->assertTrue( $this->db_manager->tableExists( 'messages' ) );
		$this->assertTrue( $this->db_manager->tableExists( 'carts' ) );

		// Run uninstall.
		$this->db_manager->uninstall();

		// Verify all tables are dropped.
		$tables = [
			'conversations',
			'messages',
			'carts',
			'customer_profiles',
			'broadcast_recipients',
			'sync_queue',
			'notification_log',
			'product_views',
			'reengagement',
			'rate_limits',
			'security_log',
			'webhook_idempotency',
			'webhook_events',
		];

		foreach ( $tables as $table ) {
			$this->assertFalse(
				$this->db_manager->tableExists( $table ),
				"Table {$table} should be dropped after uninstall"
			);
		}
	}

	/**
	 * Test that all plugin options are deleted on uninstall.
	 */
	public function test_uninstall_deletes_all_options() {
		// Create some plugin options.
		$options = [
			'wch_db_version'               => '2.6.0',
			'wch_settings'                 => [ 'test' => 'value' ],
			'wch_catalog_id'               => 'test_catalog',
			'wch_openai_api_key'           => 'sk-test-key',
			'wch_encryption_hkdf_salt'     => 'test_salt',
			'wch_stock_discrepancy_count'  => 5,
			'wch_sync_history'             => [ 'test' ],
			'wch_payment_test123'          => [ 'amount' => 100 ],
			'wch_enabled_payment_methods'  => [ 'stripe', 'cod' ],
			'wch_stripe_secret_key'        => 'sk_test_123',
			'wch_default_payment_gateway'  => 'stripe',
		];

		foreach ( $options as $key => $value ) {
			update_option( $key, $value );
		}

		// Verify options exist.
		foreach ( array_keys( $options ) as $key ) {
			$this->assertNotFalse(
				get_option( $key ),
				"Option {$key} should exist before uninstall"
			);
		}

		// Run uninstall.
		$this->db_manager->uninstall();

		// Verify all options are deleted.
		foreach ( array_keys( $options ) as $key ) {
			$this->assertFalse(
				get_option( $key ),
				"Option {$key} should be deleted after uninstall"
			);
		}

		// Verify dynamic payment options are deleted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->options} WHERE option_name LIKE 'wch_payment_%'"
		);
		$this->assertEquals( 0, $remaining, 'All wch_payment_* options should be deleted' );
	}

	/**
	 * Test that all plugin transients are deleted on uninstall.
	 */
	public function test_uninstall_deletes_all_transients() {
		// Create some plugin transients.
		$transients = [
			'wch_fallback_test'      => 'test_value',
			'wch_settings_backup'    => [ 'backup' => 'data' ],
			'wch_webhook_cache_123'  => [ 'cached' => 'webhook' ],
			'wch_circuit_test'       => [ 'circuit' => 'data' ],
			'wch_cart_lock_123'      => true,
		];

		foreach ( $transients as $key => $value ) {
			set_transient( $key, $value, HOUR_IN_SECONDS );
		}

		// Verify transients exist.
		foreach ( $transients as $key => $value ) {
			$this->assertNotFalse(
				get_transient( $key ),
				"Transient {$key} should exist before uninstall"
			);
		}

		// Run uninstall.
		$this->db_manager->uninstall();

		// Verify all transients are deleted.
		foreach ( array_keys( $transients ) as $key ) {
			$this->assertFalse(
				get_transient( $key ),
				"Transient {$key} should be deleted after uninstall"
			);
		}

		// Verify no wch_ transients remain in database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->options}
			WHERE option_name LIKE '_transient_wch_%'
			OR option_name LIKE '_transient_timeout_wch_%'"
		);
		$this->assertEquals( 0, $remaining, 'All wch_ transients should be deleted' );
	}

	/**
	 * Test that all scheduled events are cleared on uninstall.
	 */
	public function test_uninstall_clears_scheduled_events() {
		// Schedule some events.
		$events = [
			'wch_cleanup_expired_carts',
			'wch_rate_limit_cleanup',
			'wch_security_log_cleanup',
			'wch_detect_stock_discrepancies',
		];

		foreach ( $events as $event ) {
			if ( ! wp_next_scheduled( $event ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $event );
			}
		}

		// Verify events are scheduled.
		foreach ( $events as $event ) {
			$this->assertNotFalse(
				wp_next_scheduled( $event ),
				"Event {$event} should be scheduled before uninstall"
			);
		}

		// Run uninstall.
		$this->db_manager->uninstall();

		// Verify all events are cleared.
		foreach ( $events as $event ) {
			$this->assertFalse(
				wp_next_scheduled( $event ),
				"Event {$event} should be cleared after uninstall"
			);
		}
	}

	/**
	 * Test that all plugin post meta is deleted on uninstall.
	 */
	public function test_uninstall_deletes_all_post_meta() {
		// Create a test product.
		$product_id = wp_insert_post(
			[
				'post_title'  => 'Test Product',
				'post_type'   => 'product',
				'post_status' => 'publish',
			]
		);

		// Add plugin post meta.
		$post_meta = [
			'_wch_sync_status'     => 'synced',
			'_wch_last_synced'     => time(),
			'_wch_sync_error'      => '',
			'_wch_sync_message'    => 'Success',
			'_wch_last_stock_sync' => time(),
			'_wch_previous_stock'  => 10,
		];

		foreach ( $post_meta as $key => $value ) {
			update_post_meta( $product_id, $key, $value );
		}

		// Verify post meta exists.
		foreach ( array_keys( $post_meta ) as $key ) {
			$this->assertNotEmpty(
				get_post_meta( $product_id, $key, true ),
				"Post meta {$key} should exist before uninstall"
			);
		}

		// Run uninstall.
		$this->db_manager->uninstall();

		// Verify all post meta is deleted.
		foreach ( array_keys( $post_meta ) as $key ) {
			$this->assertEmpty(
				get_post_meta( $product_id, $key, true ),
				"Post meta {$key} should be deleted after uninstall"
			);
		}

		// Verify no _wch_ post meta remains.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->postmeta} WHERE meta_key LIKE '_wch_%'"
		);
		$this->assertEquals( 0, $remaining, 'All _wch_ post meta should be deleted' );

		// Clean up test product.
		wp_delete_post( $product_id, true );
	}

	/**
	 * Test that uninstall is comprehensive and leaves no trace.
	 */
	public function test_uninstall_leaves_no_trace() {
		// Install and populate data.
		$this->db_manager->install();
		update_option( 'wch_settings', [ 'api_key' => 'test' ] );
		update_option( 'wch_catalog_id', 'cat_123' );
		set_transient( 'wch_test_cache', 'value', HOUR_IN_SECONDS );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wch_cleanup_expired_carts' );

		// Create test product with post meta.
		$product_id = wp_insert_post(
			[
				'post_title'  => 'Test Product',
				'post_type'   => 'product',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $product_id, '_wch_sync_status', 'synced' );

		// Run uninstall.
		$this->db_manager->uninstall();

		// Check for any remaining wch_ prefixed data.

		// Check options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$options_count = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->options} WHERE option_name LIKE 'wch_%'"
		);
		$this->assertEquals( 0, $options_count, 'No wch_ options should remain' );

		// Check transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients_count = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->options}
			WHERE option_name LIKE '_transient_wch_%'
			OR option_name LIKE '_transient_timeout_wch_%'"
		);
		$this->assertEquals( 0, $transients_count, 'No wch_ transients should remain' );

		// Check post meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$postmeta_count = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->postmeta} WHERE meta_key LIKE '_wch_%'"
		);
		$this->assertEquals( 0, $postmeta_count, 'No _wch_ post meta should remain' );

		// Check tables.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables_count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables
				WHERE table_schema = %s
				AND table_name LIKE %s',
				DB_NAME,
				$this->wpdb->prefix . 'wch_%'
			)
		);
		$this->assertEquals( 0, $tables_count, 'No wch_ tables should remain' );

		// Clean up test product.
		wp_delete_post( $product_id, true );
	}

	/**
	 * Test that db version option is deleted.
	 */
	public function test_uninstall_deletes_db_version() {
		// Set db version.
		update_option( DatabaseManager::DB_VERSION_OPTION, DatabaseManager::DB_VERSION );

		// Verify it exists.
		$this->assertEquals(
			DatabaseManager::DB_VERSION,
			get_option( DatabaseManager::DB_VERSION_OPTION )
		);

		// Run uninstall.
		$this->db_manager->uninstall();

		// Verify it's deleted.
		$this->assertFalse(
			get_option( DatabaseManager::DB_VERSION_OPTION ),
			'DB version option should be deleted after uninstall'
		);
	}
}
