<?php
/**
 * API Exception Class
 *
 * Exception for WhatsApp Graph API errors.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Exceptions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ApiException
 *
 * Custom exception for WhatsApp Graph API errors.
 *
 * This is an InfrastructureException because it represents failures in
 * external API calls. Many API errors (rate limits, 5xx errors) are
 * retryable, while others (auth errors, validation) are not.
 */
class ApiException extends InfrastructureException {

	/**
	 * Graph API error code.
	 *
	 * @var int|null
	 */
	protected ?int $apiErrorCode;

	/**
	 * Graph API error type.
	 *
	 * @var string|null
	 */
	protected ?string $apiErrorType;

	/**
	 * Graph API error subcode.
	 *
	 * @var int|null
	 */
	protected ?int $apiErrorSubcode;

	/**
	 * Constructor.
	 *
	 * @param string          $message         Exception message.
	 * @param int|null        $apiErrorCode    Graph API error code.
	 * @param string|null     $apiErrorType    Graph API error type.
	 * @param int|null        $apiErrorSubcode Graph API error subcode.
	 * @param int             $httpStatus      HTTP status code.
	 * @param array           $context         Additional context data.
	 * @param \Throwable|null $previous        Previous exception.
	 */
	public function __construct(
		string $message = '',
		?int $apiErrorCode = null,
		?string $apiErrorType = null,
		?int $apiErrorSubcode = null,
		int $httpStatus = 500,
		array $context = [],
		?\Throwable $previous = null
	) {
		$errorCode = 'api_error';
		if ( null !== $apiErrorCode ) {
			$errorCode = 'api_error_' . $apiErrorCode;
		}

		parent::__construct( $message, $errorCode, $httpStatus, $context, 0, $previous );

		$this->apiErrorCode    = $apiErrorCode;
		$this->apiErrorType    = $apiErrorType;
		$this->apiErrorSubcode = $apiErrorSubcode;
	}

	/**
	 * Get Graph API error code.
	 *
	 * @return int|null
	 */
	public function getApiErrorCode(): ?int {
		return $this->apiErrorCode;
	}

	/**
	 * Get Graph API error type.
	 *
	 * @return string|null
	 */
	public function getApiErrorType(): ?string {
		return $this->apiErrorType;
	}

	/**
	 * Get Graph API error subcode.
	 *
	 * @return int|null
	 */
	public function getApiErrorSubcode(): ?int {
		return $this->apiErrorSubcode;
	}

	/**
	 * Check if this is a rate limit error.
	 *
	 * @return bool
	 */
	public function isRateLimitError(): bool {
		$rateLimitCodes = [ 4, 17, 32, 613, 130429, 131048, 131056 ];
		return in_array( $this->apiErrorCode, $rateLimitCodes, true );
	}

	/**
	 * Check if this is an authentication error.
	 *
	 * @return bool
	 */
	public function isAuthError(): bool {
		$authCodes = [ 190, 200, 10, 100 ];
		return in_array( $this->apiErrorCode, $authCodes, true );
	}

	/**
	 * Check if this is a temporary error that can be retried.
	 *
	 * Infrastructure exceptions are generally retryable, but some API errors
	 * represent permanent failures (auth errors, recipient errors, etc).
	 *
	 * @return bool
	 */
	public function isRetryable(): bool {
		// Auth errors are NOT retryable (configuration issue).
		if ( $this->isAuthError() ) {
			return false;
		}

		// Rate limits are retryable (temporary throttling).
		if ( $this->isRateLimitError() ) {
			return true;
		}

		// 5xx errors are generally retryable (server-side issues).
		if ( $this->httpStatus >= 500 && $this->httpStatus < 600 ) {
			return true;
		}

		// Recipient errors (131000-131999) are NOT retryable.
		if ( $this->apiErrorCode >= 131000 && $this->apiErrorCode <= 131999 ) {
			return false;
		}

		// 4xx errors are generally NOT retryable (client errors).
		if ( $this->httpStatus >= 400 && $this->httpStatus < 500 ) {
			return false;
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function toArray( bool $includeTrace = false ): array {
		$data = parent::toArray( $includeTrace );

		$data['api_error_code']    = $this->apiErrorCode;
		$data['api_error_type']    = $this->apiErrorType;
		$data['api_error_subcode'] = $this->apiErrorSubcode;

		return $data;
	}

	/**
	 * Create from API response error.
	 *
	 * @param array $error   Error data from API response.
	 * @param int   $status  HTTP status code.
	 * @param array $context Additional context.
	 * @return static
	 */
	public static function fromApiResponse( array $error, int $status = 500, array $context = [] ): static {
		return new static(
			$error['message'] ?? 'Unknown API error',
			isset( $error['code'] ) ? (int) $error['code'] : null,
			$error['type'] ?? null,
			isset( $error['error_subcode'] ) ? (int) $error['error_subcode'] : null,
			$status,
			$context
		);
	}
}
