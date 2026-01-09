<?php
/**
 * Settings Sanitizer Service
 *
 * Handles sanitization of settings values.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Admin\Settings;

use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsSanitizerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsSanitizer
 *
 * Sanitizes settings values based on type.
 */
class SettingsSanitizer implements SettingsSanitizerInterface {

	/**
	 * Boolean field keys.
	 *
	 * @var array<string>
	 */
	protected array $booleanFields = array(
		'sync_enabled',
		'include_out_of_stock',
		'cod_enabled',
		'phone_verification',
		'enabled',
		'debug_mode',
		'order_confirmation_enabled',
		'status_updates_enabled',
		'shipping_enabled',
		'abandoned_cart_enabled',
		'enable_bot',
		'require_phone_verification',
		'order_confirmation',
		'order_status_updates',
		'shipping_updates',
		'abandoned_cart_reminder',
		'enable_realtime_sync',
		'notify_low_stock',
		'auto_fix_discrepancies',
		'enable_ai',
		'discount_enabled',
	);

	/**
	 * Numeric field keys.
	 *
	 * @var array<string>
	 */
	protected array $numericFields = array(
		'cod_extra_charge',
		'min_order_amount',
		'max_order_amount',
		'abandoned_cart_delay',
		'abandoned_cart_delay_hours',
		'log_retention_days',
		'temperature',
		'ai_temperature',
		'ai_max_tokens',
		'monthly_budget_cap',
		'low_stock_threshold',
		'delay_sequence_1',
		'delay_sequence_2',
		'delay_sequence_3',
		'discount_amount',
	);

	/**
	 * Array field keys.
	 *
	 * @var array<string>
	 */
	protected array $arrayFields = array(
		'operating_hours',
		'enabled_payment_methods',
		'categories',
		'products',
	);

	/**
	 * Textarea field keys.
	 *
	 * @var array<string>
	 */
	protected array $textareaFields = array(
		'system_prompt',
		'ai_system_prompt',
		'welcome_message',
		'fallback_message',
	);

	/**
	 * Enum fields with allowed values.
	 *
	 * @var array<string, array<string>>
	 */
	protected array $enumFields = array(
		'sync_products'      => array( 'all', 'published', 'selected' ),
		'product_selection'  => array( 'all', 'categories', 'products' ),
		'ai_model'           => array( 'gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo' ),
		'model'              => array( 'gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo' ),
		'discount_type'      => array( 'percent', 'fixed' ),
	);

