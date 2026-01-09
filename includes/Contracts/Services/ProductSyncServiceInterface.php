<?php
/**
 * Product Sync Service Interface
 *
 * Contract for product synchronization with WhatsApp Catalog.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ProductSyncServiceInterface
 *
 * Defines the contract for product catalog synchronization operations.
 */
interface ProductSyncServiceInterface {

	/**
	 * Sync a product to WhatsApp Catalog.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array{success: bool, catalog_item_id: string|null, error: string|null}
	 */
	public function syncProduct( int $product_id ): array;

	/**
	 * Sync all eligible products to WhatsApp Catalog.
	 *
	 * Queues products in batches for async processing.
	 *
	 * @return array{queued: int, batches: int}
	 */
	public function syncAllProducts(): array;

	/**
	 * Delete product from WhatsApp Catalog.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array{success: bool, error: string|null}
	 */
	public function deleteFromCatalog( int $product_id ): array;

	/**
	 * Validate product for catalog sync.
	 *
	 * Checks publication status, stock, and sync list.
	 *
	 * @param int $product_id Product ID.
	 * @return array{valid: bool, reason: string|null}
	 */
	public function validateProduct( int $product_id ): array;

	/**
	 * Get product sync status.
	 *
	 * @param int $product_id Product ID.
	 * @return array{status: string, last_synced: string|null, catalog_id: string|null, error: string|null}
	 */
	public function getSyncStatus( int $product_id ): array;

	/**
	 * Check if product data has changed since last sync.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True if product changed.
	 */
	public function hasProductChanged( int $product_id ): bool;

	/**
	 * Map WooCommerce product to WhatsApp catalog format.
	 *
	 * @param int      $product_id Product ID.
	 * @param int|null $parent_id  Parent product ID for variations.
	 * @return array Catalog data format.
	 */
	public function mapToCatalogFormat( int $product_id, ?int $parent_id = null ): array;

	/**
	 * Get products pending sync.
	 *
	 * @param int $limit Maximum products to return.
	 * @return array Array of product IDs.
	 */
	public function getProductsPendingSync( int $limit = 100 ): array;

	/**
	 * Get products with sync errors.
	 *
	 * @param int $limit Maximum products to return.
	 * @return array Array of product data with errors.
	 */
	public function getProductsWithErrors( int $limit = 100 ): array;

	/**
	 * Retry failed product syncs.
	 *
	 * @return array{retried: int, success: int, failed: int}
	 */
	public function retryFailedSyncs(): array;

	/**
	 * Check if sync is enabled.
	 *
	 * @return bool True if sync is enabled.
	 */
	public function isSyncEnabled(): bool;

	/**
	 * Get catalog ID.
	 *
	 * @return string|null WhatsApp catalog ID.
	 */
	public function getCatalogId(): ?string;

	/**
	 * Get sync statistics.
	 *
	 * @return array{total: int, synced: int, pending: int, errors: int, last_sync: string|null}
	 */
	public function getSyncStats(): array;

	/**
	 * Process a batch of products for sync.
	 *
	 * @param array $product_ids Product IDs to sync.
	 * @return array{success: int, failed: int, errors: array}
	 */
	public function processBatch( array $product_ids ): array;

	/**
	 * Handle product update event.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function onProductUpdate( int $product_id ): void;

	/**
	 * Handle product delete event.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function onProductDelete( int $product_id ): void;
}
