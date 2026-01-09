<?php
/**
 * Settings Import/Exporter Interface
 *
 * Contract for importing and exporting settings.
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
 * Interface SettingsImportExporterInterface
 *
 * Defines contract for settings import/export operations.
 */
interface SettingsImportExporterInterface {

	/**
	 * Export settings to array format.
	 *
	 * @return array{settings: array, filename: string} Export data.
	 */
	public function export(): array;

	/**
	 * Import settings from JSON string.
	 *
	 * @param string $jsonData JSON string of settings data.
	 * @return array{imported: int, skipped: int, errors: array} Import result.
	 */
	public function import( string $jsonData ): array;

	/**
	 * Get list of sensitive keys that should be excluded from export.
	 *
	 * @return array<string> List of sensitive setting keys.
	 */
	public function getSensitiveKeys(): array;

	/**
	 * Reset all settings to defaults.
	 *
	 * @return bool True on success.
	 */
	public function resetToDefaults(): bool;
}
