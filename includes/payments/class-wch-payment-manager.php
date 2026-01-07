<?php
/**
 * Payment Gateway Manager
 *
 * Manages all payment gateways and their registration.
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Payment_Manager {
	/**
	 * Registered payment gateways.
	 *
	 * @var array
	 */
	private static $gateways = array();

	/**
	 * Singleton instance.
	 *
	 * @var WCH_Payment_Manager
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Payment_Manager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->register_default_gateways();
	}

	/**
	 * Register default payment gateways.
	 *
	 * @return void
	 */
	private function register_default_gateways() {
		// Load gateway classes.
		require_once WCH_PLUGIN_DIR . 'includes/payments/interface-wch-payment-gateway.php';
		require_once WCH_PLUGIN_DIR . 'includes/payments/class-wch-payment-cod.php';
		require_once WCH_PLUGIN_DIR . 'includes/payments/class-wch-payment-stripe.php';
		require_once WCH_PLUGIN_DIR . 'includes/payments/class-wch-payment-razorpay.php';
		require_once WCH_PLUGIN_DIR . 'includes/payments/class-wch-payment-whatsapppay.php';
		require_once WCH_PLUGIN_DIR . 'includes/payments/class-wch-payment-pix.php';

		// Register gateways.
		$this->register_gateway( new WCH_Payment_COD() );
		$this->register_gateway( new WCH_Payment_Stripe() );
		$this->register_gateway( new WCH_Payment_Razorpay() );
		$this->register_gateway( new WCH_Payment_WhatsAppPay() );
		$this->register_gateway( new WCH_Payment_PIX() );

		// Allow third-party gateways to be registered.
		do_action( 'wch_register_payment_gateways', $this );
	}

	/**
	 * Register a payment gateway.
	 *
	 * @param WCH_Payment_Gateway $gateway Gateway instance.
	 * @return void
	 */
	public function register_gateway( $gateway ) {
		if ( ! $gateway instanceof WCH_Payment_Gateway ) {
			WCH_Logger::log(
				'Attempted to register invalid payment gateway: ' . get_class( $gateway ),
				'error'
			);
			return;
		}

		$gateway_id                    = $gateway->get_id();
		self::$gateways[ $gateway_id ] = $gateway;

		WCH_Logger::log( "Registered payment gateway: {$gateway_id}", 'debug' );
	}

	/**
	 * Get all registered gateways.
	 *
	 * @return array
	 */
	public function get_all_gateways() {
		return self::$gateways;
	}

	/**
	 * Get available gateways for a specific country.
	 *
	 * @param string $country Two-letter country code.
	 * @return array Available gateway instances.
	 */
	public function get_available_gateways( $country = '' ) {
		if ( empty( $country ) ) {
			$country = WC()->countries->get_base_country();
		}

		$available_gateways = array();
		$enabled_gateways   = get_option( 'wch_enabled_payment_methods', array( 'cod', 'stripe' ) );

		foreach ( self::$gateways as $gateway_id => $gateway ) {
			// Check if gateway is enabled in settings.
			if ( ! in_array( $gateway_id, $enabled_gateways, true ) ) {
				continue;
			}

			// Check if gateway is available for the country.
			if ( $gateway->is_available( $country ) ) {
				$available_gateways[ $gateway_id ] = $gateway;
			}
		}

		return $available_gateways;
	}

	/**
	 * Get a specific gateway by ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return WCH_Payment_Gateway|null Gateway instance or null if not found.
	 */
	public function get_gateway( $gateway_id ) {
		return self::$gateways[ $gateway_id ] ?? null;
	}

	/**
	 * Process payment for an order.
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $gateway_id  Gateway ID to use.
	 * @param array  $conversation Conversation context.
	 * @return array Payment processing result.
	 */
	public function process_order_payment( $order_id, $gateway_id, $conversation ) {
		$gateway = $this->get_gateway( $gateway_id );

		if ( ! $gateway ) {
			WCH_Logger::log( "Payment gateway not found: {$gateway_id}", 'error' );
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'invalid_gateway',
					'message' => __( 'Invalid payment gateway.', 'whatsapp-commerce-hub' ),
				),
			);
		}

		// Log payment attempt.
		WCH_Logger::log(
			sprintf(
				'Processing payment for order #%d using gateway: %s',
				$order_id,
				$gateway_id
			),
			'info'
		);

		// Process payment.
		$result = $gateway->process_payment( $order_id, $conversation );

		// Log result.
		if ( $result['success'] ?? false ) {
			WCH_Logger::log(
				sprintf(
					'Payment processed successfully for order #%d. Transaction ID: %s',
					$order_id,
					$result['transaction_id'] ?? 'N/A'
				),
				'info'
			);
		} else {
			WCH_Logger::log(
				sprintf(
					'Payment failed for order #%d. Error: %s',
					$order_id,
					$result['error']['message'] ?? 'Unknown error'
				),
				'error'
			);

			// Send friendly error message to customer.
			$this->handle_payment_failure( $order_id, $gateway_id, $result, $conversation );
		}

		return $result;
	}

	/**
	 * Handle payment failure.
	 *
	 * @param int    $order_id     Order ID.
	 * @param string $gateway_id   Gateway ID.
	 * @param array  $result       Payment result.
	 * @param array  $conversation Conversation context.
	 * @return void
	 */
	private function handle_payment_failure( $order_id, $gateway_id, $result, $conversation ) {
		$customer_phone = $conversation['customer_phone'] ?? '';
		if ( empty( $customer_phone ) ) {
			return;
		}

		$error_message = $result['error']['message'] ?? __( 'Payment processing failed.', 'whatsapp-commerce-hub' );

		// Prepare friendly error message.
		$message = sprintf(
			/* translators: %s: Error message */
			__( "Sorry, we couldn't process your payment. Error: %s\n\nWould you like to:\n1️⃣ Try again\n2️⃣ Use a different payment method", 'whatsapp-commerce-hub' ),
			$error_message
		);

		// Send message to customer.
		$whatsapp_client = new WCH_WhatsApp_API_Client();
		$whatsapp_client->send_message(
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $customer_phone,
				'type'              => 'text',
				'text'              => array(
					'body' => $message,
				),
			)
		);

		// Log the failure notification.
		WCH_Logger::log(
			sprintf(
				'Payment failure notification sent for order #%d to %s',
				$order_id,
				$customer_phone
			),
			'info'
		);
	}

	/**
	 * Handle webhook callback from a payment gateway.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $data       Webhook payload.
	 * @return array Webhook processing result.
	 */
	public function handle_webhook( $gateway_id, $data ) {
		$gateway = $this->get_gateway( $gateway_id );

		if ( ! $gateway ) {
			WCH_Logger::log( "Webhook received for unknown gateway: {$gateway_id}", 'error' );
			return array(
				'success' => false,
				'message' => __( 'Invalid payment gateway.', 'whatsapp-commerce-hub' ),
			);
		}

		WCH_Logger::log( "Processing webhook for gateway: {$gateway_id}", 'info' );

		$result = $gateway->handle_callback( $data );

		// Send notification if payment was completed.
		if ( ( $result['success'] ?? false ) && ( $result['status'] ?? '' ) === 'completed' ) {
			$order_id = $result['order_id'] ?? 0;
			if ( $order_id ) {
				$this->send_payment_confirmation( $order_id );
			}
		}

		return $result;
	}

	/**
	 * Send payment confirmation to customer.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function send_payment_confirmation( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$customer_phone = $order->get_meta( '_wch_customer_phone' );
		if ( empty( $customer_phone ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: Order number, 2: Order total */
			__( "✅ Payment confirmed!\n\nYour order #%1\$s for %2\$s has been successfully paid.\n\nYou will receive updates about your order shortly.", 'whatsapp-commerce-hub' ),
			$order->get_order_number(),
			wc_price( $order->get_total() )
		);

		// Send message to customer.
		$whatsapp_client = new WCH_WhatsApp_API_Client();
		$whatsapp_client->send_message(
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $customer_phone,
				'type'              => 'text',
				'text'              => array(
					'body' => $message,
				),
			)
		);

		WCH_Logger::log( "Payment confirmation sent for order #{$order_id}", 'info' );
	}

	/**
	 * Process refund through the original payment gateway.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Refund amount.
	 * @param string $reason  Refund reason.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function process_refund( $order_id, $amount, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'whatsapp-commerce-hub' ) );
		}

		$gateway_id     = $order->get_meta( '_wch_payment_method' );
		$transaction_id = $order->get_meta( '_wch_transaction_id' );

		if ( empty( $gateway_id ) ) {
			return new WP_Error( 'no_gateway', __( 'No payment gateway found for this order.', 'whatsapp-commerce-hub' ) );
		}

		// COD doesn't support refunds through the gateway.
		if ( $gateway_id === 'cod' ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Amount, 2: Reason */
					__( 'Manual refund required for COD order. Amount: %1$s. Reason: %2$s', 'whatsapp-commerce-hub' ),
					wc_price( $amount ),
					$reason
				)
			);
			return true;
		}

		// For other gateways, trigger the refund API.
		$gateway = $this->get_gateway( $gateway_id );
		if ( ! $gateway ) {
			return new WP_Error( 'gateway_not_found', __( 'Payment gateway not found.', 'whatsapp-commerce-hub' ) );
		}

		// Note: Refund implementation would require additional methods in the gateway interface.
		// For now, log the refund attempt.
		WCH_Logger::log(
			sprintf(
				'Refund requested for order #%d. Gateway: %s, Amount: %s, Transaction ID: %s',
				$order_id,
				$gateway_id,
				$amount,
				$transaction_id
			),
			'info'
		);

		$order->add_order_note(
			sprintf(
				/* translators: 1: Amount, 2: Reason */
				__( 'Refund of %1$s requested. Reason: %2$s. Process refund manually in payment gateway.', 'whatsapp-commerce-hub' ),
				wc_price( $amount ),
				$reason
			)
		);

		return true;
	}
}
