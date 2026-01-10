<?php
/**
 * Validation Response Value Object
 *
 * Represents the result of a validation operation.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\ValueObjects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ValidationResponse
 *
 * Immutable value object representing a validation operation result.
 */
final class ValidationResponse {

	/**
	 * Error severity levels.
	 */
	public const SEVERITY_ERROR   = 'error';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_INFO    = 'info';

	/**
	 * Constructor.
	 *
	 * @param bool  $is_valid  Whether validation passed.
	 * @param array $errors    Array of errors keyed by field name.
	 * @param array $warnings  Array of warnings keyed by field name.
	 * @param array $validated Validated/sanitized data.
	 * @param array $metadata  Additional validation metadata.
	 */
	public function __construct(
		public readonly bool $is_valid,
		public readonly array $errors = array(),
		public readonly array $warnings = array(),
		public readonly array $validated = array(),
		public readonly array $metadata = array(),
	) {}

	/**
	 * Create a valid response.
	 *
	 * @param array $validated Validated data.
	 * @param array $warnings  Optional warnings.
	 * @param array $metadata  Optional metadata.
	 * @return self
	 */
	public static function valid( array $validated = array(), array $warnings = array(), array $metadata = array() ): self {
		return new self(
			is_valid: true,
			validated: $validated,
			warnings: $warnings,
			metadata: $metadata,
		);
	}

	/**
	 * Create an invalid response.
	 *
	 * @param array $errors   Errors keyed by field name.
	 * @param array $warnings Optional warnings.
	 * @param array $metadata Optional metadata.
	 * @return self
	 */
	public static function invalid( array $errors, array $warnings = array(), array $metadata = array() ): self {
		return new self(
			is_valid: false,
			errors: $errors,
			warnings: $warnings,
			metadata: $metadata,
		);
	}

	/**
	 * Create a response with a single field error.
	 *
	 * @param string $field   Field name.
	 * @param string $message Error message.
	 * @return self
	 */
	public static function fieldError( string $field, string $message ): self {
		return new self(
			is_valid: false,
			errors: array( $field => $message ),
		);
	}

	/**
	 * Create a response from multiple field errors.
	 *
	 * @param array<string, string> $field_errors Field name => error message pairs.
	 * @return self
	 */
	public static function multipleErrors( array $field_errors ): self {
		return new self(
			is_valid: false,
			errors: $field_errors,
		);
	}

	/**
	 * Check if validation passed.
	 *
	 * @return bool
	 */
	public function isValid(): bool {
		return $this->is_valid;
	}

	/**
	 * Check if validation failed.
	 *
	 * @return bool
	 */
	public function isInvalid(): bool {
		return ! $this->is_valid;
	}

	/**
	 * Check if there are any errors.
	 *
	 * @return bool
	 */
	public function hasErrors(): bool {
		return ! empty( $this->errors );
	}

	/**
	 * Check if there are any warnings.
	 *
	 * @return bool
	 */
	public function hasWarnings(): bool {
		return ! empty( $this->warnings );
	}

	/**
	 * Check if a specific field has an error.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	public function hasFieldError( string $field ): bool {
		return isset( $this->errors[ $field ] );
	}

	/**
	 * Get error for a specific field.
	 *
	 * @param string $field Field name.
	 * @return string|null
	 */
	public function getFieldError( string $field ): ?string {
		return $this->errors[ $field ] ?? null;
	}

	/**
	 * Get warning for a specific field.
	 *
	 * @param string $field Field name.
	 * @return string|null
	 */
	public function getFieldWarning( string $field ): ?string {
		return $this->warnings[ $field ] ?? null;
	}

	/**
	 * Get all error messages as a flat array.
	 *
	 * @return array<string>
	 */
	public function getErrorMessages(): array {
		return array_values( $this->errors );
	}

	/**
	 * Get all warning messages as a flat array.
	 *
	 * @return array<string>
	 */
	public function getWarningMessages(): array {
		return array_values( $this->warnings );
	}

	/**
	 * Get first error message.
	 *
	 * @return string|null
	 */
	public function getFirstError(): ?string {
		$messages = $this->getErrorMessages();
		return $messages[0] ?? null;
	}

	/**
	 * Get first error field name.
	 *
	 * @return string|null
	 */
	public function getFirstErrorField(): ?string {
		$fields = array_keys( $this->errors );
		return $fields[0] ?? null;
	}

	/**
	 * Get validated value for a specific field.
	 *
	 * @param string $field   Field name.
	 * @param mixed  $default Default value if not found.
	 * @return mixed
	 */
	public function getValue( string $field, $default = null ) {
		return $this->validated[ $field ] ?? $default;
	}

	/**
	 * Get number of errors.
	 *
	 * @return int
	 */
	public function getErrorCount(): int {
		return count( $this->errors );
	}

	/**
	 * Get number of warnings.
	 *
	 * @return int
	 */
	public function getWarningCount(): int {
		return count( $this->warnings );
	}

	/**
	 * Merge with another validation response.
	 *
	 * @param ValidationResponse $other Other response to merge.
	 * @return self
	 */
	public function merge( ValidationResponse $other ): self {
		return new self(
			is_valid: $this->is_valid && $other->is_valid,
			errors: array_merge( $this->errors, $other->errors ),
			warnings: array_merge( $this->warnings, $other->warnings ),
			validated: array_merge( $this->validated, $other->validated ),
			metadata: array_merge( $this->metadata, $other->metadata ),
		);
	}

	/**
	 * Add validated data and return new instance.
	 *
	 * @param array $validated Data to add.
	 * @return self
	 */
	public function withValidated( array $validated ): self {
		return new self(
			is_valid: $this->is_valid,
			errors: $this->errors,
			warnings: $this->warnings,
			validated: array_merge( $this->validated, $validated ),
			metadata: $this->metadata,
		);
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'is_valid'      => $this->is_valid,
			'errors'        => $this->errors,
			'warnings'      => $this->warnings,
			'validated'     => $this->validated,
			'metadata'      => $this->metadata,
			'error_count'   => $this->getErrorCount(),
			'warning_count' => $this->getWarningCount(),
		);
	}

	/**
	 * Convert to JSON.
	 *
	 * @return string
	 */
	public function toJson(): string {
		return wp_json_encode( $this->toArray() );
	}

	/**
	 * Convert to WP_Error for REST API responses.
	 *
	 * @param string $error_code Error code for WP_Error.
	 * @return \WP_Error|null Returns WP_Error if invalid, null if valid.
	 */
	public function toWpError( string $error_code = 'validation_failed' ): ?\WP_Error {
		if ( $this->is_valid ) {
			return null;
		}

		return new \WP_Error(
			$error_code,
			$this->getFirstError() ?? __( 'Validation failed', 'whatsapp-commerce-hub' ),
			array(
				'status' => 400,
				'errors' => $this->errors,
			)
		);
	}

	/**
	 * Get formatted error summary.
	 *
	 * @param string $separator Separator between errors.
	 * @return string
	 */
	public function getErrorSummary( string $separator = "\n" ): string {
		$messages = array();

		foreach ( $this->errors as $field => $message ) {
			$messages[] = sprintf( '%s: %s', ucfirst( str_replace( '_', ' ', $field ) ), $message );
		}

		return implode( $separator, $messages );
	}
}
