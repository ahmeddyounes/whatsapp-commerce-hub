<?php
/**
 * Reengagement Service Provider
 *
 * Registers re-engagement services with the DI container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Service provider closures don't need docblocks.

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\InactiveCustomerIdentifierInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\CampaignTypeResolverInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ProductTrackingServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementMessageBuilderInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\LoyaltyCouponGeneratorInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\FrequencyCapManagerInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementAnalyticsInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementOrchestratorInterface;
use WhatsAppCommerceHub\Application\Services\Reengagement\InactiveCustomerIdentifier;
use WhatsAppCommerceHub\Application\Services\Reengagement\CampaignTypeResolver;
use WhatsAppCommerceHub\Application\Services\Reengagement\ProductTrackingService;
use WhatsAppCommerceHub\Application\Services\Reengagement\ReengagementMessageBuilder;
use WhatsAppCommerceHub\Application\Services\Reengagement\LoyaltyCouponGenerator;
use WhatsAppCommerceHub\Application\Services\Reengagement\FrequencyCapManager;
use WhatsAppCommerceHub\Application\Services\Reengagement\ReengagementAnalytics;
use WhatsAppCommerceHub\Application\Services\Reengagement\ReengagementOrchestrator;
use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReengagementServiceProvider
 *
 * Registers and configures re-engagement services.
 */
class ReengagementServiceProvider extends AbstractServiceProvider {

