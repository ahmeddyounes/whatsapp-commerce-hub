<?php
/**
 * Catalog Transformer Interface
 *
 * Contract for transforming WooCommerce products to WhatsApp catalog format.
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
 * Interface CatalogTransformerInterface
 *
 * Defines contract for product data transformation.
 */
interface CatalogTransformerInterface {

	/**
	 * Transform WooCommerce product to WhatsApp catalog format.
	 *
	 * @param WC_Product $product  Product to transform.
	 * @param int|null   $parentId Parent product ID for variations.
	 * @return array Catalog data in WhatsApp format.
	 */
	public function transform( WC_Product $product, ?int $parentId = null ): array;

	/**
	 * Transform variable product and its variations.
	 *
	 * @param WC_Product $product Variable product.
	 * @return array Array of transformed variations.
	 */
	public function transformVariableProduct( WC_Product $product ): array;

	/**
	 * Truncate and sanitize product name.
	 *
	 * @param string $name    Product name.
	 * @param int    $maxLength Maximum length (default 200).
	 * @return string Sanitized name.
	 */
	public function sanitizeName( string $name, int $maxLength = 200 ): string;

	/**
	 * Truncate and sanitize product description.
	 *
	 * @param string $description Product description.
	 * @param int    $maxLength   Maximum length (default 9999).
	 * @return string Sanitized description.
	 */
	public function sanitizeDescription( string $description, int $maxLength = 9999 ): string;
}
