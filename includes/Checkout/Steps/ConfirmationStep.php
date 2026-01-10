<?php
/**
 * Confirmation Step
 *
 * Handles order confirmation and creation during checkout.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Checkout\Steps;

use WhatsAppCommerceHub\Checkout\AbstractStep;
use WhatsAppCommerceHub\Contracts\Services\AddressServiceInterface;
use WhatsAppCommerceHub\Application\Services\MessageBuilderFactory;
use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConfirmationStep
 *
 * Handles final order confirmation and creation.
 */
class ConfirmationStep extends AbstractStep {

	/**
	 * Order sync service.
	 *
	 * @var \WCH_Order_Sync_Service|null
	 */
	private $order_sync_service;

	/**
	 * Constructor.
	 *
	 * @param MessageBuilderFactory   $message_builder    Message builder factory.
	 * @param AddressServiceInterface $address_service    Address service.
	 * @param mixed                   $order_sync_service Order sync service (optional).
	 */
	public function __construct(
		MessageBuilderFactory $message_builder,
		AddressServiceInterface $address_service,
		$order_sync_service = null
	) {
		parent::__construct( $message_builder, $address_service );
		$this->order_sync_service = $order_sync_service;
	}

	/**
	 * Get the step identifier.
	 *
	 * @return string
	 */
	public function getStepId(): string {
		return 'confirm';
	}

	/**
	 * Get the next step identifier.
	 *
	 * @return string|null
	 */
	public function getNextStep(): ?string {
		return null; // Final step.
	}

	/**
	 * Get the previous step identifier.
	 *
	 * @return string|null
	 */
	public function getPreviousStep(): ?string {
		return 'review';
	}

	/**
	 * Get the step title.
	 *
	 * @return string
	 */
	public function getTitle(): string {
		return __( 'Order Confirmation', 'whatsapp-commerce-hub' );
	}

