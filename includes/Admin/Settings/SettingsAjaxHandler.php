<?php
/**
 * Settings AJAX Handler Service
 *
 * Handles AJAX operations for admin settings.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Admin\Settings;

use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsSanitizerInterface;
use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsImportExporterInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use Exception;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsAjaxHandler
 *
 * Handles AJAX operations for settings.
 */
class SettingsAjaxHandler {

	/**
	 * Nonce action name.
	 */
	protected const NONCE_ACTION = 'wch_settings_nonce';

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface               $settings       Settings service.
	 * @param SettingsSanitizerInterface      $sanitizer      Settings sanitizer.
	 * @param SettingsImportExporterInterface $importExporter Import/exporter.
	 * @param LoggerInterface|null            $logger         Logger service.
	 */
	public function __construct(
		protected SettingsInterface $settings,
		protected SettingsSanitizerInterface $sanitizer,
		protected SettingsImportExporterInterface $importExporter,
		protected ?LoggerInterface $logger = null
	) {
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_wch_save_settings_ajax', [ $this, 'handleSaveSettings' ] );
		add_action( 'wp_ajax_wch_test_connection', [ $this, 'handleTestConnection' ] );
		add_action( 'wp_ajax_wch_regenerate_verify_token', [ $this, 'handleRegenerateVerifyToken' ] );
		add_action( 'wp_ajax_wch_sync_catalog', [ $this, 'handleSyncCatalog' ] );
		add_action( 'wp_ajax_wch_search_products', [ $this, 'handleSearchProducts' ] );
		add_action( 'wp_ajax_wch_test_notification', [ $this, 'handleTestNotification' ] );
		add_action( 'wp_ajax_wch_clear_logs', [ $this, 'handleClearLogs' ] );
		add_action( 'wp_ajax_wch_export_settings', [ $this, 'handleExportSettings' ] );
		add_action( 'wp_ajax_wch_import_settings', [ $this, 'handleImportSettings' ] );
		add_action( 'wp_ajax_wch_reset_settings', [ $this, 'handleResetSettings' ] );
	}

	/**
	 * Verify AJAX request and permissions.
	 *
	 * @return bool True if valid, sends error and exits otherwise.
	 */
	protected function verifyRequest(): bool {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
			return false;
		}

