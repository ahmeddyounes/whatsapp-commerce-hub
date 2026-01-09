<?php
/**
 * Product Sync Orchestrator Interface
 *
 * Contract for coordinating product sync operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\ProductSync;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ProductSyncOrchestratorInterface
 *
 * Defines contract for product sync orchestration.
 */
interface ProductSyncOrchestratorInterface {

	/**
	 * Sync a single product to WhatsApp catalog.
	 *
	 * @param int $productId Product ID to sync.
	 * @return array{success: bool, catalog_item_id?: string, error?: string} Result.
	 */
	public function syncProduct( int $productId ): array;

	/**
	 * Sync all eligible products.
	 *
	 * @return string|null Sync session ID or null on failure.
	 */
	public function syncAllProducts(): ?string;

	/**
	 * Delete a product from the catalog.
	 *
	 * @param int $productId Product ID to delete.
	 * @return array{success: bool, error?: string} Result.
	 */
	public function deleteProduct( int $productId ): array;

	/**
	 * Get all product IDs eligible for sync.
	 *
	 * @return array Array of product IDs.
	 */
	public function getProductsToSync(): array;

	/**
	 * Retry failed items from last sync.
	 *
	 * @return string|null New sync ID or null if no failed items.
	 */
	public function retryFailedItems(): ?string;

	/**
	 * Handle WooCommerce product update hook.
	 *
	 * @param int $productId Product ID.
	 * @return void
	 */
	public function handleProductUpdate( int $productId ): void;

	/**
	 * Handle WooCommerce product delete hook.
	 *
	 * @param int $postId Post ID.
	 * @return void
	 */
	public function handleProductDelete( int $postId ): void;
}
