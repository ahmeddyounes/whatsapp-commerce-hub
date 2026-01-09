<?php
/**
 * Settings Import/Exporter Service
 *
 * Handles importing and exporting settings.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Admin\Settings;

use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsImportExporterInterface;
use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsSanitizerInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use Exception;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsImportExporter
 *
 * Handles settings import/export operations.
 */
class SettingsImportExporter implements SettingsImportExporterInterface {

	/**
	 * Option name for settings.
	 */
	protected const OPTION_SETTINGS = 'wch_settings';

	/**
	 * Option name for schema version.
	 */
	protected const OPTION_SCHEMA_VERSION = 'wch_settings_schema_version';

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface
	 */
	protected SettingsInterface $settings;

	/**
	 * Settings sanitizer.
	 *
	 * @var SettingsSanitizerInterface
	 */
	protected SettingsSanitizerInterface $sanitizer;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface          $settings  Settings service.
	 * @param SettingsSanitizerInterface $sanitizer Settings sanitizer.
	 */
	public function __construct(
		SettingsInterface $settings,
		SettingsSanitizerInterface $sanitizer
	) {
		$this->settings  = $settings;
		$this->sanitizer = $sanitizer;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSensitiveKeys(): array {
		return array(
			'api.access_token',
			'api.webhook_verify_token',
			'ai.openai_api_key',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function export(): array {
		$allSettings    = $this->settings->all();
		$exportSettings = $this->removeSensitiveData( $allSettings );

		return array(
			'settings' => $exportSettings,
			'filename' => 'wch-settings-' . gmdate( 'Y-m-d-H-i-s' ) . '.json',
		);
	}

	/**
	 * Remove sensitive data from settings array.
	 *
	 * @param array $settings Settings array.
	 * @return array Sanitized settings.
	 */
	protected function removeSensitiveData( array $settings ): array {
		$exportSettings = $settings;

		// Remove sensitive keys.
		unset( $exportSettings['api']['access_token'] );
		unset( $exportSettings['api']['webhook_verify_token'] );
		unset( $exportSettings['ai']['openai_api_key'] );

		return $exportSettings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function import( string $jsonData ): array {
		$importData = json_decode( $jsonData, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => array( __( 'Invalid JSON format', 'whatsapp-commerce-hub' ) ),
			);
		}

		$whitelist      = $this->sanitizer->getImportableWhitelist();
		$importedCount  = 0;
		$skippedCount   = 0;
		$errors         = array();

		foreach ( $importData as $section => $values ) {
			// Reject unknown sections.
			if ( ! isset( $whitelist[ $section ] ) ) {
				$skippedCount++;
				$errors[] = sprintf(
					/* translators: %s: section name */
					__( 'Section "%s" is not allowed for import', 'whatsapp-commerce-hub' ),
					sanitize_text_field( $section )
				);
				continue;
			}

			if ( ! is_array( $values ) ) {
				$skippedCount++;
				continue;
			}

			$allowedKeys = $whitelist[ $section ];

			foreach ( $values as $key => $value ) {
				// Reject keys not in whitelist for this section.
				if ( ! in_array( $key, $allowedKeys, true ) ) {
					$skippedCount++;
					continue;
				}

				// Sanitize value based on expected type.
				$sanitizedValue = $this->sanitizer->sanitizeImportValue( $section, $key, $value );

				if ( null !== $sanitizedValue ) {
					$this->settings->set( $section . '.' . $key, $sanitizedValue );
					$importedCount++;
				} else {
					$skippedCount++;
				}
			}
		}

		return array(
			'imported' => $importedCount,
			'skipped'  => $skippedCount,
			'errors'   => $errors,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function resetToDefaults(): bool {
		delete_option( self::OPTION_SETTINGS );
		delete_option( self::OPTION_SCHEMA_VERSION );

		return true;
	}

	/**
	 * Create a backup of current settings before import.
	 *
	 * @return string|null Backup JSON string or null on failure.
	 */
	public function createBackup(): ?string {
		$exportData = $this->export();

		if ( empty( $exportData['settings'] ) ) {
			return null;
		}

		$backup = wp_json_encode( $exportData['settings'], JSON_PRETTY_PRINT );

		if ( false === $backup ) {
			return null;
		}

		// Store backup in transient for 1 hour.
		set_transient( 'wch_settings_backup', $backup, HOUR_IN_SECONDS );

		return $backup;
	}

	/**
	 * Restore settings from backup.
	 *
	 * @return array{success: bool, message: string} Restore result.
	 */
	public function restoreFromBackup(): array {
		$backup = get_transient( 'wch_settings_backup' );

		if ( empty( $backup ) ) {
			return array(
				'success' => false,
				'message' => __( 'No backup found', 'whatsapp-commerce-hub' ),
			);
		}

		$result = $this->import( $backup );

		if ( $result['imported'] > 0 ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of restored settings */
					__( 'Restored %d settings from backup', 'whatsapp-commerce-hub' ),
					$result['imported']
				),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Failed to restore settings from backup', 'whatsapp-commerce-hub' ),
		);
	}

	/**
	 * Validate import data before importing.
	 *
	 * @param string $jsonData JSON string of settings data.
	 * @return array{valid: bool, errors: array} Validation result.
	 */
	public function validateImportData( string $jsonData ): array {
		$errors = array();

		$importData = json_decode( $jsonData, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'valid'  => false,
				'errors' => array( __( 'Invalid JSON format', 'whatsapp-commerce-hub' ) ),
			);
		}

		if ( ! is_array( $importData ) ) {
			return array(
				'valid'  => false,
				'errors' => array( __( 'Settings data must be an object', 'whatsapp-commerce-hub' ) ),
			);
		}

		$whitelist = $this->sanitizer->getImportableWhitelist();

		foreach ( $importData as $section => $values ) {
			if ( ! isset( $whitelist[ $section ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: section name */
					__( 'Unknown section: %s', 'whatsapp-commerce-hub' ),
					sanitize_text_field( $section )
				);
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Get import preview without actually importing.
	 *
	 * @param string $jsonData JSON string of settings data.
	 * @return array{sections: array, total_settings: int, warnings: array} Preview data.
	 */
	public function getImportPreview( string $jsonData ): array {
		$importData = json_decode( $jsonData, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'sections'       => array(),
				'total_settings' => 0,
				'warnings'       => array( __( 'Invalid JSON format', 'whatsapp-commerce-hub' ) ),
			);
		}

		$whitelist      = $this->sanitizer->getImportableWhitelist();
		$sections       = array();
		$totalSettings  = 0;
		$warnings       = array();

		foreach ( $importData as $section => $values ) {
			if ( ! isset( $whitelist[ $section ] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: section name */
					__( 'Section "%s" will be skipped (not in whitelist)', 'whatsapp-commerce-hub' ),
					sanitize_text_field( $section )
				);
				continue;
			}

			if ( ! is_array( $values ) ) {
				continue;
			}

			$allowedKeys    = $whitelist[ $section ];
			$sectionDetails = array(
				'name'     => $section,
				'settings' => array(),
				'skipped'  => array(),
			);

			foreach ( $values as $key => $value ) {
				if ( in_array( $key, $allowedKeys, true ) ) {
					$sectionDetails['settings'][] = $key;
					$totalSettings++;
				} else {
					$sectionDetails['skipped'][] = $key;
				}
			}

			$sections[] = $sectionDetails;
		}

		return array(
			'sections'       => $sections,
			'total_settings' => $totalSettings,
			'warnings'       => $warnings,
		);
	}
}
