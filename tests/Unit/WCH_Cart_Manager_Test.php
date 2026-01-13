<?php
/**
 * Unit tests for WCH_Cart_Manager
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Cart_Manager class.
 */
class WCH_Cart_Manager_Test extends WCH_Unit_Test_Case {

	/**
	 * Cart manager instance.
	 *
	 * @var WCH_Cart_Manager
	 */
	private $cart_manager;

	/**
	 * Test customer phone.
	 *
	 * @var string
	 */
	private $customer_phone = '+1234567890';

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure database tables exist.
		$db_manager = new WCH_Database_Manager();
		$db_manager->install();

		$this->cart_manager = new WCH_Cart_Manager();
	}

	/**
	 * Test creating a new cart.
	 */
	public function test_create_cart() {
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->assertIsInt( $cart_id );
		$this->assertGreaterThan( 0, $cart_id );
	}

	/**
	 * Test getting active cart.
	 */
	public function test_get_active_cart() {
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );
		$cart = $this->cart_manager->get_active_cart( $this->customer_phone );

		$this->assertIsArray( $cart );
		$this->assertEquals( $cart_id, $cart['id'] );
		$this->assertEquals( 'active', $cart['status'] );
	}

	/**
	 * Test adding item to cart.
	 */
	public function test_add_item_to_cart() {
		$product = $this->create_test_product();
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$result = $this->cart_manager->add_item( $cart_id, $product->get_id(), 2 );

		$this->assertTrue( $result );

		$items = $this->cart_manager->get_cart_items( $cart_id );
		$this->assertCount( 1, $items );
		$this->assertEquals( $product->get_id(), $items[0]['product_id'] );
		$this->assertEquals( 2, $items[0]['quantity'] );
	}

	/**
	 * Test adding multiple items.
	 */
	public function test_add_multiple_items() {
		$product1 = $this->create_test_product( [ 'name' => 'Product 1' ] );
		$product2 = $this->create_test_product( [ 'name' => 'Product 2' ] );
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->cart_manager->add_item( $cart_id, $product1->get_id(), 1 );
		$this->cart_manager->add_item( $cart_id, $product2->get_id(), 3 );

		$items = $this->cart_manager->get_cart_items( $cart_id );
		$this->assertCount( 2, $items );
	}

	/**
	 * Test updating item quantity.
	 */
	public function test_update_item_quantity() {
		$product = $this->create_test_product();
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->cart_manager->add_item( $cart_id, $product->get_id(), 1 );
		$this->cart_manager->update_quantity( $cart_id, $product->get_id(), 5 );

		$items = $this->cart_manager->get_cart_items( $cart_id );
		$this->assertEquals( 5, $items[0]['quantity'] );
	}

	/**
	 * Test removing item from cart.
	 */
	public function test_remove_item() {
		$product = $this->create_test_product();
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->cart_manager->add_item( $cart_id, $product->get_id(), 2 );
		$this->cart_manager->remove_item( $cart_id, $product->get_id() );

		$items = $this->cart_manager->get_cart_items( $cart_id );
		$this->assertCount( 0, $items );
	}

	/**
	 * Test clearing cart.
	 */
	public function test_clear_cart() {
		$product1 = $this->create_test_product();
		$product2 = $this->create_test_product();
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->cart_manager->add_item( $cart_id, $product1->get_id(), 1 );
		$this->cart_manager->add_item( $cart_id, $product2->get_id(), 1 );

		$this->cart_manager->clear_cart( $cart_id );

		$items = $this->cart_manager->get_cart_items( $cart_id );
		$this->assertCount( 0, $items );
	}

	/**
	 * Test calculating cart total.
	 */
	public function test_calculate_cart_total() {
		$product1 = $this->create_test_product( [ 'regular_price' => '10.00' ] );
		$product2 = $this->create_test_product( [ 'regular_price' => '20.00' ] );
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->cart_manager->add_item( $cart_id, $product1->get_id(), 2 ); // 2 * 10 = 20
		$this->cart_manager->add_item( $cart_id, $product2->get_id(), 1 ); // 1 * 20 = 20

		$total = $this->cart_manager->get_cart_total( $cart_id );
		$this->assertEquals( 40.00, $total );
	}

	/**
	 * Test stock validation when adding to cart.
	 */
	public function test_validates_stock_when_adding() {
		$product = $this->create_test_product( [ 'stock_quantity' => 5 ] );
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->expectException( WCH_Cart_Exception::class );
		$this->cart_manager->add_item( $cart_id, $product->get_id(), 10 );
	}

	/**
	 * Test preventing adding out of stock products.
	 */
	public function test_prevents_adding_out_of_stock_products() {
		$product = $this->create_test_product( [ 'stock_quantity' => 0 ] );
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->expectException( WCH_Cart_Exception::class );
		$this->cart_manager->add_item( $cart_id, $product->get_id(), 1 );
	}

	/**
	 * Test getting cart item count.
	 */
	public function test_get_cart_item_count() {
		$product1 = $this->create_test_product();
		$product2 = $this->create_test_product();
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->cart_manager->add_item( $cart_id, $product1->get_id(), 2 );
		$this->cart_manager->add_item( $cart_id, $product2->get_id(), 3 );

		$count = $this->cart_manager->get_item_count( $cart_id );
		$this->assertEquals( 5, $count ); // 2 + 3
	}

	/**
	 * Test abandoning cart.
	 */
	public function test_abandon_cart() {
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );
		$this->cart_manager->abandon_cart( $cart_id );

		$cart = $this->cart_manager->get_cart( $cart_id );
		$this->assertEquals( 'abandoned', $cart['status'] );
	}

	/**
	 * Test converting cart to order.
	 */
	public function test_convert_to_order() {
		$product = $this->create_test_product();
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );
		$this->cart_manager->add_item( $cart_id, $product->get_id(), 1 );

		$order_id = $this->cart_manager->convert_to_order( $cart_id );

		$this->assertIsInt( $order_id );
		$this->assertGreaterThan( 0, $order_id );

		$cart = $this->cart_manager->get_cart( $cart_id );
		$this->assertEquals( 'completed', $cart['status'] );
	}

	/**
	 * Test incrementing existing item quantity.
	 */
	public function test_increment_existing_item() {
		$product = $this->create_test_product();
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		$this->cart_manager->add_item( $cart_id, $product->get_id(), 2 );
		$this->cart_manager->add_item( $cart_id, $product->get_id(), 3 ); // Should increment

		$items = $this->cart_manager->get_cart_items( $cart_id );
		$this->assertCount( 1, $items );
		$this->assertEquals( 5, $items[0]['quantity'] );
	}

	/**
	 * Test cart expiration.
	 */
	public function test_identify_expired_carts() {
		$cart_id = $this->cart_manager->create_cart( $this->customer_phone );

		// Manually set created_at to old date.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wch_carts',
			[ 'created_at' => date( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) ],
			[ 'id' => $cart_id ]
		);

		$expired = $this->cart_manager->get_expired_carts( 24 ); // 24 hours
		$this->assertNotEmpty( $expired );
	}
}
