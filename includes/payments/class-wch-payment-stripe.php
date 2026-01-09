<?php
/**
 * Stripe Payment Gateway
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Payment_Stripe implements WCH_Payment_Gateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	const GATEWAY_ID = 'stripe';

	/**
	 * Stripe API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_key = get_option( 'wch_stripe_secret_key', '' );
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
		return __( 'Stripe', 'whatsapp-commerce-hub' );
	}

	/**
	 * Check if Stripe is available for the country.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public function is_available( $country ) {
		// Stripe is available in most countries.
		// Check if API key is configured.
		if ( empty( $this->api_key ) ) {
			return false;
		}

		$supported_countries = array(
			'US',
			'CA',
			'GB',
			'AU',
			'NZ',
			'IE',
			'AT',
			'BE',
			'BG',
			'HR',
			'CY',
			'CZ',
			'DK',
			'EE',
			'FI',
			'FR',
			'DE',
			'GR',
			'HU',
			'IT',
			'LV',
			'LT',
			'LU',
			'MT',
			'NL',
			'PL',
			'PT',
			'RO',
			'SK',
			'SI',
			'ES',
			'SE',
			'CH',
			'NO',
			'JP',
			'SG',
			'HK',
			'AE',
			'IN',
			'BR',
			'MX',
			'MY',
			'TH',
			'ID',
			'PH',
		);

		return in_array( $country, $supported_countries, true );
	}

	/**
	 * Process Stripe payment.
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

		if ( empty( $this->api_key ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'configuration_error',
					'message' => __( 'Stripe is not configured properly.', 'whatsapp-commerce-hub' ),
				),
			);
		}

		try {
			// Create Stripe Checkout Session.
			$session = $this->create_checkout_session( $order, $conversation );

			if ( ! $session || empty( $session['url'] ) ) {
				throw new Exception( __( 'Failed to create Stripe checkout session.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$order->set_payment_method( self::GATEWAY_ID );
			$order->set_payment_method_title( $this->get_title() );
			$order->update_status( 'pending', __( 'Awaiting Stripe payment.', 'whatsapp-commerce-hub' ) );

			// Store transaction metadata.
			$order->update_meta_data( '_wch_transaction_id', $session['id'] );
			$order->update_meta_data( '_wch_payment_method', self::GATEWAY_ID );
			$order->update_meta_data( '_stripe_session_id', $session['id'] );
			$order->update_meta_data( '_stripe_payment_intent', $session['payment_intent'] ?? '' );
			$order->save();

			return array(
				'success'        => true,
				'transaction_id' => $session['id'],
				'payment_url'    => $session['url'],
				'message'        => sprintf(
					/* translators: %s: Payment URL */
					__( 'Please complete your payment by clicking this link: %s', 'whatsapp-commerce-hub' ),
					$session['url']
				),
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Stripe payment error: ' . $e->getMessage(), 'error' );

			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'stripe_error',
					'message' => $e->getMessage(),
				),
			);
		}
	}

	/**
	 * Create Stripe Checkout Session.
	 *
	 * @param WC_Order $order        Order object.
	 * @param array    $conversation Conversation context.
	 * @return array|null Session data or null on failure.
	 */
	private function create_checkout_session( $order, $conversation ) {
		$line_items = array();

		// Add order items.
		foreach ( $order->get_items() as $item ) {
			$line_items[] = array(
				'price_data' => array(
					'currency'     => strtolower( $order->get_currency() ),
					'product_data' => array(
						'name' => $item->get_name(),
					),
					'unit_amount'  => intval( $item->get_total() * 100 / $item->get_quantity() ),
				),
				'quantity'   => $item->get_quantity(),
			);
		}

		// Add shipping if applicable.
		if ( $order->get_shipping_total() > 0 ) {
			$line_items[] = array(
				'price_data' => array(
					'currency'     => strtolower( $order->get_currency() ),
					'product_data' => array(
						'name' => __( 'Shipping', 'whatsapp-commerce-hub' ),
					),
					'unit_amount'  => intval( $order->get_shipping_total() * 100 ),
				),
				'quantity'   => 1,
			);
		}

		// Add tax if applicable.
		if ( $order->get_total_tax() > 0 ) {
			$line_items[] = array(
				'price_data' => array(
					'currency'     => strtolower( $order->get_currency() ),
					'product_data' => array(
						'name' => __( 'Tax', 'whatsapp-commerce-hub' ),
					),
					'unit_amount'  => intval( $order->get_total_tax() * 100 ),
				),
				'quantity'   => 1,
			);
		}

		$session_data = array(
			'payment_method_types' => array( 'card' ),
			'line_items'           => $line_items,
			'mode'                 => 'payment',
			'success_url'          => add_query_arg( 'wch_payment', 'success', home_url() ),
			'cancel_url'           => add_query_arg( 'wch_payment', 'cancelled', home_url() ),
			'client_reference_id'  => $order->get_id(),
			'customer_email'       => $order->get_billing_email(),
			'metadata'             => array(
				'order_id'        => $order->get_id(),
				'customer_phone'  => $conversation['customer_phone'] ?? '',
				'conversation_id' => $conversation['id'] ?? '',
			),
		);

		$response = $this->stripe_api_request( 'checkout/sessions', $session_data );

		return $response;
	}

	/**
	 * Make Stripe API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $data     Request data.
	 * @return array|null Response data or null on failure.
	 */
	private function stripe_api_request( $endpoint, $data ) {
		$url = 'https://api.stripe.com/v1/' . $endpoint;

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $data ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			WCH_Logger::log( 'Stripe API error: ' . $response->get_error_message(), 'error' );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['error'] ) ) {
			WCH_Logger::log( 'Stripe API error: ' . $data['error']['message'], 'error' );
			return null;
		}

		return $data;
	}

	/**
	 * Handle Stripe webhook callback.
	 *
	 * @param array $data Webhook payload.
	 * @return array
	 */
	public function handle_callback( $data ) {
		$event_type = $data['type'] ?? '';

		if ( $event_type === 'payment_intent.succeeded' ) {
			$payment_intent = $data['data']['object'] ?? array();
			$metadata       = $payment_intent['metadata'] ?? array();
			$order_id       = intval( $metadata['order_id'] ?? 0 );

			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					// SECURITY: Check if order still needs payment to prevent double-spend.
					// This prevents race conditions where concurrent webhooks could both complete the payment.
					if ( ! $order->needs_payment() ) {
						WCH_Logger::info(
							'Stripe payment webhook skipped - order already paid',
							array(
								'order_id'       => $order_id,
								'order_status'   => $order->get_status(),
								'transaction_id' => $payment_intent['id'] ?? 'unknown',
							)
						);
						return array(
							'success'  => true,
							'order_id' => $order_id,
							'status'   => 'already_completed',
							'message'  => __( 'Order already paid.', 'whatsapp-commerce-hub' ),
						);
					}

					// SECURITY: Validate payment currency matches order currency to prevent fraud.
					// An attacker could pay 100 INR instead of 100 USD if currency isn't validated.
					$paid_currency     = strtoupper( $payment_intent['currency'] ?? '' );
					$expected_currency = strtoupper( $order->get_currency() );

					if ( $paid_currency !== $expected_currency ) {
						WCH_Logger::error(
							'Stripe payment currency mismatch - possible fraud attempt',
							array(
								'order_id'          => $order_id,
								'paid_currency'     => $paid_currency,
								'expected_currency' => $expected_currency,
								'transaction_id'    => $payment_intent['id'] ?? 'unknown',
							)
						);
						$order->update_status(
							'on-hold',
							sprintf(
								/* translators: %1$s: Paid currency, %2$s: Expected currency */
								__( 'Payment currency mismatch. Paid in: %1$s, Expected: %2$s. Manual review required.', 'whatsapp-commerce-hub' ),
								$paid_currency,
								$expected_currency
							)
						);
						return array(
							'success'  => false,
							'order_id' => $order_id,
							'status'   => 'currency_mismatch',
							'message'  => __( 'Payment currency does not match order currency.', 'whatsapp-commerce-hub' ),
						);
					}

					// SECURITY: Validate payment amount matches order total to prevent underpayment attacks.
					// Stripe amounts are in cents (smallest currency unit).
					$paid_amount     = intval( $payment_intent['amount_received'] ?? $payment_intent['amount'] ?? 0 );
					$expected_amount = intval( round( floatval( $order->get_total() ) * 100 ) );

					// Allow 1 cent tolerance for rounding differences.
					if ( abs( $paid_amount - $expected_amount ) > 1 ) {
						WCH_Logger::error(
							'Stripe payment amount mismatch - possible fraud attempt',
							array(
								'order_id'        => $order_id,
								'paid_amount'     => $paid_amount,
								'expected_amount' => $expected_amount,
								'currency'        => $payment_intent['currency'] ?? 'unknown',
								'transaction_id'  => $payment_intent['id'] ?? 'unknown',
							)
						);
						$order->update_status(
							'on-hold',
							sprintf(
								/* translators: %1$s: Paid amount, %2$s: Expected amount */
								__( 'Payment amount mismatch. Paid: %1$s, Expected: %2$s. Manual review required.', 'whatsapp-commerce-hub' ),
								$paid_amount / 100,
								$expected_amount / 100
							)
						);
						return array(
							'success'  => false,
							'order_id' => $order_id,
							'status'   => 'amount_mismatch',
							'message'  => __( 'Payment amount does not match order total.', 'whatsapp-commerce-hub' ),
						);
					}

					$order->payment_complete( $payment_intent['id'] );
					$order->add_order_note(
						sprintf(
							/* translators: %s: Transaction ID */
							__( 'Stripe payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
							$payment_intent['id']
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
		} elseif ( $event_type === 'payment_intent.payment_failed' ) {
			$payment_intent = $data['data']['object'] ?? array();
			$metadata       = $payment_intent['metadata'] ?? array();
			$order_id       = intval( $metadata['order_id'] ?? 0 );

			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					// Only fail orders that are still pending payment.
					if ( $order->needs_payment() ) {
						$order->update_status(
							'failed',
							sprintf(
								/* translators: %s: Error message */
								__( 'Stripe payment failed: %s', 'whatsapp-commerce-hub' ),
								$payment_intent['last_payment_error']['message'] ?? __( 'Unknown error', 'whatsapp-commerce-hub' )
							)
						);
					}

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
	 * @param string $transaction_id Transaction ID (session ID).
	 * @return array
	 */
	public function get_payment_status( $transaction_id ) {
		$response = $this->stripe_api_request( 'checkout/sessions/' . $transaction_id, array() );

		if ( ! $response ) {
			return array(
				'status'         => 'unknown',
				'transaction_id' => $transaction_id,
				'amount'         => 0,
				'currency'       => '',
				'metadata'       => array(),
			);
		}

		$payment_status = $response['payment_status'] ?? 'unpaid';
		$status_map     = array(
			'paid'   => 'completed',
			'unpaid' => 'pending',
		);

		return array(
			'status'         => $status_map[ $payment_status ] ?? 'pending',
			'transaction_id' => $transaction_id,
			'amount'         => ( $response['amount_total'] ?? 0 ) / 100,
			'currency'       => strtoupper( $response['currency'] ?? '' ),
			'metadata'       => $response['metadata'] ?? array(),
		);
	}
}
