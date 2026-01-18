<?php
/**
 * Product Sync Settings Constants
 *
 * Centralized constants for product sync settings keys.
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
 * Class ProductSyncSettings
 *
 * Defines all settings keys used for product sync configuration.
 */
final class ProductSyncSettings {

	/**
	 * Enable/disable product sync.
	 */
	public const SYNC_ENABLED = 'catalog.sync_enabled';

	/**
	 * WhatsApp catalog ID.
	 */
	public const CATALOG_ID = 'catalog.catalog_id';

	/**
	 * Products to sync: 'all' or array of product IDs.
	 */
	public const SYNC_PRODUCTS = 'catalog.sync_products';

	/**
	 * Include out-of-stock products in sync.
	 */
	public const INCLUDE_OUT_OF_STOCK = 'catalog.include_out_of_stock';

	/**
	 * WhatsApp Business phone number ID.
	 */
	public const PHONE_NUMBER_ID = 'api.whatsapp_phone_number_id';

	/**
	 * WhatsApp API access token.
	 */
	public const ACCESS_TOKEN = 'api.access_token';

	/**
	 * Sync mode: 'manual', 'on_change', or 'scheduled'.
	 */
	public const SYNC_MODE = 'sync.mode';

	/**
	 * Sync frequency: 'hourly', 'twicedaily', or 'daily'.
	 */
	public const SYNC_FREQUENCY = 'sync.frequency';

	/**
	 * Category IDs to include in sync.
	 */
	public const CATEGORIES_INCLUDE = 'sync.categories_include';

	/**
	 * Category IDs to exclude from sync.
	 */
	public const CATEGORIES_EXCLUDE = 'sync.categories_exclude';

	/**
	 * Timestamp of last full sync.
	 */
	public const LAST_FULL_SYNC = 'sync.last_full_sync';

	/**
	 * Get all settings keys as array.
	 *
	 * @return array Array of all settings key constants.
	 */
	public static function getAllKeys(): array {
		return [
			self::SYNC_ENABLED,
			self::CATALOG_ID,
			self::SYNC_PRODUCTS,
			self::INCLUDE_OUT_OF_STOCK,
			self::PHONE_NUMBER_ID,
			self::ACCESS_TOKEN,
			self::SYNC_MODE,
			self::SYNC_FREQUENCY,
			self::CATEGORIES_INCLUDE,
			self::CATEGORIES_EXCLUDE,
			self::LAST_FULL_SYNC,
		];
	}
}
