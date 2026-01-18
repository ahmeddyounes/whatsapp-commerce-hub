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
					$container->get( SettingsInterface::class ),
					$container->get( SettingsSanitizerInterface::class )
				);
			}
		);

		// Register Settings AJAX Handler.
		$this->container->singleton(
			SettingsAjaxHandler::class,
			function ( $container ) {
				return new SettingsAjaxHandler(
					$container->get( SettingsInterface::class ),
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
					$container->get( SettingsInterface::class ),
					$container->get( SettingsTabRendererInterface::class ),
					$container->get( SettingsSanitizerInterface::class ),
					$container->get( SettingsAjaxHandler::class )
				);
			}
		);

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
	/**
	 * @return array<class-string<\WhatsAppCommerceHub\Container\ServiceProviderInterface>>
	 */
	public function dependsOn(): array {
		return [
			\WhatsAppCommerceHub\Providers\CoreServiceProvider::class,
		];
	}

	public function provides(): array {
		return [
			SettingsTabRendererInterface::class,
			SettingsSanitizerInterface::class,
			SettingsImportExporterInterface::class,
			SettingsAjaxHandler::class,
			AdminSettingsController::class,
		];
	}

}
