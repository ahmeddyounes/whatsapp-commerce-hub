<?php
/**
 * Product Sync Status Constants
 *
 * Centralized constants for product sync status values.
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
 * Class ProductSyncStatus
 *
 * Defines all possible product sync status values.
 */
final class ProductSyncStatus {

	/**
	 * Product successfully synced to WhatsApp catalog.
	 */
	public const SYNCED = 'synced';

	/**
	 * Product sync failed with error.
	 */
	public const ERROR = 'error';

	/**
	 * Product partially synced (some variations failed).
	 */
	public const PARTIAL = 'partial';

	/**
	 * Product queued for sync but not yet processed.
	 */
	public const PENDING = 'pending';

	/**
	 * Product not synced (initial state).
	 */
	public const NOT_SYNCED = 'not_synced';

	/**
	 * Get all valid status values.
	 *
	 * @return array Array of all status constants.
	 */
	public static function getAllStatuses(): array {
		return [
			self::SYNCED,
			self::ERROR,
			self::PARTIAL,
			self::PENDING,
			self::NOT_SYNCED,
		];
	}

	/**
	 * Check if a status value is valid.
	 *
	 * @param string $status Status to validate.
	 * @return bool True if valid status.
	 */
	public static function isValid( string $status ): bool {
		return in_array( $status, self::getAllStatuses(), true );
	}
}
