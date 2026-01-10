<?php
/**
 * Integration tests for WCH_Order_Sync_Service
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Order_Sync_Service integration.
 */
class WCH_Order_Sync_Test extends WCH_Integration_Test_Case {

	/**
	 * Order sync service instance.
	 *
	 * @var WCH_Order_Sync_Service
	 */
	private $sync_service;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sync_service = WCH_Order_Sync_Service::instance();
		$this->mock_whatsapp_success();
	}

	/**
	 * Test creating order from WhatsApp conversation.
	 */
	public function test_create_order_from_conversation() {
		$product = $this->create_test_product( [ 'regular_price' => '50.00' ] );
		$conversation_id = $this->create_test_conversation( [
			'customer_phone' => '+1234567890',
			'customer_name' => 'John Doe',
		] );

		$order_data = [
			'customer_phone' => '+1234567890',
			'customer_name' => 'John Doe',
			'items' => array(
				array(
					'product_id' => $product->get_id(),
					'quantity' => 2,
				),
			),
			'shipping_address' => array(
				'address_1' => '123 Main St',
				'city' => 'New York',
				'postcode' => '10001',
				'country' => 'US',
			),
		];

		$order_id = $this->sync_service->create_order( $order_data );

		$this->assertIsInt( $order_id );
		$this->assertGreaterThan( 0, $order_id );

		$order = wc_get_order( $order_id );
		$this->assertEquals( '+1234567890', $order->get_billing_phone() );
		$this->assertEquals( 'John Doe', $order->get_billing_first_name() );
	}

	/**
	 * Test syncing order status to WhatsApp.
	 */
	public function test_sync_order_status_notification() {
		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$result = $this->sync_service->notify_order_status( $order->get_id(), 'processing' );

		$this->assertTrue( $result );
	}

	/**
	 * Test tracking number update notification.
	 */
	public function test_notify_tracking_number() {
		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		update_post_meta( $order->get_id(), '_tracking_number', 'TRACK123456' );

		$result = $this->sync_service->notify_tracking_update( $order->get_id() );

		$this->assertTrue( $result );
	}

	/**
	 * Test order completion notification.
	 */
	public function test_notify_order_completed() {
		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
			'status' => 'processing',
		] );

		$order->set_status( 'completed' );
		$order->save();

		$result = $this->sync_service->notify_order_status( $order->get_id(), 'completed' );

		$this->assertTrue( $result );
	}

	/**
	 * Test order cancellation notification.
	 */
	public function test_notify_order_cancelled() {
		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$order->set_status( 'cancelled' );
		$order->save();

		$result = $this->sync_service->notify_order_status( $order->get_id(), 'cancelled' );

		$this->assertTrue( $result );
	}

	/**
	 * Test linking order to conversation.
	 */
	public function test_link_order_to_conversation() {
		$conversation_id = $this->create_test_conversation();
		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$this->sync_service->link_order_to_conversation( $order->get_id(), $conversation_id );

		$linked_conversation = get_post_meta( $order->get_id(), '_wch_conversation_id', true );
		$this->assertEquals( $conversation_id, $linked_conversation );
	}

	/**
	 * Test getting orders by conversation.
	 */
	public function test_get_orders_by_conversation() {
		$conversation_id = $this->create_test_conversation();
		$product = $this->create_test_product();

		$order1 = $this->create_test_order( [ 'billing_phone' => '+1234567890', 'product' => $product ] );
		$order2 = $this->create_test_order( [ 'billing_phone' => '+1234567890', 'product' => $product ] );

		$this->sync_service->link_order_to_conversation( $order1->get_id(), $conversation_id );
		$this->sync_service->link_order_to_conversation( $order2->get_id(), $conversation_id );

		$orders = $this->sync_service->get_conversation_orders( $conversation_id );

		$this->assertCount( 2, $orders );
	}

	/**
	 * Test order with multiple products.
	 */
	public function test_create_order_with_multiple_products() {
		$product1 = $this->create_test_product( [ 'regular_price' => '25.00' ] );
		$product2 = $this->create_test_product( [ 'regular_price' => '35.00' ] );

		$order_data = [
			'customer_phone' => '+1234567890',
			'items' => array(
				array( 'product_id' => $product1->get_id(), 'quantity' => 2 ),
				array( 'product_id' => $product2->get_id(), 'quantity' => 1 ),
			),
		];

		$order_id = $this->sync_service->create_order( $order_data );

		$order = wc_get_order( $order_id );
		$items = $order->get_items();

		$this->assertCount( 2, $items );
		$this->assertEquals( 85.00, $order->get_total() );
	}
}
