<?php
declare(strict_types=1);

/**
 * Product Validator Service
 *
 * Validates WooCommerce products before WhatsApp catalog sync.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\ProductSync;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductValidatorInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WC_Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductValidatorService
 *
 * Handles product validation logic for catalog sync.
 */
class ProductValidatorService implements ProductValidatorInterface {

	/**
	 * Meta key for sync hash.
	 */
	public const META_SYNC_HASH = '_wch_sync_hash';

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface|null
	 */
	protected ?SettingsInterface $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface|null $settings Settings service.
	 */
	public function __construct( ?SettingsInterface $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate( WC_Product $product ): array {
		// Must be published.
		if ( 'publish' !== $product->get_status() ) {
			return array(
				'valid'  => false,
				'reason' => 'Product is not published',
			);
		}

		// Check stock if setting enabled.
		$includeOutOfStock = $this->getSetting( 'catalog.include_out_of_stock', false );
		if ( ! $includeOutOfStock && ! $product->is_in_stock() ) {
			return array(
				'valid'  => false,
				'reason' => 'Product is out of stock',
			);
		}

		// Check if product is in allowed list.
		$syncProducts = $this->getSetting( 'catalog.sync_products', 'all' );
		if ( 'all' !== $syncProducts && is_array( $syncProducts ) ) {
			if ( ! in_array( $product->get_id(), $syncProducts, true ) ) {
				return array(
					'valid'  => false,
					'reason' => 'Product is not in the sync list',
				);
			}
		}

		// Must have a price.
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return array(
				'valid'  => false,
				'reason' => 'Product has no price',
			);
		}

		// Must have a name.
		if ( '' === trim( $product->get_name() ) ) {
			return array(
				'valid'  => false,
				'reason' => 'Product has no name',
			);
		}

		/**
		 * Filter product validation result.
		 *
		 * @param array      $result  Validation result.
		 * @param WC_Product $product Product being validated.
		 */
		return apply_filters( 'wch_product_validation', array( 'valid' => true ), $product );
	}

	/**
	 * {@inheritdoc}
	 */
	public function hasProductChanged( int $productId ): bool {
		$product = wc_get_product( $productId );
		if ( ! $product ) {
			return false;
		}

		$currentHash = $this->generateProductHash( $product );
		$storedHash  = get_post_meta( $productId, self::META_SYNC_HASH, true );

		// Update stored hash.
		update_post_meta( $productId, self::META_SYNC_HASH, $currentHash );

		// Return true if hash changed or no previous hash.
		return empty( $storedHash ) || $storedHash !== $currentHash;
	}

	/**
	 * {@inheritdoc}
	 */
	public function generateProductHash( WC_Product $product ): string {
		$dataToHash = array(
			'name'        => $product->get_name(),
			'price'       => $product->get_price(),
			'stock'       => $product->get_stock_status(),
			'image'       => $product->get_image_id(),
			'description' => $product->get_description(),
			'categories'  => $product->get_category_ids(),
		);

		return md5( wp_json_encode( $dataToHash ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function isSyncEnabled(): bool {
		return (bool) $this->getSetting( 'catalog.sync_enabled', false );
	}

	/**
	 * Get setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed Setting value.
	 */
	protected function getSetting( string $key, mixed $default = null ): mixed {
		if ( null !== $this->settings ) {
			return $this->settings->get( $key, $default );
		}

		// Fallback to legacy settings.
		if ( class_exists( 'WCH_Settings' ) ) {
			return \WCH_Settings::instance()->get( $key, $default );
		}

		return $default;
	}
}
