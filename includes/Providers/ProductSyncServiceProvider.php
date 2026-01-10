<?php
/**
 * Product Sync Service Provider
 *
 * Registers product sync services with the DI container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductValidatorInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\CatalogTransformerInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\CatalogApiInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\SyncProgressTrackerInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Application\Services\ProductSync\ProductValidatorService;
use WhatsAppCommerceHub\Application\Services\ProductSync\CatalogTransformerService;
use WhatsAppCommerceHub\Application\Services\ProductSync\CatalogApiService;
use WhatsAppCommerceHub\Application\Services\ProductSync\SyncProgressTracker;
use WhatsAppCommerceHub\Application\Services\ProductSync\ProductSyncOrchestrator;
use WhatsAppCommerceHub\Application\Services\ProductSync\ProductSyncAdminUI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductSyncServiceProvider
 *
 * Registers and configures product sync services.
 */
class ProductSyncServiceProvider extends AbstractServiceProvider {

	/**
	 * Register services with the container.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Register Product Validator.
		$this->container->singleton(
			ProductValidatorInterface::class,
			function ( $container ) {
				return new ProductValidatorService(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: null
				);
			}
		);

		// Register Catalog Transformer.
		$this->container->singleton(
			CatalogTransformerInterface::class,
			function ( $container ) {
				return new CatalogTransformerService(
					$container->get( ProductValidatorInterface::class )
				);
			}
		);

		// Register Catalog API Service.
		$this->container->singleton(
			CatalogApiInterface::class,
			function ( $container ) {
				return new CatalogApiService(
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: null,
					$container->has( LoggerInterface::class )
						? $container->get( LoggerInterface::class )
						: null
				);
			}
		);

		// Register Sync Progress Tracker.
		$this->container->singleton(
			SyncProgressTrackerInterface::class,
			function ( $container ) {
				global $wpdb;
				return new SyncProgressTracker(
					$wpdb,
					$container->has( LoggerInterface::class )
						? $container->get( LoggerInterface::class )
						: null
				);
			}
		);

		// Register Product Sync Orchestrator.
		$this->container->singleton(
			ProductSyncOrchestratorInterface::class,
			function ( $container ) {
				return new ProductSyncOrchestrator(
					$container->get( ProductValidatorInterface::class ),
					$container->get( CatalogTransformerInterface::class ),
					$container->get( CatalogApiInterface::class ),
					$container->get( SyncProgressTrackerInterface::class ),
					$container->has( SettingsInterface::class )
						? $container->get( SettingsInterface::class )
						: null,
					$container->has( LoggerInterface::class )
						? $container->get( LoggerInterface::class )
						: null
				);
			}
		);

		// Register Admin UI.
		$this->container->singleton(
			ProductSyncAdminUI::class,
			function ( $container ) {
				return new ProductSyncAdminUI(
					$container->get( ProductValidatorInterface::class ),
					$container->get( ProductSyncOrchestratorInterface::class )
				);
			}
		);

		// Register aliases for backward compatibility.
		$this->container->alias( 'product_validator', ProductValidatorInterface::class );
		$this->container->alias( 'catalog_transformer', CatalogTransformerInterface::class );
		$this->container->alias( 'catalog_api', CatalogApiInterface::class );
		$this->container->alias( 'sync_progress_tracker', SyncProgressTrackerInterface::class );
		$this->container->alias( 'product_sync_orchestrator', ProductSyncOrchestratorInterface::class );
		$this->container->alias( 'product_sync_admin_ui', ProductSyncAdminUI::class );
	}

	/**
	 * Bootstrap services after all providers are registered.
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		// Initialize admin UI hooks.
		if ( is_admin() ) {
			$adminUI = $this->container->get( ProductSyncAdminUI::class );
			$adminUI->init();
		}

		// Register WordPress hooks for product updates.
		$this->registerProductHooks();

		// Register queue job handlers.
		$this->registerQueueHandlers();
	}

	/**
	 * Register product update/delete hooks.
	 *
	 * @return void
	 */
	protected function registerProductHooks(): void {
		$orchestrator = $this->container->get( ProductSyncOrchestratorInterface::class );

		add_action(
			'woocommerce_update_product',
			function ( int $productId ) use ( $orchestrator ) {
				$orchestrator->handleProductUpdate( $productId );
			},
			10,
			1
		);

		add_action(
			'woocommerce_new_product',
			function ( int $productId ) use ( $orchestrator ) {
				$orchestrator->handleProductUpdate( $productId );
			},
			10,
			1
		);

		add_action(
			'before_delete_post',
			function ( int $postId ) use ( $orchestrator ) {
				$orchestrator->handleProductDelete( $postId );
			},
			10,
			1
		);
	}

	/**
	 * Register queue job handlers.
	 *
	 * @return void
	 */
	protected function registerQueueHandlers(): void {
		// Register batch processor.
		add_action(
			'wch_sync_product_batch',
			function ( array $args ) {
				$orchestrator = $this->container->get( ProductSyncOrchestratorInterface::class );
				$orchestrator->processBatch( $args );
			}
		);

		// Register single product processor.
		add_action(
			'wch_sync_single_product',
			function ( array $args ) {
				$orchestrator = $this->container->get( ProductSyncOrchestratorInterface::class );
				$productId    = $args['product_id'] ?? null;

				if ( $productId ) {
					$orchestrator->syncProduct( (int) $productId );
				}
			}
		);
	}

	/**
	 * Get services provided by this provider.
	 *
	 * @return array
	 */
	public function provides(): array {
		return [
			ProductValidatorInterface::class,
			CatalogTransformerInterface::class,
			CatalogApiInterface::class,
			SyncProgressTrackerInterface::class,
			ProductSyncOrchestratorInterface::class,
			ProductSyncAdminUI::class,
		];
	}
}
