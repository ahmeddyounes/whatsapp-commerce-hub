<?php
/**
 * Settings Sanitizer Interface
 *
 * Contract for sanitizing settings values.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Admin\Settings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SettingsSanitizerInterface
 *
 * Defines contract for settings sanitization.
 */
interface SettingsSanitizerInterface {

	/**
	 * Sanitize a setting value based on its key.
	 *
	 * @param mixed  $value The value to sanitize.
	 * @param string $key   The setting key.
	 * @return mixed Sanitized value.
	 */
	public function sanitize( mixed $value, string $key ): mixed;

	/**
	 * Sanitize an import value with strict type checking.
	 *
	 * @param string $section Settings section.
	 * @param string $key     Setting key.
	 * @param mixed  $value   Value to sanitize.
	 * @return mixed|null Sanitized value or null if invalid.
	 */
	public function sanitizeImportValue( string $section, string $key, mixed $value ): mixed;

	/**
	 * Get whitelist of importable settings per section.
	 *
	 * @return array<string, array<string>> Section => allowed keys.
	 */
	public function getImportableWhitelist(): array;

	/**
	 * Check if a setting key is a boolean type.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function isBooleanField( string $key ): bool;

	/**
	 * Check if a setting key is a numeric type.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function isNumericField( string $key ): bool;
}
