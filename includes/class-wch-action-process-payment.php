<?php
/**
 * WCH Action: Process Payment
 *
 * Handle payment method selection and processing.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_ProcessPayment class
 *
 * Handles different payment methods:
 * - COD (Cash on Delivery): Mark as ready
 * - Card/Online: Send payment link
 * - UPI: Send UPI intent/link
 */
class WCH_Action_ProcessPayment extends WCH_Flow_Action {
	/**
	 * Supported payment methods
	 */
	const METHOD_COD    = 'cod';
	const METHOD_CARD   = 'card';
	const METHOD_UPI    = 'upi';
	const METHOD_ONLINE = 'online';

	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload with payment_method.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			// Get payment method from payload.
			$payment_method = ! empty( $payload['payment_method'] ) ? $payload['payment_method'] : null;

			// If no payment method provided, show selection.
			if ( ! $payment_method ) {
				return $this->show_payment_methods();
			}

			$this->log(
				'Processing payment',
				array(
					'phone'          => $conversation->customer_phone,
					'payment_method' => $payment_method,
				)
			);

			// Get cart for amount.
			$cart = $this->get_or_create_cart( $conversation->customer_phone );

			if ( ! $cart || empty( $cart['items'] ) ) {
				return $this->error( 'Your cart is empty. Please add items before checkout.' );
			}

