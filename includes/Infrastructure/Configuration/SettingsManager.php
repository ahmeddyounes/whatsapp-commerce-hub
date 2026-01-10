<?php
/**
 * Settings Manager
 *
 * Centralized settings management with encryption support and validation.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Configuration;

use WhatsAppCommerceHub\Infrastructure\Security\Encryption;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsManager
 *
 * Manages plugin settings with encryption, validation, and caching.
 */
class SettingsManager {

	/**
	 * Option name for storing all settings.
	 */
	private const OPTION_NAME = 'wch_settings';

	/**
	 * Encryption service.
	 */
	private Encryption $encryption;

	/**
	 * Cached settings.
	 */
	private ?array $cache = null;

	/**
	 * Fields that should be encrypted.
	 */
	private array $encryptedFields = [
		'api.access_token',
		'api.webhook_secret',
		'api.webhook_verify_token',
		'ai.openai_api_key',
	];

	/**
	 * Constructor.
	 *
	 * @param Encryption $encryption Encryption service for sensitive data.
	 */
	public function __construct( Encryption $encryption ) {
		$this->encryption = $encryption;

		// Clear cache when settings are updated externally.
		add_action( 'update_option_' . self::OPTION_NAME, [ $this, 'clearCache' ], 10, 0 );
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key in format 'section.key'.
	 * @param mixed  $default Default value if setting doesn't exist.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->getAll();
		$parts    = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return $default;
		}

		list( $section, $settingKey ) = $parts;

		// Check if the setting exists.
		if ( ! isset( $settings[ $section ][ $settingKey ] ) ) {
			// Get default from filter or provided default.
			$defaults = $this->getDefaults();
			if ( isset( $defaults[ $section ][ $settingKey ] ) ) {
				return $defaults[ $section ][ $settingKey ];
			}
			return $default;
		}

		$value = $settings[ $section ][ $settingKey ];

		// Decrypt if this is an encrypted field.
		if ( $this->isEncryptedField( $key ) && ! empty( $value ) ) {
			$decrypted = $this->encryption->decrypt( $value );
			if ( false === $decrypted ) {
				// Decryption failed - likely key rotation or data corruption.
				// Return false instead of ciphertext to prevent using encrypted data as credentials.
				error_log(
					sprintf(
						'SettingsManager: Failed to decrypt field %s - possible key rotation or data corruption',
						$key
					)
				);
				return false;
			}
			return $decrypted;
		}

