<?php
/**
 * Integration tests for CheckoutOrchestrator
 *
 * @package WhatsApp_Commerce_Hub
 */

use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutOrchestratorInterface;

/**
 * Test CheckoutOrchestrator integration.
 */
class WCH_Checkout_Test extends WCH_Integration_Test_Case {

	/**
	 * Checkout orchestrator instance.
	 *
	 * @var CheckoutOrchestratorInterface
	 */
	private $checkout;

	/**
	 * Test customer phone number.
	 *
	 * @var string
	 */
	private $customer_phone = '+1234567890';

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->checkout = wch_get_container()->get( CheckoutOrchestratorInterface::class );
		$this->mock_whatsapp_success();
	}

	/**
	 * Test starting checkout with cart items.
	 */
	public function test_start_checkout_with_items() {
		$product = $this->create_test_product( [ 'regular_price' => '99.99' ] );
		$cart_service = wch_get_container()->get( 'wch.cart' );
		$cart_service->addItem( $this->customer_phone, $product->get_id(), 1 );

		$response = $this->checkout->startCheckout( $this->customer_phone );

		$this->assertTrue( $response->success );
		$this->assertEquals( 'address', $response->current_step );
		$this->assertNotEmpty( $response->messages );
	}

	/**
	 * Test starting checkout with empty cart fails.
	 */
	public function test_start_checkout_with_empty_cart() {
		$response = $this->checkout->startCheckout( $this->customer_phone );

		$this->assertFalse( $response->success );
		$this->assertEquals( 'empty_cart', $response->error_code );
	}

	/**
	 * Test processing address selection.
	 */
	public function test_process_address_selection() {
		$product = $this->create_test_product( [ 'regular_price' => '50.00' ] );
		$cart_service = wch_get_container()->get( 'wch.cart' );
		$cart_service->addItem( $this->customer_phone, $product->get_id(), 1 );

		// Start checkout.
		$this->checkout->startCheckout( $this->customer_phone );

		// Process new address input.
		$state_data = [
			'checkout_data' => [],
		];

		$response = $this->checkout->processInput(
			$this->customer_phone,
			'new_address',
			'address',
			$state_data
		);

		$this->assertTrue( $response->success );
	}

	/**
	 * Test navigating to previous step.
	 */
	public function test_go_back_from_shipping() {
		$product = $this->create_test_product( [ 'regular_price' => '50.00' ] );
		$cart_service = wch_get_container()->get( 'wch.cart' );
		$cart_service->addItem( $this->customer_phone, $product->get_id(), 1 );

		$state_data = [
			'checkout_data' => [
				'shipping_address' => [
					'address_1' => '123 Main St',
					'city' => 'New York',
					'postcode' => '10001',
					'country' => 'US',
				],
			],
		];

		$response = $this->checkout->goBack( $this->customer_phone, 'shipping', $state_data );

		$this->assertEquals( 'address', $response->current_step );
	}

	/**
	 * Test cancelling checkout.
	 */
	public function test_cancel_checkout() {
		$response = $this->checkout->cancelCheckout( $this->customer_phone );

		$this->assertFalse( $response->success );
		$this->assertEquals( 'checkout_cancelled', $response->error_code );
		$this->assertNotEmpty( $response->messages );
	}

	/**
	 * Test getting checkout steps.
	 */
	public function test_get_steps() {
		$steps = $this->checkout->getSteps();

		$this->assertArrayHasKey( 'address', $steps );
		$this->assertArrayHasKey( 'shipping', $steps );
		$this->assertArrayHasKey( 'payment', $steps );
		$this->assertArrayHasKey( 'review', $steps );
		$this->assertArrayHasKey( 'confirm', $steps );
	}

	/**
	 * Test getting individual step.
	 */
	public function test_get_individual_step() {
		$address_step = $this->checkout->getStep( 'address' );

		$this->assertNotNull( $address_step );
		$this->assertEquals( 'address', $address_step->getStepId() );
		$this->assertEquals( 'shipping', $address_step->getNextStep() );
		$this->assertNull( $address_step->getPreviousStep() );
	}

	/**
	 * Test navigating to specific step.
	 */
	public function test_go_to_step() {
		$product = $this->create_test_product( [ 'regular_price' => '50.00' ] );
		$cart_service = wch_get_container()->get( 'wch.cart' );
		$cart_service->addItem( $this->customer_phone, $product->get_id(), 1 );

		$state_data = [
			'checkout_data' => [
				'shipping_address' => [
					'address_1' => '123 Main St',
					'city' => 'New York',
					'postcode' => '10001',
					'country' => 'US',
				],
			],
		];

		$response = $this->checkout->goToStep( $this->customer_phone, 'payment', $state_data );

		$this->assertTrue( $response->success );
		$this->assertEquals( 'payment', $response->current_step );
	}

	/**
	 * Test step progression through checkout flow.
	 */
	public function test_complete_checkout_flow() {
		$product = $this->create_test_product( [ 'regular_price' => '75.00' ] );
		$cart_service = wch_get_container()->get( 'wch.cart' );
		$cart_service->addItem( $this->customer_phone, $product->get_id(), 1 );

		// Step 1: Start checkout (address step).
		$response = $this->checkout->startCheckout( $this->customer_phone );
		$this->assertTrue( $response->success );
		$this->assertEquals( 'address', $response->current_step );

		// Build state data progressively.
		$state_data = [
			'checkout_data' => [],
		];

		// Step 2: Process address selection (use saved address).
		$response = $this->checkout->processInput(
			$this->customer_phone,
			'addr_1',
			'address',
			$state_data
		);

		// Update state with address.
		if ( ! empty( $response->step_data ) ) {
			$state_data['checkout_data'] = array_merge(
				$state_data['checkout_data'],
				$response->step_data
			);
		}

		// Add test address to state for subsequent steps.
		$state_data['checkout_data']['shipping_address'] = [
			'address_1' => '123 Main St',
			'city' => 'New York',
			'state' => 'NY',
			'postcode' => '10001',
			'country' => 'US',
		];

		// Step 3: Go to shipping step.
		$response = $this->checkout->goToStep( $this->customer_phone, 'shipping', $state_data );
		$this->assertTrue( $response->success );

		// Step 4: Select shipping method.
		$response = $this->checkout->processInput(
			$this->customer_phone,
			'shipping_free_shipping',
			'shipping',
			$state_data
		);

		if ( ! empty( $response->step_data ) ) {
			$state_data['checkout_data'] = array_merge(
				$state_data['checkout_data'],
				$response->step_data
			);
		}

		// Step 5: Go to payment step.
		$response = $this->checkout->goToStep( $this->customer_phone, 'payment', $state_data );
		$this->assertTrue( $response->success );

		// Step 6: Select payment method.
		$response = $this->checkout->processInput(
			$this->customer_phone,
			'payment_cod',
			'payment',
			$state_data
		);

		if ( ! empty( $response->step_data ) ) {
			$state_data['checkout_data'] = array_merge(
				$state_data['checkout_data'],
				$response->step_data
			);
		}

		// Add payment method to state.
		$state_data['checkout_data']['payment_method'] = [
			'id' => 'cod',
			'label' => 'Cash on Delivery',
		];

		// Step 7: Go to review step.
		$response = $this->checkout->goToStep( $this->customer_phone, 'review', $state_data );
		$this->assertTrue( $response->success );

		// Add shipping method to state.
		$state_data['checkout_data']['shipping_method'] = [
			'id' => 'free_shipping',
			'label' => 'Free Shipping',
			'cost' => 0,
		];

		// Step 8: Confirm order.
		$response = $this->checkout->processInput(
			$this->customer_phone,
			'confirm_order',
			'review',
			$state_data
		);

		// Verify order was created.
		$this->assertTrue( $response->success || $response->is_completed );
	}
}
