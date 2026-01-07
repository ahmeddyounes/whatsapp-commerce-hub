<?php
/**
 * WCH Action: Confirm Order
 *
 * Create and confirm customer order.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_ConfirmOrder class
 *
 * Handles order confirmation:
 * - Shows final order summary
 * - Creates WooCommerce order
 * - Sends order confirmation with order number
 * - Updates cart status
 */
class WCH_Action_ConfirmOrder extends WCH_Flow_Action {
	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			$this->log( 'Confirming order', array( 'phone' => $conversation->customer_phone ) );

			// Get cart.
			$cart = $this->get_or_create_cart( $conversation->customer_phone );

			if ( ! $cart || empty( $cart['items'] ) ) {
				return $this->error( 'Your cart is empty. Please add items before placing an order.' );
			}

			// Validate required context data.
			if ( empty( $context['shipping_address'] ) ) {
				return $this->error( 'Shipping address is required. Please provide your address.' );
			}

			if ( empty( $context['payment_method'] ) ) {
				return $this->error( 'Payment method is required. Please select a payment method.' );
			}

			// Check for idempotency - prevent duplicate orders.
			if ( ! empty( $context['order_id'] ) ) {
				$this->log(
					'Order already created',
					array( 'order_id' => $context['order_id'] ),
					'info'
				);

				// Return existing order confirmation.
				return $this->show_existing_order( $context['order_id'] );
			}

			// Create WooCommerce order.
			$order_id = $this->create_wc_order( $cart, $context, $conversation );

			if ( ! $order_id ) {
				$this->log( 'Failed to create WC order', array(), 'error' );
				return $this->error( 'Failed to create order. Please try again or contact support.' );
			}

			// Update cart status to completed.
			$this->update_cart( $cart['id'], array( 'status' => 'completed' ) );

			// Track abandoned cart recovery conversion.
			$this->track_cart_recovery( $cart, $order_id );

			// Send confirmation message.
			$message = $this->build_confirmation_message( $order_id );

