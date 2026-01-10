<?php
/**
 * Settings Interface
 *
 * Contract for settings management services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SettingsInterface
 *
 * Defines the contract for settings operations.
 */
interface SettingsInterface {

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value if not found.
	 * @return mixed Setting value.
	 */
	public function get( string $key, mixed $default = null ): mixed;

	/**
	 * Set a setting value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool True on success.
	 */
	public function set( string $key, $value ): bool;

	/**
	 * Delete a setting.
	 *
	 * @param string $key Setting key.
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool;

	/**
	 * Check if a setting exists.
	 *
	 * @param string $key Setting key.
	 * @return bool True if exists.
	 */
	public function has( string $key ): bool;

	/**
	 * Get all settings.
	 *
	 * @return array<string, mixed> All settings.
	 */
	public function all(): array;

	/**
	 * Get settings for a specific group.
	 *
	 * @param string $group Group name (e.g., 'api', 'notifications', 'checkout').
	 * @return array<string, mixed> Group settings.
	 */
	public function getGroup( string $group ): array;

	/**
	 * Check if the plugin is properly configured.
	 *
	 * @return bool True if configured.
	 */
	public function isConfigured(): bool;

	/**
	 * Get WhatsApp API credentials.
	 *
	 * @return array{access_token: string, phone_number_id: string, business_account_id: string}
	 */
	public function getApiCredentials(): array;

	/**
	 * Refresh cached settings.
	 *
	 * @return void
	 */
	public function refresh(): void;
}
