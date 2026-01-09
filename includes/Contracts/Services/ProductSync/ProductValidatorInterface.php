<?php
/**
 * Product Validator Interface
 *
 * Contract for validating products before catalog sync.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\ProductSync;

use WC_Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ProductValidatorInterface
 *
 * Defines contract for product validation before sync.
 */
interface ProductValidatorInterface {

	/**
	 * Validate if a product should be synced.
	 *
	 * @param WC_Product $product Product to validate.
	 * @return array{valid: bool, reason?: string} Validation result.
	 */
	public function validate( WC_Product $product ): array;

	/**
	 * Check if product data has changed since last sync.
	 *
	 * @param int $productId Product ID.
	 * @return bool True if product changed.
	 */
	public function hasProductChanged( int $productId ): bool;

	/**
	 * Generate hash of product data for change detection.
	 *
	 * @param WC_Product $product Product to hash.
	 * @return string Hash of product data.
	 */
	public function generateProductHash( WC_Product $product ): string;

	/**
	 * Check if sync is enabled in settings.
	 *
	 * @return bool
	 */
	public function isSyncEnabled(): bool;
}
