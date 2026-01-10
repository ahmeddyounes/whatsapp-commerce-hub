<?php
/**
 * Catalog Sync Service Interface
 *
 * Defines the contract for product catalog synchronization operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.1.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CatalogSyncServiceInterface
 *
 * Contract for catalog synchronization management.
 */
interface CatalogSyncServiceInterface {

	/**
	 * Get sync status overview.
	 *
	 * @return array Sync status data (total_synced, last_sync, error_count).
	 */
	public function getSyncStatusOverview(): array;

	/**
	 * Get products with filtering and pagination.
	 *
	 * @param array $filters Filter criteria (page, per_page, search, category, stock, sync_status).
	 * @return array Products data with pagination info.
	 */
	public function getProducts( array $filters ): array;

	/**
	 * Sync multiple products.
	 *
	 * @param array $product_ids Product IDs to sync.
	 * @param bool  $sync_all Whether to sync all products.
	 * @return array Result with status and count.
	 */
	public function bulkSync( array $product_ids, bool $sync_all = false ): array;

	/**
	 * Sync a single product.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result with success status.
	 */
	public function syncProduct( int $product_id ): array;

	/**
	 * Remove products from catalog.
	 *
	 * @param array $product_ids Product IDs to remove.
	 * @return array Result with count.
	 */
	public function removeFromCatalog( array $product_ids ): array;

	/**
	 * Get sync history.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Items per page.
	 * @return array History entries with pagination info.
	 */
	public function getSyncHistory( int $page = 1, int $per_page = 20 ): array;

	/**
	 * Record sync history entry.
	 *
	 * @param int    $product_count Products affected.
	 * @param string $triggered_by Who triggered the sync.
	 * @param string $status Sync status (success, error).
	 * @param int    $duration Duration in seconds.
	 * @param array  $errors List of errors.
	 * @return void
	 */
	public function recordSyncHistory(
		int $product_count,
		string $triggered_by = 'manual',
		string $status = 'success',
		int $duration = 0,
		array $errors = array()
	): void;

	/**
	 * Save sync settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool True if saved successfully.
	 */
	public function saveSyncSettings( array $settings ): bool;

	/**
	 * Perform dry run sync (preview).
	 *
	 * @param int $limit Maximum number of products to preview.
	 * @return array Products that would be synced (count, preview_count, products, truncated).
	 */
	public function dryRunSync( int $limit = 100 ): array;

	/**
	 * Retry failed products.
	 *
	 * @return array Result with count of retried products.
	 */
	public function retryFailed(): array;

	/**
	 * Get bulk sync progress.
	 *
	 * @return array|null Progress data or null if no sync in progress.
	 */
	public function getBulkSyncProgress(): ?array;

	/**
	 * Clear sync progress.
	 *
	 * @return bool True if cleared, false if sync in progress.
	 */
	public function clearSyncProgress(): bool;
}
