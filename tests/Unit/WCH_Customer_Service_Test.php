<?php
/**
 * Unit tests for WCH_Customer_Service
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Customer_Service class.
 */
class WCH_Customer_Service_Test extends WCH_Unit_Test_Case {

	/**
	 * Customer service instance.
	 *
	 * @var WCH_Customer_Service
	 */
	private $customer_service;

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

		$this->customer_service = WCH_Customer_Service::instance();
	}

	/**
	 * Test creating customer profile.
	 */
	public function test_create_customer_profile() {
		$profile_id = $this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$this->assertIsInt( $profile_id );
		$this->assertGreaterThan( 0, $profile_id );
	}

	/**
	 * Test getting customer profile.
	 */
	public function test_get_customer_profile() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$profile = $this->customer_service->get_profile( $this->customer_phone );

		$this->assertInstanceOf( WCH_Customer_Profile::class, $profile );
		$this->assertEquals( $this->customer_phone, $profile->get_phone() );
		$this->assertEquals( 'John Doe', $profile->get_name() );
	}

	/**
	 * Test updating customer profile.
	 */
	public function test_update_customer_profile() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$result = $this->customer_service->update_profile( $this->customer_phone, [
			'name' => 'Jane Doe',
			'email' => 'jane@example.com',
		] );

		$this->assertTrue( $result );

		$profile = $this->customer_service->get_profile( $this->customer_phone );
		$this->assertEquals( 'Jane Doe', $profile->get_name() );
		$this->assertEquals( 'jane@example.com', $profile->get_email() );
	}

	/**
	 * Test storing customer preferences.
	 */
	public function test_store_customer_preferences() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$this->customer_service->set_preference( $this->customer_phone, 'language', 'en' );
		$this->customer_service->set_preference( $this->customer_phone, 'notifications', true );

		$language = $this->customer_service->get_preference( $this->customer_phone, 'language' );
		$notifications = $this->customer_service->get_preference( $this->customer_phone, 'notifications' );

		$this->assertEquals( 'en', $language );
		$this->assertTrue( $notifications );
	}

	/**
	 * Test getting customer order history.
	 */
	public function test_get_customer_order_history() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		// Create test orders.
		$order1 = $this->create_test_order( [ 'billing_phone' => $this->customer_phone ] );
		$order2 = $this->create_test_order( [ 'billing_phone' => $this->customer_phone ] );

		$orders = $this->customer_service->get_order_history( $this->customer_phone );

		$this->assertIsArray( $orders );
		$this->assertCount( 2, $orders );
	}

	/**
	 * Test tracking customer lifetime value.
	 */
	public function test_calculate_lifetime_value() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$product = $this->create_test_product( [ 'regular_price' => '50.00' ] );

		$order1 = $this->create_test_order( [
			'billing_phone' => $this->customer_phone,
			'product' => $product,
			'quantity' => 2,
		] );
		$order1->set_status( 'completed' );
		$order1->save();

		$ltv = $this->customer_service->get_lifetime_value( $this->customer_phone );

		$this->assertGreaterThan( 0, $ltv );
	}

	/**
	 * Test getting customer segments.
	 */
	public function test_get_customer_segment() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		// Create completed orders to qualify as VIP.
		$product = $this->create_test_product( [ 'regular_price' => '100.00' ] );

		for ( $i = 0; $i < 5; $i++ ) {
			$order = $this->create_test_order( [
				'billing_phone' => $this->customer_phone,
				'product' => $product,
			] );
			$order->set_status( 'completed' );
			$order->save();
		}

		$segment = $this->customer_service->get_segment( $this->customer_phone );

		$this->assertContains( $segment, [ 'new', 'regular', 'vip' ] );
	}

	/**
	 * Test finding profile by WooCommerce customer ID.
	 */
	public function test_find_by_wc_customer_id() {
		$wc_customer_id = 123;

		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
			'wc_customer_id' => $wc_customer_id,
		] );

		$profile = $this->customer_service->find_by_wc_customer( $wc_customer_id );

		$this->assertInstanceOf( WCH_Customer_Profile::class, $profile );
		$this->assertEquals( $this->customer_phone, $profile->get_phone() );
	}

	/**
	 * Test deleting customer profile.
	 */
	public function test_delete_customer_profile() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$result = $this->customer_service->delete_profile( $this->customer_phone );

		$this->assertTrue( $result );

		$profile = $this->customer_service->get_profile( $this->customer_phone );
		$this->assertNull( $profile );
	}

	/**
	 * Test opt-in for marketing.
	 */
	public function test_opt_in_marketing() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$this->customer_service->opt_in_marketing( $this->customer_phone );

		$profile = $this->customer_service->get_profile( $this->customer_phone );
		$this->assertTrue( $profile->is_opted_in() );
	}

	/**
	 * Test opt-out from marketing.
	 */
	public function test_opt_out_marketing() {
		$this->customer_service->create_profile( [
			'phone' => $this->customer_phone,
			'name' => 'John Doe',
		] );

		$this->customer_service->opt_in_marketing( $this->customer_phone );
		$this->customer_service->opt_out_marketing( $this->customer_phone );

		$profile = $this->customer_service->get_profile( $this->customer_phone );
		$this->assertFalse( $profile->is_opted_in() );
	}
}
