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
					$container->get( SettingsInterface::class )
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

}