		return true;
	}

	/**
	 * Handle save settings AJAX request.
	 *
	 * @return void
	 */
	public function handleSaveSettings(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		$sections = [ 'api', 'catalog', 'checkout', 'notifications', 'ai', 'advanced' ];

		foreach ( $sections as $section ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
			if ( isset( $_POST[ $section ] ) && is_array( $_POST[ $section ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified, values sanitized by sanitizer.
				foreach ( $_POST[ $section ] as $key => $value ) {
					$settingKey     = $section . '.' . sanitize_key( $key );
					$sanitizedValue = $this->sanitizer->sanitize( $value, $key );
					$this->settings->set( $settingKey, $sanitizedValue );
				}
			}
		}

		$this->log( 'info', 'Settings saved via AJAX', [] );

		wp_send_json_success( [ 'message' => __( 'Settings saved successfully.', 'whatsapp-commerce-hub' ) ] );
	}

	/**
	 * Handle test connection AJAX request.
	 *
	 * @return void
	 */
	public function handleTestConnection(): void {
		$this->verifyRequest();

		try {
			if ( ! class_exists( 'WCH_WhatsApp_API_Client' ) ) {
				wp_send_json_error( [ 'message' => __( 'API client not available', 'whatsapp-commerce-hub' ) ] );
				return;
			}

			$api      = new \WCH_WhatsApp_API_Client();
			$response = $api->get_business_profile();

			if ( $response && isset( $response['data'] ) ) {
				wp_send_json_success(
					[
						'message' => __( 'Connection successful!', 'whatsapp-commerce-hub' ),
						'profile' => $response['data'],
					]
				);
			} else {
				wp_send_json_error( [ 'message' => __( 'Connection failed. Please check your credentials.', 'whatsapp-commerce-hub' ) ] );
			}
		} catch ( Exception $e ) {
			$this->log( 'error', 'Connection test failed', [ 'error' => $e->getMessage() ] );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Handle regenerate verify token AJAX request.
	 *
	 * @return void
	 */
	public function handleRegenerateVerifyToken(): void {
		$this->verifyRequest();

		$newToken = wp_generate_password( 32, false );
		$this->settings->set( 'api.webhook_verify_token', $newToken );

		$this->log( 'info', 'Webhook verify token regenerated', [] );

		wp_send_json_success( [ 'token' => $newToken ] );
	}

	/**
	 * Handle sync catalog AJAX request.
	 *
	 * @return void
	 */
	public function handleSyncCatalog(): void {
		$this->verifyRequest();

		try {
			// Try new DI-based orchestrator first.
			if ( function_exists( 'wch_container' ) ) {
				$container = wch_container();
				if ( $container->has( \WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncOrchestratorInterface::class ) ) {
					$orchestrator = $container->get( \WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncOrchestratorInterface::class );
					$syncId       = $orchestrator->syncAllProducts();

					$this->settings->set( 'catalog.last_sync', current_time( 'mysql' ) );

					wp_send_json_success(
						[
							'message' => __( 'Product sync has been queued for processing', 'whatsapp-commerce-hub' ),
							'sync_id' => $syncId,
							'result'  => [
								'timestamp' => current_time( 'mysql' ),
							],
						]
					);
					return;
				}
			}

			// Fallback to legacy service.
			if ( class_exists( 'WCH_Product_Sync_Service' ) ) {
				$catalogSync = \WCH_Product_Sync_Service::instance();
				$catalogSync->sync_all_products();

				$this->settings->set( 'catalog.last_sync', current_time( 'mysql' ) );

				wp_send_json_success(
					[
						'message' => __( 'Product sync has been queued for processing', 'whatsapp-commerce-hub' ),
						'result'  => [
							'timestamp' => current_time( 'mysql' ),
						],
					]
				);
			} else {
				wp_send_json_error( [ 'message' => __( 'Product sync service not available', 'whatsapp-commerce-hub' ) ] );
			}
		} catch ( Exception $e ) {
			$this->log( 'error', 'Catalog sync failed', [ 'error' => $e->getMessage() ] );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Handle search products AJAX request.
	 *
	 * @return void
	 */
	public function handleSearchProducts(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

		if ( empty( $query ) ) {
			wp_send_json_error( [ 'message' => __( 'Search query is required', 'whatsapp-commerce-hub' ) ] );
		}

		$args = [
			'post_type'      => 'product',
			'posts_per_page' => 20,
			's'              => $query,
			'post_status'    => 'publish',
		];

		$productsQuery = new \WP_Query( $args );
		$products      = [];

		if ( $productsQuery->have_posts() ) {
			while ( $productsQuery->have_posts() ) {
				$productsQuery->the_post();
				$product = wc_get_product( get_the_ID() );
				if ( $product ) {
					$products[] = [
						'id'    => $product->get_id(),
						'name'  => $product->get_name(),
						'sku'   => $product->get_sku(),
						'price' => $product->get_price(),
					];
				}
			}
			wp_reset_postdata();
		}

		wp_send_json_success( [ 'products' => $products ] );
	}

	/**
	 * Handle test notification AJAX request.
	 *
	 * @return void
	 */
	public function handleTestNotification(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		if ( empty( $type ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid notification type', 'whatsapp-commerce-hub' ) ] );
		}

		try {
			$currentUser = wp_get_current_user();
			$phone       = get_user_meta( $currentUser->ID, 'billing_phone', true );

			if ( empty( $phone ) ) {
				wp_send_json_error( [ 'message' => __( 'No phone number found for current user', 'whatsapp-commerce-hub' ) ] );
			}

			if ( ! class_exists( 'WCH_WhatsApp_API_Client' ) ) {
				wp_send_json_error( [ 'message' => __( 'API client not available', 'whatsapp-commerce-hub' ) ] );
			}

			$api     = new \WCH_WhatsApp_API_Client();
			$message = sprintf(
				/* translators: %s: notification type */
				__( 'This is a test %s notification from WhatsApp Commerce Hub.', 'whatsapp-commerce-hub' ),
				str_replace( '_', ' ', $type )
			);

			$api->send_text_message( $phone, $message );

			$this->log( 'info', 'Test notification sent', [ 'type' => $type ] );

			wp_send_json_success( [ 'message' => __( 'Test notification sent successfully', 'whatsapp-commerce-hub' ) ] );
		} catch ( Exception $e ) {
			$this->log(
				'error',
				'Test notification failed',
				[
					'type'  => $type,
					'error' => $e->getMessage(),
				]
			);
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Handle clear logs AJAX request.
	 *
	 * @return void
	 */
	public function handleClearLogs(): void {
		$this->verifyRequest();

		try {
			if ( class_exists( 'WCH_Logger' ) ) {
				\WCH_Logger::clear_all_logs();
			}

			$this->log( 'info', 'Logs cleared', [] );

			wp_send_json_success( [ 'message' => __( 'All logs cleared successfully', 'whatsapp-commerce-hub' ) ] );
		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Handle export settings AJAX request.
	 *
	 * @return void
	 */
	public function handleExportSettings(): void {
		$this->verifyRequest();

		$exportData = $this->importExporter->export();

		$this->log( 'info', 'Settings exported', [] );

		wp_send_json_success( $exportData );
	}

	/**
	 * Handle import settings AJAX request.
	 *
	 * @return void
	 */
	public function handleImportSettings(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
		if ( ! isset( $_POST['settings'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No settings data provided', 'whatsapp-commerce-hub' ) ] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified, JSON sanitized in import method.
		$jsonData = wp_unslash( $_POST['settings'] );

		// Create backup before import.
		$this->importExporter->createBackup();

		$result = $this->importExporter->import( $jsonData );

		$message = sprintf(
			/* translators: 1: imported count, 2: skipped count */
			__( 'Settings imported: %1$d, Skipped: %2$d', 'whatsapp-commerce-hub' ),
			$result['imported'],
			$result['skipped']
		);

		$this->log(
			'info',
			'Settings imported',
			[
				'imported' => $result['imported'],
				'skipped'  => $result['skipped'],
			]
		);

		if ( ! empty( $result['errors'] ) ) {
			wp_send_json_error(
				[
					'message' => $message,
					'errors'  => $result['errors'],
				]
			);
		}

		wp_send_json_success( [ 'message' => $message ] );
	}

	/**
	 * Handle reset settings AJAX request.
	 *
	 * @return void
	 */
	public function handleResetSettings(): void {
		$this->verifyRequest();

		try {
			// Create backup before reset.
			$this->importExporter->createBackup();

			$this->importExporter->resetToDefaults();

			$this->log( 'info', 'Settings reset to defaults', [] );

			wp_send_json_success( [ 'message' => __( 'Settings reset to defaults successfully', 'whatsapp-commerce-hub' ) ] );
		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	protected function log( string $level, string $message, array $context = [] ): void {
		$context['category'] = 'admin-settings';

		if ( null !== $this->logger ) {
			$this->logger->log( $level, $message, 'admin', $context );
			return;
		}

		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $context, $level );
		}
	}
}
