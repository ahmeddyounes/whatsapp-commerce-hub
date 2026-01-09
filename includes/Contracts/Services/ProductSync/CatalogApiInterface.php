<?php
/**
 * Catalog API Interface
 *
 * Contract for WhatsApp Catalog API operations.
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
 * Interface CatalogApiInterface
 *
 * Defines contract for catalog API operations.
 */
interface CatalogApiInterface {

	/**
	 * Create or update a product in the WhatsApp catalog.
	 *
	 * @param array $catalogData Product data in WhatsApp format.
	 * @return array{success: bool, catalog_item_id?: string, error?: string} Result.
	 */
	public function createProduct( array $catalogData ): array;

	/**
	 * Delete a product from the WhatsApp catalog.
	 *
	 * @param string $catalogItemId Catalog item ID to delete.
	 * @return array{success: bool, error?: string} Result.
	 */
	public function deleteProduct( string $catalogItemId ): array;

	/**
	 * Get catalog ID from settings.
	 *
	 * @return string|null Catalog ID or null if not configured.
	 */
	public function getCatalogId(): ?string;

	/**
	 * Check if API is properly configured.
	 *
	 * @return bool True if API is configured.
	 */
	public function isConfigured(): bool;
}
