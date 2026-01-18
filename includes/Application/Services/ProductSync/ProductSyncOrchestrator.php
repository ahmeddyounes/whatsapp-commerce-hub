<?php
/**
 * Product Sync Orchestrator Service
 *
 * Coordinates product sync operations between all services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\ProductSync;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductValidatorInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\CatalogTransformerInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\CatalogApiInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\SyncProgressTrackerInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductSyncOrchestrator
 *
 * Coordinates all product sync operations.
 */
class ProductSyncOrchestrator implements ProductSyncOrchestratorInterface {

	/**
	 * Batch size for bulk operations.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Constructor.
	 *
	 * @param ProductValidatorInterface    $validator       Product validator.
	 * @param CatalogTransformerInterface  $transformer     Catalog transformer.
	 * @param CatalogApiInterface          $catalogApi      Catalog API.
	 * @param SyncProgressTrackerInterface $progressTracker Progress tracker.
	 * @param SettingsInterface|null       $settings        Settings service.
	 * @param LoggerInterface|null         $logger          Logger service.
	 */
	public function __construct(
		protected ProductValidatorInterface $validator,
		protected CatalogTransformerInterface $transformer,
		protected CatalogApiInterface $catalogApi,
		protected SyncProgressTrackerInterface $progressTracker,
		protected ?SettingsInterface $settings = null,
		protected ?LoggerInterface $logger = null
	) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function syncProduct( int $productId ): array {
		// Check if sync is enabled.
		if ( ! $this->validator->isSyncEnabled() ) {
			return [
				'success' => false,
				'error'   => 'Product sync is not enabled',
			];
		}

		// Get product.
		$product = wc_get_product( $productId );
		if ( ! $product ) {
			return [
				'success' => false,
				'error'   => 'Product not found',
			];
		}

		// Validate product.
		$validation = $this->validator->validate( $product );
		if ( ! $validation['valid'] ) {
			return [
				'success' => false,
				'error'   => $validation['reason'],
			];
		}

		// Check API configuration.
		if ( ! $this->catalogApi->isConfigured() ) {
			return [
				'success' => false,
				'error'   => 'WhatsApp API not configured',
			];
		}

		// Handle variable products.
		if ( $product->is_type( 'variable' ) ) {
			return $this->syncVariableProduct( $product );
		}

		// Transform and sync simple product.
		$catalogData = $this->transformer->transform( $product );
		$result      = $this->catalogApi->createProduct( $catalogData );

		// Update sync metadata.
		if ( $result['success'] ) {
			$this->catalogApi->updateSyncStatus(
				$productId,
				'synced',
				$result['catalog_item_id'] ?? ''
			);

			$this->log(
				'info',
				'Product synced to WhatsApp catalog',
				[
					'product_id' => $productId,
				]
			);
		} else {
			$this->catalogApi->updateSyncStatus(
				$productId,
				'error',
				'',
				$result['error'] ?? 'Unknown error'
			);

			$this->log(
				'error',
				'Failed to sync product',
				[
					'product_id' => $productId,
					'error'      => $result['error'] ?? 'Unknown error',
				]
			);
		}

		return $result;
	}

