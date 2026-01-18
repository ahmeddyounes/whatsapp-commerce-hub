<?php
/**
 * Product Sync Metadata Constants
 *
 * Centralized constants for product sync metadata keys.
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
 * Class ProductSyncMetadata
 *
 * Defines all metadata keys used for product sync operations.
 */
final class ProductSyncMetadata {

	/**
	 * MD5 hash of product data for change detection.
	 */
	public const SYNC_HASH = '_wch_sync_hash';

	/**
	 * WhatsApp catalog item ID.
	 */
	public const CATALOG_ID = '_wch_catalog_id';

	/**
	 * Timestamp of last successful sync.
	 */
	public const LAST_SYNCED = '_wch_last_synced';

	/**
	 * Current sync status (synced/error/partial/pending/not_synced).
	 */
	public const SYNC_STATUS = '_wch_sync_status';

	/**
	 * Error message or status message.
	 */
	public const SYNC_MESSAGE = '_wch_sync_message';

	/**
	 * Get all metadata keys as array.
	 *
	 * @return array Array of all metadata key constants.
	 */
	public static function getAllKeys(): array {
		return [
			self::SYNC_HASH,
			self::CATALOG_ID,
			self::LAST_SYNCED,
			self::SYNC_STATUS,
			self::SYNC_MESSAGE,
		];
	}
}