		return $value;
	}

	/**
	 * Set a setting value.
	 *
	 * @param string $key   Setting key in format 'section.key'.
	 * @param mixed  $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, $value ): bool {
		$parts = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return false;
		}

		list( $section, $settingKey ) = $parts;

		// Validate the value type.
		if ( ! $this->validateSetting( $key, $value ) ) {
			return false;
		}

		// Get current settings.
		$settings = $this->getAll();

		// Ensure section exists.
		if ( ! isset( $settings[ $section ] ) ) {
			$settings[ $section ] = [];
		}

		// Encrypt if this is an encrypted field.
		if ( $this->isEncryptedField( $key ) && ! empty( $value ) ) {
			$encrypted = $this->encryption->encrypt( $value );
			if ( false === $encrypted ) {
				// Encryption failed - reject the write to prevent storing plaintext credentials.
				error_log(
					sprintf(
						'SettingsManager: CRITICAL - Failed to encrypt sensitive field %s, rejecting write',
						$key
					)
				);
				return false;
			}
			$value = $encrypted;
		}

		// Set the value.
		$settings[ $section ][ $settingKey ] = $value;

		// Clear cache BEFORE database write to prevent race conditions.
		$this->cache = null;

		// Save to database.
		$result = update_option( self::OPTION_NAME, $settings );

		if ( ! $result ) {
			error_log(
				sprintf(
					'SettingsManager: Failed to update setting %s - check database permissions',
					$key
				)
			);
		}

		return $result;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function getAll(): array {
		// Return cached settings if available.
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		// Get settings from database.
		$settings = get_option( self::OPTION_NAME, [] );

		// Ensure it's an array.
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		// Cache the settings.
		$this->cache = $settings;

		return $settings;
	}

	/**
	 * Delete a setting.
	 *
	 * @param string $key Setting key in format 'section.key'.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $key ): bool {
		$parts = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return false;
		}

		list( $section, $settingKey ) = $parts;

		// Get current settings.
		$settings = $this->getAll();

		// Check if the setting exists.
		if ( ! isset( $settings[ $section ][ $settingKey ] ) ) {
			return false;
		}

		// Delete the setting.
		unset( $settings[ $section ][ $settingKey ] );

		// If section is empty, remove it.
		if ( empty( $settings[ $section ] ) ) {
			unset( $settings[ $section ] );
		}

		// Clear cache BEFORE database write to prevent race conditions.
		$this->cache = null;

		// Save to database.
		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Get all settings in a section.
	 *
	 * @param string $section Section name.
	 * @return array
	 */
	public function getSection( string $section ): array {
		$settings = $this->getAll();

		if ( ! isset( $settings[ $section ] ) ) {
			// Return defaults for this section.
			$defaults = $this->getDefaults();
			return $defaults[ $section ] ?? [];
		}

		// Decrypt encrypted fields in this section using get() for consistency.
		$sectionSettings = [];
		foreach ( $settings[ $section ] as $key => $value ) {
			$fullKey                 = $section . '.' . $key;
			$sectionSettings[ $key ] = $this->get( $fullKey, $value );
		}

		return $sectionSettings;
	}

	/**
	 * Check if a setting exists.
	 *
	 * @param string $key Setting key in format 'section.key'.
	 * @return bool
	 */
	public function has( string $key ): bool {
		// Use a unique sentinel to detect if key exists.
		$sentinel = new \stdClass();
		$value    = $this->get( $key, $sentinel );

		return $value !== $sentinel;
	}

	/**
	 * Check if API is configured.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		$accessToken   = $this->get( 'api.access_token', '' );
		$phoneNumberId = $this->get( 'api.whatsapp_phone_number_id', '' );

		return ! empty( $accessToken ) && ! empty( $phoneNumberId );
	}

	/**
	 * Get API credentials.
	 *
	 * @return array{access_token: string, phone_number_id: string, business_account_id: string}
	 */
	public function getApiCredentials(): array {
		return [
			'access_token'        => $this->get( 'api.access_token', '' ),
			'phone_number_id'     => $this->get( 'api.whatsapp_phone_number_id', '' ),
			'business_account_id' => $this->get( 'api.whatsapp_business_account_id', '' ),
		];
	}

	/**
	 * Clear the settings cache.
	 *
	 * @return void
	 */
	public function clearCache(): void {
		$this->cache = null;
	}

	/**
	 * Check if a field should be encrypted.
	 *
	 * @param string $key Setting key in format 'section.key'.
	 * @return bool
	 */
	private function isEncryptedField( string $key ): bool {
		return in_array( $key, $this->encryptedFields, true );
	}

	/**
	 * Validate a setting value.
	 *
	 * @param string $key   Setting key in format 'section.key'.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	private function validateSetting( string $key, $value ): bool {
		$schema = $this->getSchema();
		$parts  = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return false;
		}

		list( $section, $settingKey ) = $parts;

		// If schema doesn't define this setting, allow it.
		if ( ! isset( $schema[ $section ][ $settingKey ] ) ) {
			return true;
		}

		$expectedType = $schema[ $section ][ $settingKey ];

		// Validate based on expected type.
		return match ( $expectedType ) {
			'bool', 'boolean' => is_bool( $value ),
			'int', 'integer' => is_int( $value ),
			'float', 'double' => is_float( $value ) || is_int( $value ),
			'string' => is_string( $value ),
			'array' => is_array( $value ),
			'json' => is_string( $value ) || is_array( $value ),
			default => true,
		};
	}

	/**
	 * Get the settings schema (type definitions).
	 *
	 * @return array
	 */
	private function getSchema(): array {
		return [
			'api'           => [
				'whatsapp_phone_number_id'     => 'string',
				'whatsapp_business_account_id' => 'string',
				'access_token'                 => 'string',
				'webhook_verify_token'         => 'string',
				'webhook_secret'               => 'string',
				'api_key_hash'                 => 'string',
				'api_version'                  => 'string',
			],
			'general'       => [
				'enable_bot'       => 'bool',
				'business_name'    => 'string',
				'welcome_message'  => 'string',
				'fallback_message' => 'string',
				'operating_hours'  => 'json',
				'timezone'         => 'string',
			],
			'catalog'       => [
				'catalog_id'           => 'string',
				'sync_enabled'         => 'bool',
				'sync_products'        => 'array',
				'include_out_of_stock' => 'bool',
				'price_format'         => 'string',
				'currency_symbol'      => 'string',
			],
			'checkout'      => [
				'enabled_payment_methods'    => 'array',
				'cod_enabled'                => 'bool',
				'cod_extra_charge'           => 'float',
				'min_order_amount'           => 'float',
				'max_order_amount'           => 'float',
				'require_phone_verification' => 'bool',
			],
			'notifications' => [
				'order_confirmation'         => 'bool',
				'order_status_updates'       => 'bool',
				'shipping_updates'           => 'bool',
				'abandoned_cart_reminder'    => 'bool',
				'abandoned_cart_delay_hours' => 'int',
			],
			'inventory'     => [
				'enable_realtime_sync'   => 'bool',
				'low_stock_threshold'    => 'int',
				'notify_low_stock'       => 'bool',
				'auto_fix_discrepancies' => 'bool',
			],
			'ai'            => [
				'enable_ai'          => 'bool',
				'openai_api_key'     => 'string',
				'ai_model'           => 'string',
				'ai_temperature'     => 'float',
				'ai_max_tokens'      => 'int',
				'ai_system_prompt'   => 'string',
				'monthly_budget_cap' => 'float',
			],
		];
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	private function getDefaults(): array {
		// Safe defaults that handle missing WordPress/WooCommerce functions.
		$businessName   = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'My Store';
		$timezone       = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		$priceFormat    = function_exists( 'get_woocommerce_price_format' ) ? get_woocommerce_price_format() : '%1$s%2$s';
		$currencySymbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

		$defaults = [
			'api'           => [
				'api_version' => 'v18.0',
			],
			'general'       => [
				'enable_bot'       => false,
				'business_name'    => $businessName,
				'welcome_message'  => __( 'Welcome! How can we help you today?', 'whatsapp-commerce-hub' ),
				'fallback_message' => __(
					'Sorry, I didn\'t understand that. Please try again or type "help" for assistance.',
					'whatsapp-commerce-hub'
				),
				'operating_hours'  => [],
				'timezone'         => $timezone,
			],
			'catalog'       => [
				'sync_enabled'         => false,
				'sync_products'        => 'all',
				'include_out_of_stock' => false,
				'price_format'         => $priceFormat,
				'currency_symbol'      => $currencySymbol,
			],
			'checkout'      => [
				'enabled_payment_methods'    => [],
				'cod_enabled'                => false,
				'cod_extra_charge'           => 0.0,
				'min_order_amount'           => 0.0,
				'max_order_amount'           => 0.0,
				'require_phone_verification' => false,
			],
			'notifications' => [
				'order_confirmation'         => true,
				'order_status_updates'       => true,
				'shipping_updates'           => false,
				'abandoned_cart_reminder'    => false,
				'abandoned_cart_delay_hours' => 24,
			],
			'inventory'     => [
				'enable_realtime_sync'   => false,
				'low_stock_threshold'    => 5,
				'notify_low_stock'       => false,
				'auto_fix_discrepancies' => false,
			],
			'ai'            => [
				'enable_ai'          => false,
				'ai_model'           => 'gpt-4',
				'ai_temperature'     => 0.7,
				'ai_max_tokens'      => 500,
				'ai_system_prompt'   => __( 'You are a helpful customer service assistant for an e-commerce store.', 'whatsapp-commerce-hub' ),
				'monthly_budget_cap' => 0.0,
			],
			'recovery'      => [
				'enabled'             => false,
				'delay_sequence_1'    => 4,
				'delay_sequence_2'    => 24,
				'delay_sequence_3'    => 48,
				'template_sequence_1' => null,
				'template_sequence_2' => null,
				'template_sequence_3' => null,
				'discount_enabled'    => false,
				'discount_type'       => 'percent',
				'discount_amount'     => 10,
			],
		];

		/**
		 * Filter default settings.
		 *
		 * @param array $defaults Default settings array.
		 */
		return apply_filters( 'wch_settings_defaults', $defaults );
	}
}
