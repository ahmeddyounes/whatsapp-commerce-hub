<?php
/**
 * Base Integration Test Case
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Abstract base class for integration tests with real database operations.
 */
abstract class WCH_Integration_Test_Case extends WCH_Unit_Test_Case {

	/**
	 * HTTP request mocker.
	 *
	 * @var array
	 */
	protected $http_mocks = [];

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure database tables exist.
		$this->ensure_database_tables();

		// Setup HTTP mocking.
		$this->setup_http_mocking();
	}

	/**
	 * Teardown after each test.
	 */
	protected function tearDown(): void {
		// Clear HTTP mocks.
		$this->http_mocks = [];
		remove_all_filters( 'pre_http_request' );

		// Clean up database.
		$this->cleanup_database();

		parent::tearDown();
	}

	/**
	 * Ensure database tables exist for testing.
	 */
	protected function ensure_database_tables(): void {
		$db_manager = new WCH_Database_Manager();
		$db_manager->install();
	}

	/**
	 * Setup HTTP mocking for external API calls.
	 */
	protected function setup_http_mocking(): void {
		// Add filter to intercept HTTP requests.
		add_filter( 'pre_http_request', [ $this, 'mock_http_request' ], 10, 3 );
	}

	/**
	 * Mock HTTP requests.
	 *
	 * @param false|array|WP_Error $preempt Response to return or false to continue.
	 * @param array $args HTTP request arguments.
	 * @param string $url Request URL.
	 * @return false|array|WP_Error
	 */
	public function mock_http_request( $preempt, $args, $url ) {
		// Check if we have a mock for this URL.
		foreach ( $this->http_mocks as $pattern => $response ) {
			if ( preg_match( $pattern, $url ) ) {
				return $response;
			}
		}

		// Return default success for WhatsApp API calls if not mocked.
		if ( strpos( $url, 'graph.facebook.com' ) !== false ) {
			return [
				'response' => array(
					'code' => 200,
					'message' => 'OK',
				),
				'body' => wp_json_encode( array(
					'success' => true,
					'messages' => array(
						array( 'id' => 'wamid.test_' . wp_generate_uuid4() ),
					),
				) ),
			];
		}

		return $preempt;
	}

	/**
	 * Add HTTP mock response.
	 *
	 * @param string $url_pattern URL pattern (regex).
	 * @param array $response Mock response.
	 */
	protected function add_http_mock( string $url_pattern, array $response ): void {
		$this->http_mocks[ $url_pattern ] = $response;
	}

	/**
	 * Mock successful WhatsApp API response.
	 *
	 * @param string $message_id Optional message ID.
	 */
	protected function mock_whatsapp_success( string $message_id = '' ): void {
		if ( empty( $message_id ) ) {
			$message_id = 'wamid.test_' . wp_generate_uuid4();
		}

		$this->add_http_mock(
			'/graph\.facebook\.com/',
			[
				'response' => array(
					'code' => 200,
					'message' => 'OK',
				),
				'body' => wp_json_encode( array(
					'messages' => array(
						array( 'id' => $message_id ),
					),
				) ),
			]
		);
	}

	/**
	 * Mock failed WhatsApp API response.
	 *
	 * @param string $error_message Error message.
	 * @param int $error_code Error code.
	 */
	protected function mock_whatsapp_error( string $error_message = 'API Error', int $error_code = 400 ): void {
		$this->add_http_mock(
			'/graph\.facebook\.com/',
			[
				'response' => array(
					'code' => $error_code,
					'message' => 'Bad Request',
				),
				'body' => wp_json_encode( array(
					'error' => array(
						'message' => $error_message,
						'code' => $error_code,
					),
				) ),
			]
		);
	}

	/**
	 * Cleanup database after tests.
	 */
	protected function cleanup_database(): void {
		global $wpdb;

		// Clean up our custom tables.
		$tables = [
			'wch_conversations',
			'wch_conversation_context',
			'wch_messages',
			'wch_carts',
			'wch_cart_items',
			'wch_broadcast_campaigns',
			'wch_broadcast_recipients',
			'wch_analytics_events',
		];

		foreach ( $tables as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
		}

		// Clean up WooCommerce data.
		$this->cleanup_woocommerce_data();
	}

	/**
	 * Cleanup WooCommerce data.
	 */
	protected function cleanup_woocommerce_data(): void {
		global $wpdb;

		// Delete all products.
		$products = get_posts( [
			'post_type' => 'product',
			'numberposts' => -1,
			'post_status' => 'any',
		] );

		foreach ( $products as $product ) {
			wp_delete_post( $product->ID, true );
		}

		// Delete all orders.
		$orders = wc_get_orders( [
			'limit' => -1,
			'status' => 'any',
		] );

		foreach ( $orders as $order ) {
			$order->delete( true );
		}

		// Clean up meta tables.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})" );
	}

	/**
	 * Assert database row exists.
	 *
	 * @param string $table Table name (without prefix).
	 * @param array $conditions WHERE conditions.
	 * @param string $message Optional message.
	 */
	protected function assertDatabaseHas( string $table, array $conditions, string $message = '' ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . $table;
		$where = [];
		$values = [];

		foreach ( $conditions as $column => $value ) {
			$where[] = "$column = %s";
			$values[] = $value;
		}

		$sql = "SELECT COUNT(*) FROM $table_name WHERE " . implode( ' AND ', $where );
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) );

		$this->assertGreaterThan(
			0,
			$count,
			$message ?: "Failed asserting that table $table has row matching conditions."
		);
	}

	/**
	 * Assert database row does not exist.
	 *
	 * @param string $table Table name (without prefix).
	 * @param array $conditions WHERE conditions.
	 * @param string $message Optional message.
	 */
	protected function assertDatabaseMissing( string $table, array $conditions, string $message = '' ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . $table;
		$where = [];
		$values = [];

		foreach ( $conditions as $column => $value ) {
			$where[] = "$column = %s";
			$values[] = $value;
		}

		$sql = "SELECT COUNT(*) FROM $table_name WHERE " . implode( ' AND ', $where );
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) );

		$this->assertEquals(
			0,
			$count,
			$message ?: "Failed asserting that table $table does not have row matching conditions."
		);
	}
}
