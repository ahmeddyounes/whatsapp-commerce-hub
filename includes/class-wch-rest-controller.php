<?php
/**
 * REST API Base Controller
 *
 * Abstract base class for all REST API controllers.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class WCH_REST_Controller
 */
abstract class WCH_REST_Controller extends WP_REST_Controller {
	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wch/v1';

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	protected $settings;

	/**
	 * Rate limit defaults.
	 *
	 * @var array
	 */
	protected $rate_limits = array(
		'admin'   => 100,  // 100 requests per minute for admin endpoints.
		'webhook' => 1000, // 1000 requests per minute for webhook.
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = WCH_Settings::getInstance();
	}

	/**
	 * Register routes for this controller.
	 *
	 * Must be implemented by child classes.
	 */
	abstract public function register_routes();

	/**
	 * Get the item schema for this controller.
	 *
	 * Must be implemented by child classes.
	 *
	 * @return array
	 */
	abstract public function get_item_schema();

	/**
	 * Check if current user has admin permissions.
	 *
	 * @return bool|WP_Error True if has permission, WP_Error otherwise.
	 */
	public function check_admin_permission() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wch_rest_forbidden',
				__( 'You do not have permission to access this resource.', 'whatsapp-commerce-hub' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate and sanitize phone number to E.164 format.
	 *
	 * @param string $phone Phone number to validate.
	 * @return string|WP_Error Sanitized phone number or WP_Error on failure.
	 */
	public function validate_phone( $phone ) {
		// Remove all non-digit characters.
		$phone = preg_replace( '/[^0-9]/', '', $phone );

		// Check if phone number is empty.
		if ( empty( $phone ) ) {
			return new WP_Error(
				'wch_rest_invalid_phone',
				__( 'Phone number cannot be empty.', 'whatsapp-commerce-hub' ),
				array( 'status' => 400 )
			);
		}

		// E.164 format: + followed by 1-15 digits.
		// If it doesn't start with a country code, we can't validate it properly.
		// For now, we'll accept 10-15 digit numbers and add + prefix.
		if ( strlen( $phone ) < 10 || strlen( $phone ) > 15 ) {
			return new WP_Error(
				'wch_rest_invalid_phone',
				__( 'Phone number must be between 10 and 15 digits.', 'whatsapp-commerce-hub' ),
				array( 'status' => 400 )
			);
		}

		// Add + prefix for E.164 format.
		return '+' . $phone;
	}

	/**
	 * Prepare response data.
	 *
	 * @param mixed           $data    Response data.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function prepare_response( $data, $request ) {
		$response = rest_ensure_response( $data );

		// Add CORS headers if needed.
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, X-WCH-API-Key, X-Hub-Signature-256' );

		return $response;
	}

	/**
	 * Add pagination headers to response.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param int              $total    Total number of items.
	 * @param int              $per_page Number of items per page.
	 * @param int              $page     Current page number.
	 * @return WP_REST_Response
	 */
	public function add_pagination_headers( $response, $total, $per_page, $page ) {
		$total_pages = (int) ceil( $total / $per_page );

		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		// Add Link header for pagination.
		$base_url = rest_url( $this->namespace . '/' . $this->rest_base );
		$links    = array();

		if ( $page > 1 ) {
			$links['prev'] = add_query_arg( 'page', $page - 1, $base_url );
		}

		if ( $page < $total_pages ) {
			$links['next'] = add_query_arg( 'page', $page + 1, $base_url );
		}

		if ( ! empty( $links ) ) {
			$link_header = array();
			foreach ( $links as $rel => $url ) {
				$link_header[] = sprintf( '<%s>; rel="%s"', $url, $rel );
			}
			$response->header( 'Link', implode( ', ', $link_header ) );
		}

		return $response;
	}

	/**
	 * Check API key permission.
	 *
	 * Validates the X-WCH-API-Key header against stored hash.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function check_api_key_permission( $request ) {
		$api_key = $request->get_header( 'X-WCH-API-Key' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wch_rest_missing_api_key',
				__( 'API key is required. Please provide X-WCH-API-Key header.', 'whatsapp-commerce-hub' ),
				array( 'status' => 401 )
			);
		}

		// Get stored API key hash.
		$stored_hash = $this->settings->get( 'api.api_key_hash', '' );

		if ( empty( $stored_hash ) ) {
			return new WP_Error(
				'wch_rest_api_key_not_configured',
				__( 'API key authentication is not configured.', 'whatsapp-commerce-hub' ),
				array( 'status' => 500 )
			);
		}

		// Verify the API key.
		if ( ! wp_check_password( $api_key, $stored_hash ) ) {
			return new WP_Error(
				'wch_rest_invalid_api_key',
				__( 'Invalid API key.', 'whatsapp-commerce-hub' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Check webhook signature.
	 *
	 * Validates the X-Hub-Signature-256 header using webhook secret.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function check_webhook_signature( $request ) {
		$signature = $request->get_header( 'X-Hub-Signature-256' );

		if ( empty( $signature ) ) {
			return new WP_Error(
				'wch_rest_missing_signature',
				__( 'Webhook signature is required.', 'whatsapp-commerce-hub' ),
				array( 'status' => 401 )
			);
		}

		// Get webhook secret.
		$secret = $this->settings->get( 'api.webhook_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_Error(
				'wch_rest_webhook_not_configured',
				__( 'Webhook authentication is not configured.', 'whatsapp-commerce-hub' ),
				array( 'status' => 500 )
			);
		}

		// Get request body.
		$body = $request->get_body();

		// Calculate expected signature.
		$expected_signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		// Compare signatures using timing-safe comparison.
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return new WP_Error(
				'wch_rest_invalid_signature',
				__( 'Invalid webhook signature.', 'whatsapp-commerce-hub' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Check rate limit for the current request.
	 *
	 * @param string $endpoint_type Endpoint type ('admin' or 'webhook').
	 * @return bool|WP_Error True if within limit, WP_Error otherwise.
	 */
	public function check_rate_limit( $endpoint_type = 'admin' ) {
		// Get rate limit for this endpoint type.
		$limit = isset( $this->rate_limits[ $endpoint_type ] ) ? $this->rate_limits[ $endpoint_type ] : 100;

		// Get client identifier (IP address or API key).
		$client_id = $this->get_client_identifier();

		// Create transient key.
		$transient_key = 'wch_rate_limit_' . $endpoint_type . '_' . md5( $client_id );

		// Get current count.
		$count = get_transient( $transient_key );

		if ( false === $count ) {
			// First request in this minute.
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		// Check if limit exceeded.
		if ( $count >= $limit ) {
			return new WP_Error(
				'wch_rest_rate_limit_exceeded',
				sprintf(
					/* translators: %d: rate limit */
					__( 'Rate limit exceeded. Maximum %d requests per minute allowed.', 'whatsapp-commerce-hub' ),
					$limit
				),
				array( 'status' => 429 )
			);
		}

		// Increment count.
		set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Get client identifier for rate limiting.
	 *
	 * @return string
	 */
	protected function get_client_identifier() {
		// Try to get API key first.
		$api_key = isset( $_SERVER['HTTP_X_WCH_API_KEY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WCH_API_KEY'] ) ) : '';

		if ( ! empty( $api_key ) ) {
			return 'api_' . md5( $api_key );
		}

		// Fall back to IP address.
		$ip = $this->get_client_ip();

		return 'ip_' . $ip;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	protected function get_client_ip() {
		// Check for forwarded IP (from proxy/load balancer).
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ips       = explode( ',', $forwarded );
			return trim( $ips[0] );
		}

		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '0.0.0.0';
	}

	/**
	 * Prepare error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $details Additional error details.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error
	 */
	protected function prepare_error( $code, $message, $details = array(), $status = 400 ) {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				array( 'status' => $status ),
				$details
			)
		);
	}
}
