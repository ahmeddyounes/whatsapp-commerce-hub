<?php
/**
 * Razorpay Payment Gateway
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
 * Class RazorpayGateway
 *
 * Razorpay payment gateway implementation for India.
 */
class RazorpayGateway extends AbstractGateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected string $id = 'razorpay';

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
	protected array $supportedCountries = array( 'IN', 'MY' );

	/**
	 * Razorpay API key ID.
	 *
	 * @var string
	 */
	private string $apiKey;

	/**
	 * Razorpay API key secret.
	 *
	 * @var string
	 */
	private string $apiSecret;

	/**
	 * Webhook secret.
	 *
	 * @var string
	 */
	private string $webhookSecret;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.razorpay.com/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->title         = __( 'Razorpay', 'whatsapp-commerce-hub' );
		$this->description   = __( 'UPI, Cards, Net Banking, Wallets', 'whatsapp-commerce-hub' );
		$this->apiKey        = get_option( 'wch_razorpay_key_id', '' );
		$this->apiSecret     = get_option( 'wch_razorpay_key_secret', '' );
		$this->webhookSecret = get_option( 'wch_razorpay_webhook_secret', '' );
	}

	/**
	 * Check if Razorpay is configured.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return ! empty( $this->apiKey ) && ! empty( $this->apiSecret );
	}

	/**
	 * Process Razorpay payment.
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
			// Create Razorpay Payment Link.
			$paymentLink = $this->createPaymentLink( $order, $conversation );

			if ( ! $paymentLink || empty( $paymentLink['short_url'] ) ) {
				throw new \Exception( __( 'Failed to create Razorpay payment link.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$this->setOrderPaymentMethod( $order );
			$order->update_status( 'pending', __( 'Awaiting Razorpay payment.', 'whatsapp-commerce-hub' ) );

			// Store transaction metadata.
			$this->storeTransactionMeta(
				$order,
				$paymentLink['id'],
				array( '_razorpay_payment_link_id' => $paymentLink['id'] )
			);
			$order->save();

			$this->log(
				'Razorpay payment link created',
				array(
					'order_id' => $orderId,
					'link_id'  => $paymentLink['id'],
				)
			);

			return PaymentResult::success(
				$paymentLink['id'],
				sprintf(
					/* translators: %s: Payment URL */
					__( 'Please complete your payment by clicking this link: %s. You can pay using UPI, Cards, Net Banking, or Wallets.', 'whatsapp-commerce-hub' ),
					$paymentLink['short_url']
				),
				$paymentLink['short_url']
			);

		} catch ( \Exception $e ) {
			$this->log( 'Razorpay payment error', array( 'error' => $e->getMessage() ), 'error' );

			return PaymentResult::failure( 'razorpay_error', $e->getMessage() );
		}
	}

	/**
	 * Create Razorpay Payment Link.
	 *
	 * @param \WC_Order $order        Order object.
	 * @param array     $conversation Conversation context.
	 * @return array|null
	 */
	private function createPaymentLink( \WC_Order $order, array $conversation ): ?array {
		$amount        = intval( $order->get_total() * 100 ); // Convert to paise.
		$customerPhone = $this->getCustomerPhone( $conversation );

		$data = array(
			'amount'          => $amount,
			'currency'        => $order->get_currency(),
			'description'     => sprintf(
				/* translators: %s: Order number */
				__( 'Order #%s', 'whatsapp-commerce-hub' ),
				$order->get_order_number()
			),
			'customer'        => array(
				'name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'   => $order->get_billing_email() ?: 'noreply@example.com',
				'contact' => preg_replace( '/[^0-9]/', '', $customerPhone ),
			),
			'notify'          => array(
				'sms'   => false,
				'email' => false,
			),
			'callback_url'    => add_query_arg( 'wch_payment', 'success', home_url() ),
			'callback_method' => 'get',
			'notes'           => array(
				'order_id'        => (string) $order->get_id(),
				'customer_phone'  => $customerPhone,
				'conversation_id' => $conversation['id'] ?? '',
			),
		);

		return $this->razorpayRequest( 'payment_links', $data );
	}

	/**
	 * Handle Razorpay webhook.
	 *
	 * @param array  $data      Webhook payload.
	 * @param string $signature Request signature.
	 * @return WebhookResult
	 */
	public function handleWebhook( array $data, string $signature = '' ): WebhookResult {
		$event = $data['event'] ?? '';

		// Validate webhook timestamp (5-minute tolerance).
		$webhookTimestamp = intval( $data['created_at'] ?? 0 );
		if ( $webhookTimestamp > 0 && abs( time() - $webhookTimestamp ) > 300 ) {
			$this->log( 'Razorpay webhook timestamp expired', array( 'event' => $event ), 'warning' );
			return WebhookResult::failure( __( 'Webhook timestamp expired.', 'whatsapp-commerce-hub' ) );
		}

		if ( $event === 'payment.captured' ) {
			return $this->handlePaymentCaptured( $data );
		}

		if ( $event === 'payment.failed' ) {
			return $this->handlePaymentFailed( $data );
		}

		return WebhookResult::failure( __( 'Unhandled webhook event.', 'whatsapp-commerce-hub' ) );
	}

	/**
	 * Handle payment.captured event.
	 *
	 * @param array $data Event data.
	 * @return WebhookResult
	 */
	private function handlePaymentCaptured( array $data ): WebhookResult {
		$payment = $data['payload']['payment']['entity'] ?? array();
		$notes   = $payment['notes'] ?? array();
		$orderId = intval( $notes['order_id'] ?? 0 );

		if ( ! $orderId ) {
			return WebhookResult::failure( __( 'Order ID not found.', 'whatsapp-commerce-hub' ) );
		}

		$order = $this->getOrder( $orderId );
		if ( ! $order ) {
			return WebhookResult::failure( __( 'Order not found.', 'whatsapp-commerce-hub' ) );
		}

		// Security check: prevent double-spend.
		if ( ! $this->orderNeedsPayment( $order ) ) {
			$this->log(
				'Razorpay webhook skipped - order already paid',
				array(
					'order_id'   => $orderId,
					'payment_id' => $payment['id'] ?? '',
				)
			);
			return WebhookResult::alreadyCompleted( $orderId, $payment['id'] ?? '' );
		}

		$order->payment_complete( $payment['id'] );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Transaction ID */
				__( 'Razorpay payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
				$payment['id']
			)
		);

		$this->log( 'Razorpay payment completed', array( 'order_id' => $orderId ) );

		return WebhookResult::success(
			$orderId,
			WebhookResult::STATUS_COMPLETED,
			__( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
			$payment['id']
		);
	}

	/**
	 * Handle payment.failed event.
	 *
	 * @param array $data Event data.
	 * @return WebhookResult
	 */
	private function handlePaymentFailed( array $data ): WebhookResult {
		$payment = $data['payload']['payment']['entity'] ?? array();
		$notes   = $payment['notes'] ?? array();
		$orderId = intval( $notes['order_id'] ?? 0 );

		if ( ! $orderId ) {
			return WebhookResult::failure( __( 'Order ID not found.', 'whatsapp-commerce-hub' ) );
		}

		$order = $this->getOrder( $orderId );
		if ( ! $order ) {
			return WebhookResult::failure( __( 'Order not found.', 'whatsapp-commerce-hub' ) );
		}

		// Only fail orders that are still pending.
		if ( $this->orderNeedsPayment( $order ) ) {
			$errorDescription = $payment['error_description'] ?? __( 'Unknown error', 'whatsapp-commerce-hub' );
			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Razorpay payment failed: %s', 'whatsapp-commerce-hub' ),
					$errorDescription
				)
			);
		}

		return WebhookResult::success(
			$orderId,
			WebhookResult::STATUS_FAILED,
			__( 'Payment failed.', 'whatsapp-commerce-hub' ),
			$payment['id'] ?? ''
		);
	}

	/**
	 * Verify webhook signature.
	 *
	 * @param string $payload   Raw payload.
	 * @param string $signature Signature header.
	 * @return bool
	 */
	public function verifyWebhookSignature( string $payload, string $signature ): bool {
		// SECURITY: Reject webhooks if secret is not configured.
		// Previously returned true, allowing payment fraud via fake webhooks.
		if ( empty( $this->webhookSecret ) ) {
			$this->log(
				'Razorpay webhook rejected - webhook secret not configured',
				array( 'payload_length' => strlen( $payload ) ),
				'error'
			);
			return false;
		}

		$expectedSignature = hash_hmac( 'sha256', $payload, $this->webhookSecret );
		return hash_equals( $expectedSignature, $signature );
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transactionId Transaction ID (payment link ID).
	 * @return PaymentStatus
	 */
	public function getPaymentStatus( string $transactionId ): PaymentStatus {
		$response = $this->razorpayRequest( "payment_links/{$transactionId}", array(), 'GET' );

		if ( ! $response ) {
			return PaymentStatus::unknown( $transactionId );
		}

		$paymentStatus = $response['status'] ?? 'created';
		$statusMap     = array(
			'paid'           => PaymentStatus::COMPLETED,
			'created'        => PaymentStatus::PENDING,
			'partially_paid' => PaymentStatus::PENDING,
			'expired'        => PaymentStatus::FAILED,
			'cancelled'      => PaymentStatus::FAILED,
		);

		return new PaymentStatus(
			$statusMap[ $paymentStatus ] ?? PaymentStatus::PENDING,
			$transactionId,
			( $response['amount'] ?? 0 ) / 100,
			$response['currency'] ?? '',
			$response['notes'] ?? array()
		);
	}

	/**
	 * Make Razorpay API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $data     Request data.
	 * @param string $method   HTTP method.
	 * @return array|null
	 */
	private function razorpayRequest( string $endpoint, array $data = array(), string $method = 'POST' ): ?array {
		$url = self::API_BASE . '/' . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->apiKey . ':' . $this->apiSecret ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( $method === 'POST' && ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Razorpay API error', array( 'error' => $response->get_error_message() ), 'error' );
			return null;
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( isset( $result['error'] ) ) {
			$this->log( 'Razorpay API error', array( 'error' => $result['error']['description'] ?? 'Unknown error' ), 'error' );
			return null;
		}

		return $result;
	}
}
