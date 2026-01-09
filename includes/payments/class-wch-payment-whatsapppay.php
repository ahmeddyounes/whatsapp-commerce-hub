<?php
/**
 * WhatsApp Pay Payment Gateway
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Payment_WhatsAppPay implements WCH_Payment_Gateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	const GATEWAY_ID = 'whatsapppay';

	/**
	 * WhatsApp API client.
	 *
	 * @var WCH_WhatsApp_API_Client
	 */
	private $api_client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_client = new WCH_WhatsApp_API_Client();
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
		return __( 'WhatsApp Pay', 'whatsapp-commerce-hub' );
	}

	/**
	 * Check if WhatsApp Pay is available for the country.
	 *
	 * WhatsApp Pay is available in select countries.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public function is_available( $country ) {
		// WhatsApp Pay is currently available in Brazil and India.
		$supported_countries = array( 'BR', 'IN', 'SG' );
		return in_array( $country, $supported_countries, true );
	}

	/**
	 * Process WhatsApp Pay payment.
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

		try {
			// Create WhatsApp payment request.
			$payment_request = $this->create_payment_request( $order, $conversation );

			if ( ! $payment_request ) {
				throw new Exception( __( 'Failed to create WhatsApp payment request.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$order->set_payment_method( self::GATEWAY_ID );
			$order->set_payment_method_title( $this->get_title() );
			$order->update_status( 'pending', __( 'Awaiting WhatsApp Pay payment.', 'whatsapp-commerce-hub' ) );

			// Store transaction metadata.
			$transaction_id = 'wp_' . $order_id . '_' . time();
			$order->update_meta_data( '_wch_transaction_id', $transaction_id );
			$order->update_meta_data( '_wch_payment_method', self::GATEWAY_ID );
			$order->update_meta_data( '_whatsapp_pay_reference', $payment_request['reference_id'] ?? $transaction_id );
			$order->save();

			return array(
				'success'        => true,
				'transaction_id' => $transaction_id,
				'payment_url'    => '',
				'message'        => __( 'Please approve the payment request in WhatsApp to complete your order.', 'whatsapp-commerce-hub' ),
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'WhatsApp Pay payment error: ' . $e->getMessage(), 'error' );

			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'whatsapppay_error',
					'message' => $e->getMessage(),
				),
			);
		}
	}

	/**
	 * Create WhatsApp payment request.
	 *
	 * @param WC_Order $order        Order object.
	 * @param array    $conversation Conversation context.
	 * @return array|null Payment request data or null on failure.
	 */
	private function create_payment_request( $order, $conversation ) {
		$customer_phone = $conversation['customer_phone'] ?? '';
		if ( empty( $customer_phone ) ) {
			return null;
		}

		// Prepare order items for payment request.
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'amount'   => array(
					'value'  => $item->get_total(),
					'offset' => 100,
				),
				'quantity' => $item->get_quantity(),
			);
		}

		// Build payment configuration.
		$payment_config = array(
			'type'             => 'payment',
			'payment_settings' => array(
				array(
					'type'            => 'payment_gateway',
					'payment_gateway' => array(
						'type'               => 'razorpay', // WhatsApp Pay uses underlying payment processors.
						'configuration_name' => get_option( 'wch_whatsapppay_config_name', 'default' ),
					),
				),
			),
		);

		// Create order object for WhatsApp.
		$order_data = array(
			'reference_id' => 'wp_' . $order->get_id() . '_' . time(),
			'type'         => 'digital-goods',
			'payment'      => $payment_config,
			'total_amount' => array(
				'value'  => intval( $order->get_total() * 100 ),
				'offset' => 100,
			),
			'order'        => array(
				'items' => $items,
			),
		);

		// Send interactive payment message.
		$message = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $customer_phone,
			'type'              => 'interactive',
			'interactive'       => array(
				'type'   => 'order_details',
				'body'   => array(
					'text' => sprintf(
						/* translators: %s: Order number */
						__( 'Please review and confirm your payment for order #%s', 'whatsapp-commerce-hub' ),
						$order->get_order_number()
					),
				),
				'action' => array(
					'name'       => 'review_and_pay',
					'parameters' => $order_data,
				),
			),
		);

		$response = $this->api_client->send_message( $message );

		if ( $response && isset( $response['messages'][0]['id'] ) ) {
			return array(
				'success'      => true,
				'reference_id' => $order_data['reference_id'],
				'message_id'   => $response['messages'][0]['id'],
			);
		}

		return null;
	}

	/**
	 * Handle WhatsApp Pay webhook callback.
	 *
	 * @param array $data Webhook payload.
	 * @return array
	 */
	public function handle_callback( $data ) {
		// Extract payment status from webhook.
		$entry    = $data['entry'][0] ?? array();
		$changes  = $entry['changes'][0] ?? array();
		$value    = $changes['value'] ?? array();
		$messages = $value['messages'] ?? array();

		foreach ( $messages as $message ) {
			if ( isset( $message['type'] ) && $message['type'] === 'order' ) {
				$order_info   = $message['order'] ?? array();
				$reference_id = $order_info['reference_id'] ?? '';

				// Extract order ID from reference.
				if ( preg_match( '/^wp_(\d+)_/', $reference_id, $matches ) ) {
					$order_id = intval( $matches[1] );
					$order    = wc_get_order( $order_id );

					if ( $order ) {
						$payment_status = $order_info['payment_status'] ?? '';

						if ( $payment_status === 'captured' || $payment_status === 'completed' ) {
							// SECURITY: Check if order still needs payment to prevent double-spend.
							if ( ! $order->needs_payment() ) {
								WCH_Logger::info(
									'WhatsApp Pay payment webhook skipped - order already paid',
									array(
										'order_id'       => $order_id,
										'order_status'   => $order->get_status(),
										'transaction_id' => $reference_id,
									)
								);
								return array(
									'success'  => true,
									'order_id' => $order_id,
									'status'   => 'already_completed',
									'message'  => __( 'Order already paid.', 'whatsapp-commerce-hub' ),
								);
							}

							$order->payment_complete( $reference_id );
							$order->add_order_note(
								sprintf(
									/* translators: %s: Transaction ID */
									__( 'WhatsApp Pay payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
									$reference_id
								)
							);

							return array(
								'success'  => true,
								'order_id' => $order_id,
								'status'   => 'completed',
								'message'  => __( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
							);
						} elseif ( $payment_status === 'failed' ) {
							// Only fail orders that are still pending payment.
							if ( $order->needs_payment() ) {
								$order->update_status( 'failed', __( 'WhatsApp Pay payment failed.', 'whatsapp-commerce-hub' ) );
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
			}
		}

		return array(
			'success' => false,
			'message' => __( 'No payment information found in webhook.', 'whatsapp-commerce-hub' ),
		);
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transaction_id Transaction ID.
	 * @return array
	 */
	public function get_payment_status( $transaction_id ) {
		// Extract order ID from transaction ID.
		if ( preg_match( '/^wp_(\d+)_/', $transaction_id, $matches ) ) {
			$order_id = intval( $matches[1] );
			$order    = wc_get_order( $order_id );

			if ( $order ) {
				$status = $order->get_status();
				return array(
					'status'         => $status === 'completed' || $status === 'processing' ? 'completed' : 'pending',
					'transaction_id' => $transaction_id,
					'amount'         => $order->get_total(),
					'currency'       => $order->get_currency(),
					'metadata'       => array(
						'order_id' => $order_id,
						'method'   => 'whatsapppay',
					),
				);
			}
		}

		return array(
			'status'         => 'unknown',
			'transaction_id' => $transaction_id,
			'amount'         => 0,
			'currency'       => '',
			'metadata'       => array(),
		);
	}
}
