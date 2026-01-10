<?php
/**
 * Abstract REST Controller
 *
 * Base class for all REST API controllers with common functionality.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Controllers;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WhatsAppCommerceHub\Security\RateLimiter;
use WhatsAppCommerceHub\Application\Services\SettingsService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractController
 *
 * Provides common functionality for REST API controllers.
 */
abstract class AbstractController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected string $apiNamespace = 'wch/v1';

	/**
	 * Settings service.
	 *
	 * @var SettingsService|null
	 */
	protected ?SettingsService $settings = null;

	/**
	 * Rate limiter service.
	 *
	 * @var RateLimiter|null
	 */
	protected ?RateLimiter $rateLimiter = null;

	/**
	 * Rate limit defaults per endpoint type.
	 *
	 * @var array<string, int>
	 */
	protected array $rateLimits = array(
		'admin'   => 100,  // 100 requests per minute for admin endpoints.
		'webhook' => 1000, // 1000 requests per minute for webhook.
		'public'  => 60,   // 60 requests per minute for public endpoints.
	);

	/**
	 * Constructor.
	 *
	 * @param SettingsService|null $settings    Settings service.
	 * @param RateLimiter|null     $rateLimiter Rate limiter service.
	 */
	public function __construct( ?SettingsService $settings = null, ?RateLimiter $rateLimiter = null ) {
		$this->settings    = $settings;
		$this->rateLimiter = $rateLimiter;
	}

	/**
	 * Register routes for this controller.
	 *
	 * @return void
	 */
	abstract public function registerRoutes(): void;

	/**
	 * Get the item schema for this controller.
	 *
	 * @return array
	 */
	abstract public function getItemSchema(): array;

	/**
	 * Check if current user has admin permissions.
	 *
	 * @return bool|WP_Error True if has permission, WP_Error otherwise.
	 */
	public function checkAdminPermission() {
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
	public function validatePhone( string $phone ) {
		// Remove all non-digit characters.
		$phone = preg_replace( '/[^0-9]/', '', $phone );

		if ( empty( $phone ) ) {
			return new WP_Error(
				'wch_rest_invalid_phone',
				__( 'Phone number cannot be empty.', 'whatsapp-commerce-hub' ),
				array( 'status' => 400 )
			);
		}

		// E.164 format: + followed by 1-15 digits.
		if ( strlen( $phone ) < 10 || strlen( $phone ) > 15 ) {
			return new WP_Error(
				'wch_rest_invalid_phone',
				__( 'Phone number must be between 10 and 15 digits.', 'whatsapp-commerce-hub' ),
				array( 'status' => 400 )
			);
		}

		return '+' . $phone;
	}

	/**
	 * Prepare response data with CORS headers.
	 *
	 * @param mixed           $data    Response data.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function prepareResponse( $data, WP_REST_Request $request ): WP_REST_Response {
		$response = rest_ensure_response( $data );

		// Add CORS headers with security restrictions.
		$allowedOrigin = $this->getAllowedCorsOrigin( $request );
		if ( $allowedOrigin ) {
			$response->header( 'Access-Control-Allow-Origin', $allowedOrigin );
			$response->header( 'Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS' );
			$response->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization, X-WCH-API-Key, X-Hub-Signature-256' );
			$response->header( 'Vary', 'Origin' );
		}

		return $response;
	}

	/**
	 * Get allowed CORS origin based on request origin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null Allowed origin or null if not allowed.
	 */
	protected function getAllowedCorsOrigin( WP_REST_Request $request ): ?string {
		$origin = $request->get_header( 'Origin' );

		if ( empty( $origin ) ) {
			return null;
		}

		$allowedOrigins = $this->getAllowedOrigins();

		foreach ( $allowedOrigins as $allowed ) {
			if ( strcasecmp( $origin, $allowed ) === 0 ) {
				return $origin;
			}
		}

		$this->log( 'CORS request blocked', array( 'origin' => $origin ), 'warning' );

		return null;
	}

	/**
	 * Get list of allowed CORS origins.
	 *
	 * @return array<string>
	 */
	protected function getAllowedOrigins(): array {
		$siteUrl = get_site_url();
		$parsed  = wp_parse_url( $siteUrl );
		$scheme  = $parsed['scheme'] ?? 'https';
		$host    = $parsed['host'] ?? '';
		$origin  = "{$scheme}://{$host}";

		if ( isset( $parsed['port'] ) ) {
			$origin .= ':' . $parsed['port'];
		}

		$allowed = array( $origin );

		// Add configured allowed origins.
		if ( $this->settings ) {
			$configured = $this->settings->get( 'api.cors_allowed_origins', '' );
			if ( ! empty( $configured ) ) {
				$additional = array_map( 'trim', explode( "\n", $configured ) );
				$additional = array_filter( $additional, fn( $o ) => ! empty( $o ) && filter_var( $o, FILTER_VALIDATE_URL ) );
				$allowed    = array_merge( $allowed, $additional );
			}
		}

		// WhatsApp/Meta webhook origins.
		$allowed[] = 'https://graph.facebook.com';
		$allowed[] = 'https://www.facebook.com';

		return array_unique( $allowed );
	}

	/**
	 * Add pagination headers to response.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param int              $total    Total number of items.
	 * @param int              $perPage  Number of items per page.
	 * @param int              $page     Current page number.
	 * @return WP_REST_Response
	 */
	public function addPaginationHeaders( WP_REST_Response $response, int $total, int $perPage, int $page ): WP_REST_Response {
		$totalPages = (int) ceil( $total / $perPage );

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $totalPages );

		$baseUrl = rest_url( $this->apiNamespace . '/' . $this->rest_base );
		$links   = array();

		if ( $page > 1 ) {
			$links['prev'] = add_query_arg( 'page', $page - 1, $baseUrl );
		}

		if ( $page < $totalPages ) {
			$links['next'] = add_query_arg( 'page', $page + 1, $baseUrl );
		}

		if ( ! empty( $links ) ) {
			$linkHeader = array();
			foreach ( $links as $rel => $url ) {
				$linkHeader[] = sprintf( '<%s>; rel="%s"', $url, $rel );
			}
			$response->header( 'Link', implode( ', ', $linkHeader ) );
		}

		return $response;
	}

	/**
	 * Check API key permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function checkApiKeyPermission( WP_REST_Request $request ) {
		$apiKey = $request->get_header( 'X-WCH-API-Key' );

		if ( empty( $apiKey ) ) {
			return new WP_Error(
				'wch_rest_missing_api_key',
				__( 'API key is required. Please provide X-WCH-API-Key header.', 'whatsapp-commerce-hub' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->settings ) {
			return new WP_Error(
				'wch_rest_api_key_not_configured',
				__( 'API key authentication is not configured.', 'whatsapp-commerce-hub' ),
				array( 'status' => 500 )
			);
		}

		$storedHash = $this->settings->get( 'api.api_key_hash', '' );

		if ( empty( $storedHash ) ) {
			return new WP_Error(
				'wch_rest_api_key_not_configured',
				__( 'API key authentication is not configured.', 'whatsapp-commerce-hub' ),
				array( 'status' => 500 )
			);
		}

		if ( ! wp_check_password( $apiKey, $storedHash ) ) {
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
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function checkWebhookSignature( WP_REST_Request $request ) {
		$signature = $request->get_header( 'X-Hub-Signature-256' );

		if ( empty( $signature ) ) {
			return new WP_Error(
				'wch_rest_missing_signature',
				__( 'Webhook signature is required.', 'whatsapp-commerce-hub' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->settings ) {
			return new WP_Error(
				'wch_rest_webhook_not_configured',
				__( 'Webhook authentication is not configured.', 'whatsapp-commerce-hub' ),
				array( 'status' => 500 )
			);
		}

		$secret = $this->settings->get( 'api.webhook_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_Error(
				'wch_rest_webhook_not_configured',
				__( 'Webhook authentication is not configured.', 'whatsapp-commerce-hub' ),
				array( 'status' => 500 )
			);
		}

		$body              = $request->get_body();
		$expectedSignature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expectedSignature, $signature ) ) {
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
	 * @param string $endpointType Endpoint type ('admin', 'webhook', or 'public').
	 * @return bool|WP_Error True if within limit, WP_Error otherwise.
	 */
	public function checkRateLimit( string $endpointType = 'admin' ) {
		$limit    = $this->rateLimits[ $endpointType ] ?? 100;
		$clientId = $this->getClientIdentifier();

		// Use database-backed RateLimiter if available.
		if ( $this->rateLimiter ) {
			$result = $this->rateLimiter->checkAndHit( $clientId, $endpointType, $limit, 60 );

			if ( ! $result['allowed'] ) {
				return new WP_Error(
					'wch_rest_rate_limit_exceeded',
					sprintf(
						/* translators: %d: rate limit */
						__( 'Rate limit exceeded. Maximum %d requests per minute allowed.', 'whatsapp-commerce-hub' ),
						$limit
					),
					array(
						'status'      => 429,
						'remaining'   => $result['remaining'] ?? 0,
						'reset_at'    => $result['reset_at'] ?? null,
						'retry_after' => $result['retry_after'] ?? 60,
					)
				);
			}

			return true;
		}

		// Fallback to transient-based rate limiting.
		return $this->checkRateLimitLegacy( $clientId, $endpointType, $limit );
	}

	/**
	 * Legacy transient-based rate limiting (fallback).
	 *
	 * @param string $clientId     Client identifier.
	 * @param string $endpointType Endpoint type.
	 * @param int    $limit        Rate limit.
	 * @return bool|WP_Error True if within limit, WP_Error otherwise.
	 */
	protected function checkRateLimitLegacy( string $clientId, string $endpointType, int $limit ) {
		$transientKey = 'wch_rate_limit_' . $endpointType . '_' . md5( $clientId );
		$count        = get_transient( $transientKey );

		if ( false === $count ) {
			set_transient( $transientKey, 1, MINUTE_IN_SECONDS );
			return true;
		}

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

		set_transient( $transientKey, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Get client identifier for rate limiting.
	 *
	 * @return string
	 */
	protected function getClientIdentifier(): string {
		$apiKey = isset( $_SERVER['HTTP_X_WCH_API_KEY'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WCH_API_KEY'] ) )
			: '';

		if ( ! empty( $apiKey ) ) {
			return 'api_' . md5( $apiKey );
		}

		return 'ip_' . $this->getClientIp();
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	protected function getClientIp(): string {
		$remoteAddr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';

		if ( ! $this->isValidIp( $remoteAddr ) ) {
			return '0.0.0.0';
		}

		if ( ! $this->isTrustedProxy( $remoteAddr ) ) {
			return $remoteAddr;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ips       = array_map( 'trim', explode( ',', $forwarded ) );

			foreach ( array_reverse( $ips ) as $ip ) {
				if ( $this->isValidIp( $ip ) && ! $this->isTrustedProxy( $ip ) ) {
					return $ip;
				}
			}
		}

		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$realIp = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
			if ( $this->isValidIp( $realIp ) ) {
				return $realIp;
			}
		}

		return $remoteAddr;
	}

	/**
	 * Check if an IP address is from a trusted proxy.
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if trusted proxy.
	 */
	protected function isTrustedProxy( string $ip ): bool {
		$trustedRanges = array(
			'127.0.0.0/8',
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'::1/128',
			'fc00::/7',
		);

		if ( $this->settings ) {
			$configured = $this->settings->get( 'api.trusted_proxy_ips', '' );
			if ( ! empty( $configured ) ) {
				$additional    = array_filter( array_map( 'trim', explode( "\n", $configured ) ) );
				$trustedRanges = array_merge( $trustedRanges, $additional );
			}
		}

		foreach ( $trustedRanges as $range ) {
			if ( $this->ipInRange( $ip, $range ) ) {
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
	protected function isValidIp( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
	}

	/**
	 * Check if IP is in a CIDR range.
	 *
	 * @param string $ip    IP address to check.
	 * @param string $range CIDR range.
	 * @return bool True if IP is in range.
	 */
	protected function ipInRange( string $ip, string $range ): bool {
		if ( ! str_contains( $range, '/' ) ) {
			return $ip === $range;
		}

		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$bits                  = (int) $bits;

		// IPv6
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return false;
			}

			$ipBin     = inet_pton( $ip );
			$subnetBin = inet_pton( $subnet );

			if ( false === $ipBin || false === $subnetBin ) {
				return false;
			}

			$mask = str_repeat( 'f', (int) ( $bits / 4 ) );
			if ( $bits % 4 ) {
				$mask .= dechex( 0xf << ( 4 - $bits % 4 ) & 0xf );
			}
			$mask = str_pad( $mask, 32, '0' );
			$mask = pack( 'H*', $mask );

			return ( $ipBin & $mask ) === ( $subnetBin & $mask );
		}

		// IPv4
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ||
			! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		$ipLong     = ip2long( $ip );
		$subnetLong = ip2long( $subnet );

		if ( false === $ipLong || false === $subnetLong ) {
			return false;
		}

		$mask = -1 << ( 32 - $bits );

		return ( $ipLong & $mask ) === ( $subnetLong & $mask );
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
	protected function prepareError( string $code, string $message, array $details = array(), int $status = 400 ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array_merge( array( 'status' => $status ), $details )
		);
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @param string $level   Log level.
	 * @return void
	 */
	protected function log( string $message, array $context = array(), string $level = 'info' ): void {
		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $context, $level );
		}
	}
}