	/**
	 * Execute the step (create order and show confirmation).
	 *
	 * @param array $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function execute( array $context ): CheckoutResponse {
		try {
			$this->log( 'Creating order', array( 'phone' => $this->getCustomerPhone( $context ) ) );

			$cart = $this->getCart( $context );
			$checkout_data = $this->getCheckoutData( $context );
			$customer_phone = $this->getCustomerPhone( $context );

			// Validate all required data is present.
			$validation = $this->validate( array(), $context );
			if ( ! $validation['is_valid'] ) {
				$first_error = reset( $validation['errors'] );
				return $this->failure(
					__( 'Missing checkout data', 'whatsapp-commerce-hub' ),
					'missing_checkout_data',
					array( $this->errorMessage( $first_error ) )
				);
			}

			// Create the order.
			$order_result = $this->createOrder( $cart, $checkout_data, $customer_phone );

			if ( ! $order_result['success'] ) {
				return $this->failure(
					$order_result['error'] ?? __( 'Order creation failed', 'whatsapp-commerce-hub' ),
					'order_creation_failed',
					array( $this->errorMessage( __( 'Sorry, we could not create your order. Please try again.', 'whatsapp-commerce-hub' ) ) )
				);
			}

			$order_id = $order_result['order_id'];
			$order_number = $order_result['order_number'] ?? $order_id;

			// Build confirmation message.
			$message = $this->buildConfirmationMessage( $order_id, $order_number, $checkout_data );

			return CheckoutResponse::completed(
				$order_id,
				array( $message ),
				array(
					'order_number' => $order_number,
					'payment_method' => $checkout_data['payment_method']['id'] ?? 'cod',
				)
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Error creating order', array( 'error' => $e->getMessage() ) );

			return $this->failure(
				$e->getMessage(),
				'confirmation_failed',
				array( $this->errorMessage( __( 'Sorry, we could not process your order. Please try again.', 'whatsapp-commerce-hub' ) ) )
			);
		}
	}

	/**
	 * Process user input for confirmation step.
	 *
	 * @param string $input   User's input.
	 * @param array  $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function processInput( string $input, array $context ): CheckoutResponse {
		// The confirmation step doesn't process input - it only executes.
		// Any input after confirmation would be a new conversation.
		return $this->execute( $context );
	}

	/**
	 * Validate confirmation data.
	 *
	 * @param array $data    Confirmation data.
	 * @param array $context Checkout context.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $data, array $context ): array {
		$errors = array();
		$cart = $this->getCart( $context );
		$checkout_data = $this->getCheckoutData( $context );

		if ( empty( $cart['items'] ) ) {
			$errors['cart'] = __( 'Cart is empty', 'whatsapp-commerce-hub' );
		}

		if ( empty( $checkout_data['shipping_address'] ) ) {
			$errors['shipping_address'] = __( 'Shipping address is required', 'whatsapp-commerce-hub' );
		}

		if ( empty( $checkout_data['shipping_method'] ) ) {
			$errors['shipping_method'] = __( 'Shipping method is required', 'whatsapp-commerce-hub' );
		}

		if ( empty( $checkout_data['payment_method'] ) ) {
			$errors['payment_method'] = __( 'Payment method is required', 'whatsapp-commerce-hub' );
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		);
	}

	/**
	 * Create WooCommerce order.
	 *
	 * @param array  $cart          Cart data.
	 * @param array  $checkout_data Checkout data.
	 * @param string $customer_phone Customer phone.
	 * @return array{success: bool, order_id?: int, order_number?: string, error?: string}
	 */
	private function createOrder( array $cart, array $checkout_data, string $customer_phone ): array {
		try {
			// Use order sync service if available.
			if ( $this->order_sync_service ) {
				$order_id = $this->order_sync_service->create_order_from_cart(
					$cart,
					$checkout_data['shipping_address'],
					$checkout_data['payment_method']['id'] ?? 'cod'
				);

				if ( $order_id ) {
					$order = wc_get_order( $order_id );
					return array(
						'success'      => true,
						'order_id'     => $order_id,
						'order_number' => $order ? $order->get_order_number() : $order_id,
					);
				}

				return array(
					'success' => false,
					'error'   => __( 'Order sync service failed to create order', 'whatsapp-commerce-hub' ),
				);
			}

			// Fallback: Create order directly using WooCommerce.
			if ( ! function_exists( 'wc_create_order' ) ) {
				return array(
					'success' => false,
					'error'   => __( 'WooCommerce is not available', 'whatsapp-commerce-hub' ),
				);
			}

			$order = wc_create_order();

			if ( is_wp_error( $order ) ) {
				return array(
					'success' => false,
					'error'   => $order->get_error_message(),
				);
			}

			// Add items to order.
			foreach ( $cart['items'] as $item ) {
				$product_id = $item['product_id'] ?? 0;
				$variation_id = $item['variation_id'] ?? 0;
				$quantity = $item['quantity'] ?? 1;

				$product = $variation_id > 0 ? wc_get_product( $variation_id ) : wc_get_product( $product_id );

				if ( $product ) {
					$order->add_product( $product, $quantity );
				}
			}

			// Set address.
			$address = $checkout_data['shipping_address'];
			$wc_address = $this->address_service->formatForWooCommerce( $address, 'billing' );
			$order->set_address( $wc_address, 'billing' );

			$wc_shipping = $this->address_service->formatForWooCommerce( $address, 'shipping' );
			$order->set_address( $wc_shipping, 'shipping' );

			// Set phone.
			$order->set_billing_phone( $customer_phone );

			// Set payment method.
			$payment_method = $checkout_data['payment_method']['id'] ?? 'cod';
			$order->set_payment_method( $payment_method );

			// Add shipping.
			$shipping = $checkout_data['shipping_method'] ?? array();
			if ( ! empty( $shipping['cost'] ) && $shipping['cost'] > 0 ) {
				$shipping_item = new \WC_Order_Item_Shipping();
				$shipping_item->set_method_title( $shipping['label'] ?? __( 'Shipping', 'whatsapp-commerce-hub' ) );
				$shipping_item->set_total( $shipping['cost'] );
				$order->add_item( $shipping_item );
			}

			// Calculate totals.
			$order->calculate_totals();

			// Add order note.
			$order->add_order_note(
				sprintf(
					/* translators: %s: phone number */
					__( 'Order placed via WhatsApp from %s', 'whatsapp-commerce-hub' ),
					$customer_phone
				)
			);

			// Set status.
			$order->set_status( 'pending' );
			$order->save();

			// Add meta.
			$order->update_meta_data( '_wch_source', 'whatsapp' );
			$order->update_meta_data( '_wch_customer_phone', $customer_phone );
			$order->save();

			return array(
				'success'      => true,
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Order creation exception', array( 'error' => $e->getMessage() ) );

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Build confirmation message.
	 *
	 * @param int    $order_id     Order ID.
	 * @param string $order_number Order number.
	 * @param array  $checkout_data Checkout data.
	 * @return \WCH_Message_Builder
	 */
	private function buildConfirmationMessage( int $order_id, string $order_number, array $checkout_data ): \WCH_Message_Builder {
		$payment_method = $checkout_data['payment_method']['id'] ?? 'cod';

		$text = sprintf(
			/* translators: %s: order number */
			__( "✅ *Order Confirmed!*\n\nThank you for your order!\n\n*Order Number:* #%s\n\n", 'whatsapp-commerce-hub' ),
			$order_number
		);

		// Add payment-specific instructions.
		switch ( $payment_method ) {
			case 'cod':
				$text .= __( "💰 *Payment:* Cash on Delivery\n\nPlease have the exact amount ready when your order arrives.", 'whatsapp-commerce-hub' );
				break;

			case 'upi':
			case 'pix':
			case 'razorpay':
			case 'stripe':
				$text .= __( "💳 *Payment:* You will receive payment instructions shortly.", 'whatsapp-commerce-hub' );
				break;

			default:
				$text .= __( "📦 We'll send you updates as your order progresses.", 'whatsapp-commerce-hub' );
		}

		$text .= "\n\n" . __( "Reply 'track' anytime to check your order status.", 'whatsapp-commerce-hub' );

		return $this->message_builder->text( $text );
	}
}
