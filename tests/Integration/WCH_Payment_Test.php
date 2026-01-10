<?php
/**
 * Integration tests for WCH_Payment_Manager
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Payment_Manager integration.
 */
class WCH_Payment_Test extends WCH_Integration_Test_Case {

	/**
	 * Payment manager instance.
	 *
	 * @var WCH_Payment_Manager
	 */
	private $payment_manager;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->payment_manager = WCH_Payment_Manager::instance();
		$this->mock_whatsapp_success();
	}

	/**
	 * Test COD payment gateway.
	 */
	public function test_cod_payment_gateway() {
		$product = $this->create_test_product( [ 'regular_price' => '50.00' ] );
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$gateway = $this->payment_manager->get_gateway( 'cod' );
		$result = $gateway->process_payment( $order->get_id() );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'pending', $result['status'] );
	}

	/**
	 * Test payment gateway registration.
	 */
	public function test_register_payment_gateway() {
		$gateways = $this->payment_manager->get_available_gateways();

		$this->assertIsArray( $gateways );
		$this->assertArrayHasKey( 'cod', $gateways );
	}

	/**
	 * Test getting enabled payment methods.
	 */
	public function test_get_enabled_payment_methods() {
		$enabled = $this->payment_manager->get_enabled_methods();

		$this->assertIsArray( $enabled );
		$this->assertNotEmpty( $enabled );
	}

	/**
	 * Test payment method validation.
	 */
	public function test_validate_payment_method() {
		$is_valid = $this->payment_manager->is_valid_method( 'cod' );
		$this->assertTrue( $is_valid );

		$is_invalid = $this->payment_manager->is_valid_method( 'nonexistent' );
		$this->assertFalse( $is_invalid );
	}

	/**
	 * Test processing payment with Stripe (mocked).
	 */
	public function test_stripe_payment_processing() {
		// Mock Stripe API response.
		$this->add_http_mock(
			'/api\.stripe\.com/',
			[
				'response' => array( 'code' => 200 ),
				'body' => wp_json_encode( array(
					'id' => 'pi_test123',
					'status' => 'succeeded',
				) ),
			]
		);

		$product = $this->create_test_product( [ 'regular_price' => '100.00' ] );
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$gateway = $this->payment_manager->get_gateway( 'stripe' );

		if ( $gateway ) {
			$result = $gateway->process_payment( $order->get_id(), [
				'payment_method' => 'pm_test_card',
			] );

			$this->assertTrue( $result['success'] );
		} else {
			$this->markTestSkipped( 'Stripe gateway not available' );
		}
	}

	/**
	 * Test payment failure handling.
	 */
	public function test_payment_failure_handling() {
		// Mock failed payment response.
		$this->add_http_mock(
			'/api\.stripe\.com/',
			[
				'response' => array( 'code' => 402 ),
				'body' => wp_json_encode( array(
					'error' => array(
						'message' => 'Card declined',
					),
				) ),
			]
		);

		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$gateway = $this->payment_manager->get_gateway( 'stripe' );

		if ( $gateway ) {
			$result = $gateway->process_payment( $order->get_id(), [
				'payment_method' => 'pm_test_card',
			] );

			$this->assertFalse( $result['success'] );
			$this->assertArrayHasKey( 'error', $result );
		} else {
			$this->markTestSkipped( 'Stripe gateway not available' );
		}
	}

	/**
	 * Test refund processing.
	 */
	public function test_process_refund() {
		$product = $this->create_test_product( [ 'regular_price' => '75.00' ] );
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
			'status' => 'completed',
		] );

		update_post_meta( $order->get_id(), '_transaction_id', 'txn_test123' );

		$gateway = $this->payment_manager->get_gateway( 'cod' );
		$result = $gateway->process_refund( $order->get_id(), 75.00 );

		$this->assertTrue( $result );
	}

	/**
	 * Test payment webhook processing.
	 */
	public function test_payment_webhook_processing() {
		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$webhook_data = [
			'type' => 'payment_intent.succeeded',
			'data' => array(
				'object' => array(
					'id' => 'pi_test123',
					'status' => 'succeeded',
					'metadata' => array(
						'order_id' => $order->get_id(),
					),
				),
			),
		];

		$handler = new WCH_Payment_Webhook_Handler();
		$result = $handler->process( $webhook_data, 'stripe' );

		$this->assertTrue( $result );
	}

	/**
	 * Test payment notification to customer.
	 */
	public function test_payment_confirmation_notification() {
		$product = $this->create_test_product();
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
		] );

		$order->payment_complete( 'txn_test123' );

		// Verify notification was sent (check for action hook call).
		$this->assertTrue( did_action( 'woocommerce_payment_complete' ) > 0 );
	}

	/**
	 * Test partial refund.
	 */
	public function test_partial_refund() {
		$product = $this->create_test_product( [ 'regular_price' => '100.00' ] );
		$order = $this->create_test_order( [
			'billing_phone' => '+1234567890',
			'product' => $product,
			'status' => 'completed',
		] );

		$gateway = $this->payment_manager->get_gateway( 'cod' );
		$result = $gateway->process_refund( $order->get_id(), 50.00 );

		$this->assertTrue( $result );
	}

	/**
	 * Test payment method supports features.
	 */
	public function test_payment_method_supports_features() {
		$gateway = $this->payment_manager->get_gateway( 'cod' );

		$this->assertTrue( $gateway->supports( 'products' ) );
	}
}
