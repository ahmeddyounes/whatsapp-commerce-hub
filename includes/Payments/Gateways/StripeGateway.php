<?php
/**
 * Stripe Payment Gateway
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Payments\Gateways;

use WhatsAppCommerceHub\Payments\Contracts\PaymentResult;
use WhatsAppCommerceHub\Payments\Contracts\PaymentStatus;
use WhatsAppCommerceHub\Payments\Contracts\RefundResult;
use WhatsAppCommerceHub\Payments\Contracts\WebhookResult;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StripeGateway
 *
 * Stripe payment gateway implementation.
 */
class StripeGateway extends AbstractGateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected string $id = 'stripe';

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
	 * Stripe API key.
	 *
	 * @var string
	 */
	private string $apiKey;

	/**
	 * Stripe webhook secret.
	 *
	 * @var string
	 */
	private string $webhookSecret;

	/**
	 * Stripe API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->title         = __( 'Stripe', 'whatsapp-commerce-hub' );
		$this->description   = __( 'Secure card and local payments', 'whatsapp-commerce-hub' );
		$this->apiKey        = get_option( 'wch_stripe_secret_key', '' );
		$this->webhookSecret = get_option( 'wch_stripe_webhook_secret', '' );
	}

	/**
	 * Check if Stripe is configured.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return ! empty( $this->apiKey );
	}

	/**
	 * Process Stripe payment.
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
			// Create Stripe Checkout Session.
			$session = $this->createCheckoutSession( $order, $conversation );

			if ( ! $session || empty( $session['url'] ) ) {
				throw new \Exception( __( 'Failed to create Stripe checkout session.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$this->setOrderPaymentMethod( $order );
			$order->update_status( 'pending', __( 'Awaiting Stripe payment.', 'whatsapp-commerce-hub' ) );

			// Store transaction metadata.
			$this->storeTransactionMeta(
				$order,
				$session['id'],
				[ '_stripe_session_id' => $session['id'] ]
			);
			$order->save();

			$this->log(
				'Stripe session created',
				[
					'order_id'   => $orderId,
					'session_id' => $session['id'],
				]
			);

			return PaymentResult::success(
				$session['id'],
				sprintf(
					/* translators: %s: Payment URL */
					__( 'Please complete your payment by clicking this link: %s', 'whatsapp-commerce-hub' ),
					$session['url']
				),
				$session['url']
			);

		} catch ( \Exception $e ) {
			$this->log( 'Stripe payment error', [ 'error' => $e->getMessage() ], 'error' );

			return PaymentResult::failure( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Create Stripe Checkout Session.
	 *
	 * @param \WC_Order $order        Order object.
	 * @param array     $conversation Conversation context.
	 * @return array|null
	 */
	private function createCheckoutSession( \WC_Order $order, array $conversation ): ?array {
		$lineItems = [];

		foreach ( $order->get_items() as $item ) {
			$lineItems[] = [
				'price_data' => [
					'currency'     => strtolower( $order->get_currency() ),
					'product_data' => [
						'name' => $item->get_name(),
					],
					'unit_amount'  => intval( ( $item->get_total() / $item->get_quantity() ) * 100 ),
				],
				'quantity'   => $item->get_quantity(),
			];
		}

		// Add shipping if applicable.
		$shippingTotal = (float) $order->get_shipping_total();
		if ( $shippingTotal > 0 ) {
			$lineItems[] = [
				'price_data' => [
					'currency'     => strtolower( $order->get_currency() ),
					'product_data' => [
						'name' => __( 'Shipping', 'whatsapp-commerce-hub' ),
					],
					'unit_amount'  => intval( $shippingTotal * 100 ),
				],
				'quantity'   => 1,
			];
		}

		$data = [
			'payment_method_types' => [ 'card' ],
			'line_items'           => $lineItems,
			'mode'                 => 'payment',
			'success_url'          => add_query_arg( 'wch_payment', 'success', home_url() ),
			'cancel_url'           => add_query_arg( 'wch_payment', 'cancelled', home_url() ),
			'customer_email'       => $order->get_billing_email(),
			'metadata'             => [
				'order_id'        => $order->get_id(),
				'customer_phone'  => $this->getCustomerPhone( $conversation ),
				'conversation_id' => $conversation['id'] ?? '',
			],
		];

		return $this->stripeRequest( 'checkout/sessions', $data );
	}

	/**
	 * Handle Stripe webhook.
	 *
	 * @param array  $data      Webhook payload.
	 * @param string $signature Request signature.
	 * @return WebhookResult
	 */
	public function handleWebhook( array $data, string $signature = '' ): WebhookResult {
		$eventType = $data['type'] ?? '';

		if ( $eventType === 'checkout.session.completed' ) {
			return $this->handleSessionCompleted( $data );
		}

		if ( $eventType === 'payment_intent.succeeded' ) {
			return $this->handlePaymentIntentSucceeded( $data );
		}

		if ( $eventType === 'payment_intent.payment_failed' ) {
			return $this->handlePaymentIntentFailed( $data );
		}

		return WebhookResult::failure( __( 'Unhandled webhook event.', 'whatsapp-commerce-hub' ) );
	}

	/**
	 * Handle checkout.session.completed event.
	 *
	 * @param array $data Event data.
	 * @return WebhookResult
	 */
	private function handleSessionCompleted( array $data ): WebhookResult {
		$session  = $data['data']['object'] ?? [];
		$metadata = $session['metadata'] ?? [];
		$orderId  = intval( $metadata['order_id'] ?? 0 );

		if ( ! $orderId ) {
			return WebhookResult::failure( __( 'Order ID not found in session.', 'whatsapp-commerce-hub' ) );
		}

		$order = $this->getOrder( $orderId );
		if ( ! $order ) {
			return WebhookResult::failure( __( 'Order not found.', 'whatsapp-commerce-hub' ) );
		}

		// Security check: prevent double-spend.
		if ( ! $this->orderNeedsPayment( $order ) ) {
			$this->log(
				'Stripe webhook skipped - order already paid',
				[
					'order_id'   => $orderId,
					'session_id' => $session['id'] ?? '',
				]
			);
			return WebhookResult::alreadyCompleted( $orderId, $session['id'] ?? '' );
		}

		$paymentIntent = $session['payment_intent'] ?? $session['id'];
		$order->payment_complete( $paymentIntent );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Transaction ID */
				__( 'Stripe payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
				$paymentIntent
			)
		);

		$this->log( 'Stripe payment completed', [ 'order_id' => $orderId ] );

		return WebhookResult::success(
			$orderId,
			WebhookResult::STATUS_COMPLETED,
			__( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
			$paymentIntent
		);
	}

	/**
	 * Handle payment_intent.succeeded event.
	 *
	 * @param array $data Event data.
	 * @return WebhookResult
	 */
	private function handlePaymentIntentSucceeded( array $data ): WebhookResult {
		$paymentIntent = $data['data']['object'] ?? [];
		$metadata      = $paymentIntent['metadata'] ?? [];
		$orderId       = intval( $metadata['order_id'] ?? 0 );

		if ( ! $orderId ) {
			return WebhookResult::failure( __( 'Order ID not found.', 'whatsapp-commerce-hub' ) );
		}

		$order = $this->getOrder( $orderId );
		if ( ! $order ) {
			return WebhookResult::failure( __( 'Order not found.', 'whatsapp-commerce-hub' ) );
		}

		// Security check: prevent double-spend.
		if ( ! $this->orderNeedsPayment( $order ) ) {
			return WebhookResult::alreadyCompleted( $orderId, $paymentIntent['id'] ?? '' );
		}

		$order->payment_complete( $paymentIntent['id'] );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Transaction ID */
				__( 'Stripe payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
				$paymentIntent['id']
			)
		);

		return WebhookResult::success(
			$orderId,
			WebhookResult::STATUS_COMPLETED,
			__( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
			$paymentIntent['id']
		);
	}

	/**
	 * Handle payment_intent.payment_failed event.
	 *
	 * @param array $data Event data.
	 * @return WebhookResult
	 */
	private function handlePaymentIntentFailed( array $data ): WebhookResult {
		$paymentIntent = $data['data']['object'] ?? [];
		$metadata      = $paymentIntent['metadata'] ?? [];
		$orderId       = intval( $metadata['order_id'] ?? 0 );

		if ( ! $orderId ) {
			return WebhookResult::failure( __( 'Order ID not found.', 'whatsapp-commerce-hub' ) );
		}

		$order = $this->getOrder( $orderId );
		if ( ! $order ) {
			return WebhookResult::failure( __( 'Order not found.', 'whatsapp-commerce-hub' ) );
		}

		// Only fail orders that are still pending.
		if ( $this->orderNeedsPayment( $order ) ) {
			$errorMessage = $paymentIntent['last_payment_error']['message'] ?? __( 'Payment failed', 'whatsapp-commerce-hub' );
			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Stripe payment failed: %s', 'whatsapp-commerce-hub' ),
					$errorMessage
				)
			);
		}

		return WebhookResult::success(
			$orderId,
			WebhookResult::STATUS_FAILED,
			__( 'Payment failed.', 'whatsapp-commerce-hub' ),
			$paymentIntent['id'] ?? ''
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
				'Stripe webhook rejected - webhook secret not configured',
				[ 'payload_length' => strlen( $payload ) ],
				'error'
			);
			return false;
		}

		// Parse signature header.
		$elements      = explode( ',', $signature );
		$signatureData = [];

		foreach ( $elements as $element ) {
			$parts = explode( '=', $element, 2 );
			if ( count( $parts ) === 2 ) {
				$signatureData[ $parts[0] ] = $parts[1];
			}
		}

		$timestamp         = $signatureData['t'] ?? '';
		$expectedSignature = $signatureData['v1'] ?? '';

		if ( empty( $timestamp ) || empty( $expectedSignature ) ) {
			return false;
		}

		// Check timestamp tolerance (5 minutes).
		if ( abs( time() - intval( $timestamp ) ) > 300 ) {
			$this->log( 'Stripe webhook timestamp expired', [], 'warning' );
			return false;
		}

		// Compute expected signature.
		$signedPayload     = $timestamp . '.' . $payload;
		$computedSignature = hash_hmac( 'sha256', $signedPayload, $this->webhookSecret );

		return hash_equals( $expectedSignature, $computedSignature );
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transactionId Transaction ID (session or payment intent).
	 * @return PaymentStatus
	 */
	public function getPaymentStatus( string $transactionId ): PaymentStatus {
		// Try to get session first.
		if ( str_starts_with( $transactionId, 'cs_' ) ) {
			$session = $this->stripeRequest( "checkout/sessions/{$transactionId}", [], 'GET' );

			if ( $session ) {
				$statusMap = [
					'complete' => PaymentStatus::COMPLETED,
					'expired'  => PaymentStatus::FAILED,
					'open'     => PaymentStatus::PENDING,
				];

				return new PaymentStatus(
					$statusMap[ $session['status'] ?? 'open' ] ?? PaymentStatus::PENDING,
					$transactionId,
					( $session['amount_total'] ?? 0 ) / 100,
					$session['currency'] ?? '',
					$session['metadata'] ?? []
				);
			}
		}

		// Try payment intent.
		if ( str_starts_with( $transactionId, 'pi_' ) ) {
			$paymentIntent = $this->stripeRequest( "payment_intents/{$transactionId}", [], 'GET' );

			if ( $paymentIntent ) {
				$statusMap = [
					'succeeded'               => PaymentStatus::COMPLETED,
					'canceled'                => PaymentStatus::FAILED,
					'requires_payment_method' => PaymentStatus::PENDING,
					'requires_confirmation'   => PaymentStatus::PENDING,
					'requires_action'         => PaymentStatus::PENDING,
					'processing'              => PaymentStatus::PENDING,
				];

				return new PaymentStatus(
					$statusMap[ $paymentIntent['status'] ?? 'processing' ] ?? PaymentStatus::PENDING,
					$transactionId,
					( $paymentIntent['amount'] ?? 0 ) / 100,
					$paymentIntent['currency'] ?? '',
					$paymentIntent['metadata'] ?? []
				);
			}
		}

		return PaymentStatus::unknown( $transactionId );
	}

	/**
	 * Process refund.
	 *
	 * @param int    $orderId       Order ID.
	 * @param float  $amount        Refund amount.
	 * @param string $reason        Refund reason.
	 * @param string $transactionId Transaction ID.
	 * @return RefundResult
	 */
	public function processRefund( int $orderId, float $amount, string $reason, string $transactionId ): RefundResult {
		if ( ! $this->isConfigured() ) {
			return RefundResult::failure( 'not_configured', __( 'Stripe is not configured.', 'whatsapp-commerce-hub' ) );
		}

		// Get payment intent from transaction ID.
		$paymentIntentId = $transactionId;
		if ( str_starts_with( $transactionId, 'cs_' ) ) {
			$session         = $this->stripeRequest( "checkout/sessions/{$transactionId}", [], 'GET' );
			$paymentIntentId = $session['payment_intent'] ?? '';
		}

		if ( empty( $paymentIntentId ) ) {
			return RefundResult::failure( 'no_payment_intent', __( 'Payment intent not found.', 'whatsapp-commerce-hub' ) );
		}

		$data = [
			'payment_intent' => $paymentIntentId,
			'amount'         => intval( $amount * 100 ),
		];

		if ( $reason ) {
			$data['reason']   = 'requested_by_customer';
			$data['metadata'] = [ 'reason' => $reason ];
		}

		$refund = $this->stripeRequest( 'refunds', $data );

		if ( ! $refund || ! isset( $refund['id'] ) ) {
			return RefundResult::failure(
				'refund_failed',
				$refund['error']['message'] ?? __( 'Refund failed.', 'whatsapp-commerce-hub' )
			);
		}

		$this->log(
			'Stripe refund processed',
			[
				'order_id'  => $orderId,
				'refund_id' => $refund['id'],
			]
		);

		return RefundResult::success(
			$refund['id'],
			$amount,
			__( 'Refund processed successfully.', 'whatsapp-commerce-hub' )
		);
	}

	/**
	 * Make Stripe API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $data     Request data.
	 * @param string $method   HTTP method.
	 * @return array|null
	 */
	private function stripeRequest( string $endpoint, array $data = [], string $method = 'POST' ): ?array {
		$url = self::API_BASE . '/' . $endpoint;

		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->apiKey,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'timeout' => 30,
		];

		if ( $method === 'POST' && ! empty( $data ) ) {
			$args['body'] = http_build_query( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Stripe API error', [ 'error' => $response->get_error_message() ], 'error' );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}
}
