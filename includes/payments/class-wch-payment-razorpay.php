<?php
/**
 * Razorpay Payment Gateway
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Payment_Razorpay implements WCH_Payment_Gateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	const GATEWAY_ID = 'razorpay';

	/**
	 * Razorpay API credentials.
	 *
	 * @var array
	 */
	private $api_key;
	private $api_secret;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_key    = get_option( 'wch_razorpay_key_id', '' );
		$this->api_secret = get_option( 'wch_razorpay_key_secret', '' );
	}

	/**
	 * Get the gateway ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return self::GATEWAY_ID;
	}

	/**
	 * Get the gateway title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Razorpay', 'whatsapp-commerce-hub' );
	}

	/**
	 * Check if Razorpay is available for the country.
	 *
	 * Razorpay primarily serves India.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public function is_available( $country ) {
		if ( empty( $this->api_key ) || empty( $this->api_secret ) ) {
			return false;
		}

		// Razorpay is primarily available in India and Malaysia.
		$supported_countries = array( 'IN', 'MY' );
		return in_array( $country, $supported_countries, true );
	}

	/**
	 * Process Razorpay payment.
	 *
	 * @param int   $order_id     Order ID.
	 * @param array $conversation Conversation context.
	 * @return array Payment result.
	 */
	public function process_payment( $order_id, $conversation ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'invalid_order',
					'message' => __( 'Invalid order ID.', 'whatsapp-commerce-hub' ),
				),
			);
		}

		if ( empty( $this->api_key ) || empty( $this->api_secret ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'configuration_error',
					'message' => __( 'Razorpay is not configured properly.', 'whatsapp-commerce-hub' ),
				),
			);
		}

		try {
			// Create Razorpay Payment Link.
			$payment_link = $this->create_payment_link( $order, $conversation );

			if ( ! $payment_link || empty( $payment_link['short_url'] ) ) {
				throw new Exception( __( 'Failed to create Razorpay payment link.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$order->set_payment_method( self::GATEWAY_ID );
			$order->set_payment_method_title( $this->get_title() );
			$order->update_status( 'pending', __( 'Awaiting Razorpay payment.', 'whatsapp-commerce-hub' ) );

			// Store transaction metadata.
			$order->update_meta_data( '_wch_transaction_id', $payment_link['id'] );
			$order->update_meta_data( '_wch_payment_method', self::GATEWAY_ID );
			$order->update_meta_data( '_razorpay_payment_link_id', $payment_link['id'] );
			$order->save();

			return array(
				'success'        => true,
				'transaction_id' => $payment_link['id'],
				'payment_url'    => $payment_link['short_url'],
				'message'        => sprintf(
					/* translators: %s: Payment URL */
					__( 'Please complete your payment by clicking this link: %s. You can pay using UPI, Cards, Net Banking, or Wallets.', 'whatsapp-commerce-hub' ),
					$payment_link['short_url']
				),
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Razorpay payment error: ' . $e->getMessage(), 'error' );

			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'razorpay_error',
					'message' => $e->getMessage(),
				),
			);
		}
	}

	/**
	 * Create Razorpay Payment Link.
	 *
	 * @param WC_Order $order        Order object.
	 * @param array    $conversation Conversation context.
	 * @return array|null Payment link data or null on failure.
	 */
	private function create_payment_link( $order, $conversation ) {
		$amount = intval( $order->get_total() * 100 ); // Convert to paise.

		$data = array(
			'amount'      => $amount,
			'currency'    => $order->get_currency(),
			'description' => sprintf(
				/* translators: %s: Order number */
				__( 'Order #%s', 'whatsapp-commerce-hub' ),
				$order->get_order_number()
			),
			'customer'    => array(
				'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email'   => $order->get_billing_email() ?: 'noreply@example.com',
				'contact' => preg_replace( '/[^0-9]/', '', $conversation['customer_phone'] ?? '' ),
			),
			'notify'      => array(
				'sms'   => false,
				'email' => false,
			),
			'callback_url'    => add_query_arg( 'wch_payment', 'success', home_url() ),
			'callback_method' => 'get',
			'notes'           => array(
				'order_id'        => $order->get_id(),
				'customer_phone'  => $conversation['customer_phone'] ?? '',
				'conversation_id' => $conversation['id'] ?? '',
			),
		);

		$response = $this->razorpay_api_request( 'payment_links', $data, 'POST' );

		return $response;
	}

	/**
	 * Make Razorpay API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $data     Request data.
	 * @param string $method   HTTP method.
	 * @return array|null Response data or null on failure.
	 */
	private function razorpay_api_request( $endpoint, $data = array(), $method = 'GET' ) {
		$url = 'https://api.razorpay.com/v1/' . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' . $this->api_secret ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $method === 'POST' ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			WCH_Logger::log( 'Razorpay API error: ' . $response->get_error_message(), 'error' );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['error'] ) ) {
			WCH_Logger::log( 'Razorpay API error: ' . $data['error']['description'], 'error' );
			return null;
		}

		return $data;
	}

	/**
	 * Handle Razorpay webhook callback.
	 *
	 * @param array $data Webhook payload.
	 * @return array
	 */
	public function handle_callback( $data ) {
		$event = $data['event'] ?? '';

		if ( $event === 'payment.captured' ) {
			$payment = $data['payload']['payment']['entity'] ?? array();
			$notes   = $payment['notes'] ?? array();
			$order_id = intval( $notes['order_id'] ?? 0 );

			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->payment_complete( $payment['id'] );
					$order->add_order_note(
						sprintf(
							/* translators: %s: Transaction ID */
							__( 'Razorpay payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
							$payment['id']
						)
					);

					return array(
						'success'  => true,
						'order_id' => $order_id,
						'status'   => 'completed',
						'message'  => __( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
					);
				}
			}
		} elseif ( $event === 'payment.failed' ) {
			$payment  = $data['payload']['payment']['entity'] ?? array();
			$notes    = $payment['notes'] ?? array();
			$order_id = intval( $notes['order_id'] ?? 0 );

			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->update_status(
						'failed',
						sprintf(
							/* translators: %s: Error message */
							__( 'Razorpay payment failed: %s', 'whatsapp-commerce-hub' ),
							$payment['error_description'] ?? __( 'Unknown error', 'whatsapp-commerce-hub' )
						)
					);

					return array(
						'success'  => true,
						'order_id' => $order_id,
						'status'   => 'failed',
						'message'  => __( 'Payment failed.', 'whatsapp-commerce-hub' ),
					);
				}
			}
		}

		return array(
			'success' => false,
			'message' => __( 'Unhandled webhook event.', 'whatsapp-commerce-hub' ),
		);
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transaction_id Transaction ID (payment link ID).
	 * @return array
	 */
	public function get_payment_status( $transaction_id ) {
		$response = $this->razorpay_api_request( 'payment_links/' . $transaction_id );

		if ( ! $response ) {
			return array(
				'status'         => 'unknown',
				'transaction_id' => $transaction_id,
				'amount'         => 0,
				'currency'       => '',
				'metadata'       => array(),
			);
		}

		$payment_status = $response['status'] ?? 'created';
		$status_map     = array(
			'paid'      => 'completed',
			'created'   => 'pending',
			'partially_paid' => 'pending',
			'expired'   => 'failed',
			'cancelled' => 'failed',
		);

		return array(
			'status'         => $status_map[ $payment_status ] ?? 'pending',
			'transaction_id' => $transaction_id,
			'amount'         => ( $response['amount'] ?? 0 ) / 100,
			'currency'       => $response['currency'] ?? '',
			'metadata'       => $response['notes'] ?? array(),
		);
	}
}
