<?php
/**
 * Base Exception Class
 *
 * Custom exception with enhanced error handling properties.
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
 * Class WchException
 *
 * Base exception with error code, HTTP status, and context data.
 */
class WchException extends \Exception {

	/**
	 * Error code identifier.
	 *
	 * @var string
	 */
	protected string $errorCode;

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	protected int $httpStatus;

	/**
	 * Additional context data.
	 *
	 * @var array<string, mixed>
	 */
	protected array $context;

	/**
	 * Constructor.
	 *
	 * @param string          $message    Exception message.
	 * @param string          $errorCode  Error code identifier.
	 * @param int             $httpStatus HTTP status code.
	 * @param array           $context    Additional context data.
	 * @param int             $code       Exception code.
	 * @param \Throwable|null $previous   Previous exception.
	 */
	public function __construct(
		string $message = '',
		string $errorCode = 'unknown_error',
		int $httpStatus = 500,
		array $context = array(),
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, $code, $previous );

		$this->errorCode  = $errorCode;
		$this->httpStatus = $httpStatus;
		$this->context    = $context;
	}

	/**
	 * Get error code.
	 *
	 * @return string
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/**
	 * Get HTTP status code.
	 *
	 * @return int
	 */
	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	/**
	 * Get context data.
	 *
	 * @return array<string, mixed>
	 */
	public function getContext(): array {
		return $this->context;
	}

	/**
	 * Add context data.
	 *
	 * @param string $key   Context key.
	 * @param mixed  $value Context value.
	 * @return self
	 */
	public function withContext( string $key, $value ): self {
		$this->context[ $key ] = $value;
		return $this;
	}

	/**
	 * Convert exception to array.
	 *
	 * @param bool $includeTrace Whether to include stack trace.
	 * @return array<string, mixed>
	 */
	public function toArray( bool $includeTrace = false ): array {
		$data = array(
			'message'     => $this->getMessage(),
			'error_code'  => $this->errorCode,
			'http_status' => $this->httpStatus,
			'context'     => $this->context,
			'file'        => $this->getFile(),
			'line'        => $this->getLine(),
		);

		if ( $includeTrace || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			$data['trace'] = $this->getTraceAsString();
		}

		return $data;
	}

	/**
	 * Convert exception to JSON.
	 *
	 * @param bool $includeTrace Whether to include stack trace.
	 * @return string
	 */
	public function toJson( bool $includeTrace = false ): string {
		return (string) wp_json_encode( $this->toArray( $includeTrace ) );
	}

	/**
	 * Get exception as WP_Error.
	 *
	 * @return \WP_Error
	 */
	public function toWpError(): \WP_Error {
		return new \WP_Error(
			$this->errorCode,
			$this->getMessage(),
			array(
				'status'  => $this->httpStatus,
				'context' => $this->context,
			)
		);
	}

	/**
	 * Log this exception.
	 *
	 * @param string $level Log level.
	 * @return void
	 */
	public function log( string $level = 'error' ): void {
		$context = array_merge(
			$this->context,
			array(
				'exception'   => static::class,
				'error_code'  => $this->errorCode,
				'http_status' => $this->httpStatus,
				'file'        => $this->getFile(),
				'line'        => $this->getLine(),
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$context['trace'] = $this->getTraceAsString();
		}

		do_action( "wch_log_{$level}", $this->getMessage(), $context );
	}

	/**
	 * Create exception from WP_Error.
	 *
	 * @param \WP_Error $error WordPress error.
	 * @return static
	 */
	public static function fromWpError( \WP_Error $error ): static {
		$data       = $error->get_error_data();
		$httpStatus = isset( $data['status'] ) ? (int) $data['status'] : 500;
		$context    = isset( $data['context'] ) ? (array) $data['context'] : array();

		return new static(
			$error->get_error_message(),
			$error->get_error_code(),
			$httpStatus,
			$context
		);
	}
}
