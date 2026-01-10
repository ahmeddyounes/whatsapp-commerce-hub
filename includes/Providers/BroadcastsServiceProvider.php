<?php
/**
 * Broadcasts Service Provider
 *
 * Registers broadcast services with the DI container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Service provider closures don't need docblocks.

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Services\Broadcasts\AudienceCalculatorInterface;
use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignDispatcherInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Application\Services\Broadcasts\CampaignRepository;
use WhatsAppCommerceHub\Application\Services\Broadcasts\AudienceCalculator;
use WhatsAppCommerceHub\Application\Services\Broadcasts\CampaignDispatcher;
use WhatsAppCommerceHub\Admin\Broadcasts\BroadcastWizardRenderer;
use WhatsAppCommerceHub\Admin\Broadcasts\CampaignReportGenerator;
use WhatsAppCommerceHub\Admin\Broadcasts\BroadcastsAjaxHandler;
use WhatsAppCommerceHub\Admin\Broadcasts\AdminBroadcastsController;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BroadcastsServiceProvider
 *
 * Registers and configures broadcast services.
 */
class BroadcastsServiceProvider extends AbstractServiceProvider {

	/**
	 * Register services with the container.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Register Campaign Repository.
		$this->container->singleton(
			CampaignRepositoryInterface::class,
			function () {
				return new CampaignRepository();
			}
		);

		// Register Audience Calculator.
		$this->container->singleton(
			AudienceCalculatorInterface::class,
			function () {
				return new AudienceCalculator();
			}
		);

		// Register Campaign Dispatcher.
		$this->container->singleton(
			CampaignDispatcherInterface::class,
			function ( $container ) {
				return new CampaignDispatcher(
					$container->get( CampaignRepositoryInterface::class ),
					$container->get( AudienceCalculatorInterface::class ),
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings()
				);
			}
		);

		// Register Broadcast Wizard Renderer.
		$this->container->singleton(
			BroadcastWizardRenderer::class,
			function () {
				return new BroadcastWizardRenderer();
			}
		);

		// Register Campaign Report Generator.
		$this->container->singleton(
			CampaignReportGenerator::class,
			function ( $container ) {
				return new CampaignReportGenerator(
					$container->get( CampaignRepositoryInterface::class )
				);
			}
		);

		// Register Broadcasts AJAX Handler.
		$this->container->singleton(
			BroadcastsAjaxHandler::class,
			function ( $container ) {
				return new BroadcastsAjaxHandler(
					$container->get( CampaignRepositoryInterface::class ),
					$container->get( AudienceCalculatorInterface::class ),
					$container->get( CampaignDispatcherInterface::class ),
					$container->get( CampaignReportGenerator::class )
				);
			}
		);

		// Register Admin Broadcasts Controller.
		$this->container->singleton(
			AdminBroadcastsController::class,
			function ( $container ) {
				return new AdminBroadcastsController(
					$container->get( CampaignRepositoryInterface::class ),
					$container->get( BroadcastWizardRenderer::class ),
					$container->get( CampaignReportGenerator::class ),
					$container->get( BroadcastsAjaxHandler::class )
				);
			}
		);

		// Register aliases for backward compatibility.
		$this->container->alias( 'campaign_repository', CampaignRepositoryInterface::class );
		$this->container->alias( 'audience_calculator', AudienceCalculatorInterface::class );
		$this->container->alias( 'campaign_dispatcher', CampaignDispatcherInterface::class );
		$this->container->alias( 'broadcast_wizard_renderer', BroadcastWizardRenderer::class );
		$this->container->alias( 'campaign_report_generator', CampaignReportGenerator::class );
		$this->container->alias( 'broadcasts_ajax_handler', BroadcastsAjaxHandler::class );
		$this->container->alias( 'admin_broadcasts_controller', AdminBroadcastsController::class );
	}

	/**
	 * Bootstrap services after all providers are registered.
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		// Only initialize in admin context.
		if ( is_admin() ) {
			$controller = $this->container->get( AdminBroadcastsController::class );
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
			CampaignRepositoryInterface::class,
			AudienceCalculatorInterface::class,
			CampaignDispatcherInterface::class,
			BroadcastWizardRenderer::class,
			CampaignReportGenerator::class,
			BroadcastsAjaxHandler::class,
			AdminBroadcastsController::class,
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