	/**
	 * {@inheritdoc}
	 */
	public function isBooleanField( string $key ): bool {
		return in_array( $key, $this->booleanFields, true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function isNumericField( string $key ): bool {
		return in_array( $key, $this->numericFields, true );
	}

	/**
	 * Check if a field is an array type.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function isArrayField( string $key ): bool {
		return in_array( $key, $this->arrayFields, true );
	}

	/**
	 * Check if a field is a textarea type.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function isTextareaField( string $key ): bool {
		return in_array( $key, $this->textareaFields, true );
	}

	/**
	 * Check if a field is an enum type.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function isEnumField( string $key ): bool {
		return isset( $this->enumFields[ $key ] );
	}

	/**
	 * Get allowed values for an enum field.
	 *
	 * @param string $key Setting key.
	 * @return array<string> Allowed values.
	 */
	public function getEnumValues( string $key ): array {
		return $this->enumFields[ $key ] ?? array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitize( mixed $value, string $key ): mixed {
		// Handle arrays.
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}

		// Handle checkboxes/booleans.
		if ( $this->isBooleanField( $key ) ) {
			return (bool) $value;
		}

		// Handle numeric values.
		if ( $this->isNumericField( $key ) ) {
			return floatval( $value );
		}

		// Handle text areas.
		if ( $this->isTextareaField( $key ) ) {
			return sanitize_textarea_field( (string) $value );
		}

		// Handle enum values.
		if ( $this->isEnumField( $key ) ) {
			$allowedValues = $this->getEnumValues( $key );
			if ( in_array( $value, $allowedValues, true ) ) {
				return $value;
			}
			return $allowedValues[0] ?? '';
		}

		// Default: sanitize as text.
		return sanitize_text_field( (string) $value );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitizeImportValue( string $section, string $key, mixed $value ): mixed {
		// Boolean fields.
		if ( $this->isBooleanField( $key ) ) {
			return (bool) $value;
		}

		// Numeric fields.
		if ( $this->isNumericField( $key ) ) {
			if ( ! is_numeric( $value ) ) {
				return null;
			}
			return floatval( $value );
		}

		// Array fields.
		if ( $this->isArrayField( $key ) ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			return array_map( 'sanitize_text_field', $value );
		}

		// Enum fields.
		if ( $this->isEnumField( $key ) ) {
			$allowedValues = $this->getEnumValues( $key );
			if ( ! in_array( $value, $allowedValues, true ) ) {
				return null;
			}
			return $value;
		}

		// String fields.
		if ( is_string( $value ) ) {
			// Allow HTML in specific fields (like messages).
			if ( $this->isTextareaField( $key ) ) {
				return wp_kses_post( $value );
			}
			return sanitize_text_field( $value );
		}

		// Reject other types.
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getImportableWhitelist(): array {
		return array(
			'general'       => array(
				'enable_bot',
				'business_name',
				'welcome_message',
				'fallback_message',
				'operating_hours',
				'timezone',
			),
			'catalog'       => array(
				'sync_enabled',
				'sync_products',
				'include_out_of_stock',
				'price_format',
				'currency_symbol',
			),
			'checkout'      => array(
				'enabled_payment_methods',
				'cod_enabled',
				'cod_extra_charge',
				'min_order_amount',
				'max_order_amount',
				'require_phone_verification',
			),
			'notifications' => array(
				'order_confirmation',
				'order_status_updates',
				'shipping_updates',
				'abandoned_cart_reminder',
				'abandoned_cart_delay_hours',
			),
			'inventory'     => array(
				'enable_realtime_sync',
				'low_stock_threshold',
				'notify_low_stock',
				'auto_fix_discrepancies',
			),
			'ai'            => array(
				'enable_ai',
				'ai_model',
				'ai_temperature',
				'ai_max_tokens',
				'ai_system_prompt',
				'monthly_budget_cap',
				// Note: openai_api_key is intentionally excluded (sensitive).
			),
			'recovery'      => array(
				'enabled',
				'delay_sequence_1',
				'delay_sequence_2',
				'delay_sequence_3',
				'template_sequence_1',
				'template_sequence_2',
				'template_sequence_3',
				'discount_enabled',
				'discount_type',
				'discount_amount',
			),
			// Note: 'api' section is intentionally excluded (contains sensitive credentials).
		);
	}

	/**
	 * Validate settings for a specific section.
	 *
	 * @param string $section Section name.
	 * @param array  $values  Values to validate.
	 * @return array{valid: bool, errors: array} Validation result.
	 */
	public function validateSection( string $section, array $values ): array {
		$errors = array();

		foreach ( $values as $key => $value ) {
			$validationResult = $this->validateField( $section, $key, $value );
			if ( ! $validationResult['valid'] ) {
				$errors[ $key ] = $validationResult['error'];
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Validate a single field.
	 *
	 * @param string $section Section name.
	 * @param string $key     Field key.
	 * @param mixed  $value   Field value.
	 * @return array{valid: bool, error: string|null} Validation result.
	 */
	protected function validateField( string $section, string $key, mixed $value ): array {
		// Enum validation.
		if ( $this->isEnumField( $key ) ) {
			$allowedValues = $this->getEnumValues( $key );
			if ( ! in_array( $value, $allowedValues, true ) ) {
				return array(
					'valid' => false,
					'error' => sprintf(
						/* translators: %s: allowed values */
						__( 'Invalid value. Allowed: %s', 'whatsapp-commerce-hub' ),
						implode( ', ', $allowedValues )
					),
				);
			}
		}

		// Numeric range validation.
		if ( $this->isNumericField( $key ) ) {
			$numericValue = floatval( $value );

			// Specific range validations.
			switch ( $key ) {
				case 'temperature':
				case 'ai_temperature':
					if ( $numericValue < 0 || $numericValue > 1 ) {
						return array(
							'valid' => false,
							'error' => __( 'Temperature must be between 0 and 1', 'whatsapp-commerce-hub' ),
						);
					}
					break;

				case 'log_retention_days':
					if ( $numericValue < 1 || $numericValue > 365 ) {
						return array(
							'valid' => false,
							'error' => __( 'Log retention must be between 1 and 365 days', 'whatsapp-commerce-hub' ),
						);
					}
					break;

				case 'abandoned_cart_delay':
				case 'abandoned_cart_delay_hours':
					if ( $numericValue < 1 || $numericValue > 168 ) {
						return array(
							'valid' => false,
							'error' => __( 'Abandoned cart delay must be between 1 and 168 hours', 'whatsapp-commerce-hub' ),
						);
					}
					break;
			}
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}
}
