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

use WhatsAppCommerceHub\Security\RateLimiter;

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

		// Add CORS headers with security restrictions.
		$allowed_origin = $this->get_allowed_cors_origin( $request );
		if ( $allowed_origin ) {
			$response->header( 'Access-Control-Allow-Origin', $allowed_origin );
			$response->header( 'Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS' );
			$response->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, X-WCH-API-Key, X-Hub-Signature-256' );
			$response->header( 'Vary', 'Origin' );
		}

		return $response;
	}

	/**
	 * Get allowed CORS origin based on request origin.
	 *
	 * Only allows requests from:
	 * 1. Same site origin
	 * 2. Configured allowed origins from settings
	 * 3. WhatsApp/Meta domains (for webhooks)
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null Allowed origin or null if not allowed.
	 */
	protected function get_allowed_cors_origin( $request ) {
		$origin = $request->get_header( 'Origin' );

		// No origin header = same-origin request, no CORS needed.
		if ( empty( $origin ) ) {
			return null;
		}

		// Get configured allowed origins.
		$allowed_origins = $this->get_allowed_origins();

		// Check if origin is in allowed list (case-insensitive comparison).
		foreach ( $allowed_origins as $allowed ) {
			if ( strcasecmp( $origin, $allowed ) === 0 ) {
				return $origin;
			}
		}

		// Log blocked CORS request for monitoring.
		do_action( 'wch_log_warning', 'CORS request blocked', array(
			'origin'  => $origin,
			'allowed' => $allowed_origins,
		) );

		return null;
	}

	/**
	 * Get list of allowed CORS origins.
	 *
	 * @return array List of allowed origins.
	 */
	protected function get_allowed_origins() {
		// Always allow same-site origin.
		$site_url    = get_site_url();
		$parsed      = wp_parse_url( $site_url );
		$site_origin = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );
		if ( isset( $parsed['port'] ) ) {
			$site_origin .= ':' . $parsed['port'];
		}

		$allowed = array( $site_origin );

		// Add configured allowed origins from settings.
		$configured = $this->settings->get( 'api.cors_allowed_origins', '' );
		if ( ! empty( $configured ) ) {
			$additional = array_map( 'trim', explode( "\n", $configured ) );
			$additional = array_filter( $additional, function ( $origin ) {
				// Validate origin format.
				return ! empty( $origin ) && filter_var( $origin, FILTER_VALIDATE_URL );
			} );
			$allowed = array_merge( $allowed, $additional );
		}

		// WhatsApp/Meta webhook origins (if webhooks are enabled).
		// Meta webhooks typically don't send Origin headers, but include for completeness.
		$allowed[] = 'https://graph.facebook.com';
		$allowed[] = 'https://www.facebook.com';

		return array_unique( $allowed );
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
	 * Uses the database-backed RateLimiter for reliable rate limiting.
	 *
	 * @param string $endpoint_type Endpoint type ('admin' or 'webhook').
	 * @return bool|WP_Error True if within limit, WP_Error otherwise.
	 */
	public function check_rate_limit( $endpoint_type = 'admin' ) {
		// Get rate limit for this endpoint type.
		$limit = isset( $this->rate_limits[ $endpoint_type ] ) ? $this->rate_limits[ $endpoint_type ] : 100;

		// Get client identifier (IP address or API key).
		$client_id = $this->get_client_identifier();

		// Try to use the new database-backed RateLimiter if available.
		if ( function_exists( 'wch_get_container' ) ) {
			try {
				$container = wch_get_container();
				if ( $container->has( RateLimiter::class ) ) {
					$rate_limiter = $container->get( RateLimiter::class );
					// Use checkAndHit() for atomic check + record (prevents TOCTOU race condition).
					$result = $rate_limiter->checkAndHit( $client_id, $endpoint_type, $limit, 60 );

					if ( ! $result['allowed'] ) {
						return new WP_Error(
							'wch_rest_rate_limit_exceeded',
							sprintf(
								/* translators: %d: rate limit */
								__( 'Rate limit exceeded. Maximum %d requests per minute allowed.', 'whatsapp-commerce-hub' ),
								$limit
							),
							array(
								'status'     => 429,
								'remaining'  => $result['remaining'] ?? 0,
								'reset_at'   => $result['reset_at'] ?? null,
								'retry_after' => $result['retry_after'] ?? 60,
							)
						);
					}

					return true;
				}
			} catch ( \Throwable $e ) {
				// Log the error and fall back to transient-based rate limiting.
				do_action( 'wch_log_warning', sprintf(
					'Rate limiter fallback: %s',
					$e->getMessage()
				) );
			}
		}

		// Fallback to transient-based rate limiting for backward compatibility.
		return $this->check_rate_limit_legacy( $client_id, $endpoint_type, $limit );
	}

	/**
	 * Legacy transient-based rate limiting (fallback).
	 *
	 * @param string $client_id     Client identifier.
	 * @param string $endpoint_type Endpoint type.
	 * @param int    $limit         Rate limit.
	 * @return bool|WP_Error True if within limit, WP_Error otherwise.
	 */
	protected function check_rate_limit_legacy( $client_id, $endpoint_type, $limit ) {
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
	 * SECURITY: Only trusts proxy headers when request comes from a trusted proxy IP.
	 * This prevents IP spoofing attacks where attackers set X-Forwarded-For headers.
	 *
	 * @return string
	 */
	protected function get_client_ip() {
		// Get the direct connection IP first.
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';

		// Validate that remote_addr is a valid IP.
		if ( ! $this->is_valid_ip( $remote_addr ) ) {
			return '0.0.0.0';
		}

		// Only trust proxy headers if the direct connection is from a trusted proxy.
		if ( ! $this->is_trusted_proxy( $remote_addr ) ) {
			return $remote_addr;
		}

		// Check for forwarded IP (from trusted proxy/load balancer).
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ips       = array_map( 'trim', explode( ',', $forwarded ) );

			// The rightmost IP that isn't a trusted proxy is the client IP.
			// Work backwards through the chain to find the actual client.
			$client_ip = null;
			foreach ( array_reverse( $ips ) as $ip ) {
				if ( $this->is_valid_ip( $ip ) && ! $this->is_trusted_proxy( $ip ) ) {
					$client_ip = $ip;
					break;
				}
			}

			if ( $client_ip ) {
				return $client_ip;
			}
		}

		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$real_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
			if ( $this->is_valid_ip( $real_ip ) ) {
				return $real_ip;
			}
		}

		return $remote_addr;
	}

	/**
	 * Check if an IP address is from a trusted proxy.
	 *
	 * Trusted proxies include:
	 * 1. Private/local networks (behind firewall)
	 * 2. Configured trusted proxy IPs
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if trusted proxy.
	 */
	protected function is_trusted_proxy( string $ip ): bool {
		// Common private/local network ranges (typically behind firewall).
		$trusted_ranges = array(
			'127.0.0.0/8',      // Loopback.
			'10.0.0.0/8',       // Private Class A.
			'172.16.0.0/12',    // Private Class B.
			'192.168.0.0/16',   // Private Class C.
			'::1/128',          // IPv6 loopback.
			'fc00::/7',         // IPv6 private.
		);

		// Add configured trusted proxy IPs.
		$configured = $this->settings->get( 'api.trusted_proxy_ips', '' );
		if ( ! empty( $configured ) ) {
			$additional = array_map( 'trim', explode( "\n", $configured ) );
			$additional = array_filter( $additional );
			$trusted_ranges = array_merge( $trusted_ranges, $additional );
		}

		// Check if IP is in any trusted range.
		foreach ( $trusted_ranges as $range ) {
			if ( $this->ip_in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate IP address format.
	 *
	 * @param string $ip IP address to validate.
	 * @return bool True if valid IP.
	 */
	protected function is_valid_ip( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
	}

	/**
	 * Check if IP is in a CIDR range.
	 *
	 * @param string $ip    IP address to check.
	 * @param string $range CIDR range (e.g., "192.168.0.0/24" or single IP).
	 * @return bool True if IP is in range.
	 */
	protected function ip_in_range( string $ip, string $range ): bool {
		// Handle single IP.
		if ( strpos( $range, '/' ) === false ) {
			return $ip === $range;
		}

		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$bits = (int) $bits;

		// Handle IPv6.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return false;
			}

			$ip_bin     = inet_pton( $ip );
			$subnet_bin = inet_pton( $subnet );

			if ( false === $ip_bin || false === $subnet_bin ) {
				return false;
			}

			// Create mask.
			$mask = str_repeat( 'f', (int) ( $bits / 4 ) );
			if ( $bits % 4 ) {
				$mask .= dechex( 0xf << ( 4 - $bits % 4 ) & 0xf );
			}
			$mask = str_pad( $mask, 32, '0' );
			$mask = pack( 'H*', $mask );

			return ( $ip_bin & $mask ) === ( $subnet_bin & $mask );
		}

		// Handle IPv4.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ||
		     ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask = -1 << ( 32 - $bits );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
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