			return WCH_Action_Result::success(
				array( $message ),
				WCH_Conversation_FSM::STATE_COMPLETED,
				array(
					'order_id'       => $order_id,
					'order_created'  => true,
					'cart_completed' => true,
				)
			);

		} catch ( Exception $e ) {
			$this->log( 'Error confirming order', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, we could not process your order. Please try again or contact support.' );
		}
	}

	/**
	 * Create WooCommerce order
	 *
	 * @param array                    $cart Cart data.
	 * @param array                    $context Conversation context.
	 * @param WCH_Conversation_Context $conversation Conversation object.
	 * @return int|null Order ID or null on failure.
	 */
	private function create_wc_order( $cart, $context, $conversation ) {
		try {
			// Get customer profile.
			$customer = $this->get_customer_profile( $conversation->customer_phone );

			// Create order object.
			$order = wc_create_order();

			if ( ! $order ) {
				return null;
			}

			// Add items to order.
			foreach ( $cart['items'] as $item ) {
				$product_id = $item['product_id'];
				$quantity   = $item['quantity'];
				$variant_id = ! empty( $item['variant_id'] ) ? $item['variant_id'] : null;

				if ( $variant_id ) {
					$product = wc_get_product( $variant_id );
				} else {
					$product = wc_get_product( $product_id );
				}

				if ( ! $product ) {
					continue;
				}

				$order->add_product( $product, $quantity );
			}

			// Set addresses.
			$address = $context['shipping_address'];
			$this->set_order_addresses( $order, $address, $customer );

			// Set customer.
			if ( $customer && ! empty( $customer->wc_customer_id ) ) {
				$order->set_customer_id( $customer->wc_customer_id );
			}

			// Add order meta.
			$order->update_meta_data( '_wch_conversation_id', $conversation->customer_phone );
			$order->update_meta_data( '_wch_cart_id', $cart['id'] );
			$order->update_meta_data( '_wch_channel', 'whatsapp' );
			$order->update_meta_data( '_wch_customer_phone', $conversation->customer_phone );

			// Calculate totals.
			$order->calculate_totals();

			// Save order before payment processing.
			$order->save();

			// Process payment through gateway manager.
			$payment_method  = $context['payment_method'];
			$payment_manager = WCH_Payment_Manager::instance();

			$payment_result = $payment_manager->process_order_payment(
				$order->get_id(),
				$payment_method,
				array(
					'customer_phone' => $conversation->customer_phone,
					'id'             => $conversation->customer_phone,
				)
			);

			if ( ! $payment_result['success'] ) {
				// Payment processing failed, cancel order.
				$order->update_status( 'cancelled', 'Payment processing failed.' );
				$this->log(
					'Payment processing failed',
					array(
						'order_id' => $order->get_id(),
						'error'    => $payment_result['error']['message'] ?? 'Unknown error',
					),
					'error'
				);
				return null;
			}

			$this->log(
				'Order created with payment',
				array(
					'order_id'       => $order->get_id(),
					'total'          => $order->get_total(),
					'payment_method' => $payment_method,
					'transaction_id' => $payment_result['transaction_id'] ?? 'N/A',
				),
				'info'
			);

			return $order->get_id();

		} catch ( Exception $e ) {
			$this->log( 'Exception creating order', array( 'error' => $e->getMessage() ), 'error' );
			return null;
		}
	}

	/**
	 * Set order addresses
	 *
	 * @param WC_Order                  $order Order object.
	 * @param array                     $address Address data.
	 * @param WCH_Customer_Profile|null $customer Customer profile.
	 */
	private function set_order_addresses( $order, $address, $customer ) {
		$address_data = array(
			'first_name' => '',
			'last_name'  => '',
			'company'    => '',
			'address_1'  => ! empty( $address['street'] ) ? $address['street'] : '',
			'address_2'  => ! empty( $address['apartment'] ) ? $address['apartment'] : '',
			'city'       => ! empty( $address['city'] ) ? $address['city'] : '',
			'state'      => ! empty( $address['state'] ) ? $address['state'] : '',
			'postcode'   => ! empty( $address['postal_code'] ) ? $address['postal_code'] : '',
			'country'    => ! empty( $address['country'] ) ? $address['country'] : '',
		);

		// Add customer name if available.
		if ( $customer && ! empty( $customer->name ) ) {
			$name_parts                 = explode( ' ', $customer->name, 2 );
			$address_data['first_name'] = $name_parts[0];
			$address_data['last_name']  = isset( $name_parts[1] ) ? $name_parts[1] : '';
		}

		// Set both billing and shipping address.
		$order->set_address( $address_data, 'billing' );
		$order->set_address( $address_data, 'shipping' );
	}


	/**
	 * Build order confirmation message
	 *
	 * @param int $order_id Order ID.
	 * @return WCH_Message_Builder
	 */
	private function build_confirmation_message( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			$message = new WCH_Message_Builder();
			$message->text( 'Order confirmed! Your order number is: #' . $order_id );
			return $message;
		}

		$message = new WCH_Message_Builder();

		$message->header( 'Order Confirmed!' );

		$body = sprintf(
			"✅ Your order has been placed successfully!\n\n"
			. "Order Number: #%s\n"
			. "Status: %s\n"
			. "Total: %s\n\n"
			. "We'll send you updates on your order status.\n\n"
			. 'Thank you for shopping with us!',
			$order->get_order_number(),
			wc_get_order_status_name( $order->get_status() ),
			$this->format_price( $order->get_total() )
		);

		$message->body( $body );

		// Add order tracking button.
		$message->button(
			'reply',
			array(
				'id'    => 'track_order_' . $order_id,
				'title' => 'Track Order',
			)
		);

		// Add new order button.
		$message->button(
			'reply',
			array(
				'id'    => 'new_order',
				'title' => 'Shop Again',
			)
		);

		return $message;
	}

	/**
	 * Show existing order confirmation
	 *
	 * @param int $order_id Existing order ID.
	 * @return WCH_Action_Result
	 */
	private function show_existing_order( $order_id ) {
		$message = $this->build_confirmation_message( $order_id );

		return WCH_Action_Result::success(
			array( $message ),
			WCH_Conversation_FSM::STATE_COMPLETED,
			array(
				'order_id'      => $order_id,
				'order_created' => true,
			)
		);
	}

	/**
	 * Track abandoned cart recovery conversion.
	 *
	 * Checks if cart had recovery messages sent and marks it as recovered.
	 *
	 * @param array $cart Cart data.
	 * @param int   $order_id WooCommerce order ID.
	 */
	private function track_cart_recovery( $cart, $order_id ) {
		// Check if any recovery reminders were sent.
		$had_reminders = ! empty( $cart['reminder_1_sent_at'] )
			|| ! empty( $cart['reminder_2_sent_at'] )
			|| ! empty( $cart['reminder_3_sent_at'] );

		if ( ! $had_reminders ) {
			return;
		}

		// Get order to get the revenue.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$revenue = $order->get_total();

		// Mark cart as recovered.
		if ( class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
			$recovery = WCH_Abandoned_Cart_Recovery::getInstance();
			$recovery->mark_cart_recovered( $cart['id'], $order_id, $revenue );

			$this->log(
				'Cart recovery tracked',
				array(
					'cart_id'  => $cart['id'],
					'order_id' => $order_id,
					'revenue'  => $revenue,
				),
				'info'
			);
		}
	}
}
