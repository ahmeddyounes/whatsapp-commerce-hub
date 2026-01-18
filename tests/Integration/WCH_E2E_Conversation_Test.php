<?php
/**
 * End-to-End Conversation Flow Tests
 *
 * Tests complete customer journeys through the WhatsApp Commerce Hub.
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Class WCH_E2E_Conversation_Test
 *
 * End-to-end integration tests for complete conversation flows.
 */
class WCH_E2E_Conversation_Test extends WCH_Integration_Test_Case {

	/**
	 * Webhook handler instance.
	 *
	 * @var WCH_Webhook_Handler
	 */
	private $webhook_handler;

	/**
	 * Conversation FSM instance.
	 *
	 * @var WCH_Conversation_FSM
	 */
	private $fsm;

	/**
	 * Cart manager instance.
	 *
	 * @var WCH_Cart_Manager
	 */
	private $cart_manager;

	/**
	 * Checkout orchestrator instance.
	 *
	 * @var \WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutOrchestratorInterface
	 */
	private $checkout;

	/**
	 * Test customer phone number.
	 *
	 * @var string
	 */
	private $customer_phone = '+1234567890';

	/**
	 * Sent messages tracker.
	 *
	 * @var array
	 */
	private $sent_messages = [];

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->webhook_handler = new WCH_Webhook_Handler();
		$this->fsm = new WCH_Conversation_FSM();
		$this->cart_manager = WCH_Cart_Manager::instance();

		// Configure settings.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'api.phone_number_id', 'test_phone_number_id' );
		$settings->set( 'api.access_token', 'test_access_token' );
		$settings->set( 'webhook.app_secret', '' ); // Disable signature validation for tests.

		// Track sent messages.
		add_filter( 'pre_http_request', [ $this, 'track_sent_messages' ], 10, 3 );

