<?php
/**
 * Payment Webhook Controller
 *
 * Handles incoming webhooks from payment gateways.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Controllers;

use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Payments\PaymentGatewayRegistry;
use WhatsAppCommerceHub\Payments\Contracts\PaymentGatewayInterface;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaymentWebhookController
 *
 * REST API controller for payment gateway webhooks.
 */
class PaymentWebhookController {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'wch/v1';

	/**
	 * Webhook events table name.
	 *
	 * @var string
	 */
	private const EVENTS_TABLE = 'wch_webhook_events';

	/**
	 * Constructor.
	 *
	 * @param array<string, PaymentGatewayInterface> $gateways Payment gateways.
	 */
	public function __construct( private array $gateways = [] ) {
	}

	/**
	 * Register a payment gateway.
	 *
	 * @param PaymentGatewayInterface $gateway Payment gateway.
	 * @return void
	 */
	public function registerGateway( PaymentGatewayInterface $gateway ): void {
		$this->gateways[ $gateway->getId() ] = $gateway;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/payment-webhook',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handlePaymentWebhook' ],
					'permission_callback' => [ $this, 'verifyWebhookPermission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/payment-webhook/(?P<gateway>[a-z]+)',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handleGatewayWebhook' ],
					'permission_callback' => [ $this, 'verifyWebhookPermission' ],
					'args'                => [
						'gateway' => [
							'required' => true,
							'type'     => 'string',
						],
					],
				],
			]
		);
	}

	/**
	 * Verify webhook permission via signature validation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function verifyWebhookPermission( WP_REST_Request $request ): bool|WP_Error {
		$gatewayId = $request->get_param( 'gateway' );
		if ( ! $gatewayId ) {
			$gatewayId = $this->detectGateway( $request );
		}

		if ( ! $gatewayId ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Unable to identify payment gateway.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Get gateway instance.
		$gateway = $this->getGateway( $gatewayId );
		if ( ! $gateway ) {
			$this->log(
				'Unknown payment gateway rejected',
				[ 'gateway' => $gatewayId ],
				'warning'
			);

			return new WP_Error(
				'rest_forbidden',
				__( 'Unknown payment gateway.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Verify signature.
		$payload   = $request->get_body();
		$signature = $this->getSignatureHeader( $gatewayId, $request );

		if ( ! $gateway->verifyWebhookSignature( $payload, $signature ) ) {
			$this->log(
				'Payment webhook signature verification failed',
				[
					'gateway' => $gatewayId,
					'ip'      => $this->getClientIp(),
				],
				'warning'
			);

			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid webhook signature.', 'whatsapp-commerce-hub' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Handle general payment webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handlePaymentWebhook( WP_REST_Request $request ): WP_REST_Response {
		$gatewayId = $request->get_param( 'gateway' );

		if ( ! $gatewayId ) {
			$gatewayId = $this->detectGateway( $request );
		}

		if ( ! $gatewayId ) {
			return new WP_REST_Response(
				[ 'error' => 'Gateway not specified' ],
				400
			);
		}

		return $this->processWebhook( $gatewayId, $request );
	}

	/**
	 * Handle gateway-specific webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handleGatewayWebhook( WP_REST_Request $request ): WP_REST_Response {
		$gatewayId = $request->get_param( 'gateway' );

		return $this->processWebhook( $gatewayId, $request );
	}

	/**
	 * Maximum allowed payload size for payment webhooks (2MB).
	 *
	 * @var int
	 */
	private const MAX_PAYLOAD_SIZE = 2097152;

	/**
	 * Process webhook through payment gateway.
	 *
	 * @param string          $gatewayId Gateway ID.
	 * @param WP_REST_Request $request   Request object.
	 * @return WP_REST_Response
	 */
	private function processWebhook( string $gatewayId, WP_REST_Request $request ): WP_REST_Response {
		// SECURITY: Validate payload size FIRST to prevent DoS via memory exhaustion.
		// This must be checked before any JSON parsing or data processing.
		$body        = $request->get_body();
		$payloadSize = strlen( $body );

		if ( $payloadSize > self::MAX_PAYLOAD_SIZE ) {
			$this->log(
				'Payment webhook payload too large',
				[
					'gateway'  => $gatewayId,
					'size'     => $payloadSize,
					'max_size' => self::MAX_PAYLOAD_SIZE,
					'ip'       => $this->getClientIp(),
				],
				'warning'
			);

			return new WP_REST_Response(
				[ 'error' => 'Payload exceeds maximum allowed size' ],
				413
			);
		}

		// Verify WooCommerce is available.
		if ( ! function_exists( 'wch_is_woocommerce_active' ) || ! wch_is_woocommerce_active() ) {
			$this->log(
				'Payment webhook received but WooCommerce is not active',
				[ 'gateway' => $gatewayId ],
				'error'
			);

			return new WP_REST_Response(
				[ 'error' => 'WooCommerce is not available' ],
				503
			);
		}

		// Get webhook data.
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}

		// Extract event ID for idempotency check.
		$eventId = $this->extractEventId( $gatewayId, $data );

		if ( $eventId ) {
			$claimResult = $this->tryClaimEvent( $eventId );

			if ( 'already_processed' === $claimResult ) {
				$this->log(
					'Payment webhook already processed (idempotent)',
					[
						'gateway'  => $gatewayId,
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

			if ( 'already_processing' === $claimResult ) {
				$this->log(
					'Payment webhook being processed by another request',
					[
						'gateway'  => $gatewayId,
						'event_id' => $eventId,
					]
				);

				return new WP_REST_Response(
					[
						'success' => true,
						'message' => 'Event is being processed',
					],
					200
				);
			}
		}

		$this->log(
			sprintf( 'Payment webhook received for gateway: %s', $gatewayId ),
			[
				'gateway'  => $gatewayId,
				'event_id' => $eventId ?? 'unknown',
			]
		);

		try {
			// Get gateway and process webhook.
			$gateway = $this->getGateway( $gatewayId );
			if ( ! $gateway ) {
				throw new \Exception( 'Gateway not found' );
			}

			$signature = $this->getSignatureHeader( $gatewayId, $request );
			$result    = $gateway->handleWebhook( $data, $signature );

			// Mark as completed on success.
			if ( $eventId && $result->isSuccess() ) {
				$this->markEventCompleted( $eventId );
			}

			if ( $result->isSuccess() ) {
				return new WP_REST_Response(
					[
						'success' => true,
						'message' => $result->getMessage(),
					],
					200
				);
			} else {
				// Clear processing flag on failure to allow retry.
				if ( $eventId ) {
					$this->clearEventProcessing( $eventId );
				}

				return new WP_REST_Response(
					[
						'success' => false,
						'message' => $result->getMessage(),
					],
					200
				);
			}
		} catch ( \Exception $e ) {
			// Clear processing flag on exception.
			if ( $eventId ) {
				$this->clearEventProcessing( $eventId );
			}

			$this->log(
				'Payment webhook processing error',
				[
					'gateway'  => $gatewayId,
					'event_id' => $eventId ?? 'unknown',
					'error'    => $e->getMessage(),
				],
				'error'
			);

			return new WP_REST_Response(
				[
					'success' => false,
					'message' => 'Internal error processing webhook',
				],
				500
			);
		}
	}

	/**
	 * Get payment gateway by ID.
	 *
	 * @param string $gatewayId Gateway ID.
	 * @return PaymentGatewayInterface|null
	 */
	private function getGateway( string $gatewayId ): ?PaymentGatewayInterface {
		if ( isset( $this->gateways[ $gatewayId ] ) ) {
			return $this->gateways[ $gatewayId ];
		}

		try {
			return wch( PaymentGatewayRegistry::class )->get( $gatewayId );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Detect gateway from request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null
	 */
	private function detectGateway( WP_REST_Request $request ): ?string {
		// Check for Stripe signature header.
		if ( $request->get_header( 'Stripe-Signature' ) ) {
			return 'stripe';
		}

		// Check for Razorpay webhook signature.
		if ( $request->get_header( 'X-Razorpay-Signature' ) ) {
			return 'razorpay';
		}

		// Check payload for gateway indicators.
		$data = $request->get_json_params();

		// Stripe events.
		if ( isset( $data['type'] ) && str_contains( $data['type'], 'payment_intent' ) ) {
			return 'stripe';
		}

		// Razorpay events.
		if ( isset( $data['event'] ) && str_contains( $data['event'], 'payment.' ) ) {
			return 'razorpay';
		}

		// Mercado Pago (PIX) events.
		if ( isset( $data['type'] ) && 'payment' === $data['type'] && isset( $data['data']['id'] ) ) {
			return 'pix';
		}

		return null;
	}

	/**
	 * Get signature header for gateway.
	 *
	 * @param string          $gatewayId Gateway ID.
	 * @param WP_REST_Request $request   Request object.
	 * @return string
	 */
	private function getSignatureHeader( string $gatewayId, WP_REST_Request $request ): string {
		$headers = [
			'stripe'   => 'Stripe-Signature',
			'razorpay' => 'X-Razorpay-Signature',
			'pix'      => 'x-signature',
		];

		$headerName = $headers[ $gatewayId ] ?? '';

		return $headerName ? (string) $request->get_header( $headerName ) : '';
	}

	/**
	 * Extract event ID from webhook payload.
	 *
	 * @param string $gatewayId Gateway ID.
	 * @param array  $data      Webhook data.
	 * @return string|null
	 */
	private function extractEventId( string $gatewayId, array $data ): ?string {
		switch ( $gatewayId ) {
			case 'stripe':
				return $data['id'] ?? null;

			case 'razorpay':
				// Use entity ID if available, else build unique ID from event metadata.
				if ( isset( $data['payload']['payment']['entity']['id'] ) ) {
					return 'razorpay_' . $data['payload']['payment']['entity']['id'];
				}
				// Build unique ID from event + account + timestamp.
				$eventName = $data['event'] ?? 'unknown';
				$accountId = $data['account_id'] ?? 'unknown';
				$createdAt = $data['created_at'] ?? time();
				$payloadId = $data['payload']['payment']['entity']['id'] ?? '';
				return "razorpay_{$eventName}_{$accountId}_{$createdAt}_{$payloadId}";

			case 'pix':
				if ( isset( $data['id'] ) ) {
					return 'pix_' . $data['id'];
				}
				if ( isset( $data['data']['id'] ) ) {
					return 'pix_' . $data['data']['id'];
				}
				return null;

			default:
				// Generate stable hash from gateway + sorted payload.
				ksort( $data );
				return $gatewayId . '_' . hash( 'sha256', wp_json_encode( $data ) );
		}
	}

	/**
	 * Try to atomically claim an event for processing.
	 *
	 * @param string $eventId Event ID.
	 * @return string|false
	 */
	private function tryClaimEvent( string $eventId ) {
		global $wpdb;

		$tableName = $wpdb->prefix . self::EVENTS_TABLE;

		// Check if table exists.
		$tableExists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName )
		);

		if ( ! $tableExists ) {
			// Fall back to transients.
			$transientKey = 'wch_webhook_' . md5( $eventId );
			$status       = get_transient( $transientKey );

			if ( 'completed' === $status ) {
				return 'already_processed';
			}
			if ( 'processing' === $status ) {
				return 'already_processing';
			}

			set_transient( $transientKey, 'processing', 300 );
			return 'claimed';
		}

		// Use INSERT IGNORE for atomic claim.
		$now    = current_time( 'mysql', true );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tableName} (event_id, status, created_at, updated_at) VALUES (%s, %s, %s, %s)",
				$eventId,
				'processing',
				$now,
				$now
			)
		);

		if ( 1 === $result ) {
			return 'claimed';
		}

		// Check existing status.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, updated_at FROM {$tableName} WHERE event_id = %s",
				$eventId
			)
		);

		if ( ! $row ) {
			return 'already_processing';
		}

		if ( 'completed' === $row->status ) {
			return 'already_processed';
		}

		// Check if processing event is stale (older than 5 minutes).
		if ( 'processing' === $row->status ) {
			$updated = strtotime( $row->updated_at );
			$now     = time();

			// If stale, attempt to reclaim by updating.
			if ( ( $now - $updated ) > 300 ) {
				$wpdb->update(
					$tableName,
					[ 'updated_at' => current_time( 'mysql', true ) ],
					[ 'event_id' => $eventId, 'status' => 'processing' ],
					[ '%s' ],
					[ '%s', '%s' ]
				);

				// If we updated a row, we claimed it.
				if ( 1 === $wpdb->rows_affected ) {
					return 'claimed';
				}
			}
		}

		return 'already_processing';
	}

	/**
	 * Mark event as completed.
	 *
	 * @param string $eventId Event ID.
	 * @return void
	 */
	private function markEventCompleted( string $eventId ): void {
		global $wpdb;

		$tableName = $wpdb->prefix . self::EVENTS_TABLE;

		$tableExists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName )
		);

		if ( ! $tableExists ) {
			set_transient( 'wch_webhook_' . md5( $eventId ), 'completed', DAY_IN_SECONDS );
			return;
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			$tableName,
			[
				'status'       => 'completed',
				'completed_at' => $now,
				'updated_at'   => $now,
			],
			[ 'event_id' => $eventId ],
			[ '%s', '%s', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Clear processing flag.
	 *
	 * @param string $eventId Event ID.
	 * @return void
	 */
	private function clearEventProcessing( string $eventId ): void {
		global $wpdb;

		$tableName = $wpdb->prefix . self::EVENTS_TABLE;

		$tableExists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName )
		);

		if ( ! $tableExists ) {
			delete_transient( 'wch_webhook_' . md5( $eventId ) );
			return;
		}

		$wpdb->delete(
			$tableName,
			[
				'event_id' => $eventId,
				'status'   => 'processing',
			],
			[ '%s', '%s' ]
		);
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function getClientIp(): string {
		$trustedProxies = apply_filters( 'wch_trusted_proxies', [] );

		// Only trust X-Forwarded-For if from a trusted proxy.
		if ( ! empty( $trustedProxies ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$remoteAddr = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

			if ( in_array( $remoteAddr, $trustedProxies, true ) ) {
				if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
					$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
					$ips       = explode( ',', $forwarded );
					return trim( $ips[0] );
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @param string $level   Log level.
	 * @return void
	 */
	private function log( string $message, array $context = [], string $level = 'info' ): void {
		try {
			$logger = wch( LoggerInterface::class );
		} catch ( \Throwable $e ) {
			return;
		}

		$contextStr = $context['category'] ?? 'payments';
		unset( $context['category'] );

		$logger->log( $level, $message, $contextStr, $context );
	}

	/**
	 * Get the schema for webhook items.
	 *
	 * @return array
	 */
	public function getItemSchema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'payment_webhook',
			'type'       => 'object',
			'properties' => [
				'gateway'    => [
					'description' => 'Payment gateway name',
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'event_type' => [
					'description' => 'Webhook event type',
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'order_id'   => [
					'description' => 'Order ID',
					'type'        => 'integer',
					'context'     => [ 'view' ],
				],
			],
		];
	}
}
