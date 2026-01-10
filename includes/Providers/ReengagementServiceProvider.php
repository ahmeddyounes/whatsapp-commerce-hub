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
			function () {
				return new FrequencyCapManager(
					\WCH_Database_Manager::instance()
				);
			}
		);

		// Register Product Tracking Service.
		$this->container->singleton(
			ProductTrackingServiceInterface::class,
			function ( $container ) {
				$service = new ProductTrackingService(
					\WCH_Database_Manager::instance(),
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
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings()
				);
			}
		);

		// Register Inactive Customer Identifier.
		$this->container->singleton(
			InactiveCustomerIdentifierInterface::class,
			function ( $container ) {
				return new InactiveCustomerIdentifier(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings(),
					\WCH_Database_Manager::instance()
				);
			}
		);

		// Register Message Builder.
		$this->container->singleton(
			ReengagementMessageBuilderInterface::class,
			function ( $container ) {
				return new ReengagementMessageBuilder(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings(),
					$container->get( ProductTrackingServiceInterface::class ),
					$container->get( LoyaltyCouponGeneratorInterface::class )
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
					$container->get( ReengagementMessageBuilderInterface::class )
				);
			}
		);

		// Register Analytics.
		$this->container->singleton(
			ReengagementAnalyticsInterface::class,
			function () {
				return new ReengagementAnalytics(
					\WCH_Database_Manager::instance()
				);
			}
		);

		// Register Orchestrator.
		$this->container->singleton(
			ReengagementOrchestratorInterface::class,
			function ( $container ) {
				return new ReengagementOrchestrator(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: $this->getFallbackSettings(),
					$container->get( InactiveCustomerIdentifierInterface::class ),
					$container->get( CampaignTypeResolverInterface::class ),
					$container->get( ReengagementMessageBuilderInterface::class ),
					$container->get( FrequencyCapManagerInterface::class )
				);
			}
		);

		// Register aliases for backward compatibility.
		$this->container->alias( 'inactive_customer_identifier', InactiveCustomerIdentifierInterface::class );
		$this->container->alias( 'campaign_type_resolver', CampaignTypeResolverInterface::class );
		$this->container->alias( 'product_tracking', ProductTrackingServiceInterface::class );
		$this->container->alias( 'reengagement_message_builder', ReengagementMessageBuilderInterface::class );
		$this->container->alias( 'loyalty_coupon_generator', LoyaltyCouponGeneratorInterface::class );
		$this->container->alias( 'frequency_cap_manager', FrequencyCapManagerInterface::class );
		$this->container->alias( 'reengagement_analytics', ReengagementAnalyticsInterface::class );
		$this->container->alias( 'reengagement_orchestrator', ReengagementOrchestratorInterface::class );
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
		add_action( 'wch_process_reengagement_campaigns', array( $this, 'handleProcessCampaigns' ) );
		add_action( 'wch_send_reengagement_message', array( $this, 'handleSendMessage' ) );
		add_action( 'wch_check_back_in_stock', array( $this, 'handleCheckBackInStock' ) );
	}

	/**
	 * Get services provided by this provider.
	 *
	 * @return array
	 */
	public function provides(): array {
		return array(
			InactiveCustomerIdentifierInterface::class,
			CampaignTypeResolverInterface::class,
			ProductTrackingServiceInterface::class,
			ReengagementMessageBuilderInterface::class,
			LoyaltyCouponGeneratorInterface::class,
			FrequencyCapManagerInterface::class,
			ReengagementAnalyticsInterface::class,
			ReengagementOrchestratorInterface::class,
		);
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
	 * @param array $args Job arguments.
	 * @return void
	 */
	public function handleSendMessage( array $args ): void {
		$orchestrator = $this->container->get( ReengagementOrchestratorInterface::class );
		$orchestrator->sendMessage( $args );
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
			public function get( string $key, $default = null ) {
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
				return get_option( 'wch_settings', array() );
			}

			public function getGroup( string $group ): array {
				$all = $this->all();
				return $all[ $group ] ?? array();
			}

			public function isConfigured(): bool {
				$creds = $this->getApiCredentials();
				return ! empty( $creds['access_token'] ) && ! empty( $creds['phone_number_id'] );
			}

			public function getApiCredentials(): array {
				return array(
					'access_token'        => $this->get( 'api.access_token', '' ),
					'phone_number_id'     => $this->get( 'api.phone_number_id', '' ),
					'business_account_id' => $this->get( 'api.business_account_id', '' ),
				);
			}

			public function refresh(): void {
				wp_cache_delete( 'wch_settings', 'options' );
			}
		};
	}
}
