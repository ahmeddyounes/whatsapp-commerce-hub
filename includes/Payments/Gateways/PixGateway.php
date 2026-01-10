<?php
/**
 * PIX Payment Gateway (Brazil)
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Payments\Gateways;

use WhatsAppCommerceHub\Payments\Contracts\PaymentResult;
use WhatsAppCommerceHub\Payments\Contracts\PaymentStatus;
use WhatsAppCommerceHub\Payments\Contracts\WebhookResult;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PixGateway
 *
 * PIX instant payment gateway for Brazil.
 */
class PixGateway extends AbstractGateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected string $id = 'pix';

	/**
	 * Gateway title.
	 *
	 * @var string
	 */
	protected string $title = '';

	/**
	 * Gateway description.
	 *
	 * @var string
	 */
	protected string $description = '';

	/**
	 * Supported countries.
	 *
	 * @var string[]
	 */
	protected array $supportedCountries = array( 'BR' );

	/**
	 * Payment processor (mercadopago or pagseguro).
	 *
	 * @var string
	 */
	private string $processor;

	/**
	 * API token.
	 *
	 * @var string
	 */
	private string $apiToken;

	/**
	 * WhatsApp API client.
	 *
	 * @var \WCH_WhatsApp_API_Client
	 */
	private \WCH_WhatsApp_API_Client $whatsappClient;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->title          = __( 'PIX', 'whatsapp-commerce-hub' );
		$this->description    = __( 'Instant payment via PIX', 'whatsapp-commerce-hub' );
		$this->processor      = get_option( 'wch_pix_processor', 'mercadopago' );
		$this->apiToken       = get_option( 'wch_pix_api_token', '' );
		$this->whatsappClient = new \WCH_WhatsApp_API_Client();
	}

	/**
	 * Check if PIX is configured.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return ! empty( $this->apiToken );
	}

	/**
	 * Process PIX payment.
	 *
	 * @param int   $orderId      Order ID.
	 * @param array $conversation Conversation context.
	 * @return PaymentResult
	 */
	public function processPayment( int $orderId, array $conversation ): PaymentResult {
		$order = $this->getOrder( $orderId );

		if ( ! $order ) {
			return $this->invalidOrderResult();
		}

		if ( ! $this->isConfigured() ) {
			return $this->configurationErrorResult();
		}

		try {
			// Generate PIX QR code.
			$pixData = $this->generatePixCode( $order, $conversation );

			if ( ! $pixData || empty( $pixData['qr_code'] ) ) {
				throw new \Exception( __( 'Failed to generate PIX QR code.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$this->setOrderPaymentMethod( $order );
			$order->update_status( 'pending', __( 'Awaiting PIX payment.', 'whatsapp-commerce-hub' ) );

			// Store transaction metadata.
			$this->storeTransactionMeta(
				$order,
				$pixData['transaction_id'],
				array(
					'_pix_qr_code'      => $pixData['qr_code'],
					'_pix_qr_code_text' => $pixData['qr_code_text'] ?? '',
				)
			);
			$order->save();

			// Send QR code to customer.
			$customerPhone = $this->getCustomerPhone( $conversation );
			$this->sendPixQrCode( $customerPhone, $pixData );

			$this->log(
				'PIX payment created',
				array(
					'order_id'       => $orderId,
					'transaction_id' => $pixData['transaction_id'],
				)
			);

			return PaymentResult::success(
				$pixData['transaction_id'],
				__(
					'PIX QR code has been sent! Please scan it with your banking app to complete the payment. ' .
					'The code is valid for 30 minutes.',
					'whatsapp-commerce-hub'
				)
			);

		} catch ( \Exception $e ) {
			$this->log( 'PIX payment error', array( 'error' => $e->getMessage() ), 'error' );

			return PaymentResult::failure( 'pix_error', $e->getMessage() );
		}
	}

	/**
	 * Generate PIX QR code via payment processor.
	 *
	 * @param \WC_Order $order        Order object.
	 * @param array     $conversation Conversation context.
	 * @return array|null
	 */
	private function generatePixCode( \WC_Order $order, array $conversation ): ?array {
		if ( $this->processor === 'mercadopago' ) {
			return $this->generateMercadoPagoPix( $order, $conversation );
		}

		if ( $this->processor === 'pagseguro' ) {
			return $this->generatePagSeguroPix( $order, $conversation );
		}

		return null;
	}

	/**
	 * Generate PIX via Mercado Pago.
	 *
	 * @param \WC_Order $order        Order object.
	 * @param array     $conversation Conversation context.
	 * @return array|null
	 */
	private function generateMercadoPagoPix( \WC_Order $order, array $conversation ): ?array {
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
				'customer_phone'  => $this->getCustomerPhone( $conversation ),
				'conversation_id' => $conversation['id'] ?? '',
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->apiToken,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Mercado Pago PIX error', array( 'error' => $response->get_error_message() ), 'error' );
			return null;
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( isset( $result['point_of_interaction']['transaction_data'] ) ) {
			$transactionData = $result['point_of_interaction']['transaction_data'];
			return array(
				'transaction_id' => (string) $result['id'],
				'qr_code'        => $transactionData['qr_code_base64'] ?? '',
				'qr_code_text'   => $transactionData['qr_code'] ?? '',
			);
		}

		return null;
	}

	/**
	 * Generate PIX via PagSeguro.
	 *
	 * @param \WC_Order $order        Order object.
	 * @param array     $conversation Conversation context.
	 * @return array|null
	 */
	private function generatePagSeguroPix( \WC_Order $order, array $conversation ): ?array {
		$url = 'https://api.pagseguro.com/orders';

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'reference_id' => (string) $item->get_product_id(),
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'unit_amount'  => intval( $item->get_total() * 100 / $item->get_quantity() ),
			);
		}

		$data = array(
			'reference_id'      => $order->get_order_number(),
			'customer'          => array(
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => $order->get_billing_email() ?: 'noreply@example.com',
			),
			'items'             => $items,
			'qr_codes'          => array(
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

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->apiToken,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'PagSeguro PIX error', array( 'error' => $response->get_error_message() ), 'error' );
			return null;
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( isset( $result['qr_codes'][0] ) ) {
			$qrCode = $result['qr_codes'][0];
			return array(
				'transaction_id' => $result['id'],
				'qr_code'        => $qrCode['links'][0]['href'] ?? '',
				'qr_code_text'   => $qrCode['text'] ?? '',
			);
		}

		return null;
	}

	/**
	 * Send PIX QR code to customer via WhatsApp.
	 *
	 * @param string $phone   Customer phone number.
	 * @param array  $pixData PIX data.
	 * @return void
	 */
	private function sendPixQrCode( string $phone, array $pixData ): void {
		if ( empty( $phone ) ) {
			return;
		}

		// Send QR code as image if base64 is available.
		if ( ! empty( $pixData['qr_code'] ) && strpos( $pixData['qr_code'], 'base64' ) !== false ) {
			$uploadDir = wp_upload_dir();
			$qrFile    = $uploadDir['path'] . '/pix_qr_' . time() . '.png';

			// Extract base64 data.
			$qrData = preg_replace( '/^data:image\/\w+;base64,/', '', $pixData['qr_code'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $qrFile, base64_decode( $qrData ) );

			// Upload to WhatsApp.
			$media = $this->whatsappClient->upload_media( $qrFile, 'image/png' );

			if ( $media && isset( $media['id'] ) ) {
				$this->whatsappClient->send_message(
					array(
						'messaging_product' => 'whatsapp',
						'to'                => $phone,
						'type'              => 'image',
						'image'             => array(
							'id'      => $media['id'],
							'caption' => __( 'Scan this PIX QR code with your banking app to complete the payment.', 'whatsapp-commerce-hub' ),
						),
					)
				);
			}

			// Clean up temporary file.
			@unlink( $qrFile );
		}

		// Also send the PIX code as text for copy-paste.
		if ( ! empty( $pixData['qr_code_text'] ) ) {
			$this->whatsappClient->send_message(
				array(
					'messaging_product' => 'whatsapp',
					'to'                => $phone,
					'type'              => 'text',
					'text'              => array(
						'body' => sprintf(
							/* translators: %s: PIX code */
							__( "Or copy this PIX code to pay:\n\n```%s```", 'whatsapp-commerce-hub' ),
							$pixData['qr_code_text']
						),
					),
				)
			);
		}
	}

	/**
	 * Handle PIX webhook.
	 *
	 * @param array  $data      Webhook payload.
	 * @param string $signature Request signature.
	 * @return WebhookResult
	 */
	public function handleWebhook( array $data, string $signature = '' ): WebhookResult {
		if ( $this->processor === 'mercadopago' ) {
			return $this->handleMercadoPagoCallback( $data );
		}

		if ( $this->processor === 'pagseguro' ) {
			return $this->handlePagSeguroCallback( $data );
		}

		return WebhookResult::failure( __( 'Unknown processor.', 'whatsapp-commerce-hub' ) );
	}

	/**
	 * Handle Mercado Pago callback.
	 *
	 * @param array $data Webhook data.
	 * @return WebhookResult
	 */
	private function handleMercadoPagoCallback( array $data ): WebhookResult {
		$type = $data['type'] ?? '';

		if ( $type !== 'payment' ) {
			return WebhookResult::failure( __( 'Unhandled webhook type.', 'whatsapp-commerce-hub' ) );
		}

		$paymentId = $data['data']['id'] ?? '';
		$payment   = $this->getMercadoPagoPayment( $paymentId );

		if ( ! $payment ) {
			return WebhookResult::failure( __( 'Failed to retrieve payment details.', 'whatsapp-commerce-hub' ) );
		}

		$metadata = $payment['metadata'] ?? array();
		$orderId  = intval( $metadata['order_id'] ?? 0 );

		if ( ! $orderId ) {
			return WebhookResult::failure( __( 'Order ID not found.', 'whatsapp-commerce-hub' ) );
		}

		$order  = $this->getOrder( $orderId );
		$status = $payment['status'] ?? '';

		if ( ! $order ) {
			return WebhookResult::failure( __( 'Order not found.', 'whatsapp-commerce-hub' ) );
		}

		if ( $status === 'approved' ) {
			// Security check: prevent double-spend.
			if ( ! $this->orderNeedsPayment( $order ) ) {
				return WebhookResult::alreadyCompleted( $orderId, $paymentId );
			}

			$order->payment_complete( $paymentId );
			$order->add_order_note(
				sprintf(
					/* translators: %s: Transaction ID */
					__( 'PIX payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
					$paymentId
				)
			);

			$this->log( 'PIX payment completed', array( 'order_id' => $orderId ) );

			return WebhookResult::success(
				$orderId,
				WebhookResult::STATUS_COMPLETED,
				__( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
				$paymentId
			);
		}

		return WebhookResult::failure( __( 'Unhandled payment status.', 'whatsapp-commerce-hub' ) );
	}

	/**
	 * Get Mercado Pago payment details.
	 *
	 * @param string $paymentId Payment ID.
	 * @return array|null
	 */
	private function getMercadoPagoPayment( string $paymentId ): ?array {
		$url = 'https://api.mercadopago.com/v1/payments/' . $paymentId;

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->apiToken,
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
	 * @return WebhookResult
	 */
	private function handlePagSeguroCallback( array $data ): WebhookResult {
		$referenceId = $data['reference_id'] ?? '';
		$status      = $data['charges'][0]['status'] ?? '';

		if ( $status !== 'PAID' ) {
			return WebhookResult::failure( __( 'Unhandled payment status.', 'whatsapp-commerce-hub' ) );
		}

		$order = wc_get_order( $referenceId );

		if ( ! $order ) {
			return WebhookResult::failure( __( 'Order not found.', 'whatsapp-commerce-hub' ) );
		}

		// Security check: prevent double-spend.
		if ( ! $this->orderNeedsPayment( $order ) ) {
			return WebhookResult::alreadyCompleted( $order->get_id(), $data['id'] ?? '' );
		}

		$order->payment_complete( $data['id'] ?? '' );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Transaction ID */
				__( 'PIX payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
				$data['id'] ?? ''
			)
		);

		return WebhookResult::success(
			$order->get_id(),
			WebhookResult::STATUS_COMPLETED,
			__( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
			$data['id'] ?? ''
		);
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transactionId Transaction ID.
	 * @return PaymentStatus
	 */
	public function getPaymentStatus( string $transactionId ): PaymentStatus {
		if ( $this->processor === 'mercadopago' ) {
			$payment = $this->getMercadoPagoPayment( $transactionId );

			if ( $payment ) {
				$statusMap = array(
					'approved'  => PaymentStatus::COMPLETED,
					'pending'   => PaymentStatus::PENDING,
					'rejected'  => PaymentStatus::FAILED,
					'cancelled' => PaymentStatus::FAILED,
				);

				return new PaymentStatus(
					$statusMap[ $payment['status'] ?? 'pending' ] ?? PaymentStatus::PENDING,
					$transactionId,
					$payment['transaction_amount'] ?? 0,
					$payment['currency_id'] ?? 'BRL',
					$payment['metadata'] ?? array()
				);
			}
		}

		return PaymentStatus::unknown( $transactionId );
	}
}