	/**
	 * Register services with the container.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Register Frequency Cap Manager (no dependencies on other reengagement services).
		$this->container->singleton(
			FrequencyCapManagerInterface::class,
			function ( $container ) {
				return new FrequencyCapManager(
					$container->get( DatabaseManager::class )
				);
			}
		);

		// Register Product Tracking Service.
		$this->container->singleton(
			ProductTrackingServiceInterface::class,
			function ( $container ) {
				$service = new ProductTrackingService(
					$container->get( DatabaseManager::class ),
					$container->get( JobDispatcher::class ),
					$container->get( FrequencyCapManagerInterface::class )
				);
				return $service;
			}
		);

		// Register Loyalty Coupon Generator.
		$this->container->singleton(
			LoyaltyCouponGeneratorInterface::class,
			function ( $container ) {
				return new LoyaltyCouponGenerator(
					$container->get( SettingsInterface::class ),
					$container->get( CustomerServiceInterface::class ),
					$container->get( LoggerInterface::class )
				);
			}
		);

		// Register Inactive Customer Identifier.
		$this->container->singleton(
			InactiveCustomerIdentifierInterface::class,
			function ( $container ) {
				return new InactiveCustomerIdentifier(
					$container->get( SettingsInterface::class ),
					$container->get( DatabaseManager::class )
				);
			}
		);

		// Register Message Builder.
		$this->container->singleton(
			ReengagementMessageBuilderInterface::class,
			function ( $container ) {
				return new ReengagementMessageBuilder(
					$container->get( SettingsInterface::class ),
					$container->get( ProductTrackingServiceInterface::class ),
					$container->get( LoyaltyCouponGeneratorInterface::class ),
					$container->get( CustomerServiceInterface::class )
				);
			}
		);

		// Register Campaign Type Resolver.
		$this->container->singleton(
			CampaignTypeResolverInterface::class,
			function ( $container ) {
				return new CampaignTypeResolver(
					$container->get( ProductTrackingServiceInterface::class ),
					$container->get( LoyaltyCouponGeneratorInterface::class ),
					$container->get( ReengagementMessageBuilderInterface::class ),
					$container->get( CustomerServiceInterface::class )
				);
			}
		);

		// Register Analytics.
		$this->container->singleton(
			ReengagementAnalyticsInterface::class,
			function ( $container ) {
				return new ReengagementAnalytics(
					$container->get( DatabaseManager::class ),
					$container->get( LoggerInterface::class )
				);
			}
		);

		// Register Orchestrator.
		$this->container->singleton(
			ReengagementOrchestratorInterface::class,
			function ( $container ) {
				$orchestrator = new ReengagementOrchestrator(
					$container->get( SettingsInterface::class ),
					$container->get( InactiveCustomerIdentifierInterface::class ),
					$container->get( CampaignTypeResolverInterface::class ),
					$container->get( ReengagementMessageBuilderInterface::class ),
					$container->get( FrequencyCapManagerInterface::class ),
					$container->get( CustomerServiceInterface::class ),
					$container->get( JobDispatcher::class ),
					$container->get( LoggerInterface::class )
				);

				// Optionally inject WhatsApp API client if available.
				try {
					$apiClient = $container->get( WhatsAppApiClient::class );
					$orchestrator->setApiClient( $apiClient );
				} catch ( \Throwable $e ) {
					// API client not available yet, will be set later via init action.
				}

				return $orchestrator;
			}
		);

	}

	/**
	 * Bootstrap services after all providers are registered.
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		// Initialize orchestrator to schedule tasks.
		$orchestrator = $this->container->get( ReengagementOrchestratorInterface::class );
		$orchestrator->init();

		// Register job handlers for scheduled tasks.
		add_action( 'wch_process_reengagement_campaigns', [ $this, 'handleProcessCampaigns' ] );
		add_action( 'wch_send_reengagement_message', [ $this, 'handleSendMessage' ] );
		add_action( 'wch_check_back_in_stock', [ $this, 'handleCheckBackInStock' ] );
	}

	/**
	 * Determine if this provider should boot in the current context.
	 *
	 * Reengagement services need to boot in:
	 * - Admin (for UI and manual triggers)
	 * - Cron (for scheduled campaign processing)
	 *
	 * Skip on frontend and REST requests to reduce overhead.
	 *
	 * @return bool True if provider should boot.
	 */
	public function shouldBoot(): bool {
		return $this->isAdmin() || $this->isCron();
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
			\WhatsAppCommerceHub\Providers\BusinessServiceProvider::class,
			\WhatsAppCommerceHub\Providers\QueueServiceProvider::class,
		];
	}

	public function provides(): array {
		return [
			InactiveCustomerIdentifierInterface::class,
			CampaignTypeResolverInterface::class,
			ProductTrackingServiceInterface::class,
			ReengagementMessageBuilderInterface::class,
			LoyaltyCouponGeneratorInterface::class,
			FrequencyCapManagerInterface::class,
			ReengagementAnalyticsInterface::class,
			ReengagementOrchestratorInterface::class,
		];
	}

	/**
	 * Handle process campaigns job.
	 *
	 * @return void
	 */
	public function handleProcessCampaigns(): void {
		$orchestrator = $this->container->get( ReengagementOrchestratorInterface::class );
		$orchestrator->processCampaigns();
	}

	/**
	 * Handle send message job.
	 *
	 * @param array $args Job arguments (may be wrapped v2 payload).
	 * @return void
	 */
	public function handleSendMessage( array $args ): void {
		// Unwrap v2 payload format if present.
		$unwrapped = $this->unwrapPayload( $args );

		$orchestrator = $this->container->get( ReengagementOrchestratorInterface::class );
		$orchestrator->sendMessage( $unwrapped );
	}

	/**
	 * Handle check back in stock job.
	 *
	 * @return void
	 */
	public function handleCheckBackInStock(): void {
		$productTracking = $this->container->get( ProductTrackingServiceInterface::class );
		$productTracking->processBackInStockNotifications();
	}

	/**
	 * Unwrap queue payload (v2 format support).
	 *
	 * Handles both wrapped v2 payloads and legacy unwrapped payloads.
	 *
	 * @param array $payload The job payload (may be wrapped).
	 * @return array The unwrapped user args.
	 */
	private function unwrapPayload( array $payload ): array {
		// Check if this is a v2 wrapped payload.
		if ( isset( $payload['_wch_version'] ) && 2 === (int) $payload['_wch_version'] ) {
			// Log wrapped payload for debugging.
			do_action(
				'wch_log_debug',
				'[ReengagementServiceProvider] Unwrapping v2 payload',
				[
					'has_args'    => isset( $payload['args'] ),
					'has_meta'    => isset( $payload['_wch_meta'] ),
					'meta_keys'   => isset( $payload['_wch_meta'] ) ? array_keys( $payload['_wch_meta'] ) : [],
				]
			);

			return $payload['args'] ?? [];
		}

		// Legacy unwrapped payload or unexpected format - return as-is.
		do_action(
			'wch_log_debug',
			'[ReengagementServiceProvider] Using legacy/unwrapped payload',
			[
				'payload_keys' => array_keys( $payload ),
			]
		);

		return $payload;
	}

}
