<?php
/**
 * Payment Gateway Webhook Handler
 *
 * Handles incoming webhooks from payment gateways.
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Payment_Webhook_Handler extends WCH_REST_Controller {

	/**
	 * Processed webhook event IDs for idempotency (per-request cache).
	 *
	 * @var array
	 */
	private $processed_events = array();

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/payment-webhook',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_payment_webhook' ),
					'permission_callback' => array( $this, 'verify_webhook_permission' ),
				),
			)
		);

		// Gateway-specific endpoints.
		register_rest_route(
			$this->namespace,
			'/payment-webhook/(?P<gateway>[a-z]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_gateway_webhook' ),
					'permission_callback' => array( $this, 'verify_webhook_permission' ),
					'args'                => array(
						'gateway' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Verify webhook permission via signature validation.
	 *
	 * This is the proper security gate - webhooks must pass signature
	 * verification before the callback is invoked.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function verify_webhook_permission( $request ) {
		// Detect gateway from route or request.
		$gateway_id = $request->get_param( 'gateway' );
		if ( ! $gateway_id ) {
			$gateway_id = $this->detect_gateway( $request );
		}

		if ( ! $gateway_id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Unable to identify payment gateway.', 'whatsapp-commerce-hub' ),
				array( 'status' => 400 )
			);
		}

		// Verify signature in permission callback (proper security gate).
		if ( ! $this->verify_webhook_signature( $gateway_id, $request ) ) {
			WCH_Logger::warning(
				'Payment webhook signature verification failed',
				array(
					'gateway' => $gateway_id,
					'ip'      => $this->get_client_ip(),
				)
			);

			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid webhook signature.', 'whatsapp-commerce-hub' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	// Note: get_client_ip() is inherited from WCH_REST_Controller which properly
	// validates trusted proxies before trusting X-Forwarded-For headers.

	/**
	 * Handle general payment webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_payment_webhook( $request ) {
		$gateway_id = $request->get_param( 'gateway' );

		if ( ! $gateway_id ) {
			// Try to detect gateway from headers or payload.
			$gateway_id = $this->detect_gateway( $request );
		}

		if ( ! $gateway_id ) {
			return new WP_REST_Response(
				array( 'error' => 'Gateway not specified' ),
				400
			);
		}

		return $this->process_webhook( $gateway_id, $request );
	}

	/**
	 * Handle gateway-specific webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_gateway_webhook( $request ) {
		$gateway_id = $request->get_param( 'gateway' );

		return $this->process_webhook( $gateway_id, $request );
	}

	/**
	 * Process webhook through payment manager.
	 *
	 * Signature verification is already done in permission callback.
	 * This method handles idempotency and actual processing.
	 *
	 * @param string          $gateway_id Gateway ID.
	 * @param WP_REST_Request $request    Request object.
	 * @return WP_REST_Response
	 */
	private function process_webhook( $gateway_id, $request ) {
		// Verify WooCommerce is available - payment webhooks require WC for order processing.
		if ( ! wch_is_woocommerce_active() ) {
			WCH_Logger::error(
				'Payment webhook received but WooCommerce is not active',
				array(
					'category' => 'webhook',
					'gateway'  => $gateway_id,
				)
			);

			return new WP_REST_Response(
				array( 'error' => 'WooCommerce is not available' ),
				503
			);
		}

		// Get webhook data.
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}

		// Extract event ID for idempotency check.
		$event_id = $this->extract_event_id( $gateway_id, $data );

		if ( $event_id ) {
			// Atomically try to claim the event for processing.
			// This prevents TOCTOU race conditions where concurrent requests could both process the same event.
			$claim_result = $this->try_claim_event( $event_id );

			if ( 'already_processed' === $claim_result ) {
				WCH_Logger::info(
					'Payment webhook already processed (idempotent)',
					array(
						'gateway'  => $gateway_id,
						'event_id' => $event_id,
					)
				);
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Event already processed',
					),
					200
				);
			}

			if ( 'already_processing' === $claim_result ) {
				WCH_Logger::info(
					'Payment webhook being processed by another request',
					array(
						'gateway'  => $gateway_id,
						'event_id' => $event_id,
					)
				);
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Event is being processed',
					),
					200
				);
			}

			// 'claimed' - we successfully claimed this event for processing.
		}

		// Log webhook receipt (mask sensitive data).
		WCH_Logger::info(
			sprintf( 'Payment webhook received for gateway: %s', $gateway_id ),
			array(
				'gateway'  => $gateway_id,
				'event_id' => $event_id ?? 'unknown',
			)
		);

		try {
			// Process through payment manager.
			$payment_manager = WCH_Payment_Manager::instance();
			$result          = $payment_manager->handle_webhook( $gateway_id, $data );

			// Mark as completed on success.
			if ( $event_id && ( $result['success'] ?? false ) ) {
				$this->mark_event_completed( $event_id );
			}

			if ( $result['success'] ?? false ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => $result['message'] ?? 'Webhook processed',
					),
					200
				);
			} else {
				// Clear processing flag on failure to allow retry.
				if ( $event_id ) {
					$this->clear_event_processing( $event_id );
				}

				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $result['message'] ?? 'Webhook processing failed',
					),
					200
				);
			}
		} catch ( Exception $e ) {
			// Clear processing flag on exception to allow retry.
			if ( $event_id ) {
				$this->clear_event_processing( $event_id );
			}

			WCH_Logger::error(
				'Payment webhook processing error',
				array(
					'gateway'  => $gateway_id,
					'event_id' => $event_id ?? 'unknown',
					'error'    => $e->getMessage(),
				)
			);

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Internal error processing webhook',
				),
				500
			);
		}
	}

	/**
	 * Extract event ID from webhook payload for idempotency.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $data       Webhook data.
	 * @return string|null Event ID or null.
	 */
	private function extract_event_id( $gateway_id, $data ) {
		switch ( $gateway_id ) {
			case 'stripe':
				return $data['id'] ?? null;

			case 'razorpay':
				return $data['payload']['payment']['entity']['id'] ?? ( $data['event'] . '_' . ( $data['created_at'] ?? time() ) );

			case 'pix':
				return isset( $data['id'] ) ? 'pix_' . $data['id'] : ( isset( $data['data']['id'] ) ? 'pix_' . $data['data']['id'] : null );

			default:
				// Generate hash of payload as fallback.
				return $gateway_id . '_' . md5( wp_json_encode( $data ) );
		}
	}

	/**
	 * Try to atomically claim an event for processing.
	 *
	 * This combines check-and-mark into a single atomic operation to prevent
	 * TOCTOU race conditions where concurrent requests could both claim the same event.
	 *
	 * @param string $event_id Event ID.
	 * @return string|false 'claimed' if successfully claimed, 'already_processed' if completed,
	 *                      'already_processing' if being processed, false on error.
	 */
	private function try_claim_event( $event_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_webhook_events';

		// Check if table exists (may not during initial setup).
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( ! $table_exists ) {
			// Fall back to transient-based approach (less reliable but works).
			$transient_key   = 'wch_webhook_' . md5( $event_id );
			$current_status  = get_transient( $transient_key );

			if ( 'completed' === $current_status ) {
				return 'already_processed';
			}
			if ( 'processing' === $current_status ) {
				return 'already_processing';
			}

			// Try to set transient - not truly atomic but best we can do with transients.
			set_transient( $transient_key, 'processing', 300 );
			return 'claimed';
		}

		// Use INSERT IGNORE to atomically claim the event.
		// If event_id has UNIQUE constraint, INSERT IGNORE will fail silently if duplicate.
		$now    = current_time( 'mysql', true );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table_name (event_id, status, created_at) VALUES (%s, %s, %s)",
				$event_id,
				'processing',
				$now
			)
		);

		if ( $result === 1 ) {
			// Successfully inserted - we claimed it.
			return 'claimed';
		}

		// Insert failed (duplicate) - check the existing status.
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM $table_name WHERE event_id = %s",
				$event_id
			)
		);

		if ( 'completed' === $status ) {
			return 'already_processed';
		}

		return 'already_processing';
	}

	/**
	 * Check if event was already processed.
	 *
	 * @deprecated Use try_claim_event() for atomic check-and-claim.
	 * @param string $event_id Event ID.
	 * @return bool True if already processed.
	 */
	private function is_event_processed( $event_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_webhook_events';

		// Check if table exists (may not during initial setup).
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( ! $table_exists ) {
			// Fall back to transient-based check.
			return (bool) get_transient( 'wch_webhook_' . md5( $event_id ) );
		}

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM $table_name WHERE event_id = %s",
				$event_id
			)
		);

		return 'completed' === $status;
	}

	/**
	 * Mark event as currently processing (prevents concurrent duplicates).
	 *
	 * @deprecated Use try_claim_event() for atomic check-and-claim.
	 * @param string $event_id Event ID.
	 */
	private function mark_event_processing( $event_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_webhook_events';

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( ! $table_exists ) {
			// Fall back to transient (short TTL).
			set_transient( 'wch_webhook_' . md5( $event_id ), 'processing', 300 );
			return;
		}

		$wpdb->replace(
			$table_name,
			array(
				'event_id'   => $event_id,
				'status'     => 'processing',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Mark event as completed.
	 *
	 * @param string $event_id Event ID.
	 */
	private function mark_event_completed( $event_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_webhook_events';

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( ! $table_exists ) {
			// Fall back to transient with longer TTL.
			set_transient( 'wch_webhook_' . md5( $event_id ), 'completed', DAY_IN_SECONDS );
			return;
		}

		$wpdb->update(
			$table_name,
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'event_id' => $event_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Clear processing flag (allows retry on failure).
	 *
	 * @param string $event_id Event ID.
	 */
	private function clear_event_processing( $event_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_webhook_events';

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( ! $table_exists ) {
			delete_transient( 'wch_webhook_' . md5( $event_id ) );
			return;
		}

		$wpdb->delete(
			$table_name,
			array(
				'event_id' => $event_id,
				'status'   => 'processing',
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Detect gateway from request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null Gateway ID or null.
	 */
	private function detect_gateway( $request ) {
		// Check for Stripe signature header.
		$stripe_signature = $request->get_header( 'Stripe-Signature' );
		if ( $stripe_signature ) {
			return 'stripe';
		}

		// Check for Razorpay webhook signature.
		$razorpay_signature = $request->get_header( 'X-Razorpay-Signature' );
		if ( $razorpay_signature ) {
			return 'razorpay';
		}

		// Check payload for gateway indicators.
		$data = $request->get_json_params();

		// Stripe events have a 'type' field.
		if ( isset( $data['type'] ) && strpos( $data['type'], 'payment_intent' ) !== false ) {
			return 'stripe';
		}

		// Razorpay events have an 'event' field.
		if ( isset( $data['event'] ) && strpos( $data['event'], 'payment.' ) !== false ) {
			return 'razorpay';
		}

		// Mercado Pago (PIX) events.
		if ( isset( $data['type'] ) && $data['type'] === 'payment' && isset( $data['data']['id'] ) ) {
			return 'pix';
		}

		return null;
	}

	/**
	 * Verify webhook signature.
	 *
	 * SECURITY: All gateways must implement signature verification.
	 * Unknown gateways are rejected by default.
	 *
	 * @param string          $gateway_id Gateway ID.
	 * @param WP_REST_Request $request    Request object.
	 * @return bool
	 */
	private function verify_webhook_signature( $gateway_id, $request ) {
		switch ( $gateway_id ) {
			case 'stripe':
				return $this->verify_stripe_signature( $request );

			case 'razorpay':
				return $this->verify_razorpay_signature( $request );

			case 'pix':
				return $this->verify_mercadopago_signature( $request );

			default:
				// SECURITY: Reject unknown gateways - no open door.
				WCH_Logger::warning(
					'Unknown payment gateway rejected',
					array( 'gateway' => $gateway_id )
				);
				return false;
		}
	}

	/**
	 * Verify Mercado Pago (PIX) webhook signature.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function verify_mercadopago_signature( $request ) {
		// Mercado Pago uses x-signature header with format: ts=...,v1=...
		$signature_header = $request->get_header( 'x-signature' );
		$request_id       = $request->get_header( 'x-request-id' );
		$webhook_secret   = get_option( 'wch_mercadopago_webhook_secret', '' );

		// If no secret configured, reject (fail secure).
		if ( empty( $webhook_secret ) ) {
			WCH_Logger::warning(
				'Mercado Pago webhook secret not configured',
				array( 'request_id' => $request_id )
			);
			return false;
		}

		if ( empty( $signature_header ) ) {
			WCH_Logger::warning(
				'Mercado Pago webhook missing signature header',
				array( 'request_id' => $request_id )
			);
			return false;
		}

		// Parse signature header (format: ts=123456789,v1=abc123...)
		$parts    = array();
		$elements = explode( ',', $signature_header );
		foreach ( $elements as $element ) {
			$kv = explode( '=', $element, 2 );
			if ( count( $kv ) === 2 ) {
				$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}

		$timestamp = $parts['ts'] ?? '';
		$v1_sig    = $parts['v1'] ?? '';

		if ( empty( $timestamp ) || empty( $v1_sig ) ) {
			return false;
		}

		// Get data.id from query string for signature validation.
		$data_id = $request->get_param( 'data.id' );
		if ( empty( $data_id ) ) {
			// Try getting from body.
			$body    = $request->get_json_params();
			$data_id = $body['data']['id'] ?? '';
		}

		// Build manifest string for signature calculation.
		// Format: id:[data.id];request-id:[x-request-id];ts:[ts];
		$manifest = sprintf(
			'id:%s;request-id:%s;ts:%s;',
			$data_id,
			$request_id ?? '',
			$timestamp
		);

		// Compute HMAC signature.
		$expected_sig = hash_hmac( 'sha256', $manifest, $webhook_secret );

		// SECURITY: Verify signature FIRST before checking timestamp.
		// This prevents attackers from probing valid timestamps without a valid signature.
		if ( ! hash_equals( $expected_sig, $v1_sig ) ) {
			WCH_Logger::warning(
				'Mercado Pago webhook signature mismatch',
				array( 'request_id' => $request_id )
			);
			return false;
		}

		// Verify timestamp AFTER signature (5-minute tolerance for replay attack prevention).
		$ts_int = intval( $timestamp );
		if ( abs( time() - $ts_int ) > 300 ) {
			WCH_Logger::warning(
				'Mercado Pago webhook timestamp expired',
				array(
					'timestamp'   => $timestamp,
					'current'     => time(),
					'request_id'  => $request_id,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Verify Stripe webhook signature.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function verify_stripe_signature( $request ) {
		$signature      = $request->get_header( 'Stripe-Signature' );
		$webhook_secret = get_option( 'wch_stripe_webhook_secret', '' );

		if ( empty( $signature ) || empty( $webhook_secret ) ) {
			return false;
		}

		$payload = $request->get_body();

		// Parse signature header.
		$elements = explode( ',', $signature );
		$sig_data = array();

		foreach ( $elements as $element ) {
			list( $key, $value ) = explode( '=', $element, 2 );
			$sig_data[ $key ]    = $value;
		}

		$timestamp  = $sig_data['t'] ?? '';
		$signatures = isset( $sig_data['v1'] ) ? array( $sig_data['v1'] ) : array();

		if ( empty( $timestamp ) || empty( $signatures ) ) {
			return false;
		}

		// Compute expected signature.
		$signed_payload = $timestamp . '.' . $payload;
		$expected_sig   = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		// SECURITY: Verify signature FIRST before checking timestamp.
		// This prevents attackers from probing valid timestamps without a valid signature.
		$signature_valid = false;
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected_sig, $sig ) ) {
				$signature_valid = true;
				break;
			}
		}

		if ( ! $signature_valid ) {
			return false;
		}

		// Verify timestamp AFTER signature (tolerance: 5 minutes).
		if ( abs( time() - intval( $timestamp ) ) > 300 ) {
			WCH_Logger::warning(
				'Stripe webhook timestamp expired',
				array(
					'timestamp' => $timestamp,
					'current'   => time(),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Verify Razorpay webhook signature.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function verify_razorpay_signature( $request ) {
		$signature      = $request->get_header( 'X-Razorpay-Signature' );
		$webhook_secret = get_option( 'wch_razorpay_webhook_secret', '' );

		if ( empty( $signature ) || empty( $webhook_secret ) ) {
			return false;
		}

		$payload      = $request->get_body();
		$expected_sig = hash_hmac( 'sha256', $payload, $webhook_secret );

		return hash_equals( $expected_sig, $signature );
	}

	/**
	 * Get the schema for webhook items.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'payment_webhook',
			'type'       => 'object',
			'properties' => array(
				'gateway'    => array(
					'description' => 'Payment gateway name',
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'event_type' => array(
					'description' => 'Webhook event type',
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'order_id'   => array(
					'description' => 'Order ID',
					'type'        => 'integer',
					'context'     => array( 'view' ),
				),
			),
		);
	}
}