			// Process based on payment method.
			switch ( $payment_method ) {
				case self::METHOD_COD:
					return $this->process_cod( $cart );

				case self::METHOD_CARD:
				case self::METHOD_ONLINE:
					return $this->process_online_payment( $cart, $payment_method );

				case self::METHOD_UPI:
					return $this->process_upi( $cart );

				default:
					return $this->error( 'Invalid payment method. Please select a valid option.' );
			}

		} catch ( Exception $e ) {
			$this->log( 'Error processing payment', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, we could not process your payment. Please try again.' );
		}
	}

	/**
	 * Show payment method selection
	 *
	 * @return WCH_Action_Result
	 */
	private function show_payment_methods() {
		$message = new WCH_Message_Builder();

		$message->header( 'Select Payment Method' );
		$message->body( 'How would you like to pay?' );

		// Get available payment gateways.
		$payment_manager = WCH_Payment_Manager::instance();
		$country         = WC()->countries->get_base_country();
		$gateways        = $payment_manager->get_available_gateways( $country );

		// Build payment options.
		$options = array();
		foreach ( $gateways as $gateway_id => $gateway ) {
			$description = '';

			// Add gateway-specific descriptions.
			switch ( $gateway_id ) {
				case 'cod':
					$description = 'Pay when you receive the order';
					break;
				case 'stripe':
					$description = 'Secure card and local payments';
					break;
				case 'razorpay':
					$description = 'UPI, Cards, Net Banking, Wallets';
					break;
				case 'whatsapppay':
					$description = 'Pay directly in WhatsApp';
					break;
				case 'pix':
					$description = 'Instant payment via PIX';
					break;
			}

			$options[] = array(
				'id'          => 'payment_' . $gateway_id,
				'title'       => $gateway->get_title(),
				'description' => $description,
			);
		}

		if ( empty( $options ) ) {
			return $this->error( 'No payment methods are currently available. Please contact support.' );
		}

		$message->section( 'Payment Options', $options );

		return WCH_Action_Result::success( array( $message ) );
	}

	/**
	 * Process COD payment
	 *
	 * @param array $cart Cart data.
	 * @return WCH_Action_Result
	 */
	private function process_cod( $cart ) {
		$message = new WCH_Message_Builder();

		$total = $this->format_price( $cart['total'] );

		$text = sprintf(
			"✅ Cash on Delivery Selected\n\n"
			. "Amount to pay: %s\n\n"
			. "You will pay this amount when your order is delivered.\n\n"
			. 'Please confirm your order to proceed.',
			$total
		);

		$message->text( $text );

		$message->button(
			'reply',
			array(
				'id'    => 'confirm_order',
				'title' => 'Confirm Order',
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'change_payment',
				'title' => 'Change Payment',
			)
		);

		return WCH_Action_Result::success(
			array( $message ),
			null,
			array(
				'payment_method' => self::METHOD_COD,
				'payment_status' => 'ready',
			)
		);
	}

	/**
	 * Process online/card payment
	 *
	 * @param array  $cart Cart data.
	 * @param string $method Payment method.
	 * @return WCH_Action_Result
	 */
	private function process_online_payment( $cart, $method ) {
		// Generate payment link.
		$payment_link = $this->generate_payment_link( $cart, $method );

		if ( ! $payment_link ) {
			return $this->error( 'Failed to generate payment link. Please try again or select a different payment method.' );
		}

		$message = new WCH_Message_Builder();

		$total = $this->format_price( $cart['total'] );

		$text = sprintf(
			"💳 Online Payment\n\n"
			. "Amount: %s\n\n"
			. "Click the button below to complete your payment securely.\n\n"
			. "Your order will be confirmed once payment is received.",
			$total
		);

		$message->text( $text );

		$message->button(
			'url',
			array(
				'title' => 'Pay Now',
				'url'   => $payment_link,
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'change_payment',
				'title' => 'Change Payment',
			)
		);

		return WCH_Action_Result::success(
			array( $message ),
			null,
			array(
				'payment_method' => $method,
				'payment_link'   => $payment_link,
				'payment_status' => 'pending',
			)
		);
	}

	/**
	 * Process UPI payment
	 *
	 * @param array $cart Cart data.
	 * @return WCH_Action_Result
	 */
	private function process_upi( $cart ) {
		// Generate UPI intent/link.
		$upi_link = $this->generate_upi_link( $cart );

		if ( ! $upi_link ) {
			return $this->error( 'Failed to generate UPI payment link. Please try again or select a different payment method.' );
		}

		$message = new WCH_Message_Builder();

		$total = $this->format_price( $cart['total'] );

		$text = sprintf(
			"📱 UPI Payment\n\n"
			. "Amount: %s\n\n"
			. "Click the button below to pay using your preferred UPI app (Google Pay, PhonePe, Paytm, etc.).\n\n"
			. "Your order will be confirmed once payment is received.",
			$total
		);

		$message->text( $text );

		$message->button(
			'url',
			array(
				'title' => 'Pay via UPI',
				'url'   => $upi_link,
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'change_payment',
				'title' => 'Change Payment',
			)
		);

		return WCH_Action_Result::success(
			array( $message ),
			null,
			array(
				'payment_method' => self::METHOD_UPI,
				'payment_link'   => $upi_link,
				'payment_status' => 'pending',
			)
		);
	}

	/**
	 * Generate payment link
	 *
	 * @param array  $cart Cart data.
	 * @param string $method Payment method.
	 * @return string|null Payment link or null on failure.
	 */
	private function generate_payment_link( $cart, $method ) {
		// In production, integrate with payment gateway (Stripe, Razorpay, etc.).
		// For now, return a placeholder.

		$settings = WCH_Settings::getInstance();
		$base_url = $settings->get( 'payment.gateway_url', site_url( '/payment' ) );

		$payment_data = array(
			'amount'   => $cart['total'],
			'currency' => get_woocommerce_currency(),
			'cart_id'  => $cart['id'],
			'method'   => $method,
		);

		// Generate unique payment ID.
		$payment_id = 'wch_' . wp_generate_uuid4();

		// Store payment intent (in production, use payment gateway API).
		update_option( 'wch_payment_' . $payment_id, $payment_data, false );

		return add_query_arg(
			array(
				'payment_id' => $payment_id,
				'method'     => $method,
			),
			$base_url
		);
	}

	/**
	 * Generate UPI payment link
	 *
	 * @param array $cart Cart data.
	 * @return string|null UPI link or null on failure.
	 */
	private function generate_upi_link( $cart ) {
		// In production, integrate with UPI payment gateway.
		// For now, generate a basic UPI intent link.

		$settings = WCH_Settings::getInstance();
		$upi_id   = $settings->get( 'payment.upi_id', 'merchant@upi' );

		$params = array(
			'pa' => $upi_id, // Payee address.
			'pn' => get_bloginfo( 'name' ), // Payee name.
			'am' => number_format( $cart['total'], 2, '.', '' ), // Amount.
			'cu' => get_woocommerce_currency(),
			'tn' => 'Order payment', // Transaction note.
		);

		return 'upi://pay?' . http_build_query( $params );
	}
}