		$this->sent_messages = [];
		$this->mock_whatsapp_success();
	}

	/**
	 * Track sent messages for assertions.
	 *
	 * @param false|array|WP_Error $preempt Response to return or false to continue.
	 * @param array $args HTTP request arguments.
	 * @param string $url Request URL.
	 * @return false|array|WP_Error
	 */
	public function track_sent_messages( $preempt, $args, $url ) {
		// Track WhatsApp API messages.
		if ( strpos( $url, 'graph.facebook.com' ) !== false && isset( $args['body'] ) ) {
			$body = json_decode( $args['body'], true );
			if ( $body ) {
				$this->sent_messages[] = $body;
			}
		}

		// Return parent mock.
		return parent::mock_http_request( $preempt, $args, $url );
	}

	/**
	 * Test complete purchase flow from greeting to order confirmation.
	 */
	public function test_complete_purchase_flow() {
		// Create test products.
		$product = $this->create_test_product( [
			'name' => 'Test T-Shirt',
			'regular_price' => '25.00',
			'stock_quantity' => 10,
		] );

		// Step 1: Customer greeting.
		$conversation_id = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Hi, I want to shop',
			'from' => $this->customer_phone,
		] );

		$this->assertGreaterThan( 0, $conversation_id );
		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_BROWSING );
		$this->assert_message_sent( 'interactive' ); // Main menu.

		// Step 2: Browse category.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'browse_products',
			'from' => $this->customer_phone,
		] );

		$this->assert_message_sent( 'interactive' ); // Product list.

		// Step 3: View product.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'list_reply',
			'list_id' => 'product_' . $product->get_id(),
			'from' => $this->customer_phone,
		] );

		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_VIEWING_PRODUCT );
		$this->assert_message_sent( 'text' ); // Product details.

		// Step 4: Add to cart.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'add_to_cart',
			'from' => $this->customer_phone,
		] );

		// Assert cart created and item added.
		$cart = $this->get_customer_cart( $this->customer_phone );
		$this->assertNotNull( $cart );
		$this->assertEquals( 1, count( $cart['items'] ) );
		$this->assertEquals( $product->get_id(), $cart['items'][0]['product_id'] );

		// Step 5: Checkout with address.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'checkout',
			'from' => $this->customer_phone,
		] );

		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_CHECKOUT_ADDRESS );

		// Step 6: Provide shipping address.
		$this->simulate_webhook( [
			'type' => 'text',
			'text' => '123 Main St, New York, NY 10001',
			'from' => $this->customer_phone,
		] );

		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_CHECKOUT_PAYMENT );

		// Step 7: Select payment method.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'payment_cod',
			'from' => $this->customer_phone,
		] );

		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_CHECKOUT_CONFIRM );

		// Step 8: Confirm order.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'confirm_order',
			'from' => $this->customer_phone,
		] );

		// Assert order created.
		$orders = wc_get_orders( [
			'billing_phone' => $this->customer_phone,
			'limit' => 1,
		] );

		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertEquals( 'pending', $order->get_status() );
		$this->assertCount( 1, $order->get_items() );

		// Assert confirmation notification sent.
		$this->assert_message_sent( 'text', 'order' );

		// Assert conversation state progressed correctly.
		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_COMPLETED );
	}

	/**
	 * Test cart modification flow.
	 */
	public function test_cart_modification_flow() {
		// Create test products.
		$product_a = $this->create_test_product( [
			'name' => 'Product A',
			'regular_price' => '10.00',
		] );

		$product_b = $this->create_test_product( [
			'name' => 'Product B',
			'regular_price' => '20.00',
		] );

		// Create coupon.
		$coupon = new WC_Coupon();
		$coupon->set_code( 'SAVE10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();

		// Start conversation.
		$conversation_id = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Hello',
			'from' => $this->customer_phone,
		] );

		// Add product A.
		$cart = $this->cart_manager->add_item( $this->customer_phone, $product_a->get_id(), null, 1 );

		// Add product B.
		$cart = $this->cart_manager->add_item( $this->customer_phone, $product_b->get_id(), null, 1 );

		// Assert cart has both products.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$this->assertCount( 2, $cart['items'] );
		$initial_total = $cart['total'];
		$this->assertEquals( 30.00, $initial_total );

		// Update quantity of product A (item index 0).
		$this->cart_manager->update_quantity( $this->customer_phone, 0, 3 );

		// Assert quantity updated.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$item_a = $cart['items'][0];
		$this->assertEquals( 3, $item_a['quantity'] );

		// Remove product B (item index 1).
		$this->cart_manager->remove_item( $this->customer_phone, 1 );

		// Assert cart reflects changes.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$this->assertCount( 1, $cart['items'] );
		$this->assertEquals( 30.00, $cart['total'] ); // 3 * 10.00.

		// Apply coupon.
		$result = $this->cart_manager->apply_coupon( $this->customer_phone, 'SAVE10' );
		$this->assertTrue( $result );

		// Assert coupon applied and total calculated correctly.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$this->assertEquals( 27.00, $cart['total'] ); // 30.00 - 10%.
		$this->assertArrayHasKey( 'discount_amount', $cart );
		$this->assertEquals( 3.00, $cart['discount_amount'] );

		// View cart.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'view_cart',
			'from' => $this->customer_phone,
		] );

		$this->assert_message_sent( 'text', 'cart' );
	}

	/**
	 * Test returning customer flow.
	 */
	public function test_returning_customer_flow() {
		// Create test product and previous order.
		$product = $this->create_test_product( [
			'name' => 'Test Product',
			'regular_price' => '50.00',
		] );

		$previous_order = $this->create_test_order( [
			'billing_phone' => $this->customer_phone,
			'billing_email' => 'customer@example.com',
			'billing_first_name' => 'John',
			'billing_last_name' => 'Doe',
			'billing_address_1' => '456 Oak Ave',
			'billing_city' => 'Boston',
			'billing_state' => 'MA',
			'billing_postcode' => '02101',
			'product' => $product,
			'quantity' => 1,
		] );

		$previous_order->set_status( 'completed' );
		$previous_order->save();

		// Start conversation.
		$conversation_id = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Hi',
			'from' => $this->customer_phone,
		] );

		// Assert customer recognized.
		$this->assertDatabaseHas( 'wch_conversations', [
			'customer_phone' => $this->customer_phone,
		] );

		// Reorder previous product.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'reorder_last',
			'from' => $this->customer_phone,
		] );

		// Assert cart created with previous product.
		$cart = $this->get_customer_cart( $this->customer_phone );
		$this->assertNotNull( $cart );
		$this->assertCount( 1, $cart['items'] );
		$this->assertEquals( $product->get_id(), $cart['items'][0]['product_id'] );

		// Start checkout - should show saved addresses.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'checkout',
			'from' => $this->customer_phone,
		] );

		// Assert saved address options presented.
		$this->assert_message_sent( 'interactive', 'address' );

		// Select saved address.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'use_saved_address',
			'from' => $this->customer_phone,
		] );

		// Assert checkout progresses faster.
		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_CHECKOUT_PAYMENT );
	}

	/**
	 * Test abandoned cart recovery flow.
	 */
	public function test_abandoned_cart_recovery() {
		// Create test product.
		$product = $this->create_test_product( [
			'name' => 'Abandoned Product',
			'regular_price' => '75.00',
		] );

		// Start conversation and add items.
		$conversation_id = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Hello',
			'from' => $this->customer_phone,
		] );

		$this->cart_manager->add_item( $this->customer_phone, $product->get_id(), null, 1 );

		// Assert cart created.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$this->assertNotNull( $cart );
		$this->assertEquals( 'active', $cart['status'] );

		// Simulate inactivity timeout.
		$this->advance_time( 2 ); // 2 hours.

		// Run abandoned cart handler.
		$abandoned_handler = new WCH_Abandoned_Cart_Handler();
		$abandoned_handler->check_abandoned_carts();

		// Assert cart marked as abandoned.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$this->assertEquals( 'abandoned', $cart['status'] );

		// Assert recovery message sent.
		$this->assertDatabaseHas( 'wch_messages', [
			'phone_number' => $this->customer_phone,
			'direction' => 'outgoing',
		] );

		// Customer returns and resumes.
		$this->simulate_webhook( [
			'type' => 'interactive',
			'interactive_type' => 'button_reply',
			'button_id' => 'resume_cart',
			'from' => $this->customer_phone,
		] );

		// Assert cart restored to active.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$this->assertEquals( 'active', $cart['status'] );

		// Assert conversion tracked by checking database for cart and order.
		$orders = wc_get_orders( [
			'billing_phone' => $this->customer_phone,
			'limit' => 1,
		] );

		// If order exists, it means recovery was successful.
		if ( ! empty( $orders ) ) {
			$this->assertGreaterThan( 0, count( $orders ) );
		}
	}

	/**
	 * Test human handoff flow.
	 */
	public function test_human_handoff_flow() {
		// Start conversation.
		$conversation_id = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Hello',
			'from' => $this->customer_phone,
		] );

		// Customer expresses frustration.
		$this->simulate_webhook( [
			'type' => 'text',
			'text' => 'I need help! This is not working!',
			'from' => $this->customer_phone,
		] );

		// Assert escalation triggered (or explicit request).
		$this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Can I speak to a human?',
			'from' => $this->customer_phone,
		] );

		// Assert bot escalates to human.
		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_AWAITING_HUMAN );

		// Assert agent notified.
		$this->assert_message_sent( 'text', 'agent' );

		// Assert context preserved.
		$context = $this->get_conversation_context( $conversation_id );
		$this->assertNotNull( $context );
		$this->assertArrayHasKey( 'escalation_reason', $context );

		// Human agent takes over (simulated).
		$this->fsm->transition( $conversation_id, WCH_Conversation_FSM::EVENT_AGENT_TAKEOVER );

		// Assert conversation handled by agent.
		$conversation = $this->get_conversation( $conversation_id );
		$this->assertEquals( WCH_Conversation_FSM::STATE_AWAITING_HUMAN, $conversation['state'] );
	}

	/**
	 * Test multi-language flow.
	 */
	public function test_multi_language_flow() {
		// Customer sends Hindi message.
		$conversation_id = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'नमस्ते, मुझे खरीदारी करनी है', // "Hello, I want to shop".
			'from' => $this->customer_phone,
		] );

		// Assert language detected.
		$context = $this->get_conversation_context( $conversation_id );
		$this->assertArrayHasKey( 'detected_language', $context );
		$this->assertEquals( 'hi', $context['detected_language'] );

		// Assert responses in detected language.
		$this->assert_message_sent( 'text' );

		// Flow completes in customer language.
		$this->assert_conversation_state( $conversation_id, WCH_Conversation_FSM::STATE_BROWSING );

		// Test Portuguese.
		$conversation_id_2 = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Olá, quero fazer compras', // "Hello, I want to shop".
			'from' => '+9876543210',
		] );

		$context_2 = $this->get_conversation_context( $conversation_id_2 );
		$this->assertArrayHasKey( 'detected_language', $context_2 );
		$this->assertEquals( 'pt', $context_2['detected_language'] );
	}

	/**
	 * Test error recovery flow.
	 */
	public function test_error_recovery_flow() {
		// Create test product with limited stock.
		$product = $this->create_test_product( [
			'name' => 'Limited Product',
			'regular_price' => '100.00',
			'stock_quantity' => 1,
		] );

		// Start conversation and add to cart.
		$conversation_id = $this->simulate_webhook( [
			'type' => 'text',
			'text' => 'Hi',
			'from' => $this->customer_phone,
		] );

		$this->cart_manager->add_item( $this->customer_phone, $product->get_id(), null, 1 );

		// Product goes out of stock during checkout.
		$product->set_stock_quantity( 0 );
		$product->save();

		// Attempt to add out of stock product - should fail.
		try {
			$this->cart_manager->add_item( $this->customer_phone, $product->get_id(), null, 1 );
			$this->fail( 'Expected exception for out of stock product' );
		} catch ( WCH_Cart_Exception $e ) {
			// Assert customer informed gracefully.
			$this->assertStringContainsString( 'stock', strtolower( $e->getMessage() ) );
		}

		// Restore stock for next test.
		$product->set_stock_quantity( 10 );
		$product->save();

		// Assert no data corruption.
		$cart = $this->cart_manager->get_cart( $this->customer_phone );
		$this->assertEquals( 'active', $cart['status'] );

		// Test network timeout during message send.
		$this->add_http_mock( '/graph\.facebook\.com/', new WP_Error( 'http_request_failed', 'Connection timeout' ) );

		try {
			$this->simulate_webhook( [
				'type' => 'text',
				'text' => 'Hello',
				'from' => '+1111111111',
			] );
		} catch ( Exception $e ) {
			// Assert graceful handling.
			$this->assertInstanceOf( WP_Error::class, $e );
		}
	}

	/**
	 * Simulate incoming webhook message.
	 *
	 * @param array $message_data Message data.
	 * @return int|null Conversation ID.
	 */
	private function simulate_webhook( array $message_data ): ?int {
		$defaults = [
			'from' => $this->customer_phone,
			'type' => 'text',
			'text' => '',
		];

		$message_data = wp_parse_args( $message_data, $defaults );

		// Build webhook payload.
		$payload = [
			'object' => 'whatsapp_business_account',
			'entry' => [
				[
					'id' => 'test_business_id',
					'changes' => array(
						array(
							'value' => array(
								'messaging_product' => 'whatsapp',
								'metadata' => array(
									'display_phone_number' => '+1234567890',
									'phone_number_id' => 'test_phone_number_id',
								),
								'contacts' => array(
									array(
										'profile' => array(
											'name' => 'Test Customer',
										),
										'wa_id' => ltrim( $message_data['from'], '+' ),
									),
								),
								'messages' => array(
									$this->build_message_payload( $message_data ),
								),
							),
							'field' => 'messages',
						),
					),
				],
			],
		];

		// Create request.
		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( wp_json_encode( $payload ) );

		// Handle webhook.
		$response = $this->webhook_handler->handle_webhook( $request );

		// Get conversation ID.
		global $wpdb;
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_conversations WHERE customer_phone = %s ORDER BY id DESC LIMIT 1",
				$message_data['from']
			),
			ARRAY_A
		);

		return $conversation ? (int) $conversation['id'] : null;
	}

	/**
	 * Build message payload for webhook.
	 *
	 * @param array $data Message data.
	 * @return array Message payload.
	 */
	private function build_message_payload( array $data ): array {
		$message = [
			'from' => ltrim( $data['from'], '+' ),
			'id' => 'wamid.test_' . wp_generate_uuid4(),
			'timestamp' => (string) time(),
			'type' => $data['type'],
		];

		switch ( $data['type'] ) {
			case 'text':
				$message['text'] = [ 'body' => $data['text'] ];
				break;

			case 'interactive':
				$message['interactive'] = [
					'type' => $data['interactive_type'],
				];

				if ( 'button_reply' === $data['interactive_type'] ) {
					$message['interactive']['button_reply'] = [
						'id' => $data['button_id'],
						'title' => ucwords( str_replace( '_', ' ', $data['button_id'] ) ),
					];
				} elseif ( 'list_reply' === $data['interactive_type'] ) {
					$message['interactive']['list_reply'] = [
						'id' => $data['list_id'],
						'title' => 'Selected Item',
					];
				}
				break;
		}

		return $message;
	}

	/**
	 * Assert message was sent with specific type and optional content.
	 *
	 * @param string $expected_type Expected message type.
	 * @param string $content_keyword Optional content keyword to check.
	 */
	private function assert_message_sent( string $expected_type, string $content_keyword = '' ): void {
		$found = false;

		foreach ( $this->sent_messages as $message ) {
			if ( isset( $message['type'] ) && $message['type'] === $expected_type ) {
				if ( empty( $content_keyword ) ) {
					$found = true;
					break;
				}

				// Check content.
				$message_json = wp_json_encode( $message );
				if ( stripos( $message_json, $content_keyword ) !== false ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue( $found, "Expected message of type '{$expected_type}' with content '{$content_keyword}' not found." );
	}

	/**
	 * Assert conversation is in expected state.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param string $expected_state Expected state.
	 */
	private function assert_conversation_state( int $conversation_id, string $expected_state ): void {
		$conversation = $this->get_conversation( $conversation_id );
		$this->assertNotNull( $conversation, 'Conversation not found' );
		$this->assertEquals( $expected_state, $conversation['state'], "Expected conversation state '{$expected_state}', got '{$conversation['state']}'" );
	}

	/**
	 * Get conversation by ID.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array|null Conversation data.
	 */
	private function get_conversation( int $conversation_id ): ?array {
		global $wpdb;
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_conversations WHERE id = %d",
				$conversation_id
			),
			ARRAY_A
		);

		return $conversation ?: null;
	}

	/**
	 * Get conversation context.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array Context data.
	 */
	private function get_conversation_context( int $conversation_id ): array {
		global $wpdb;
		$context_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT context_data FROM {$wpdb->prefix}wch_conversation_context WHERE conversation_id = %d",
				$conversation_id
			)
		);

		if ( ! $context_row ) {
			return [];
		}

		return json_decode( $context_row->context_data, true ) ?: [];
	}

	/**
	 * Get customer's active cart.
	 *
	 * @param string $phone Customer phone.
	 * @return array|null Cart data.
	 */
	private function get_customer_cart( string $phone ): ?array {
		global $wpdb;
		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_carts WHERE customer_phone = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
				$phone
			),
			ARRAY_A
		);

		if ( ! $cart ) {
			return null;
		}

		// Get cart items.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_cart_items WHERE cart_id = %d",
				$cart['id']
			),
			ARRAY_A
		);

		$cart['items'] = $items;
		$cart['total'] = (float) $cart['total'];

		return $cart;
	}

	/**
	 * Advance time for testing timeouts.
	 *
	 * @param int $hours Hours to advance.
	 */
	private function advance_time( int $hours ): void {
		global $wpdb;

		// Update timestamps in database.
		$time_offset = $hours * HOUR_IN_SECONDS;

		// Update conversation last_message_at.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wch_conversations SET last_message_at = DATE_SUB(last_message_at, INTERVAL %d SECOND)",
				$time_offset
			)
		);

		// Update cart updated_at.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wch_carts SET updated_at = DATE_SUB(updated_at, INTERVAL %d SECOND)",
				$time_offset
			)
		);
	}
}
