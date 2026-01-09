<?php
/**
 * Validation Exception Class
 *
 * Exception for validation errors.
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
 * Class ValidationException
 *
 * Exception thrown when validation fails.
 */
class ValidationException extends WchException {

	/**
	 * Validation errors by field.
	 *
	 * @var array<string, string[]>
	 */
	protected array $errors;

	/**
	 * Constructor.
	 *
	 * @param string          $message    Exception message.
	 * @param array           $errors     Validation errors by field.
	 * @param array           $context    Additional context data.
	 * @param \Throwable|null $previous   Previous exception.
	 */
	public function __construct(
		string $message = 'Validation failed',
		array $errors = array(),
		array $context = array(),
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'validation_error', 422, $context, 0, $previous );

		$this->errors = $errors;
	}

	/**
	 * Get validation errors.
	 *
	 * @return array<string, string[]>
	 */
	public function getErrors(): array {
		return $this->errors;
	}

	/**
	 * Get errors for a specific field.
	 *
	 * @param string $field Field name.
	 * @return string[] Errors for the field.
	 */
	public function getFieldErrors( string $field ): array {
		return $this->errors[ $field ] ?? array();
	}

	/**
	 * Check if a field has errors.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	public function hasFieldError( string $field ): bool {
		return ! empty( $this->errors[ $field ] );
	}

	/**
	 * Get the first error message.
	 *
	 * @return string|null First error message or null.
	 */
	public function getFirstError(): ?string {
		foreach ( $this->errors as $fieldErrors ) {
			if ( ! empty( $fieldErrors ) ) {
				return $fieldErrors[0];
			}
		}
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function toArray( bool $includeTrace = false ): array {
		$data           = parent::toArray( $includeTrace );
		$data['errors'] = $this->errors;
		return $data;
	}

	/**
	 * Create from a single field error.
	 *
	 * @param string $field   Field name.
	 * @param string $message Error message.
	 * @return static
	 */
	public static function forField( string $field, string $message ): static {
		return new static(
			$message,
			array( $field => array( $message ) )
		);
	}

	/**
	 * Create from multiple errors.
	 *
	 * @param array<string, string|string[]> $errors Field errors (field => message or messages).
	 * @return static
	 */
	public static function withErrors( array $errors ): static {
		$normalizedErrors = array();
		foreach ( $errors as $field => $messages ) {
			$normalizedErrors[ $field ] = is_array( $messages ) ? $messages : array( $messages );
		}

		$firstError = '';
		foreach ( $normalizedErrors as $fieldErrors ) {
			if ( ! empty( $fieldErrors ) ) {
				$firstError = $fieldErrors[0];
				break;
			}
		}

		return new static(
			$firstError ?: 'Validation failed',
			$normalizedErrors
		);
	}
}
