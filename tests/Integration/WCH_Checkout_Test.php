<?php
/**
 * Integration tests for WCH_Checkout_Controller
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Checkout_Controller integration.
 */
class WCH_Checkout_Test extends WCH_Integration_Test_Case {

	/**
	 * Checkout controller instance.
	 *
	 * @var WCH_Checkout_Controller
	 */
	private $checkout;

	/**
	 * Test conversation ID.
	 *
	 * @var int
	 */
	private $conversation_id;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->checkout = WCH_Checkout_Controller::instance();
		$this->conversation_id = $this->create_test_conversation( array(
			'customer_phone' => '+1234567890',
		) );

		$this->mock_whatsapp_success();
	}

	/**
	 * Test initiating checkout.
	 */
	public function test_initiate_checkout() {
		$product = $this->create_test_product( array( 'regular_price' => '99.99' ) );
		$cart_manager = new WCH_Cart_Manager();
		$cart_id = $cart_manager->create_cart( '+1234567890' );
		$cart_manager->add_item( $cart_id, $product->get_id(), 1 );

		$result = $this->checkout->initiate( $this->conversation_id, $cart_id );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'checkout_id', $result );
	}

	/**
	 * Test collecting shipping address.
	 */
	public function test_collect_shipping_address() {
		$address_data = array(
			'address_1' => '123 Main Street',
			'city' => 'New York',
			'state' => 'NY',
			'postcode' => '10001',
			'country' => 'US',
		);

		$result = $this->checkout->set_shipping_address( $this->conversation_id, $address_data );

		$this->assertTrue( $result );
	}

	/**
	 * Test validating shipping address.
	 */
	public function test_validate_shipping_address() {
		$valid_address = array(
			'address_1' => '123 Main St',
			'city' => 'New York',
			'postcode' => '10001',
			'country' => 'US',
		);

		$result = $this->checkout->validate_address( $valid_address );

		$this->assertTrue( $result );
	}

	/**
	 * Test selecting payment method.
	 */
	public function test_select_payment_method() {
		$result = $this->checkout->set_payment_method( $this->conversation_id, 'cod' );

		$this->assertTrue( $result );
	}

	/**
	 * Test calculating order total with shipping.
	 */
	public function test_calculate_total_with_shipping() {
		$product = $this->create_test_product( array( 'regular_price' => '50.00' ) );
		$cart_manager = new WCH_Cart_Manager();
		$cart_id = $cart_manager->create_cart( '+1234567890' );
		$cart_manager->add_item( $cart_id, $product->get_id(), 2 );

		$total = $this->checkout->calculate_total( $cart_id, array(
			'shipping_cost' => 10.00,
		) );

		$this->assertEquals( 110.00, $total );
	}

	/**
	 * Test completing full checkout flow.
	 */
	public function test_complete_checkout_flow() {
		$product = $this->create_test_product( array( 'regular_price' => '75.00' ) );
		$cart_manager = new WCH_Cart_Manager();
		$cart_id = $cart_manager->create_cart( '+1234567890' );
		$cart_manager->add_item( $cart_id, $product->get_id(), 1 );

		$this->checkout->initiate( $this->conversation_id, $cart_id );

		$this->checkout->set_shipping_address( $this->conversation_id, array(
			'address_1' => '123 Main St',
			'city' => 'New York',
			'postcode' => '10001',
			'country' => 'US',
		) );

		$this->checkout->set_payment_method( $this->conversation_id, 'cod' );

		$result = $this->checkout->complete( $this->conversation_id );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'order_id', $result );
	}

	/**
	 * Test preventing checkout with empty cart.
	 */
	public function test_prevent_checkout_with_empty_cart() {
		$cart_manager = new WCH_Cart_Manager();
		$cart_id = $cart_manager->create_cart( '+1234567890' );

		$this->expectException( WCH_Exception::class );
		$this->checkout->initiate( $this->conversation_id, $cart_id );
	}

	/**
	 * Test applying discount code.
	 */
	public function test_apply_discount_code() {
		$product = $this->create_test_product( array( 'regular_price' => '100.00' ) );
		$cart_manager = new WCH_Cart_Manager();
		$cart_id = $cart_manager->create_cart( '+1234567890' );
		$cart_manager->add_item( $cart_id, $product->get_id(), 1 );

		// Create a coupon.
		$coupon = new WC_Coupon();
		$coupon->set_code( 'SAVE10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();

		$result = $this->checkout->apply_coupon( $cart_id, 'SAVE10' );

		$this->assertTrue( $result );
	}

	/**
	 * Test checkout with out of stock product.
	 */
	public function test_checkout_with_out_of_stock_product() {
		$product = $this->create_test_product( array( 'stock_quantity' => 0 ) );
		$cart_manager = new WCH_Cart_Manager();
		$cart_id = $cart_manager->create_cart( '+1234567890' );

		$this->expectException( WCH_Cart_Exception::class );
		$cart_manager->add_item( $cart_id, $product->get_id(), 1 );
	}

	/**
	 * Test checkout saves customer info.
	 */
	public function test_checkout_saves_customer_info() {
		$product = $this->create_test_product();
		$cart_manager = new WCH_Cart_Manager();
		$cart_id = $cart_manager->create_cart( '+1234567890' );
		$cart_manager->add_item( $cart_id, $product->get_id(), 1 );

		$this->checkout->initiate( $this->conversation_id, $cart_id );
		$this->checkout->set_shipping_address( $this->conversation_id, array(
			'first_name' => 'John',
			'last_name' => 'Doe',
			'email' => 'john@example.com',
			'address_1' => '123 Main St',
			'city' => 'New York',
			'postcode' => '10001',
			'country' => 'US',
		) );
		$this->checkout->set_payment_method( $this->conversation_id, 'cod' );

		$result = $this->checkout->complete( $this->conversation_id );

		$order = wc_get_order( $result['order_id'] );
		$this->assertEquals( 'John', $order->get_billing_first_name() );
		$this->assertEquals( 'Doe', $order->get_billing_last_name() );
		$this->assertEquals( 'john@example.com', $order->get_billing_email() );
	}
}
