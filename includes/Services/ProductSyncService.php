<?php
/**
 * Product Sync Service
 *
 * Handles product synchronization between WooCommerce and WhatsApp Catalog.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Services;

use WhatsAppCommerceHub\Contracts\Services\ProductSyncServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\QueueServiceInterface;
use WhatsAppCommerceHub\Contracts\Clients\WhatsAppClientInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductSyncService
 *
 * Implements product catalog synchronization operations.
 */
class ProductSyncService implements ProductSyncServiceInterface {

	/**
	 * Meta key for catalog item ID.
	 */
	private const META_CATALOG_ID = '_wch_catalog_item_id';

	/**
	 * Meta key for sync status.
	 */
	private const META_SYNC_STATUS = '_wch_sync_status';

	/**
	 * Meta key for last sync time.
	 */
	private const META_LAST_SYNCED = '_wch_last_synced';

	/**
	 * Meta key for sync error.
	 */
	private const META_SYNC_ERROR = '_wch_sync_error';

	/**
	 * Meta key for product data hash.
	 */
	private const META_DATA_HASH = '_wch_data_hash';

	/**
	 * Sync status values.
	 */
	private const STATUS_PENDING = 'pending';
	private const STATUS_SYNCED  = 'synced';
	private const STATUS_ERROR   = 'error';

	/**
	 * Batch size for bulk operations.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * WhatsApp API client.
	 *
	 * @var WhatsAppClientInterface|null
	 */
	private ?WhatsAppClientInterface $whatsapp_client;

	/**
	 * Queue service.
	 *
	 * @var QueueServiceInterface|null
	 */
	private ?QueueServiceInterface $queue_service;

	/**
	 * Constructor.
	 *
	 * @param WhatsAppClientInterface|null $whatsapp_client WhatsApp API client.
	 * @param QueueServiceInterface|null   $queue_service   Queue service.
	 */
	public function __construct(
		?WhatsAppClientInterface $whatsapp_client = null,
		?QueueServiceInterface $queue_service = null
	) {
		$this->whatsapp_client = $whatsapp_client;
		$this->queue_service   = $queue_service;
	}