	/**
	 * Sync a variable product and its variations.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array Result array.
	 */
	protected function syncVariableProduct( \WC_Product $product ): array {
		$parentId   = $product->get_id();
		$variations = $this->transformer->transformVariableProduct( $product );
		$synced     = 0;
		$errors     = [];

		foreach ( $variations as $variation ) {
			$variationId = $variation['variation_id'];
			$catalogData = $variation['catalog_data'];

			$result = $this->catalogApi->createProduct( $catalogData );

			if ( $result['success'] ) {
				$this->catalogApi->updateSyncStatus(
					$variationId,
					'synced',
					$result['catalog_item_id'] ?? ''
				);
				++$synced;
			} else {
				$this->catalogApi->updateSyncStatus(
					$variationId,
					'error',
					'',
					$result['error'] ?? 'Unknown error'
				);
				$errors[] = "Variation {$variationId}: " . ( $result['error'] ?? 'Unknown error' );
			}
		}

		// Update parent sync status.
		if ( empty( $errors ) ) {
			$this->catalogApi->updateSyncStatus( $parentId, 'synced' );
		} else {
			$this->catalogApi->updateSyncStatus(
				$parentId,
				'partial',
				'',
				implode( '; ', $errors )
			);
		}

		return [
			'success'      => $synced > 0,
			'synced_count' => $synced,
			'total_count'  => count( $variations ),
			'errors'       => $errors,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function syncAllProducts(): ?string {
		if ( ! $this->validator->isSyncEnabled() ) {
			$this->log( 'warning', 'Attempted to sync all products but sync is disabled', [] );
			return null;
		}

		// Check if a sync is already in progress.
		$existingProgress = $this->progressTracker->getProgress();
		if ( $existingProgress && 'in_progress' === $existingProgress['status'] ) {
			$this->log(
				'warning',
				'Bulk sync already in progress',
				[
					'sync_id' => $existingProgress['sync_id'],
				]
			);
			return $existingProgress['sync_id'];
		}

		// Get products to sync.
		$productIds = $this->getProductsToSync();

		if ( empty( $productIds ) ) {
			$this->log( 'info', 'No products found to sync', [] );
			return null;
		}

		// Initialize progress tracking.
		$syncId = $this->progressTracker->startSync( count( $productIds ) );

		$this->log(
			'info',
			'Starting bulk product sync',
			[
				'sync_id'        => $syncId,
				'total_products' => count( $productIds ),
			]
		);

		// Process in batches via queue.
		$batches = array_chunk( $productIds, self::BATCH_SIZE );

		foreach ( $batches as $batchIndex => $batch ) {
			$this->dispatchBatch( $batch, $batchIndex, count( $batches ), $syncId );
		}

		$this->log(
			'info',
			'Queued all product batches for sync',
			[
				'sync_id'       => $syncId,
				'total_batches' => count( $batches ),
			]
		);

		return $syncId;
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteProduct( int $productId ): array {
		$catalogItemId = $this->catalogApi->getCatalogItemId( $productId );

		if ( empty( $catalogItemId ) ) {
			return [
				'success' => false,
				'error'   => 'Product not synced to catalog',
			];
		}

		$result = $this->catalogApi->deleteProduct( $catalogItemId );

		if ( $result['success'] ) {
			$this->catalogApi->clearSyncMetadata( $productId );

			$this->log(
				'info',
				'Product removed from WhatsApp catalog',
				[
					'product_id' => $productId,
				]
			);
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getProductsToSync(): array {
		$syncProducts = $this->getSetting( 'catalog.sync_products', 'all' );

		// If specific products are configured.
		if ( 'all' !== $syncProducts && is_array( $syncProducts ) ) {
			return $syncProducts;
		}

		// Get published products using pagination.
		$allProductIds = [];
		$page          = 1;
		$perPage       = 100;

		$baseArgs = [
			'status' => 'publish',
			'return' => 'ids',
			'limit'  => $perPage,
		];

		// Exclude out of stock if setting enabled.
		$includeOutOfStock = $this->getSetting( 'catalog.include_out_of_stock', false );
		if ( ! $includeOutOfStock ) {
			$baseArgs['stock_status'] = 'instock';
		}

		do {
			$args       = array_merge( $baseArgs, [ 'page' => $page ] );
			$productIds = wc_get_products( $args );

			if ( empty( $productIds ) ) {
				break;
			}

			$allProductIds = array_merge( $allProductIds, $productIds );
			++$page;

			// Safety limit (max 100,000 products).
			if ( $page > 1000 ) {
				$this->log(
					'warning',
					'Product sync hit safety limit',
					[
						'fetched' => count( $allProductIds ),
					]
				);
				break;
			}
		} while ( count( $productIds ) === $perPage );

		return $allProductIds;
	}

	/**
	 * {@inheritdoc}
	 */
	public function retryFailedItems(): ?string {
		$failedProductIds = $this->progressTracker->getFailedItems();

		if ( empty( $failedProductIds ) ) {
			return null;
		}

		// Clear old progress.
		$this->progressTracker->clearProgress( true );

		// Start new sync with failed items.
		$syncId  = $this->progressTracker->startSync( count( $failedProductIds ) );
		$batches = array_chunk( $failedProductIds, self::BATCH_SIZE );

		foreach ( $batches as $batchIndex => $batch ) {
			$this->dispatchBatch( $batch, $batchIndex, count( $batches ), $syncId, true );
		}

		$this->log(
			'info',
			'Retrying failed sync items',
			[
				'sync_id'     => $syncId,
				'retry_count' => count( $failedProductIds ),
			]
		);

		return $syncId;
	}

	/**
	 * {@inheritdoc}
	 */
	public function handleProductUpdate( int $productId ): void {
		// Skip if sync is not enabled.
		if ( ! $this->validator->isSyncEnabled() ) {
			return;
		}

		// Skip auto-saves and revisions.
		if ( wp_is_post_autosave( $productId ) || wp_is_post_revision( $productId ) ) {
			return;
		}

		// Check if product data changed.
		if ( ! $this->validator->hasProductChanged( $productId ) ) {
			return;
		}

		// Queue sync.
		$this->dispatchSingleSync( $productId );

		$this->log(
			'debug',
			'Product update queued for sync',
			[
				'product_id' => $productId,
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function handleProductDelete( int $postId ): void {
		// Check if this is a product.
		if ( 'product' !== get_post_type( $postId ) ) {
			return;
		}

		// Skip if sync is not enabled.
		if ( ! $this->validator->isSyncEnabled() ) {
			return;
		}

		// Delete from catalog.
		$this->deleteProduct( $postId );
	}

	/**
	 * Process a batch of products (called by queue handler).
	 *
	 * @param array $args Job arguments.
	 * @return void
	 */
	public function processBatch( array $args ): void {
		$productIds   = $args['product_ids'] ?? [];
		$batchIndex   = $args['batch_index'] ?? 0;
		$totalBatches = $args['total_batches'] ?? 1;
		$syncId       = $args['sync_id'] ?? null;

		$this->log(
			'info',
			'Processing product batch',
			[
				'sync_id'       => $syncId,
				'batch_index'   => $batchIndex,
				'total_batches' => $totalBatches,
				'product_count' => count( $productIds ),
			]
		);

		$processed  = 0;
		$successful = 0;
		$failed     = 0;

		foreach ( $productIds as $productId ) {
			$result = $this->syncProduct( $productId );
			++$processed;

			if ( ! empty( $result['success'] ) ) {
				++$successful;
			} else {
				++$failed;

				if ( $syncId ) {
					$errorMessage = $result['error'] ?? 'Unknown error';
					$this->progressTracker->addFailure( $syncId, $productId, $errorMessage );
				}
			}
		}

		// Update progress tracking.
		if ( $syncId ) {
			$this->progressTracker->updateProgress( $syncId, $processed, $successful, $failed );
		}

		$this->log(
			'info',
			'Completed product batch',
			[
				'sync_id'    => $syncId,
				'batch'      => $batchIndex,
				'processed'  => $processed,
				'successful' => $successful,
				'failed'     => $failed,
			]
		);
	}

	/**
	 * Dispatch batch to queue.
	 *
	 * @param array  $productIds   Product IDs.
	 * @param int    $batchIndex   Batch index.
	 * @param int    $totalBatches Total batches.
	 * @param string $syncId       Sync session ID.
	 * @param bool   $isRetry      Whether this is a retry.
	 * @return void
	 */
	protected function dispatchBatch( array $productIds, int $batchIndex, int $totalBatches, string $syncId, bool $isRetry = false ): void {
		wch( JobDispatcher::class )->dispatch(
			'wch_sync_product_batch',
			[
				'product_ids'   => $productIds,
				'batch_index'   => $batchIndex,
				'total_batches' => $totalBatches,
				'sync_id'       => $syncId,
				'is_retry'      => $isRetry,
			]
		);
	}

	/**
	 * Dispatch single product sync to queue.
	 *
	 * @param int $productId Product ID.
	 * @return void
	 */
	protected function dispatchSingleSync( int $productId ): void {
		wch( JobDispatcher::class )->dispatch(
			'wch_sync_single_product',
			[ 'product_id' => $productId ]
		);
	}

	/**
	 * Get setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed Setting value.
	 */
	protected function getSetting( string $key, mixed $default = null ): mixed {
		if ( null !== $this->settings ) {
			return $this->settings->get( $key, $default );
		}

		return $default;
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
		$context['category'] = 'product-sync';

		if ( null !== $this->logger ) {
			$this->logger->log( $level, $message, 'product_sync', $context );
			return;
		}

		wch( LoggerInterface::class )->log( $level, $message, 'product_sync', $context );
	}
}
