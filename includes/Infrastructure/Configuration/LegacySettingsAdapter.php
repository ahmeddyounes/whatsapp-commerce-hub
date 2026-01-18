<?php
/**
 * Legacy Settings Adapter
 *
 * Provides backward compatibility for the deprecated flat 'wch.settings' schema
 * by mapping it to the new sectioned SettingsInterface.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 * @deprecated This adapter is for backward compatibility only. Use SettingsInterface directly.
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Configuration;

use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use ArrayAccess;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LegacySettingsAdapter
 *
 * Adapter that makes the old flat settings array work with the new sectioned SettingsInterface.
 *
 * @deprecated 3.0.0 Use SettingsInterface directly instead.
 */
class LegacySettingsAdapter implements ArrayAccess {

	/**
	 * Settings service instance.
	 *
	 * @var SettingsInterface
	 */
	private SettingsInterface $settings;

	/**
	 * Mapping from old flat keys to new sectioned keys.
	 *
	 * @var array<string, string>
	 */
	private array $keyMapping = [
		// API Settings.
		'phone_number_id'     => 'api.whatsapp_phone_number_id',
		'business_account_id' => 'api.whatsapp_business_account_id',
		'access_token'        => 'api.access_token',
		'verify_token'        => 'api.webhook_verify_token',
		'webhook_secret'      => 'api.webhook_secret',

		// AI Settings.
		'openai_api_key'      => 'ai.openai_api_key',
		'enable_ai_chat'      => 'ai.enable_ai',
		'ai_model'            => 'ai.ai_model',

		// General/Store Settings.
		'store_currency'      => 'catalog.currency_symbol',

		// Cart Recovery Settings.
		'enable_cart_recovery' => 'recovery.enabled',
		'cart_expiry_hours'   => 'recovery.delay_sequence_3',
		'reminder_1_delay'    => 'recovery.delay_sequence_1',
		'reminder_2_delay'    => 'recovery.delay_sequence_2',
		'reminder_3_delay'    => 'recovery.delay_sequence_3',

		// Order Tracking.
		'enable_order_tracking' => 'notifications.order_status_updates',

		// Debug Logging (no direct equivalent - use Logger service instead).
		'enable_debug_logging' => null,
	];

	/**
	 * Default values for deprecated settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $defaults = [
		'phone_number_id'       => '',
		'business_account_id'   => '',
		'access_token'          => '',
		'verify_token'          => '',
		'webhook_secret'        => '',
		'openai_api_key'        => '',
		'enable_ai_chat'        => true,
		'ai_model'              => 'gpt-4o-mini',
		'store_currency'        => 'USD',
		'enable_cart_recovery'  => true,
		'cart_expiry_hours'     => 72,
		'reminder_1_delay'      => 1,
		'reminder_2_delay'      => 24,
		'reminder_3_delay'      => 72,
		'enable_order_tracking' => true,
		'enable_debug_logging'  => false,
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface $settings Settings service instance.
	 */
	public function __construct( SettingsInterface $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check if a setting exists (ArrayAccess).
	 *
	 * @param mixed $offset The key to check.
	 * @return bool
	 */
	public function offsetExists( mixed $offset ): bool {
		if ( ! is_string( $offset ) ) {
			return false;
		}

		// Check if this is a known legacy key.
		if ( ! isset( $this->keyMapping[ $offset ] ) ) {
			return false;
		}

		$newKey = $this->keyMapping[ $offset ];

		// If mapping is null, use default.
		if ( null === $newKey ) {
			return true;
		}

		return $this->settings->has( $newKey );
	}

	/**
	 * Get a setting value (ArrayAccess).
	 *
	 * @param mixed $offset The key to get.
	 * @return mixed
	 */
	public function offsetGet( mixed $offset ): mixed {
		if ( ! is_string( $offset ) ) {
			return null;
		}

		// Log deprecation warning (only in debug mode to avoid spam).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'DEPRECATED: wch.settings[%s] is deprecated. Use SettingsInterface with sectioned keys instead.',
					$offset
				)
			);
		}

		// Get the new key mapping.
		$newKey = $this->keyMapping[ $offset ] ?? null;

		// If mapping is null or key is not known, return default.
		if ( null === $newKey ) {
			return $this->defaults[ $offset ] ?? null;
		}

		// Get value from new settings system.
		$default = $this->defaults[ $offset ] ?? null;
		return $this->settings->get( $newKey, $default );
	}

	/**
	 * Set a setting value (ArrayAccess).
	 *
	 * @param mixed $offset The key to set.
	 * @param mixed $value  The value to set.
	 * @return void
	 */
	public function offsetSet( mixed $offset, mixed $value ): void {
		if ( ! is_string( $offset ) ) {
			return;
		}

		// Log deprecation warning.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'DEPRECATED: Setting wch.settings[%s] is deprecated. Use SettingsInterface with sectioned keys instead.',
					$offset
				)
			);
		}

		// Get the new key mapping.
		$newKey = $this->keyMapping[ $offset ] ?? null;

		// If mapping is null, ignore (cannot be stored).
		if ( null === $newKey ) {
			return;
		}

		// Set value in new settings system.
		$this->settings->set( $newKey, $value );
	}

	/**
	 * Unset a setting value (ArrayAccess).
	 *
	 * @param mixed $offset The key to unset.
	 * @return void
	 */
	public function offsetUnset( mixed $offset ): void {
		if ( ! is_string( $offset ) ) {
			return;
		}

		// Get the new key mapping.
		$newKey = $this->keyMapping[ $offset ] ?? null;

		// If mapping is null, ignore.
		if ( null === $newKey ) {
			return;
		}

		// Delete from new settings system.
		$this->settings->delete( $newKey );
	}

	/**
	 * Get all settings as an array (for compatibility with array functions).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$result = [];

		foreach ( array_keys( $this->keyMapping ) as $oldKey ) {
			$result[ $oldKey ] = $this->offsetGet( $oldKey );
		}

		return $result;
	}
}
