<?php
/**
 * Payment Webhook Handler
 *
 * Handles incoming webhooks from payment gateways with signature verification
 * and idempotency checks.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\Payments;

use WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController;
use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payment Webhook Handler
 *
 * Processes payment gateway webhooks with:
 * - Signature verification (Stripe, MercadoPago, etc.)
 * - Idempotency handling
 * - Event routing and processing
 */
class WebhookHandler extends RestController {

	/**
	 * Processed webhook event IDs for idempotency (per-request cache)
	 *
	 * @var array<string, bool>
	 */
	private array $processedEvents = [];

	/**
	 * Event processing lock timeout in seconds
	 */
	private const PROCESSING_TIMEOUT = 300;

	/**
	 * Constructor
	 *
	 * @param SettingsManager $settings Settings manager
	 * @param Logger          $logger Logger instance
	 */
	public function __construct(
		SettingsManager $settings,
		private readonly Logger $logger
	) {
		parent::__construct( $settings );
	}

	/**
	 * Get the schema for a single item.
	 *
	 * @return array Item schema.
	 */
	public function getItemSchema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'payment-webhook',
			'type'       => 'object',
			'properties' => [
				'event_type' => [
					'description' => 'Type of payment event',
					'type'        => 'string',
				],
				'payload'    => [
					'description' => 'Webhook payload data',
					'type'        => 'object',
				],
			],
		];
	}

	/**
	 * Register REST routes
	 */
	public function registerRoutes(): void {
		// Generic payment webhook endpoint
		register_rest_route(
			$this->namespace,
			'/payment-webhook',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handlePaymentWebhook' ],
					'permission_callback' => [ $this, 'verifyWebhookPermission' ],
				],
			]
		);

		// Gateway-specific endpoints
		register_rest_route(
			$this->namespace,
			'/payment-webhook/(?P<gateway>[a-z_]+)',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handleGatewayWebhook' ],
					'permission_callback' => [ $this, 'verifyWebhookPermission' ],
					'args'                => [
						'gateway' => [
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^[a-z_]+$',
						],
					],
				],
			]
		);
	}

	/**
	 * Verify webhook permission via signature validation
	 *
	 * @param WP_REST_Request $request Request object
	 * @return bool|WP_Error True if authorized, WP_Error otherwise
	 */
	public function verifyWebhookPermission( WP_REST_Request $request ): bool|WP_Error {
		$gatewayId = $request->get_param( 'gateway' );
		if ( ! $gatewayId ) {
			$gatewayId = $this->detectGateway( $request );
		}

		if ( ! $gatewayId ) {
			return new WP_Error(
				'wch_rest_forbidden',
				__( 'Unable to identify payment gateway.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Verify webhook signature
		$isValid = $this->verifyWebhookSignature( $gatewayId, $request );

		if ( is_wp_error( $isValid ) ) {
			$this->logger->error(
				'Webhook signature verification failed',
				[
					'gateway' => $gatewayId,
					'error'   => $isValid->get_error_message(),
				]
			);
			return $isValid;
		}

		return true;
	}

	/**
	 * Handle payment webhook (generic endpoint)
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function handlePaymentWebhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gatewayId = $this->detectGateway( $request );

		if ( ! $gatewayId ) {
			return new WP_Error(
				'wch_invalid_gateway',
				__( 'Unable to identify payment gateway', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		return $this->processWebhook( $gatewayId, $request );
	}

	/**
	 * Handle gateway-specific webhook
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function handleGatewayWebhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gatewayId = $request->get_param( 'gateway' );
		return $this->processWebhook( $gatewayId, $request );
	}

	/**
	 * Process webhook
	 *
	 * @param string          $gatewayId Gateway identifier
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	private function processWebhook( string $gatewayId, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $request->get_json_params() ?: [];

		$this->logger->info(
			'Processing payment webhook',
			[
				'gateway'    => $gatewayId,
				'event_type' => $data['type'] ?? 'unknown',
			]
		);

		// Extract event ID for idempotency
		$eventId = $this->extractEventId( $gatewayId, $data );

		if ( ! $eventId ) {
			return new WP_Error(
				'wch_invalid_webhook',
				__( 'Unable to extract event ID', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Check idempotency
		if ( $this->isEventProcessed( $eventId ) ) {
			$this->logger->info(
				'Webhook event already processed (idempotent)',
				[
					'event_id' => $eventId,
				]
			);

			return new WP_REST_Response(
				[
					'success' => true,
					'message' => 'Event already processed',
				],
				200
			);
		}

		// Try to claim event for processing (atomic)
		$claimed = $this->tryClaimEvent( $eventId );

		if ( ! $claimed ) {
			$this->logger->warning(
				'Event already being processed',
				[
					'event_id' => $eventId,
				]
			);

			return new WP_REST_Response(
				[
					'success' => true,
					'message' => 'Event being processed',
				],
				200
			);
		}

		try {
			// Route to gateway-specific handler
			$result = match ( $gatewayId ) {
				'stripe' => $this->processStripeWebhook( $data ),
				'mercadopago' => $this->processMercadoPagoWebhook( $data ),
				'paypal' => $this->processPayPalWebhook( $data ),
				default => new WP_Error( 'wch_unsupported_gateway', "Gateway {$gatewayId} not supported" ),
			};

			if ( is_wp_error( $result ) ) {
				$this->clearEventProcessing( $eventId );
				return $result;
			}

			// Mark as completed
			$this->markEventCompleted( $eventId );

			return new WP_REST_Response(
				[
					'success' => true,
					'message' => 'Webhook processed successfully',
				],
				200
			);
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Webhook processing exception',
				[
					'event_id' => $eventId,
					'error'    => $e->getMessage(),
				]
			);

			$this->clearEventProcessing( $eventId );

			return new WP_Error(
				'wch_processing_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Process Stripe webhook
	 *
	 * @param array<string, mixed> $data Webhook data
	 * @return true|WP_Error True on success, WP_Error on failure
	 */
	private function processStripeWebhook( array $data ): true|WP_Error {
		$eventType = $data['type'] ?? '';

		return match ( $eventType ) {
			'payment_intent.succeeded' => $this->handlePaymentSuccess( $data ),
			'payment_intent.payment_failed' => $this->handlePaymentFailed( $data ),
			'charge.refunded' => $this->handleRefundCompleted( $data ),
			default => true, // Ignore other events
		};
	}

	/**
	 * Process MercadoPago webhook
	 *
	 * @param array<string, mixed> $data Webhook data
	 * @return true|WP_Error True on success, WP_Error on failure
	 */
	private function processMercadoPagoWebhook( array $data ): true|WP_Error {
		$action = $data['action'] ?? '';

		return match ( $action ) {
			'payment.created', 'payment.updated' => $this->handleMercadoPagoPayment( $data ),
			default => true,
		};
	}

	/**
	 * Process PayPal webhook
	 *
	 * @param array<string, mixed> $data Webhook data
	 * @return true|WP_Error True on success, WP_Error on failure
	 */
	private function processPayPalWebhook( array $data ): true|WP_Error {
		$eventType = $data['event_type'] ?? '';

		return match ( $eventType ) {
			'PAYMENT.CAPTURE.COMPLETED' => $this->handlePaymentSuccess( $data ),
			'PAYMENT.CAPTURE.DENIED' => $this->handlePaymentFailed( $data ),
			default => true,
		};
	}

	/**
	 * Handle payment success
	 *
	 * @param array<string, mixed> $data Payment data
	 * @return true|WP_Error True on success
	 */
	private function handlePaymentSuccess( array $data ): true|WP_Error {
		// Extract order ID from metadata
		$orderId = $this->extractOrderId( $data );

		if ( ! $orderId ) {
			return new WP_Error( 'wch_order_not_found', 'Order ID not found in payment data' );
		}

		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return new WP_Error( 'wch_invalid_order', "Order #{$orderId} not found" );
		}

		// Update order status
		$order->payment_complete();
		$order->add_order_note( __( 'Payment confirmed via webhook', 'whatsapp-commerce-hub' ) );

		$this->logger->info( 'Payment completed via webhook', [ 'order_id' => $orderId ] );

		// Trigger action for notifications
		do_action( 'wch_payment_completed', $order );

		return true;
	}

	/**
	 * Handle payment failed
	 *
	 * @param array<string, mixed> $data Payment data
	 * @return true|WP_Error True on success
	 */
	private function handlePaymentFailed( array $data ): true|WP_Error {
		$orderId = $this->extractOrderId( $data );

		if ( ! $orderId ) {
			return true; // Silently ignore if no order ID
		}

		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return true;
		}

		$order->update_status( 'failed', __( 'Payment failed via webhook', 'whatsapp-commerce-hub' ) );

		$this->logger->warning( 'Payment failed via webhook', [ 'order_id' => $orderId ] );

		return true;
	}

	/**
	 * Handle refund completed
	 *
	 * @param array<string, mixed> $data Refund data
	 * @return true|WP_Error True on success
	 */
	private function handleRefundCompleted( array $data ): true|WP_Error {
		$orderId = $this->extractOrderId( $data );

		if ( ! $orderId ) {
			return true;
		}

		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return true;
		}

		$order->add_order_note( __( 'Refund confirmed via webhook', 'whatsapp-commerce-hub' ) );

		$this->logger->info( 'Refund completed via webhook', [ 'order_id' => $orderId ] );

		return true;
	}

	/**
	 * Handle MercadoPago payment
	 *
	 * @param array<string, mixed> $data Payment data
	 * @return true|WP_Error True on success
	 */
	private function handleMercadoPagoPayment( array $data ): true|WP_Error {
		$paymentData = $data['data'] ?? [];
		$paymentId   = $paymentData['id'] ?? null;

		if ( ! $paymentId ) {
			return new WP_Error( 'wch_invalid_payment', 'Payment ID not found' );
		}

		// Fetch payment details from MercadoPago API
		// Implementation would fetch full payment details and process accordingly

		return true;
	}

	/**
	 * Extract event ID for idempotency
	 *
	 * @param string               $gatewayId Gateway identifier
	 * @param array<string, mixed> $data Webhook data
	 * @return string|null Event ID or null
	 */
	private function extractEventId( string $gatewayId, array $data ): ?string {
		return match ( $gatewayId ) {
			'stripe' => $data['id'] ?? null,
			'mercadopago' => isset( $data['data']['id'] ) ? "mp_{$data['data']['id']}" : null,
			'paypal' => $data['id'] ?? null,
			default => null,
		};
	}

	/**
	 * Extract order ID from payment data
	 *
	 * @param array<string, mixed> $data Payment data
	 * @return int|null Order ID or null
	 */
	private function extractOrderId( array $data ): ?int {
		// Try common metadata locations
		$orderId = $data['metadata']['order_id'] ??
					$data['data']['metadata']['order_id'] ??
					$data['purchase_units'][0]['reference_id'] ??
					null;

		return $orderId ? (int) $orderId : null;
	}

	/**
	 * Try to claim event for processing (atomic)
	 *
	 * @param string $eventId Event ID
	 * @return bool True if claimed successfully
	 */
	private function tryClaimEvent( string $eventId ): bool {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_webhook_events';

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tableName} (event_id, status, created_at) VALUES (%s, %s, %s)",
				$eventId,
				'processing',
				current_time( 'mysql' )
			)
		);

		return $result === 1;
	}

	/**
	 * Check if event is already processed
	 *
	 * @param string $eventId Event ID
	 * @return bool True if already processed
	 */
	private function isEventProcessed( string $eventId ): bool {
		if ( isset( $this->processedEvents[ $eventId ] ) ) {
			return true;
		}

		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_webhook_events';

		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$tableName} WHERE event_id = %s", $eventId )
		);

		return $status === 'completed';
	}

	/**
	 * Mark event as completed
	 *
	 * @param string $eventId Event ID
	 */
	private function markEventCompleted( string $eventId ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_webhook_events';

		$wpdb->update(
			$tableName,
			[
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql' ),
			],
			[ 'event_id' => $eventId ],
			[ '%s', '%s' ],
			[ '%s' ]
		);

		$this->processedEvents[ $eventId ] = true;
	}

	/**
	 * Clear event processing status
	 *
	 * @param string $eventId Event ID
	 */
	private function clearEventProcessing( string $eventId ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_webhook_events';

		$wpdb->delete( $tableName, [ 'event_id' => $eventId ], [ '%s' ] );
	}

	/**
	 * Detect gateway from request
	 *
	 * @param WP_REST_Request $request Request object
	 * @return string|null Gateway ID or null
	 */
	private function detectGateway( WP_REST_Request $request ): ?string {
		$data    = $request->get_json_params() ?: [];
		$headers = $request->get_headers();

		// Stripe detection
		if ( isset( $headers['stripe_signature'] ) || isset( $data['object'] ) ) {
			return 'stripe';
		}

		// MercadoPago detection
		if ( isset( $data['action'] ) && isset( $data['data']['id'] ) ) {
			return 'mercadopago';
		}

		// PayPal detection
		if ( isset( $data['event_type'] ) && isset( $data['resource'] ) ) {
			return 'paypal';
		}

		return null;
	}

	/**
	 * Verify webhook signature
	 *
	 * @param string          $gatewayId Gateway identifier
	 * @param WP_REST_Request $request Request object
	 * @return true|WP_Error True if valid, WP_Error otherwise
	 */
	private function verifyWebhookSignature( string $gatewayId, WP_REST_Request $request ): true|WP_Error {
		return match ( $gatewayId ) {
			'stripe' => $this->verifyStripeSignature( $request ),
			'mercadopago' => $this->verifyMercadoPagoSignature( $request ),
			'paypal' => $this->verifyPayPalSignature( $request ),
			default => new WP_Error( 'wch_unsupported_gateway', 'Signature verification not implemented' ),
		};
	}

	/**
	 * Verify Stripe signature
	 *
	 * @param WP_REST_Request $request Request object
	 * @return true|WP_Error True if valid
	 */
	private function verifyStripeSignature( WP_REST_Request $request ): true|WP_Error {
		$signature = $request->get_header( 'stripe_signature' );
		$secret    = $this->settings->get( 'payment.stripe.webhook_secret' );

		if ( ! $signature || ! $secret ) {
			return new WP_Error( 'wch_invalid_signature', 'Missing signature or secret' );
		}

		$payload = $request->get_body();

		try {
			\Stripe\Webhook::constructEvent( $payload, $signature, $secret );
			return true;
		} catch ( \Exception $e ) {
			return new WP_Error( 'wch_invalid_signature', $e->getMessage() );
		}
	}

	/**
	 * Verify MercadoPago signature
	 *
	 * @param WP_REST_Request $request Request object
	 * @return true|WP_Error True if valid
	 */
	private function verifyMercadoPagoSignature( WP_REST_Request $request ): true|WP_Error {
		$secret = $this->settings->get( 'payment.mercadopago.webhook_secret' );

		if ( ! $secret ) {
			return true; // No secret configured, skip verification
		}

		$xSignature = $request->get_header( 'x_signature' );
		$xRequestId = $request->get_header( 'x_request_id' );

		if ( ! $xSignature || ! $xRequestId ) {
			return new WP_Error( 'wch_invalid_signature', 'Missing signature headers' );
		}

		// Extract ts and hash from x-signature
		$parts = [];
		parse_str( str_replace( ',', '&', $xSignature ), $parts );

		$ts   = $parts['ts'] ?? '';
		$hash = $parts['v1'] ?? '';

		$data   = $request->get_json_params();
		$dataId = $data['data']['id'] ?? '';

		$manifest     = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
		$expectedHash = hash_hmac( 'sha256', $manifest, $secret );

		if ( ! hash_equals( $expectedHash, $hash ) ) {
			return new WP_Error( 'wch_invalid_signature', 'Signature mismatch' );
		}

		return true;
	}

	/**
	 * Verify PayPal signature
	 *
	 * @param WP_REST_Request $request Request object
	 * @return true|WP_Error True if valid
	 */
	private function verifyPayPalSignature( WP_REST_Request $request ): true|WP_Error {
		// PayPal webhook signature verification would be implemented here
		// Requires PayPal SDK for full verification
		return true;
	}
}