	/**
	 * Sync a product to WhatsApp Catalog.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array{success: bool, catalog_item_id: string|null, error: string|null}
	 */
	public function syncProduct( int $product_id ): array {
		// Validate product.
		$validation = $this->validateProduct( $product_id );
		if ( ! $validation['valid'] ) {
			return array(
				'success'         => false,
				'catalog_item_id' => null,
				'error'           => $validation['reason'],
			);
		}

		// Check if sync is enabled.
		if ( ! $this->isSyncEnabled() ) {
			return array(
				'success'         => false,
				'catalog_item_id' => null,
				'error'           => 'Product sync is disabled',
			);
		}

		// Check if client is available.
		if ( ! $this->whatsapp_client ) {
			return array(
				'success'         => false,
				'catalog_item_id' => null,
				'error'           => 'WhatsApp client not available',
			);
		}

		try {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				throw new \InvalidArgumentException( 'Product not found' );
			}

			// Map to catalog format.
			$catalog_data = $this->mapToCatalogFormat( $product_id );

			// Get existing catalog ID.
			$existing_catalog_id = get_post_meta( $product_id, self::META_CATALOG_ID, true );

			// Send to WhatsApp API.
			if ( $existing_catalog_id ) {
				// Update existing.
				$result = $this->whatsapp_client->updateCatalogItem(
					$this->getCatalogId(),
					$existing_catalog_id,
					$catalog_data
				);
			} else {
				// Create new.
				$result = $this->whatsapp_client->createCatalogItem(
					$this->getCatalogId(),
					$catalog_data
				);
			}

			if ( ! $result['success'] ) {
				throw new \RuntimeException( $result['error'] ?? 'Unknown API error' );
			}

			$catalog_item_id = $result['item_id'] ?? $existing_catalog_id;

			// Update product metadata.
			update_post_meta( $product_id, self::META_CATALOG_ID, $catalog_item_id );
			update_post_meta( $product_id, self::META_SYNC_STATUS, self::STATUS_SYNCED );
			update_post_meta( $product_id, self::META_LAST_SYNCED, current_time( 'mysql', true ) );
			update_post_meta( $product_id, self::META_DATA_HASH, $this->calculateDataHash( $product ) );
			delete_post_meta( $product_id, self::META_SYNC_ERROR );

			do_action( 'wch_product_synced', $product_id, $catalog_item_id );

			return array(
				'success'         => true,
				'catalog_item_id' => $catalog_item_id,
				'error'           => null,
			);

		} catch ( \Exception $e ) {
			// Record error.
			update_post_meta( $product_id, self::META_SYNC_STATUS, self::STATUS_ERROR );
			update_post_meta( $product_id, self::META_SYNC_ERROR, $e->getMessage() );

			do_action( 'wch_log_error', 'ProductSyncService: Sync failed', array(
				'product_id' => $product_id,
				'error'      => $e->getMessage(),
			) );

			return array(
				'success'         => false,
				'catalog_item_id' => null,
				'error'           => $e->getMessage(),
			);
		}
	}

	/**
	 * Sync all eligible products to WhatsApp Catalog.
	 *
	 * @return array{queued: int, batches: int}
	 */
	public function syncAllProducts(): array {
		if ( ! $this->isSyncEnabled() ) {
			return array( 'queued' => 0, 'batches' => 0 );
		}

		// Get all eligible product IDs.
		$product_ids = $this->getEligibleProductIds();
		$total       = count( $product_ids );

		if ( 0 === $total ) {
			return array( 'queued' => 0, 'batches' => 0 );
		}

		// Split into batches.
		$batches      = array_chunk( $product_ids, self::BATCH_SIZE );
		$batch_count  = count( $batches );
		$queued       = 0;

		foreach ( $batches as $index => $batch ) {
			$scheduled = $this->queueBatch( $batch, $index );
			if ( $scheduled ) {
				$queued += count( $batch );
			}
		}

		do_action( 'wch_product_sync_started', $queued, $batch_count );

		return array(
			'queued'  => $queued,
			'batches' => $batch_count,
		);
	}

	/**
	 * Delete product from WhatsApp Catalog.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array{success: bool, error: string|null}
	 */
	public function deleteFromCatalog( int $product_id ): array {
		$catalog_item_id = get_post_meta( $product_id, self::META_CATALOG_ID, true );

		if ( empty( $catalog_item_id ) ) {
			// Not in catalog, nothing to delete.
			return array( 'success' => true, 'error' => null );
		}

		if ( ! $this->whatsapp_client ) {
			return array( 'success' => false, 'error' => 'WhatsApp client not available' );
		}

		try {
			$result = $this->whatsapp_client->deleteCatalogItem(
				$this->getCatalogId(),
				$catalog_item_id
			);

			if ( ! $result['success'] ) {
				throw new \RuntimeException( $result['error'] ?? 'Delete failed' );
			}

			// Clean up metadata.
			delete_post_meta( $product_id, self::META_CATALOG_ID );
			delete_post_meta( $product_id, self::META_SYNC_STATUS );
			delete_post_meta( $product_id, self::META_LAST_SYNCED );
			delete_post_meta( $product_id, self::META_SYNC_ERROR );
			delete_post_meta( $product_id, self::META_DATA_HASH );

			do_action( 'wch_product_deleted_from_catalog', $product_id, $catalog_item_id );

			return array( 'success' => true, 'error' => null );

		} catch ( \Exception $e ) {
			do_action( 'wch_log_error', 'ProductSyncService: Delete failed', array(
				'product_id'      => $product_id,
				'catalog_item_id' => $catalog_item_id,
				'error'           => $e->getMessage(),
			) );

			return array( 'success' => false, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Validate product for catalog sync.
	 *
	 * @param int $product_id Product ID.
	 * @return array{valid: bool, reason: string|null}
	 */
	public function validateProduct( int $product_id ): array {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array( 'valid' => false, 'reason' => 'Product not found' );
		}

		// Check publication status.
		if ( 'publish' !== $product->get_status() ) {
			return array( 'valid' => false, 'reason' => 'Product is not published' );
		}

		// Check visibility.
		$visibility = $product->get_catalog_visibility();
		if ( 'hidden' === $visibility ) {
			return array( 'valid' => false, 'reason' => 'Product is hidden from catalog' );
		}

		// Check if excluded from sync.
		$excluded = get_post_meta( $product_id, '_wch_exclude_from_sync', true );
		if ( '1' === $excluded ) {
			return array( 'valid' => false, 'reason' => 'Product excluded from sync' );
		}

		// Check stock status (optional based on settings).
		$sync_out_of_stock = get_option( 'wch_sync_out_of_stock', '0' ) === '1';
		if ( ! $sync_out_of_stock && ! $product->is_in_stock() ) {
			return array( 'valid' => false, 'reason' => 'Product is out of stock' );
		}

		// Check for required fields.
		if ( empty( $product->get_name() ) ) {
			return array( 'valid' => false, 'reason' => 'Product name is required' );
		}

		if ( empty( $product->get_price() ) ) {
			return array( 'valid' => false, 'reason' => 'Product price is required' );
		}

		// Check for valid image.
		$image_id = $product->get_image_id();
		if ( empty( $image_id ) ) {
			return array( 'valid' => false, 'reason' => 'Product image is required' );
		}

		return array( 'valid' => true, 'reason' => null );
	}

	/**
	 * Get product sync status.
	 *
	 * @param int $product_id Product ID.
	 * @return array{status: string, last_synced: string|null, catalog_id: string|null, error: string|null}
	 */
	public function getSyncStatus( int $product_id ): array {
		return array(
			'status'      => get_post_meta( $product_id, self::META_SYNC_STATUS, true ) ?: self::STATUS_PENDING,
			'last_synced' => get_post_meta( $product_id, self::META_LAST_SYNCED, true ) ?: null,
			'catalog_id'  => get_post_meta( $product_id, self::META_CATALOG_ID, true ) ?: null,
			'error'       => get_post_meta( $product_id, self::META_SYNC_ERROR, true ) ?: null,
		);
	}

	/**
	 * Check if product data has changed since last sync.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True if product changed.
	 */
	public function hasProductChanged( int $product_id ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$stored_hash  = get_post_meta( $product_id, self::META_DATA_HASH, true );
		$current_hash = $this->calculateDataHash( $product );

		return $stored_hash !== $current_hash;
	}

	/**
	 * Map WooCommerce product to WhatsApp catalog format.
	 *
	 * @param int      $product_id Product ID.
	 * @param int|null $parent_id  Parent product ID for variations.
	 * @return array Catalog data format.
	 */
	public function mapToCatalogFormat( int $product_id, ?int $parent_id = null ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		// Get product image URL.
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';

		// Get additional images.
		$gallery_ids = $product->get_gallery_image_ids();
		$images      = array( $image_url );
		foreach ( array_slice( $gallery_ids, 0, 9 ) as $gallery_id ) { // WhatsApp allows up to 10 images.
			$gallery_url = wp_get_attachment_image_url( $gallery_id, 'large' );
			if ( $gallery_url ) {
				$images[] = $gallery_url;
			}
		}

		// Build catalog data.
		$catalog_data = array(
			'retailer_id'      => (string) $product_id,
			'name'             => $this->sanitizeProductName( $product->get_name() ),
			'description'      => $this->sanitizeDescription( $product->get_description() ?: $product->get_short_description() ),
			'url'              => $product->get_permalink(),
			'image_url'        => $image_url,
			'additional_image_urls' => array_slice( $images, 1 ),
			'price'            => $this->formatPrice( $product->get_price() ),
			'currency'         => get_woocommerce_currency(),
			'availability'     => $product->is_in_stock() ? 'in stock' : 'out of stock',
		);

		// Add sale price if applicable.
		if ( $product->is_on_sale() ) {
			$catalog_data['sale_price'] = $this->formatPrice( $product->get_sale_price() );
		}

		// Add category.
		$categories = wc_get_product_category_list( $product_id );
		if ( $categories ) {
			$catalog_data['category'] = wp_strip_all_tags( $categories );
		}

		// Add SKU.
		$sku = $product->get_sku();
		if ( $sku ) {
			$catalog_data['sku'] = $sku;
		}

		// Add brand from taxonomy or attribute.
		$brand = $this->getProductBrand( $product );
		if ( $brand ) {
			$catalog_data['brand'] = $brand;
		}

		// Handle variations.
		if ( $product->is_type( 'variation' ) && $parent_id ) {
			$catalog_data['parent_retailer_id'] = (string) $parent_id;

			$attributes = $product->get_variation_attributes();
			$variant_data = array();
			foreach ( $attributes as $name => $value ) {
				$clean_name = str_replace( 'attribute_', '', $name );
				$clean_name = str_replace( 'pa_', '', $clean_name );
				$variant_data[ ucfirst( $clean_name ) ] = $value;
			}
			if ( ! empty( $variant_data ) ) {
				$catalog_data['variant_attributes'] = $variant_data;
			}
		}

		// Allow customization.
		return apply_filters( 'wch_product_catalog_data', $catalog_data, $product, $product_id );
	}

	/**
	 * Get products pending sync.
	 *
	 * @param int $limit Maximum products to return.
	 * @return array Array of product IDs.
	 */
	public function getProductsPendingSync( int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => self::META_SYNC_STATUS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => self::META_SYNC_STATUS,
					'value' => self::STATUS_PENDING,
				),
			),
		);

		$query = new \WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Get products with sync errors.
	 *
	 * @param int $limit Maximum products to return.
	 * @return array Array of product data with errors.
	 */
	public function getProductsWithErrors( int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => self::META_SYNC_STATUS,
					'value' => self::STATUS_ERROR,
				),
			),
		);

		$query   = new \WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$results[] = array(
				'id'          => $product_id,
				'name'        => $product->get_name(),
				'error'       => get_post_meta( $product_id, self::META_SYNC_ERROR, true ),
				'last_synced' => get_post_meta( $product_id, self::META_LAST_SYNCED, true ),
			);
		}

		return $results;
	}

	/**
	 * Retry failed product syncs.
	 *
	 * @return array{retried: int, success: int, failed: int}
	 */
	public function retryFailedSyncs(): array {
		$failed_products = $this->getProductsWithErrors( self::BATCH_SIZE );

		$retried = count( $failed_products );
		$success = 0;
		$failed  = 0;

		foreach ( $failed_products as $product_data ) {
			// Reset status to pending.
			update_post_meta( $product_data['id'], self::META_SYNC_STATUS, self::STATUS_PENDING );
			delete_post_meta( $product_data['id'], self::META_SYNC_ERROR );

			// Attempt sync.
			$result = $this->syncProduct( $product_data['id'] );

			if ( $result['success'] ) {
				$success++;
			} else {
				$failed++;
			}
		}

		return array(
			'retried' => $retried,
			'success' => $success,
			'failed'  => $failed,
		);
	}

	/**
	 * Check if sync is enabled.
	 *
	 * @return bool True if sync is enabled.
	 */
	public function isSyncEnabled(): bool {
		return get_option( 'wch_catalog_sync_enabled', '0' ) === '1'
			&& ! empty( $this->getCatalogId() );
	}

	/**
	 * Get catalog ID.
	 *
	 * @return string|null WhatsApp catalog ID.
	 */
	public function getCatalogId(): ?string {
		$catalog_id = get_option( 'wch_whatsapp_catalog_id', '' );
		return ! empty( $catalog_id ) ? $catalog_id : null;
	}

	/**
	 * Get sync statistics.
	 *
	 * @return array{total: int, synced: int, pending: int, errors: int, last_sync: string|null}
	 */
	public function getSyncStats(): array {
		global $wpdb;

		// Get total eligible products.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'product' AND post_status = 'publish'"
		);

		// Get synced count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$synced = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			AND pm.meta_key = %s AND pm.meta_value = %s",
			self::META_SYNC_STATUS,
			self::STATUS_SYNCED
		) );

		// Get error count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$errors = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			AND pm.meta_key = %s AND pm.meta_value = %s",
			self::META_SYNC_STATUS,
			self::STATUS_ERROR
		) );

		// Get last sync time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$last_sync = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE p.post_type = 'product' AND pm.meta_key = %s
			ORDER BY pm.meta_value DESC LIMIT 1",
			self::META_LAST_SYNCED
		) );

		$pending = max( 0, $total - $synced - $errors );

		return array(
			'total'     => $total,
			'synced'    => $synced,
			'pending'   => $pending,
			'errors'    => $errors,
			'last_sync' => $last_sync ?: null,
		);
	}

	/**
	 * Process a batch of products for sync.
	 *
	 * @param array $product_ids Product IDs to sync.
	 * @return array{success: int, failed: int, errors: array}
	 */
	public function processBatch( array $product_ids ): array {
		$success = 0;
		$failed  = 0;
		$errors  = array();

		foreach ( $product_ids as $product_id ) {
			$result = $this->syncProduct( (int) $product_id );

			if ( $result['success'] ) {
				$success++;
			} else {
				$failed++;
				$errors[] = array(
					'product_id' => $product_id,
					'error'      => $result['error'],
				);
			}
		}

		do_action( 'wch_product_batch_completed', $success, $failed, $errors );

		return array(
			'success' => $success,
			'failed'  => $failed,
			'errors'  => $errors,
		);
	}

	/**
	 * Handle product update event.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function onProductUpdate( int $product_id ): void {
		if ( ! $this->isSyncEnabled() ) {
			return;
		}

		// Check if product is valid and has changed.
		$validation = $this->validateProduct( $product_id );
		if ( ! $validation['valid'] ) {
			// If previously synced but now invalid, delete from catalog.
			$status = $this->getSyncStatus( $product_id );
			if ( self::STATUS_SYNCED === $status['status'] ) {
				$this->deleteFromCatalog( $product_id );
			}
			return;
		}

		// Check if data actually changed.
		if ( ! $this->hasProductChanged( $product_id ) ) {
			return;
		}

		// Queue for sync.
		if ( $this->queue_service ) {
			$this->queue_service->dispatch(
				'wch_sync_product',
				array( 'product_id' => $product_id ),
				'wch-normal'
			);
		} else {
			// Direct sync.
			$this->syncProduct( $product_id );
		}
	}

	/**
	 * Handle product delete event.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function onProductDelete( int $product_id ): void {
		$this->deleteFromCatalog( $product_id );
	}

	/**
	 * Get all eligible product IDs.
	 *
	 * @return array Product IDs.
	 */
	private function getEligibleProductIds(): array {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'relation' => 'OR',
					array(
						'key'     => '_wch_exclude_from_sync',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_wch_exclude_from_sync',
						'value' => '1',
						'compare' => '!=',
					),
				),
			),
		);

		// Optionally exclude out of stock.
		if ( get_option( 'wch_sync_out_of_stock', '0' ) !== '1' ) {
			$args['meta_query'][] = array(
				'key'   => '_stock_status',
				'value' => 'instock',
			);
		}

		$query = new \WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Queue a batch for async processing.
	 *
	 * @param array $product_ids Product IDs.
	 * @param int   $batch_index Batch index.
	 * @return bool Success status.
	 */
	private function queueBatch( array $product_ids, int $batch_index ): bool {
		$data = array(
			'product_ids'  => $product_ids,
			'batch_index'  => $batch_index,
		);

		if ( $this->queue_service ) {
			return $this->queue_service->schedule(
				'wch_process_product_batch',
				$data,
				time() + ( $batch_index * 10 ), // Stagger batches by 10 seconds.
				'wch-bulk'
			);
		}

		// Fallback to Action Scheduler.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action(
				time() + ( $batch_index * 10 ),
				'wch_process_product_batch',
				array( $data ),
				'wch-catalog'
			);
			return $action_id > 0;
		}

		return false;
	}

	/**
	 * Calculate data hash for change detection.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Hash.
	 */
	private function calculateDataHash( \WC_Product $product ): string {
		$data = array(
			'name'        => $product->get_name(),
			'description' => $product->get_description(),
			'price'       => $product->get_price(),
			'sale_price'  => $product->get_sale_price(),
			'sku'         => $product->get_sku(),
			'stock'       => $product->get_stock_status(),
			'image'       => $product->get_image_id(),
			'gallery'     => $product->get_gallery_image_ids(),
			'modified'    => $product->get_date_modified() ? $product->get_date_modified()->getTimestamp() : 0,
		);

		return md5( wp_json_encode( $data ) );
	}

	/**
	 * Sanitize product name for catalog.
	 *
	 * @param string $name Product name.
	 * @return string Sanitized name.
	 */
	private function sanitizeProductName( string $name ): string {
		$name = wp_strip_all_tags( $name );
		$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		return mb_substr( trim( $name ), 0, 200 ); // WhatsApp limit.
	}

	/**
	 * Sanitize description for catalog.
	 *
	 * @param string $description Product description.
	 * @return string Sanitized description.
	 */
	private function sanitizeDescription( string $description ): string {
		$description = wp_strip_all_tags( $description );
		$description = html_entity_decode( $description, ENT_QUOTES, 'UTF-8' );
		$description = preg_replace( '/\s+/', ' ', $description );
		return mb_substr( trim( $description ), 0, 5000 ); // WhatsApp limit.
	}

	/**
	 * Format price for catalog.
	 *
	 * @param mixed $price Price value.
	 * @return int Price in cents.
	 */
	private function formatPrice( $price ): int {
		return (int) round( (float) $price * 100 );
	}

	/**
	 * Get product brand.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string|null Brand name.
	 */
	private function getProductBrand( \WC_Product $product ): ?string {
		// Check for brand taxonomy.
		$brand_taxonomies = array( 'product_brand', 'pa_brand', 'pwb-brand' );

		foreach ( $brand_taxonomies as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$terms = get_the_terms( $product->get_id(), $taxonomy );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return $terms[0]->name;
				}
			}
		}

		// Check for brand attribute.
		$brand_attr = $product->get_attribute( 'brand' );
		if ( $brand_attr ) {
			return $brand_attr;
		}

		return null;
	}
}
