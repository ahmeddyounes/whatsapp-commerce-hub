<?php
/**
 * Catalog Transformer Service
 *
 * Transforms WooCommerce products to WhatsApp catalog format.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\ProductSync;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\CatalogTransformerInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductValidatorInterface;
use WC_Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CatalogTransformerService
 *
 * Handles WooCommerce to WhatsApp catalog data transformation.
 */
class CatalogTransformerService implements CatalogTransformerInterface {

	/**
	 * Maximum name length per WhatsApp spec.
	 */
	public const MAX_NAME_LENGTH = 200;

	/**
	 * Maximum description length per WhatsApp spec.
	 */
	public const MAX_DESCRIPTION_LENGTH = 9999;

	/**
	 * Constructor.
	 *
	 * @param ProductValidatorInterface|null $validator Product validator.
	 */
	public function __construct( protected ?ProductValidatorInterface $validator = null ) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function transform( WC_Product $product, ?int $parentId = null ): array {
		$productId = $product->get_id();

		// Build product name.
		$name = $this->sanitizeName( $product->get_name() );

		// Build description.
		$description = $product->get_description();
		if ( empty( $description ) ) {
			$description = $product->get_short_description();
		}
		$description = $this->sanitizeDescription( $description );

		// Price in cents.
		$price = (int) ( (float) $product->get_price() * 100 );

		// Currency.
		$currency = get_woocommerce_currency();

		// Product URL.
		$url = get_permalink( $productId );

		// Main image URL.
		$imageId  = $product->get_image_id();
		$imageUrl = $imageId ? wp_get_attachment_image_url( $imageId, 'full' ) : '';

		// Availability.
		$availability = $product->is_in_stock() ? 'in stock' : 'out of stock';

		// Category.
		$category = $this->getProductCategory( $product );

		// Brand.
		$brand = $this->getProductBrand( $product );

		$catalogData = [
			'retailer_id'  => (string) $productId,
			'name'         => $name,
			'description'  => $description,
			'price'        => $price,
			'currency'     => $currency,
			'url'          => $url,
			'availability' => $availability,
		];

		// Add optional fields.
		if ( ! empty( $imageUrl ) ) {
			$catalogData['image_url'] = $imageUrl;
		}

		if ( ! empty( $category ) ) {
			$catalogData['category'] = $category;
		}

		if ( ! empty( $brand ) ) {
			$catalogData['brand'] = $brand;
		}

		// Add SKU if available.
		$sku = $product->get_sku();
		if ( ! empty( $sku ) ) {
			$catalogData['sku'] = $sku;
		}

		// Add parent reference for variations.
		if ( $parentId ) {
			$catalogData['item_group_id'] = (string) $parentId;
		}

		/**
		 * Filter catalog data before sync.
		 *
		 * @param array      $catalogData Catalog data.
		 * @param WC_Product $product     Product being transformed.
		 * @param int|null   $parentId    Parent product ID.
		 */
		return apply_filters( 'wch_catalog_data', $catalogData, $product, $parentId );
	}

	/**
	 * {@inheritdoc}
	 */
	public function transformVariableProduct( WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return [ $this->transform( $product ) ];
		}

		$parentId    = $product->get_id();
		$variations  = $product->get_available_variations();
		$transformed = [];

		foreach ( $variations as $variationData ) {
			$variationId = $variationData['variation_id'];
			$variation   = wc_get_product( $variationId );

			if ( ! $variation ) {
				continue;
			}

			// Validate variation if validator available.
			if ( null !== $this->validator ) {
				$validation = $this->validator->validate( $variation );
				if ( ! $validation['valid'] ) {
					continue;
				}
			}

			$transformed[] = [
				'variation_id' => $variationId,
				'catalog_data' => $this->transform( $variation, $parentId ),
			];
		}

		return $transformed;
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitizeName( string $name, int $maxLength = self::MAX_NAME_LENGTH ): string {
		$name = trim( $name );

		if ( mb_strlen( $name ) > $maxLength ) {
			$name = mb_substr( $name, 0, $maxLength - 3 ) . '...';
		}

		return $name;
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitizeDescription( string $description, int $maxLength = self::MAX_DESCRIPTION_LENGTH ): string {
		// Strip HTML tags.
		$description = wp_strip_all_tags( $description );

		// Normalize whitespace.
		$description = preg_replace( '/\s+/', ' ', $description );
		$description = trim( $description );

		if ( mb_strlen( $description ) > $maxLength ) {
			$description = mb_substr( $description, 0, $maxLength - 3 ) . '...';
		}

		return $description;
	}

	/**
	 * Get product category name.
	 *
	 * @param WC_Product $product Product.
	 * @return string Category name or empty string.
	 */
	protected function getProductCategory( WC_Product $product ): string {
		$categories = $product->get_category_ids();

		if ( empty( $categories ) ) {
			return '';
		}

		$categoryTerm = get_term( $categories[0], 'product_cat' );

		if ( $categoryTerm && ! is_wp_error( $categoryTerm ) ) {
			return $categoryTerm->name;
		}

		return '';
	}

	/**
	 * Get product brand (from tags or custom attribute).
	 *
	 * @param WC_Product $product Product.
	 * @return string Brand name or empty string.
	 */
	protected function getProductBrand( WC_Product $product ): string {
		// Check for brand attribute first.
		$brand = $product->get_attribute( 'brand' );
		if ( ! empty( $brand ) ) {
			return $brand;
		}

		// Fall back to first tag.
		$tags = $product->get_tag_ids();
		if ( ! empty( $tags ) ) {
			$tagTerm = get_term( $tags[0], 'product_tag' );
			if ( $tagTerm && ! is_wp_error( $tagTerm ) ) {
				return $tagTerm->name;
			}
		}

		return '';
	}
}
