<?php
/**
 * Base Unit Test Case
 *
 * @package WhatsApp_Commerce_Hub
 */

use PHPUnit\Framework\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Abstract base class for unit tests.
 */
abstract class WCH_Unit_Test_Case extends WP_UnitTestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Mock API client.
	 *
	 * @var Mockery\MockInterface
	 */
	protected $mock_api_client;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Initialize mock API client.
		$this->mock_api_client = $this->create_mock_api_client();

		// Clear any cached data.
		wp_cache_flush();
	}

	/**
	 * Teardown after each test.
	 */
	protected function tearDown(): void {
		// Close mockery.
		Mockery::close();

		// Clear database changes.
		$this->clean_up_global_scope();

		parent::tearDown();
	}

	/**
	 * Create a mock WhatsApp API client.
	 *
	 * @return Mockery\MockInterface
	 */
	protected function create_mock_api_client() {
		$mock = Mockery::mock( 'WCH_WhatsApp_API_Client' );

		// Default mock behaviors.
		$mock->shouldReceive( 'send_message' )
			->andReturn( [
				'success' => true,
				'message_id' => 'wamid.test_' . wp_generate_uuid4(),
			] )
			->byDefault();

		$mock->shouldReceive( 'send_template' )
			->andReturn( [
				'success' => true,
				'message_id' => 'wamid.test_' . wp_generate_uuid4(),
			] )
			->byDefault();

		return $mock;
	}

	/**
	 * Helper to create a test product.
	 *
	 * @param array $args Product arguments.
	 * @return WC_Product
	 */
	protected function create_test_product( array $args = [] ): WC_Product {
		$defaults = [
			'name' => 'Test Product',
			'regular_price' => '29.99',
			'sku' => 'TEST-SKU-' . wp_rand( 1000, 9999 ),
			'manage_stock' => true,
			'stock_quantity' => 10,
			'status' => 'publish',
		];

		$args = wp_parse_args( $args, $defaults );

		$product = new WC_Product_Simple();
		$product->set_name( $args['name'] );
		$product->set_regular_price( $args['regular_price'] );
		$product->set_sku( $args['sku'] );
		$product->set_manage_stock( $args['manage_stock'] );

		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_stock_quantity( $args['stock_quantity'] );
		}

		$product->set_status( $args['status'] );
		$product->save();

		return $product;
	}

	/**
	 * Helper to create a test order.
	 *
	 * @param array $args Order arguments.
	 * @return WC_Order
	 */
	protected function create_test_order( array $args = [] ): WC_Order {
		$defaults = [
			'status' => 'pending',
			'customer_id' => 1,
			'billing_phone' => '+1234567890',
			'billing_email' => 'test@example.com',
			'billing_first_name' => 'John',
			'billing_last_name' => 'Doe',
		];

		$args = wp_parse_args( $args, $defaults );

		$order = wc_create_order();
		$order->set_status( $args['status'] );
		$order->set_customer_id( $args['customer_id'] );
		$order->set_billing_phone( $args['billing_phone'] );
		$order->set_billing_email( $args['billing_email'] );
		$order->set_billing_first_name( $args['billing_first_name'] );
		$order->set_billing_last_name( $args['billing_last_name'] );

		// Add product if provided.
		if ( isset( $args['product'] ) ) {
			$order->add_product( $args['product'], $args['quantity'] ?? 1 );
		}

		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Helper to create a test conversation.
	 *
	 * @param array $args Conversation arguments.
	 * @return int Conversation ID.
	 */
	protected function create_test_conversation( array $args = [] ): int {
		global $wpdb;

		$defaults = [
			'customer_phone' => '+1234567890',
			'customer_name' => 'Test Customer',
			'state' => 'BROWSING',
			'last_message_at' => current_time( 'mysql' ),
			'created_at' => current_time( 'mysql' ),
		];

		$args = wp_parse_args( $args, $defaults );

		$table_name = $wpdb->prefix . 'wch_conversations';

		$wpdb->insert(
			$table_name,
			[
				'customer_phone' => $args['customer_phone'],
				'customer_name' => $args['customer_name'],
				'state' => $args['state'],
				'last_message_at' => $args['last_message_at'],
				'created_at' => $args['created_at'],
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Helper to create test conversation context.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param array $data Context data.
	 * @return bool
	 */
	protected function create_test_context( int $conversation_id, array $data = [] ): bool {
		global $wpdb;

		$defaults = [
			'current_category' => null,
			'current_product' => null,
			'cart_items' => array(),
			'shipping_address' => null,
		];

		$data = wp_parse_args( $data, $defaults );

		$table_name = $wpdb->prefix . 'wch_conversation_context';

		return $wpdb->insert(
			$table_name,
			[
				'conversation_id' => $conversation_id,
				'context_data' => wp_json_encode( $data ),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s' ]
		) !== false;
	}

	/**
	 * Assert that an array has specific keys.
	 *
	 * @param array $expected_keys Expected keys.
	 * @param array $array Array to check.
	 * @param string $message Optional message.
	 */
	protected function assertArrayHasKeys( array $expected_keys, array $array, string $message = '' ): void {
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $array, $message ?: "Array should have key: {$key}" );
		}
	}

	/**
	 * Clean up global scope.
	 */
	protected function clean_up_global_scope(): void {
		$_GET = [];
		$_POST = [];
		$_REQUEST = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}
}
