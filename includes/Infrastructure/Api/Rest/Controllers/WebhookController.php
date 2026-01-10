<?php

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers;

use WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController;
use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * WhatsApp Webhook Controller
 *
 * Handles incoming WhatsApp webhook events with signature validation,
 * idempotency, and async processing.
 *
 * @package WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers
 */
class WebhookController extends RestController {

	/**
	 * REST base for this controller
	 */
	protected $rest_base = 'webhook';

	/**
	 * Maximum webhook payload size (1MB)
	 */
	private const MAX_PAYLOAD_SIZE = 1048576;

	/**
	 * Rate limit for webhook verification
	 */
	private const VERIFY_RATE_LIMIT = 10;

	/**
	 * Constructor
	 */
	public function __construct(
		SettingsManager $settings,
		private readonly Logger $logger
	) {
		parent::__construct( $settings );
	}

	/**
	 * Register routes for this controller
	 */
	public function registerRoutes(): void {
		// GET /webhook - Webhook verification endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'verifyWebhook' ],
				'permission_callback' => '__return_true',
			]
		);

		// POST /webhook - Webhook event receiver
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handleWebhook' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Get the item schema for this controller
	 */
	public function getItemSchema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'webhook',
			'type'       => 'object',
			'properties' => [
				'object' => [
					'description' => __( 'Webhook object type.', 'whatsapp-commerce-hub' ),
					'type'        => 'string',
				],
				'entry'  => [
					'description' => __( 'Webhook event entries.', 'whatsapp-commerce-hub' ),
					'type'        => 'array',
				],
			],
		];
	}

	/**
	 * Verify webhook for Meta setup
	 *
	 * SECURITY: Rate limited to prevent brute-force attacks on the verify token
	 */
	public function verifyWebhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Apply rate limiting BEFORE token comparison
		$rateLimitCheck = $this->checkVerificationRateLimit();
		if ( is_wp_error( $rateLimitCheck ) ) {
			$this->logger->warning(
				'Webhook verification rate limit exceeded',
				[
					'client_ip' => $this->getClientIp(),
				]
			);
			return $rateLimitCheck;
		}

		// Get query parameters
		$mode      = $request->get_param( 'hub_mode' );
		$token     = $request->get_param( 'hub_verify_token' );
		$challenge = $request->get_param( 'hub_challenge' );

		// Get stored verify token
		$storedToken = $this->settings->get( 'api.webhook_verify_token', '' );

		// Validate mode and token
		if ( $mode === 'subscribe' && ! empty( $token ) && ! empty( $storedToken ) && hash_equals( $storedToken, $token ) ) {
			$this->logger->info(
				'Webhook verification successful',
				[
					'mode'  => $mode,
					'token' => substr( $token, 0, 10 ) . '...',
				]
			);

			// Return challenge as plain text
			return new WP_REST_Response( (int) $challenge, 200 );
		}

		$this->logger->warning(
			'Webhook verification failed',
			[
				'mode'         => $mode,
				'token_match'  => ! empty( $token ) && ! empty( $storedToken ) && hash_equals( $storedToken, $token ),
				'stored_token' => ! empty( $storedToken ),
			]
		);

		return new WP_Error(
			'wch_webhook_verification_failed',
			__( 'Webhook verification failed.', 'whatsapp-commerce-hub' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Handle incoming webhook events
	 *
	 * SECURITY: Validates signature, checks idempotency, processes events asynchronously
	 */
	public function handleWebhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Validate payload size FIRST to prevent memory exhaustion DoS
		$body        = $request->get_body();
		$payloadSize = strlen( $body );

		if ( $payloadSize > self::MAX_PAYLOAD_SIZE ) {
			$this->logger->warning(
				'Webhook payload size exceeded',
				[
					'size'     => $payloadSize,
					'max_size' => self::MAX_PAYLOAD_SIZE,
				]
			);

			return new WP_Error(
				'wch_webhook_payload_too_large',
				__( 'Webhook payload exceeds maximum allowed size.', 'whatsapp-commerce-hub' ),
				[ 'status' => 413 ]
			);
		}

		// Reject empty payloads
		if ( $payloadSize === 0 ) {
			return new WP_Error(
				'wch_webhook_empty_payload',
				__( 'Webhook payload cannot be empty.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Validate webhook signature FIRST before any other processing
		$signatureCheck = $this->checkWebhookSignature( $request );
		if ( is_wp_error( $signatureCheck ) ) {
			$this->logger->error(
				'Webhook signature validation failed',
				[
					'error' => $signatureCheck->get_error_message(),
				]
			);
			return $signatureCheck;
		}

		// Check rate limit AFTER signature validation
		$rateLimitCheck = $this->checkRateLimit( 'webhook' );
		if ( is_wp_error( $rateLimitCheck ) ) {
			return $rateLimitCheck;
		}

		// Parse JSON
		$payload = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->logger->error(
				'Webhook payload JSON decode error',
				[
					'error' => json_last_error_msg(),
				]
			);

			return new WP_Error(
				'wch_webhook_invalid_json',
				__( 'Invalid JSON payload.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Validate payload structure
		if ( ! isset( $payload['object'] ) || ! isset( $payload['entry'] ) ) {
			return new WP_Error(
				'wch_webhook_invalid_payload',
				__( 'Invalid webhook payload structure.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Process webhook events
		$this->processWebhookPayload( $payload );

		// Return 200 OK immediately (async processing)
		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Check webhook signature
	 *
	 * SECURITY: Validates X-Hub-Signature-256 header using app secret
	 */
	private function checkWebhookSignature( WP_REST_Request $request ): true|WP_Error {
		$signature = $request->get_header( 'X-Hub-Signature-256' );

		if ( empty( $signature ) ) {
			return new WP_Error(
				'wch_webhook_missing_signature',
				__( 'Missing webhook signature.', 'whatsapp-commerce-hub' ),
				[ 'status' => 401 ]
			);
		}

		$appSecret = $this->settings->get( 'api.app_secret', '' );

		if ( empty( $appSecret ) ) {
			$this->logger->error( 'App secret not configured for webhook validation' );

			return new WP_Error(
				'wch_webhook_no_app_secret',
				__( 'Webhook signature validation is not configured.', 'whatsapp-commerce-hub' ),
				[ 'status' => 500 ]
			);
		}

		// Calculate expected signature
		$body              = $request->get_body();
		$expectedSignature = 'sha256=' . hash_hmac( 'sha256', $body, $appSecret );

		// Constant-time comparison to prevent timing attacks
		if ( ! hash_equals( $expectedSignature, $signature ) ) {
			$this->logger->warning(
				'Webhook signature mismatch',
				[
					'client_ip' => $this->getClientIp(),
				]
			);

			return new WP_Error(
				'wch_webhook_invalid_signature',
				__( 'Invalid webhook signature.', 'whatsapp-commerce-hub' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Process webhook payload
	 */
	private function processWebhookPayload( array $payload ): void {
		foreach ( $payload['entry'] ?? [] as $entry ) {
			$this->processEntry( $entry );
		}
	}

	/**
	 * Process webhook entry
	 */
	private function processEntry( array $entry ): void {
		$changes = $entry['changes'] ?? [];

		foreach ( $changes as $change ) {
			$value = $change['value'] ?? [];
			$field = $change['field'] ?? '';

			match ( $field ) {
				'messages' => $this->processMessages( $value ),
				'message_statuses' => $this->processStatuses( $value ),
				'errors' => $this->processErrors( $value ),
				default => $this->logger->debug( "Unknown webhook field: {$field}" ),
			};
		}
	}

	/**
	 * Process incoming messages
	 */
	private function processMessages( array $value ): void {
		$messages = $value['messages'] ?? [];

		foreach ( $messages as $message ) {
			$messageId = $message['id'] ?? '';

			// Check idempotency
			if ( ! $this->claimMessageProcessing( $messageId ) ) {
				$this->logger->debug( "Duplicate message skipped: {$messageId}" );
				continue;
			}

			// Dispatch async processing
			do_action( 'wch_webhook_message_received', $message, $value['metadata'] ?? [] );

			$this->logger->info(
				'Message queued for processing',
				[
					'message_id' => $messageId,
					'type'       => $message['type'] ?? 'unknown',
				]
			);
		}
	}

	/**
	 * Process message statuses
	 */
	private function processStatuses( array $value ): void {
		$statuses = $value['statuses'] ?? [];

		foreach ( $statuses as $status ) {
			do_action( 'wch_webhook_status_update', $status, $value['metadata'] ?? [] );

			$this->logger->debug(
				'Status update processed',
				[
					'message_id' => $status['id'] ?? '',
					'status'     => $status['status'] ?? 'unknown',
				]
			);
		}
	}

	/**
	 * Process errors
	 */
	private function processErrors( array $value ): void {
		$errors = $value['errors'] ?? [];

		foreach ( $errors as $error ) {
			do_action( 'wch_webhook_error', $error, $value['metadata'] ?? [] );

			$this->logger->error(
				'Webhook error received',
				[
					'code'  => $error['code'] ?? '',
					'title' => $error['title'] ?? '',
				]
			);
		}
	}

	/**
	 * Atomically claim exclusive processing rights for a message
	 *
	 * Uses database INSERT IGNORE with unique constraint to prevent race conditions
	 */
	private function claimMessageProcessing( string $messageId ): bool {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_webhook_idempotency';

		// Use INSERT IGNORE for atomic claim
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tableName} (message_id, processed_at) VALUES (%s, %s)",
				$messageId,
				current_time( 'mysql' )
			)
		);

		return $result === 1;
	}

	/**
	 * Check verification rate limit
	 */
	private function checkVerificationRateLimit(): true|WP_Error {
		$ip  = $this->getClientIp();
		$key = "wch_webhook_verify_{$ip}_" . date( 'YmdHi' );

		$count = (int) get_transient( $key );

		if ( $count >= self::VERIFY_RATE_LIMIT ) {
			return new WP_Error(
				'wch_webhook_verify_rate_limit',
				__( 'Rate limit exceeded for webhook verification.', 'whatsapp-commerce-hub' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Get client IP address
	 */
	private function getClientIp(): string {
		$headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ips = explode( ',', $_SERVER[ $header ] );
				return trim( $ips[0] );
			}
		}

		return 'unknown';
	}
}
