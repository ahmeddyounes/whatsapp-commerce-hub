<?php
/**
 * Settings Management Class
 *
 * Handles centralized settings management with encryption support.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Settings
 */
class WCH_Settings {
	/**
	 * Option name for storing all settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wch_settings';

	/**
	 * Encryption helper instance.
	 *
	 * @var WCH_Encryption
	 */
	private $encryption;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	/**
	 * Fields that should be encrypted.
	 *
	 * @var array
	 */
	private $encrypted_fields = array(
		'api.access_token',
		'api.webhook_secret',
		'api.webhook_verify_token',
		'ai.openai_api_key',
	);

	/**
	 * The single instance of the class.
	 *
	 * @var WCH_Settings
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WCH_Settings
	 */
	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->encryption = new WCH_Encryption();
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key in format 'section.key'.
	 * @param mixed  $default Default value if setting doesn't exist.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->get_all();
		$parts    = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return $default;
		}

		list( $section, $setting_key ) = $parts;

		// Check if the setting exists.
		if ( ! isset( $settings[ $section ][ $setting_key ] ) ) {
			// Get default from filter or provided default.
			$defaults = $this->get_defaults();
			if ( isset( $defaults[ $section ][ $setting_key ] ) ) {
				return $defaults[ $section ][ $setting_key ];
			}
			return $default;
		}

		$value = $settings[ $section ][ $setting_key ];

		// Decrypt if this is an encrypted field.
		if ( $this->is_encrypted_field( $key ) && ! empty( $value ) ) {
			$decrypted = $this->encryption->decrypt( $value );
			return false !== $decrypted ? $decrypted : $value;
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
	public function set( $key, $value ) {
		$parts = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return false;
		}

		list( $section, $setting_key ) = $parts;

		// Validate the value type.
		if ( ! $this->validate_setting( $key, $value ) ) {
			return false;
		}

		// Get current settings.
		$settings = $this->get_all();

		// Ensure section exists.
		if ( ! isset( $settings[ $section ] ) ) {
			$settings[ $section ] = array();
		}

		// Encrypt if this is an encrypted field.
		if ( $this->is_encrypted_field( $key ) && ! empty( $value ) ) {
			$encrypted = $this->encryption->encrypt( $value );
			if ( false !== $encrypted ) {
				$value = $encrypted;
			}
		}

		// Set the value.
		$settings[ $section ][ $setting_key ] = $value;

		// Save to database.
		$result = update_option( self::OPTION_NAME, $settings );

		// Clear cache.
		$this->settings_cache = null;

		return $result;
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function get_all() {
		// Return cached settings if available.
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}

		// Get settings from database.
		$settings = get_option( self::OPTION_NAME, array() );

		// Ensure it's an array.
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Cache the settings.
		$this->settings_cache = $settings;

		return $settings;
	}

	/**
	 * Delete a setting.
	 *
	 * @param string $key Setting key in format 'section.key'.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $key ) {
		$parts = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return false;
		}

		list( $section, $setting_key ) = $parts;

		// Get current settings.
		$settings = $this->get_all();

		// Check if the setting exists.
		if ( ! isset( $settings[ $section ][ $setting_key ] ) ) {
			return false;
		}

		// Delete the setting.
		unset( $settings[ $section ][ $setting_key ] );

		// If section is empty, remove it.
		if ( empty( $settings[ $section ] ) ) {
			unset( $settings[ $section ] );
		}

		// Save to database.
		$result = update_option( self::OPTION_NAME, $settings );

		// Clear cache.
		$this->settings_cache = null;

		return $result;
	}

	/**
	 * Get all settings in a section.
	 *
	 * @param string $section Section name.
	 * @return array
	 */
	public function get_section( $section ) {
		$settings = $this->get_all();

		if ( ! isset( $settings[ $section ] ) ) {
			// Return defaults for this section.
			$defaults = $this->get_defaults();
			return isset( $defaults[ $section ] ) ? $defaults[ $section ] : array();
		}

		// Decrypt encrypted fields in this section.
		$section_settings = $settings[ $section ];
		foreach ( $section_settings as $key => $value ) {
			$full_key = $section . '.' . $key;
			if ( $this->is_encrypted_field( $full_key ) && ! empty( $value ) ) {
				$decrypted = $this->encryption->decrypt( $value );
				if ( false !== $decrypted ) {
					$section_settings[ $key ] = $decrypted;
				}
			}
		}

		return $section_settings;
	}

	/**
	 * Check if a field should be encrypted.
	 *
	 * @param string $key Setting key in format 'section.key'.
	 * @return bool
	 */
	private function is_encrypted_field( $key ) {
		return in_array( $key, $this->encrypted_fields, true );
	}

	/**
	 * Validate a setting value.
	 *
	 * @param string $key   Setting key in format 'section.key'.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	private function validate_setting( $key, $value ) {
		$schema = $this->get_schema();
		$parts  = explode( '.', $key );

		if ( count( $parts ) !== 2 ) {
			return false;
		}

		list( $section, $setting_key ) = $parts;

		// If schema doesn't define this setting, allow it.
		if ( ! isset( $schema[ $section ][ $setting_key ] ) ) {
			return true;
		}

		$expected_type = $schema[ $section ][ $setting_key ];

		// Validate based on expected type.
		switch ( $expected_type ) {
			case 'bool':
			case 'boolean':
				return is_bool( $value );

			case 'int':
			case 'integer':
				return is_int( $value );

			case 'float':
			case 'double':
				return is_float( $value ) || is_int( $value );

			case 'string':
				return is_string( $value );

			case 'array':
				return is_array( $value );

			case 'json':
				// JSON can be string or array.
				return is_string( $value ) || is_array( $value );

			default:
				return true;
		}
	}

	/**
	 * Get the settings schema (type definitions).
	 *
	 * @return array
	 */
	private function get_schema() {
		return array(
			'api'           => array(
				'whatsapp_phone_number_id'     => 'string',
				'whatsapp_business_account_id' => 'string',
				'access_token'                 => 'string',
				'webhook_verify_token'         => 'string',
				'webhook_secret'               => 'string',
				'api_key_hash'                 => 'string',
				'api_version'                  => 'string',
			),
			'general'       => array(
				'enable_bot'       => 'bool',
				'business_name'    => 'string',
				'welcome_message'  => 'string',
				'fallback_message' => 'string',
				'operating_hours'  => 'json',
				'timezone'         => 'string',
			),
			'catalog'       => array(
				'catalog_id'           => 'string',
				'sync_enabled'         => 'bool',
				'sync_products'        => 'array',
				'include_out_of_stock' => 'bool',
				'price_format'         => 'string',
				'currency_symbol'      => 'string',
			),
			'checkout'      => array(
				'enabled_payment_methods'  => 'array',
				'cod_enabled'              => 'bool',
				'cod_extra_charge'         => 'float',
				'min_order_amount'         => 'float',
				'max_order_amount'         => 'float',
				'require_phone_verification' => 'bool',
			),
			'notifications' => array(
				'order_confirmation'        => 'bool',
				'order_status_updates'      => 'bool',
				'shipping_updates'          => 'bool',
				'abandoned_cart_reminder'   => 'bool',
				'abandoned_cart_delay_hours' => 'int',
			),
			'ai'            => array(
				'enable_ai'        => 'bool',
				'openai_api_key'   => 'string',
				'ai_model'         => 'string',
				'ai_temperature'   => 'float',
				'ai_max_tokens'    => 'int',
				'ai_system_prompt' => 'string',
			),
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	private function get_defaults() {
		$defaults = array(
			'api'           => array(
				'api_version' => 'v18.0',
			),
			'general'       => array(
				'enable_bot'       => false,
				'business_name'    => get_bloginfo( 'name' ),
				'welcome_message'  => __( 'Welcome! How can we help you today?', 'whatsapp-commerce-hub' ),
				'fallback_message' => __( 'Sorry, I didn\'t understand that. Please try again or type "help" for assistance.', 'whatsapp-commerce-hub' ),
				'operating_hours'  => array(),
				'timezone'         => wp_timezone_string(),
			),
			'catalog'       => array(
				'sync_enabled'         => false,
				'sync_products'        => 'all',
				'include_out_of_stock' => false,
				'price_format'         => get_woocommerce_price_format(),
				'currency_symbol'      => get_woocommerce_currency_symbol(),
			),
			'checkout'      => array(
				'enabled_payment_methods'  => array(),
				'cod_enabled'              => false,
				'cod_extra_charge'         => 0.0,
				'min_order_amount'         => 0.0,
				'max_order_amount'         => 0.0,
				'require_phone_verification' => false,
			),
			'notifications' => array(
				'order_confirmation'        => true,
				'order_status_updates'      => true,
				'shipping_updates'          => false,
				'abandoned_cart_reminder'   => false,
				'abandoned_cart_delay_hours' => 24,
			),
			'ai'            => array(
				'enable_ai'        => false,
				'ai_model'         => 'gpt-4',
				'ai_temperature'   => 0.7,
				'ai_max_tokens'    => 500,
				'ai_system_prompt' => __( 'You are a helpful customer service assistant for an e-commerce store.', 'whatsapp-commerce-hub' ),
			),
		);

		/**
		 * Filter default settings.
		 *
		 * @param array $defaults Default settings array.
		 */
		return apply_filters( 'wch_settings_defaults', $defaults );
	}

	/**
	 * Prevent cloning of the instance.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of the instance.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
