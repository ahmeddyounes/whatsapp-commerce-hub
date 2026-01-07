<?php
/**
 * PIX Payment Gateway (Brazil)
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Payment_PIX implements WCH_Payment_Gateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	const GATEWAY_ID = 'pix';

	/**
	 * Payment processor (pagseguro or mercadopago).
	 *
	 * @var string
	 */
	private $processor;

	/**
	 * API credentials.
	 *
	 * @var array
	 */
	private $api_key;
	private $api_token;

	/**
	 * WhatsApp API client.
	 *
	 * @var WCH_WhatsApp_API_Client
	 */
	private $whatsapp_client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->processor       = get_option( 'wch_pix_processor', 'mercadopago' );
		$this->api_key         = get_option( 'wch_pix_api_key', '' );
		$this->api_token       = get_option( 'wch_pix_api_token', '' );
		$this->whatsapp_client = new WCH_WhatsApp_API_Client();
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
		return __( 'PIX', 'whatsapp-commerce-hub' );
	}

	/**
	 * Check if PIX is available for the country.
	 *
	 * PIX is only available in Brazil.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public function is_available( $country ) {
		if ( empty( $this->api_token ) ) {
			return false;
		}

		return $country === 'BR';
	}

	/**
	 * Process PIX payment.
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

		if ( empty( $this->api_token ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'configuration_error',
					'message' => __( 'PIX is not configured properly.', 'whatsapp-commerce-hub' ),
				),
			);
		}

		try {
			// Generate PIX QR code.
			$pix_data = $this->generate_pix_code( $order, $conversation );

			if ( ! $pix_data || empty( $pix_data['qr_code'] ) ) {
				throw new Exception( __( 'Failed to generate PIX QR code.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$order->set_payment_method( self::GATEWAY_ID );
			$order->set_payment_method_title( $this->get_title() );
			$order->update_status( 'pending', __( 'Awaiting PIX payment.', 'whatsapp-commerce-hub' ) );

			// Store transaction metadata.
			$order->update_meta_data( '_wch_transaction_id', $pix_data['transaction_id'] );
			$order->update_meta_data( '_wch_payment_method', self::GATEWAY_ID );
			$order->update_meta_data( '_pix_qr_code', $pix_data['qr_code'] );
			$order->update_meta_data( '_pix_qr_code_text', $pix_data['qr_code_text'] ?? '' );
			$order->save();

			// Send QR code image to customer.
			$this->send_pix_qr_code( $conversation['customer_phone'] ?? '', $pix_data );

			return array(
				'success'        => true,
				'transaction_id' => $pix_data['transaction_id'],
				'payment_url'    => '',
				'message'        => __( 'PIX QR code has been sent! Please scan it with your banking app to complete the payment. The code is valid for 30 minutes.', 'whatsapp-commerce-hub' ),
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'PIX payment error: ' . $e->getMessage(), 'error' );

			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'pix_error',
					'message' => $e->getMessage(),
				),
			);
		}
	}

	/**
	 * Generate PIX QR code via payment processor.
	 *
	 * @param WC_Order $order        Order object.
	 * @param array    $conversation Conversation context.
	 * @return array|null PIX data or null on failure.
	 */
	private function generate_pix_code( $order, $conversation ) {
		if ( $this->processor === 'mercadopago' ) {
			return $this->generate_mercadopago_pix( $order, $conversation );
		} elseif ( $this->processor === 'pagseguro' ) {
			return $this->generate_pagseguro_pix( $order, $conversation );
		}

		return null;
	}

	/**
	 * Generate PIX via Mercado Pago.
	 *
	 * @param WC_Order $order        Order object.
	 * @param array    $conversation Conversation context.
	 * @return array|null
	 */
	private function generate_mercadopago_pix( $order, $conversation ) {
		$url  = 'https://api.mercadopago.com/v1/payments';
		$data = array(
			'transaction_amount' => floatval( $order->get_total() ),
			'description'        => sprintf(
				/* translators: %s: Order number */
				__( 'Order #%s', 'whatsapp-commerce-hub' ),
				$order->get_order_number()
			),
			'payment_method_id'  => 'pix',
			'payer'              => array(
				'email'      => $order->get_billing_email() ?: 'noreply@example.com',
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
			),
			'notification_url'   => add_query_arg( 'gateway', 'pix', rest_url( 'wch/v1/payment-webhook' ) ),
			'metadata'           => array(
				'order_id'        => $order->get_id(),
				'customer_phone'  => $conversation['customer_phone'] ?? '',
				'conversation_id' => $conversation['id'] ?? '',
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			WCH_Logger::log( 'Mercado Pago PIX error: ' . $response->get_error_message(), 'error' );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['point_of_interaction']['transaction_data'] ) ) {
			$transaction_data = $data['point_of_interaction']['transaction_data'];
			return array(
				'transaction_id' => $data['id'],
				'qr_code'        => $transaction_data['qr_code_base64'] ?? '',
				'qr_code_text'   => $transaction_data['qr_code'] ?? '',
			);
		}

		return null;
	}

	/**
	 * Generate PIX via PagSeguro.
	 *
	 * @param WC_Order $order        Order object.
	 * @param array    $conversation Conversation context.
	 * @return array|null
	 */
	private function generate_pagseguro_pix( $order, $conversation ) {
		$url  = 'https://api.pagseguro.com/orders';
		$data = array(
			'reference_id' => $order->get_order_number(),
			'customer'     => array(
				'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email' => $order->get_billing_email() ?: 'noreply@example.com',
			),
			'items'        => array(),
			'qr_codes'     => array(
				array(
					'amount' => array(
						'value' => intval( $order->get_total() * 100 ),
					),
				),
			),
			'notification_urls' => array(
				add_query_arg( 'gateway', 'pix', rest_url( 'wch/v1/payment-webhook' ) ),
			),
		);

		// Add items.
		foreach ( $order->get_items() as $item ) {
			$data['items'][] = array(
				'reference_id' => $item->get_product_id(),
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'unit_amount'  => intval( $item->get_total() * 100 / $item->get_quantity() ),
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			WCH_Logger::log( 'PagSeguro PIX error: ' . $response->get_error_message(), 'error' );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['qr_codes'][0] ) ) {
			$qr_code = $data['qr_codes'][0];
			return array(
				'transaction_id' => $data['id'],
				'qr_code'        => $qr_code['links'][0]['href'] ?? '',
				'qr_code_text'   => $qr_code['text'] ?? '',
			);
		}

		return null;
	}

	/**
	 * Send PIX QR code to customer via WhatsApp.
	 *
	 * @param string $phone    Customer phone number.
	 * @param array  $pix_data PIX data.
	 * @return void
	 */
	private function send_pix_qr_code( $phone, $pix_data ) {
		if ( empty( $phone ) ) {
			return;
		}

		// Send QR code as image if base64 is available.
		if ( ! empty( $pix_data['qr_code'] ) && strpos( $pix_data['qr_code'], 'base64' ) !== false ) {
			// Save QR code temporarily.
			$upload_dir = wp_upload_dir();
			$qr_file    = $upload_dir['path'] . '/pix_qr_' . time() . '.png';

			// Extract base64 data.
			$qr_data = preg_replace( '/^data:image\/\w+;base64,/', '', $pix_data['qr_code'] );
			file_put_contents( $qr_file, base64_decode( $qr_data ) );

			// Upload to WhatsApp.
			$media = $this->whatsapp_client->upload_media( $qr_file, 'image/png' );

			if ( $media && isset( $media['id'] ) ) {
				$message = array(
					'messaging_product' => 'whatsapp',
					'to'                => $phone,
					'type'              => 'image',
					'image'             => array(
						'id'      => $media['id'],
						'caption' => __( 'Scan this PIX QR code with your banking app to complete the payment.', 'whatsapp-commerce-hub' ),
					),
				);

				$this->whatsapp_client->send_message( $message );
			}

			// Clean up temporary file.
			@unlink( $qr_file );
		}

		// Also send the PIX code as text for copy-paste.
		if ( ! empty( $pix_data['qr_code_text'] ) ) {
			$message = array(
				'messaging_product' => 'whatsapp',
				'to'                => $phone,
				'type'              => 'text',
				'text'              => array(
					'body' => sprintf(
						/* translators: %s: PIX code */
						__( "Or copy this PIX code to pay:\n\n```%s```", 'whatsapp-commerce-hub' ),
						$pix_data['qr_code_text']
					),
				),
			);

			$this->whatsapp_client->send_message( $message );
		}
	}

	/**
	 * Handle PIX webhook callback.
	 *
	 * @param array $data Webhook payload.
	 * @return array
	 */
	public function handle_callback( $data ) {
		if ( $this->processor === 'mercadopago' ) {
			return $this->handle_mercadopago_callback( $data );
		} elseif ( $this->processor === 'pagseguro' ) {
			return $this->handle_pagseguro_callback( $data );
		}

		return array(
			'success' => false,
			'message' => __( 'Unknown processor.', 'whatsapp-commerce-hub' ),
		);
	}

	/**
	 * Handle Mercado Pago callback.
	 *
	 * @param array $data Webhook data.
	 * @return array
	 */
	private function handle_mercadopago_callback( $data ) {
		$type = $data['type'] ?? '';

		if ( $type === 'payment' ) {
			$payment_id = $data['data']['id'] ?? '';

			// Fetch payment details.
			$payment = $this->get_mercadopago_payment( $payment_id );

			if ( $payment ) {
				$metadata = $payment['metadata'] ?? array();
				$order_id = intval( $metadata['order_id'] ?? 0 );

				if ( $order_id ) {
					$order  = wc_get_order( $order_id );
					$status = $payment['status'] ?? '';

					if ( $order && $status === 'approved' ) {
						$order->payment_complete( $payment_id );
						$order->add_order_note(
							sprintf(
								/* translators: %s: Transaction ID */
								__( 'PIX payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
								$payment_id
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
			}
		}

		return array(
			'success' => false,
			'message' => __( 'Unhandled webhook event.', 'whatsapp-commerce-hub' ),
		);
	}

	/**
	 * Get Mercado Pago payment details.
	 *
	 * @param string $payment_id Payment ID.
	 * @return array|null
	 */
	private function get_mercadopago_payment( $payment_id ) {
		$url = 'https://api.mercadopago.com/v1/payments/' . $payment_id;

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	/**
	 * Handle PagSeguro callback.
	 *
	 * @param array $data Webhook data.
	 * @return array
	 */
	private function handle_pagseguro_callback( $data ) {
		$reference_id = $data['reference_id'] ?? '';
		$status       = $data['charges'][0]['status'] ?? '';

		if ( $status === 'PAID' ) {
			$order = wc_get_order( $reference_id );

			if ( $order ) {
				$order->payment_complete( $data['id'] ?? '' );
				$order->add_order_note(
					sprintf(
						/* translators: %s: Transaction ID */
						__( 'PIX payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
						$data['id'] ?? ''
					)
				);

				return array(
					'success'  => true,
					'order_id' => $order->get_id(),
					'status'   => 'completed',
					'message'  => __( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
				);
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
	 * @param string $transaction_id Transaction ID.
	 * @return array
	 */
	public function get_payment_status( $transaction_id ) {
		if ( $this->processor === 'mercadopago' ) {
			$payment = $this->get_mercadopago_payment( $transaction_id );

			if ( $payment ) {
				$status_map = array(
					'approved' => 'completed',
					'pending'  => 'pending',
					'rejected' => 'failed',
					'cancelled' => 'failed',
				);

				return array(
					'status'         => $status_map[ $payment['status'] ?? 'pending' ] ?? 'pending',
					'transaction_id' => $transaction_id,
					'amount'         => $payment['transaction_amount'] ?? 0,
					'currency'       => $payment['currency_id'] ?? 'BRL',
					'metadata'       => $payment['metadata'] ?? array(),
				);
			}
		}

		return array(
			'status'         => 'unknown',
			'transaction_id' => $transaction_id,
			'amount'         => 0,
			'currency'       => 'BRL',
			'metadata'       => array(),
		);
	}
}
