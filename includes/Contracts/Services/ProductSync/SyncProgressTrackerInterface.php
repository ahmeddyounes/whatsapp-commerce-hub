<?php
/**
 * Sync Progress Tracker Interface
 *
 * Contract for tracking bulk sync progress with atomic operations.
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
 * Interface SyncProgressTrackerInterface
 *
 * Defines contract for bulk sync progress tracking.
 */
interface SyncProgressTrackerInterface {

	/**
	 * Start a new bulk sync session.
	 *
	 * @param int $totalItems Total number of items to sync.
	 * @return string Unique sync session ID.
	 */
	public function startSync( int $totalItems ): string;

	/**
	 * Update sync progress counters atomically.
	 *
	 * @param string $syncId     Sync session ID.
	 * @param int    $processed  Number of items processed.
	 * @param int    $successful Number of successful syncs.
	 * @param int    $failed     Number of failed syncs.
	 * @return bool True if update succeeded.
	 */
	public function updateProgress( string $syncId, int $processed, int $successful, int $failed ): bool;

	/**
	 * Record a failed sync item with error details.
	 *
	 * @param string $syncId       Sync session ID.
	 * @param int    $productId    Product ID that failed.
	 * @param string $errorMessage Error message.
	 * @return bool True if recorded successfully.
	 */
	public function addFailure( string $syncId, int $productId, string $errorMessage ): bool;

	/**
	 * Get current sync progress.
	 *
	 * @return array|null Progress data or null if no sync.
	 */
	public function getProgress(): ?array;

	/**
	 * Mark sync as failed.
	 *
	 * @param string $syncId Sync session ID.
	 * @param string $reason Failure reason.
	 * @return bool True if marked successfully.
	 */
	public function failSync( string $syncId, string $reason ): bool;

	/**
	 * Clear sync progress data.
	 *
	 * @param bool $force Force clear even if sync is in progress.
	 * @return bool True if cleared successfully.
	 */
	public function clearProgress( bool $force = false ): bool;

	/**
	 * Get failed items from last sync.
	 *
	 * @return array Array of failed product IDs.
	 */
	public function getFailedItems(): array;

	/**
	 * Check if a sync is currently in progress.
	 *
	 * @return bool True if sync is in progress.
	 */
	public function isSyncInProgress(): bool;

	/**
	 * Get the current sync session ID if sync is in progress.
	 *
	 * @return string|null Sync ID or null if no sync in progress.
	 */
	public function getCurrentSyncId(): ?string;
}
