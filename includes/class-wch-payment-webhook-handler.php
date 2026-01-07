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
					'permission_callback' => '__return_true',
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
					'permission_callback' => '__return_true',
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
	 * @param string          $gateway_id Gateway ID.
	 * @param WP_REST_Request $request    Request object.
	 * @return WP_REST_Response
	 */
	private function process_webhook( $gateway_id, $request ) {
		// Log webhook receipt.
		WCH_Logger::log(
			sprintf( 'Payment webhook received for gateway: %s', $gateway_id ),
			'info'
		);

		// Verify webhook signature if required.
		if ( ! $this->verify_webhook_signature( $gateway_id, $request ) ) {
			WCH_Logger::log( 'Webhook signature verification failed', 'error' );
			return new WP_REST_Response(
				array( 'error' => 'Invalid signature' ),
				401
			);
		}

		// Get webhook data.
		$data = $request->get_json_params();
		if ( empty( $data ) ) {
			$data = $request->get_body_params();
		}

		// Process through payment manager.
		$payment_manager = WCH_Payment_Manager::instance();
		$result          = $payment_manager->handle_webhook( $gateway_id, $data );

		if ( $result['success'] ?? false ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => $result['message'] ?? 'Webhook processed',
				),
				200
			);
		} else {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result['message'] ?? 'Webhook processing failed',
				),
				200
			);
		}
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
				// Mercado Pago verification.
				return true; // Implement if needed.

			default:
				// No verification for other gateways or if not implemented.
				return true;
		}
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

		// Verify timestamp to prevent replay attacks (tolerance: 5 minutes).
		if ( abs( time() - intval( $timestamp ) ) > 300 ) {
			return false;
		}

		// Compute expected signature.
		$signed_payload = $timestamp . '.' . $payload;
		$expected_sig   = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		// Compare signatures.
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected_sig, $sig ) ) {
				return true;
			}
		}

		return false;
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
