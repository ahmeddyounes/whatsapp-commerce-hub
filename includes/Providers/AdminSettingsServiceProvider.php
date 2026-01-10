<?php
/**
 * Admin Settings Service Provider
 *
 * Registers admin settings services with the DI container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Service provider closures don't need docblocks.

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsTabRendererInterface;
use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsSanitizerInterface;
use WhatsAppCommerceHub\Contracts\Admin\Settings\SettingsImportExporterInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Admin\Settings\SettingsTabRenderer;
use WhatsAppCommerceHub\Admin\Settings\SettingsSanitizer;
use WhatsAppCommerceHub\Admin\Settings\SettingsImportExporter;
use WhatsAppCommerceHub\Admin\Settings\SettingsAjaxHandler;
use WhatsAppCommerceHub\Admin\Settings\AdminSettingsController;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminSettingsServiceProvider
 *
 * Registers and configures admin settings services.
 */
class AdminSettingsServiceProvider extends AbstractServiceProvider {

	/**
	 * Register services with the container.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Register Settings Tab Renderer.
		$this->container->singleton(
			SettingsTabRendererInterface::class,
			function () {
				return new SettingsTabRenderer();
			}
		);

		// Register Settings Sanitizer.
		$this->container->singleton(
			SettingsSanitizerInterface::class,
			function () {
				return new SettingsSanitizer();
			}
		);

		// Register Settings Import/Exporter.
		$this->container->singleton(
			SettingsImportExporterInterface::class,
			function ( $container ) {
				return new SettingsImportExporter(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings(),
					$container->get( SettingsSanitizerInterface::class )
				);
			}
		);

		// Register Settings AJAX Handler.
		$this->container->singleton(
			SettingsAjaxHandler::class,
			function ( $container ) {
				return new SettingsAjaxHandler(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings(),
					$container->get( SettingsSanitizerInterface::class ),
					$container->get( SettingsImportExporterInterface::class ),
					$container->has( LoggerInterface::class )
						? $container->get( LoggerInterface::class )
						: null
				);
			}
		);

		// Register Admin Settings Controller.
		$this->container->singleton(
			AdminSettingsController::class,
			function ( $container ) {
				return new AdminSettingsController(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings(),
					$container->get( SettingsTabRendererInterface::class ),
					$container->get( SettingsSanitizerInterface::class ),
					$container->get( SettingsAjaxHandler::class )
				);
			}
		);

		// Register aliases for backward compatibility.
		$this->container->alias( 'settings_tab_renderer', SettingsTabRendererInterface::class );
		$this->container->alias( 'settings_sanitizer', SettingsSanitizerInterface::class );
		$this->container->alias( 'settings_import_exporter', SettingsImportExporterInterface::class );
		$this->container->alias( 'settings_ajax_handler', SettingsAjaxHandler::class );
		$this->container->alias( 'admin_settings_controller', AdminSettingsController::class );
	}

	/**
	 * Bootstrap services after all providers are registered.
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		// Only initialize in admin context.
		if ( is_admin() ) {
			$controller = $this->container->get( AdminSettingsController::class );
			$controller->init();
		}
	}

	/**
	 * Get services provided by this provider.
	 *
	 * @return array
	 */
	public function provides(): array {
		return [
			SettingsTabRendererInterface::class,
			SettingsSanitizerInterface::class,
			SettingsImportExporterInterface::class,
			SettingsAjaxHandler::class,
			AdminSettingsController::class,
		];
	}

	/**
	 * Get fallback settings instance.
	 *
	 * @return SettingsInterface
	 */
	protected function getFallbackSettings(): SettingsInterface {
		if ( class_exists( 'WCH_Settings' ) ) {
			return \WCH_Settings::getInstance();
		}

		// Return a minimal settings implementation.
		return new class() implements SettingsInterface {
			public function get( string $key, mixed $default = null ): mixed {
				return get_option( 'wch_' . str_replace( '.', '_', $key ), $default );
			}

			public function set( string $key, $value ): bool {
				return update_option( 'wch_' . str_replace( '.', '_', $key ), $value );
			}

			public function has( string $key ): bool {
				return get_option( 'wch_' . str_replace( '.', '_', $key ), '__not_found__' ) !== '__not_found__';
			}

			public function delete( string $key ): bool {
				return delete_option( 'wch_' . str_replace( '.', '_', $key ) );
			}

			public function all(): array {
				return get_option( 'wch_settings', [] );
			}

			public function getGroup( string $group ): array {
				$all = $this->all();
				return $all[ $group ] ?? [];
			}

			public function isConfigured(): bool {
				$creds = $this->getApiCredentials();
				return ! empty( $creds['access_token'] ) && ! empty( $creds['phone_number_id'] );
			}

			public function getApiCredentials(): array {
				return [
					'access_token'        => $this->get( 'api.access_token', '' ),
					'phone_number_id'     => $this->get( 'api.phone_number_id', '' ),
					'business_account_id' => $this->get( 'api.business_account_id', '' ),
				];
			}

			public function refresh(): void {
				wp_cache_delete( 'wch_settings', 'options' );
			}
		};
	}
}
