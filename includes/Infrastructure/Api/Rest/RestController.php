<?php
/**
 * REST Controller Base Class
 *
 * Base class for all REST API controllers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Api\Rest;

use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WP_Error;

// Ensure WP_REST_Controller is available (may not be in CLI context)
if ( ! class_exists( '\WP_REST_Controller' ) ) {
	/**
	 * Stub class for CLI context where WordPress isn't loaded
	 *
	 * @codeCoverageIgnore
	 */
	class WP_REST_Controller_Stub {
		protected $namespace;
	}
	class_alias( WP_REST_Controller_Stub::class, 'WP_REST_Controller' );
}

/**
 * REST API Base Controller
 *
 * Abstract base class for all REST API controllers with common functionality.
 *
 * @package WhatsAppCommerceHub\Infrastructure\Api\Rest
 */
abstract class RestController extends \WP_REST_Controller {

	/**
	 * REST API namespace
	 *
	 * @var string
	 */
	protected $namespace = 'wch/v1';

	/**
	 * Rate limit defaults (requests per minute)
	 */
	protected array $rateLimits = [
		'admin'   => 100,    // Admin endpoints
		'webhook' => 1000, // Webhook endpoint
	];

	/**
	 * Constructor
	 */
	public function __construct(
		protected readonly SettingsManager $settings
	) {
	}

	/**
	 * Register routes for this controller
	 *
	 * Must be implemented by child classes
	 */
	abstract public function registerRoutes(): void;

	/**
	 * Get the item schema for this controller
	 *
	 * Must be implemented by child classes
	 */
	abstract public function getItemSchema(): array;

	/**
	 * Check if current user has admin permissions
	 */
	public function checkAdminPermission(): bool|WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wch_rest_forbidden',
				__( 'You do not have permission to access this resource.', 'whatsapp-commerce-hub' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Validate and sanitize phone number to E.164 format
	 */
	public function validatePhone( string $phone ): string|WP_Error {
		// Remove all non-digit characters
		$phone = preg_replace( '/[^0-9]/', '', $phone );

		// Check if phone number is empty
		if ( empty( $phone ) ) {
			return new WP_Error(
				'wch_rest_invalid_phone',
				__( 'Phone number cannot be empty.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Validate length (E.164 allows 1-15 digits)
		if ( strlen( $phone ) < 10 || strlen( $phone ) > 15 ) {
			return new WP_Error(
				'wch_rest_invalid_phone',
				__( 'Phone number must be between 10 and 15 digits.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		// Add + prefix if not present
		if ( $phone[0] !== '+' ) {
			$phone = '+' . $phone;
		}

		return $phone;
	}

	/**
	 * Validate pagination parameters
	 */
	protected function validatePagination( array $params ): array {
		$page    = isset( $params['page'] ) ? absint( $params['page'] ) : 1;
		$perPage = isset( $params['per_page'] ) ? absint( $params['per_page'] ) : 10;

		// Enforce limits
		$page    = max( 1, $page );
		$perPage = max( 1, min( 100, $perPage ) ); // Max 100 items per page

		return [
			'page'     => $page,
			'per_page' => $perPage,
			'offset'   => ( $page - 1 ) * $perPage,
		];
	}

	/**
	 * Prepare pagination response headers
	 */
	protected function preparePaginationHeaders( int $total, int $page, int $perPage ): array {
		$totalPages = ceil( $total / $perPage );

		return [
			'X-WP-Total'      => (string) $total,
			'X-WP-TotalPages' => (string) $totalPages,
			'X-WP-Page'       => (string) $page,
			'X-WP-PerPage'    => (string) $perPage,
		];
	}

	/**
	 * Sanitize text field
	 */
	protected function sanitizeTextField( string $value ): string {
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize textarea field
	 */
	protected function sanitizeTextarea( string $value ): string {
		return sanitize_textarea_field( $value );
	}

	/**
	 * Validate email address
	 */
	protected function validateEmail( string $email ): string|WP_Error {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'wch_rest_invalid_email',
				__( 'Invalid email address.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		return $email;
	}

	/**
	 * Validate URL
	 */
	protected function validateUrl( string $url ): string|WP_Error {
		$url = esc_url_raw( $url );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'wch_rest_invalid_url',
				__( 'Invalid URL.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		return $url;
	}

	/**
	 * Validate date format
	 */
	protected function validateDate( string $date ): string|WP_Error {
		$timestamp = strtotime( $date );

		if ( $timestamp === false ) {
			return new WP_Error(
				'wch_rest_invalid_date',
				__( 'Invalid date format.', 'whatsapp-commerce-hub' ),
				[ 'status' => 400 ]
			);
		}

		return date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Check rate limit for current request
	 */
	protected function checkRateLimit( string $type = 'admin' ): bool|WP_Error {
		$limit  = $this->rateLimits[ $type ] ?? 100;
		$userId = get_current_user_id();
		$key    = "wch_rate_limit_{$type}_{$userId}_" . date( 'YmdHi' );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'wch_rest_rate_limit',
				sprintf(
					__( 'Rate limit exceeded. Maximum %d requests per minute allowed.', 'whatsapp-commerce-hub' ),
					$limit
				),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Prepare error response
	 */
	protected function prepareErrorResponse( string $code, string $message, int $status = 400, array $data = [] ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array_merge( [ 'status' => $status ], $data )
		);
	}

	/**
	 * Prepare success response
	 */
	protected function prepareSuccessResponse( mixed $data, int $status = 200 ): array {
		return [
			'success' => true,
			'data'    => $data,
			'status'  => $status,
		];
	}

	/**
	 * Log API request
	 */
	protected function logRequest( string $endpoint, array $params = [], ?int $userId = null ): void {
		if ( ! $this->settings->get( 'api.enable_logging', false ) ) {
			return;
		}

		$logger = \WCH_Logger::class;
		$logger::info(
			"API Request: {$endpoint}",
			[
				'params'     => $params,
				'user_id'    => $userId ?? get_current_user_id(),
				'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
			]
		);
	}
}
