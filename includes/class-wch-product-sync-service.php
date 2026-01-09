<?php
/**
 * Product Sync Service Class
 *
 * Handles bidirectional product synchronization between WooCommerce and WhatsApp Catalog.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Product_Sync_Service
 */
class WCH_Product_Sync_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Product_Sync_Service
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * WhatsApp API client.
	 *
	 * @var WCH_WhatsApp_API_Client
	 */
	private $api_client;

	/**
	 * Batch size for bulk operations.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Meta key for catalog item ID.
	 *
	 * @var string
	 */
	const META_CATALOG_ID = '_wch_catalog_id';

	/**
	 * Meta key for last sync timestamp.
	 *
	 * @var string
	 */
	const META_LAST_SYNCED = '_wch_last_synced';

	/**
	 * Meta key for sync status.
	 *
	 * @var string
	 */
	const META_SYNC_STATUS = '_wch_sync_status';

	/**
	 * Meta key for last sync hash.
	 *
	 * @var string
	 */
	const META_SYNC_HASH = '_wch_sync_hash';

	/**
	 * Option key for bulk sync progress.
	 *
	 * @var string
	 */
	const OPTION_SYNC_PROGRESS = 'wch_bulk_sync_progress';

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Product_Sync_Service
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = WCH_Settings::instance();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Product update hooks.
		add_action( 'woocommerce_update_product', array( $this, 'handle_product_update' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'handle_product_update' ), 10, 1 );

		// Product delete hook.
		add_action( 'before_delete_post', array( $this, 'handle_product_delete' ), 10, 1 );

		// Admin hooks.
		add_filter( 'manage_product_posts_columns', array( $this, 'add_sync_status_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_sync_status_column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'show_bulk_action_notices' ) );
	}

	/**
	 * Get API client instance.
	 *
	 * @return WCH_WhatsApp_API_Client|null
	 */
	private function get_api_client() {
		if ( null !== $this->api_client ) {
			return $this->api_client;
		}

		try {
			$phone_number_id = $this->settings->get( 'api.whatsapp_phone_number_id' );
			$access_token    = $this->settings->get( 'api.access_token' );
			$api_version     = $this->settings->get( 'api.api_version', 'v18.0' );

			if ( empty( $phone_number_id ) || empty( $access_token ) ) {
				return null;
			}

			$this->api_client = new WCH_WhatsApp_API_Client(
				array(
					'phone_number_id' => $phone_number_id,
					'access_token'    => $access_token,
					'api_version'     => $api_version,
				)
			);

			return $this->api_client;
		} catch ( Exception $e ) {
			WCH_Logger::error( 'Failed to initialize API client', array( 'category' => 'product-sync', 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get catalog ID from settings.
	 *
	 * @return string|null
	 */
	private function get_catalog_id() {
		return $this->settings->get( 'catalog.catalog_id' );
	}

	/**
	 * Check if sync is enabled.
	 *
	 * @return bool
	 */
	private function is_sync_enabled() {
		return (bool) $this->settings->get( 'catalog.sync_enabled', false );
	}

	/**
	 * Sync a product to WhatsApp Catalog.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result array with 'success' and optional 'error' keys.
	 */
	public function sync_product_to_whatsapp( $product_id ) {
		// Check if sync is enabled.
		if ( ! $this->is_sync_enabled() ) {
			return array(
				'success' => false,
				'error'   => 'Product sync is not enabled',
			);
		}

		// Get product.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'success' => false,
				'error'   => 'Product not found',
			);
		}

		// Validate product.
		$validation = $this->validate_product( $product );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'error'   => $validation['reason'],
			);
		}

		// Get API client and catalog ID.
		$api_client = $this->get_api_client();
		$catalog_id = $this->get_catalog_id();

		if ( ! $api_client || ! $catalog_id ) {
			return array(
				'success' => false,
				'error'   => 'WhatsApp API not configured',
			);
		}

		try {
			// Handle variable products - sync each variation.
			if ( $product->is_type( 'variable' ) ) {
				return $this->sync_variable_product( $product, $api_client, $catalog_id );
			}

			// Map product to WhatsApp catalog format.
			$catalog_data = $this->map_product_to_catalog_format( $product );

			// Call WhatsApp Catalog API.
			$response = $api_client->create_catalog_product( $catalog_id, $catalog_data );

			// Store catalog item ID in product meta.
			if ( isset( $response['id'] ) ) {
				update_post_meta( $product_id, self::META_CATALOG_ID, $response['id'] );
			}

			// Update sync status.
			$this->update_sync_status( $product_id, 'synced' );

			WCH_Logger::info(
				'Product synced to WhatsApp catalog',
				array(
					'category'   => 'product-sync',
					'product_id' => $product_id,
					'catalog_id' => $catalog_id,
				)
			);

			return array(
				'success'         => true,
				'catalog_item_id' => $response['id'] ?? null,
			);
		} catch ( Exception $e ) {
			$this->update_sync_status( $product_id, 'error', $e->getMessage() );

			WCH_Logger::error(
				'Failed to sync product to WhatsApp catalog',
				array(
					'category'   => 'product-sync',
					'product_id' => $product_id,
					'error'      => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Sync a variable product and its variations.
	 *
	 * @param WC_Product_Variable     $product     Variable product.
	 * @param WCH_WhatsApp_API_Client $api_client  API client.
	 * @param string                  $catalog_id  Catalog ID.
	 * @return array Result array.
	 */
	private function sync_variable_product( $product, $api_client, $catalog_id ) {
		$parent_id  = $product->get_id();
		$variations = $product->get_available_variations();
		$synced     = 0;
		$errors     = array();

		foreach ( $variations as $variation_data ) {
			$variation_id = $variation_data['variation_id'];
			$variation    = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			// Validate variation.
			$validation = $this->validate_product( $variation );
			if ( ! $validation['valid'] ) {
				$errors[] = "Variation {$variation_id}: {$validation['reason']}";
				continue;
			}

			try {
				// Map variation to catalog format with parent reference.
				$catalog_data = $this->map_product_to_catalog_format( $variation, $parent_id );

				// Call WhatsApp Catalog API.
				$response = $api_client->create_catalog_product( $catalog_id, $catalog_data );

				// Store catalog item ID.
				if ( isset( $response['id'] ) ) {
					update_post_meta( $variation_id, self::META_CATALOG_ID, $response['id'] );
				}

				// Update sync status.
				$this->update_sync_status( $variation_id, 'synced' );
				++$synced;
			} catch ( Exception $e ) {
				$this->update_sync_status( $variation_id, 'error', $e->getMessage() );
				$errors[] = "Variation {$variation_id}: {$e->getMessage()}";
			}
		}

		// Update parent sync status.
		if ( empty( $errors ) ) {
			$this->update_sync_status( $parent_id, 'synced' );
		} else {
			$this->update_sync_status( $parent_id, 'partial', implode( '; ', $errors ) );
		}

		return array(
			'success'      => $synced > 0,
			'synced_count' => $synced,
			'total_count'  => count( $variations ),
			'errors'       => $errors,
		);
	}

	/**
	 * Validate if product should be synced.
	 *
	 * @param WC_Product $product Product object.
	 * @return array Validation result with 'valid' and 'reason' keys.
	 */
	private function validate_product( $product ) {
		// Must be published.
		if ( 'publish' !== $product->get_status() ) {
			return array(
				'valid'  => false,
				'reason' => 'Product is not published',
			);
		}

		// Check stock if setting enabled.
		$include_out_of_stock = $this->settings->get( 'catalog.include_out_of_stock', false );
		if ( ! $include_out_of_stock && ! $product->is_in_stock() ) {
			return array(
				'valid'  => false,
				'reason' => 'Product is out of stock',
			);
		}

		// Check if product is in allowed list.
		$sync_products = $this->settings->get( 'catalog.sync_products', 'all' );
		if ( 'all' !== $sync_products && is_array( $sync_products ) ) {
			if ( ! in_array( $product->get_id(), $sync_products, true ) ) {
				return array(
					'valid'  => false,
					'reason' => 'Product is not in the sync list',
				);
			}
		}

		return array( 'valid' => true );
	}

	/**
	 * Map WooCommerce product to WhatsApp catalog format.
	 *
	 * @param WC_Product $product   Product object.
	 * @param int|null   $parent_id Parent product ID for variations.
	 * @return array Catalog data.
	 */
	private function map_product_to_catalog_format( $product, $parent_id = null ) {
		$product_id = $product->get_id();

		// Build product name (max 200 chars).
		$name = $product->get_name();
		if ( strlen( $name ) > 200 ) {
			$name = substr( $name, 0, 197 ) . '...';
		}

		// Build description (max 9999 chars).
		$description = $product->get_description();
		if ( empty( $description ) ) {
			$description = $product->get_short_description();
		}
		if ( strlen( $description ) > 9999 ) {
			$description = substr( $description, 0, 9996 ) . '...';
		}
		// Strip HTML tags.
		$description = wp_strip_all_tags( $description );

		// Price in cents.
		$price = (int) ( (float) $product->get_price() * 100 );

		// Currency.
		$currency = get_woocommerce_currency();

		// Product URL.
		$url = get_permalink( $product_id );

		// Main image URL.
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

		// Availability.
		$availability = $product->is_in_stock() ? 'in stock' : 'out of stock';

		// Category.
		$categories = $product->get_category_ids();
		$category   = '';
		if ( ! empty( $categories ) ) {
			$category_term = get_term( $categories[0], 'product_cat' );
			if ( $category_term && ! is_wp_error( $category_term ) ) {
				$category = $category_term->name;
			}
		}

		// Brand (use first tag or empty).
		$tags  = $product->get_tag_ids();
		$brand = '';
		if ( ! empty( $tags ) ) {
			$tag_term = get_term( $tags[0], 'product_tag' );
			if ( $tag_term && ! is_wp_error( $tag_term ) ) {
				$brand = $tag_term->name;
			}
		}

		$catalog_data = array(
			'retailer_id'  => (string) $product_id,
			'name'         => $name,
			'description'  => $description,
			'price'        => $price,
			'currency'     => $currency,
			'url'          => $url,
			'availability' => $availability,
		);

		// Add optional fields.
		if ( ! empty( $image_url ) ) {
			$catalog_data['image_url'] = $image_url;
		}

		if ( ! empty( $category ) ) {
			$catalog_data['category'] = $category;
		}

		if ( ! empty( $brand ) ) {
			$catalog_data['brand'] = $brand;
		}

		// Add parent reference for variations.
		if ( $parent_id ) {
			$catalog_data['item_group_id'] = (string) $parent_id;
		}

		return $catalog_data;
	}

	/**
	 * Sync all products based on settings.
	 *
	 * @return string|null Sync session ID on success, null on failure.
	 */
	public function sync_all_products() {
		if ( ! $this->is_sync_enabled() ) {
			WCH_Logger::warning( 'Attempted to sync all products but sync is disabled', array( 'category' => 'product-sync' ) );
			return null;
		}

		// Check if a sync is already in progress.
		$existing_progress = $this->get_sync_progress();
		if ( $existing_progress && 'in_progress' === $existing_progress['status'] ) {
			WCH_Logger::warning(
				'Bulk sync already in progress',
				array(
					'category' => 'product-sync',
					'sync_id'  => $existing_progress['sync_id'],
				)
			);
			return $existing_progress['sync_id'];
		}

		// Get products to sync.
		$product_ids = $this->get_products_to_sync();

		if ( empty( $product_ids ) ) {
			WCH_Logger::info( 'No products found to sync', array( 'category' => 'product-sync' ) );
			return null;
		}

		// Initialize progress tracking.
		$sync_id = $this->start_bulk_sync( count( $product_ids ) );

		WCH_Logger::info(
			'Starting bulk product sync',
			array(
				'category'       => 'product-sync',
				'sync_id'        => $sync_id,
				'total_products' => count( $product_ids ),
			)
		);

		// Process in batches.
		$batches = array_chunk( $product_ids, self::BATCH_SIZE );

		foreach ( $batches as $batch_index => $batch ) {
			// Queue batch for processing with sync_id for tracking.
			WCH_Job_Dispatcher::dispatch(
				'wch_sync_product_batch',
				array(
					'product_ids'   => $batch,
					'batch_index'   => $batch_index,
					'total_batches' => count( $batches ),
					'sync_id'       => $sync_id,
				)
			);
		}

		WCH_Logger::info(
			'Queued all product batches for sync',
			array(
				'category'      => 'product-sync',
				'sync_id'       => $sync_id,
				'total_batches' => count( $batches ),
			)
		);

		return $sync_id;
	}

	/**
	 * Start a bulk sync session and initialize progress tracking.
	 *
	 * @param int $total_items Total number of items to sync.
	 * @return string Unique sync session ID.
	 */
	public function start_bulk_sync( int $total_items ): string {
		$sync_id = wp_generate_uuid4();

		$progress = array(
			'sync_id'         => $sync_id,
			'status'          => 'in_progress',
			'total_items'     => $total_items,
			'processed_count' => 0,
			'success_count'   => 0,
			'failed_count'    => 0,
			'failed_items'    => array(),
			'started_at'      => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
			'completed_at'    => null,
		);

		update_option( self::OPTION_SYNC_PROGRESS, $progress, false );

		WCH_Logger::info(
			'Initialized bulk sync progress tracking',
			array(
				'category'    => 'product-sync',
				'sync_id'     => $sync_id,
				'total_items' => $total_items,
			)
		);

		return $sync_id;
	}

	/**
	 * Update bulk sync progress counters.
	 *
	 * Uses atomic operations to handle concurrent batch updates safely.
	 *
	 * @param string $sync_id     Sync session ID.
	 * @param int    $processed   Number of items processed in this batch.
	 * @param int    $successful  Number of successful syncs in this batch.
	 * @param int    $failed      Number of failed syncs in this batch.
	 * @return bool True if update succeeded.
	 */
	public function update_sync_progress( string $sync_id, int $processed, int $successful, int $failed ): bool {
		global $wpdb;

		// Use a database lock to prevent race conditions with concurrent batches.
		$lock_name = 'wch_sync_progress_lock';
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT GET_LOCK(%s, 30)",
				$lock_name
			)
		);

		if ( ! $lock_acquired ) {
			WCH_Logger::warning(
				'Failed to acquire sync progress lock',
				array(
					'category' => 'product-sync',
					'sync_id'  => $sync_id,
				)
			);
			return false;
		}

		try {
			$progress = get_option( self::OPTION_SYNC_PROGRESS );

			if ( ! $progress || $progress['sync_id'] !== $sync_id ) {
				WCH_Logger::warning(
					'Sync progress not found or ID mismatch',
					array(
						'category'         => 'product-sync',
						'expected_sync_id' => $sync_id,
						'actual_sync_id'   => $progress['sync_id'] ?? 'none',
					)
				);
				return false;
			}

			// Update counters.
			$progress['processed_count'] += $processed;
			$progress['success_count']   += $successful;
			$progress['failed_count']    += $failed;
			$progress['updated_at']       = current_time( 'mysql', true );

			// Check if sync is complete.
			if ( $progress['processed_count'] >= $progress['total_items'] ) {
				$progress['status']       = 'completed';
				$progress['completed_at'] = current_time( 'mysql', true );

				WCH_Logger::info(
					'Bulk sync completed',
					array(
						'category'      => 'product-sync',
						'sync_id'       => $sync_id,
						'total'         => $progress['total_items'],
						'successful'    => $progress['success_count'],
						'failed'        => $progress['failed_count'],
						'duration_secs' => strtotime( $progress['completed_at'] ) - strtotime( $progress['started_at'] ),
					)
				);
			}

			update_option( self::OPTION_SYNC_PROGRESS, $progress, false );

			return true;
		} finally {
			// Always release the lock.
			$wpdb->query(
				$wpdb->prepare(
					"SELECT RELEASE_LOCK(%s)",
					$lock_name
				)
			);
		}
	}

	/**
	 * Record a failed sync item with error details.
	 *
	 * @param string $sync_id      Sync session ID.
	 * @param int    $product_id   Product ID that failed.
	 * @param string $error_message Error message.
	 * @return bool True if recorded successfully.
	 */
	public function add_sync_failure( string $sync_id, int $product_id, string $error_message ): bool {
		global $wpdb;

		$lock_name = 'wch_sync_progress_lock';
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT GET_LOCK(%s, 30)",
				$lock_name
			)
		);

		if ( ! $lock_acquired ) {
			return false;
		}

		try {
			$progress = get_option( self::OPTION_SYNC_PROGRESS );

			if ( ! $progress || $progress['sync_id'] !== $sync_id ) {
				return false;
			}

			// Limit stored failures to prevent memory bloat (keep last 100).
			if ( count( $progress['failed_items'] ) >= 100 ) {
				array_shift( $progress['failed_items'] );
			}

			$progress['failed_items'][] = array(
				'product_id'    => $product_id,
				'error'         => substr( $error_message, 0, 255 ), // Truncate long errors.
				'failed_at'     => current_time( 'mysql', true ),
			);

			update_option( self::OPTION_SYNC_PROGRESS, $progress, false );

			return true;
		} finally {
			$wpdb->query(
				$wpdb->prepare(
					"SELECT RELEASE_LOCK(%s)",
					$lock_name
				)
			);
		}
	}

	/**
	 * Get current bulk sync progress.
	 *
	 * @return array|null Progress data or null if no sync in progress.
	 */
	public function get_sync_progress(): ?array {
		$progress = get_option( self::OPTION_SYNC_PROGRESS );

		if ( ! $progress || ! is_array( $progress ) ) {
			return null;
		}

		// Calculate percentage.
		$progress['percentage'] = $progress['total_items'] > 0
			? round( ( $progress['processed_count'] / $progress['total_items'] ) * 100, 1 )
			: 0;

		// Calculate elapsed time.
		if ( ! empty( $progress['started_at'] ) ) {
			$end_time = $progress['completed_at'] ?? current_time( 'mysql', true );
			$progress['elapsed_seconds'] = strtotime( $end_time ) - strtotime( $progress['started_at'] );
		}

		// Estimate remaining time based on current rate.
		if ( $progress['processed_count'] > 0 && 'in_progress' === $progress['status'] ) {
			$rate = $progress['processed_count'] / max( 1, $progress['elapsed_seconds'] );
			$remaining_items = $progress['total_items'] - $progress['processed_count'];
			$progress['estimated_remaining_seconds'] = $rate > 0 ? round( $remaining_items / $rate ) : null;
		}

		return $progress;
	}

	/**
	 * Clear sync progress data.
	 *
	 * Uses locking to prevent race condition with concurrent sync operations.
	 *
	 * @param bool $force Force clear even if sync is in progress.
	 * @return bool True if cleared successfully, false if sync in progress or lock failed.
	 */
	public function clear_sync_progress( bool $force = false ): bool {
		global $wpdb;

		// Use the same lock as update_sync_progress to prevent race conditions.
		$lock_name     = 'wch_sync_progress_lock';
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, 30)',
				$lock_name
			)
		);

		if ( ! $lock_acquired ) {
			WCH_Logger::warning( 'Failed to acquire lock for clear_sync_progress', array( 'category' => 'product-sync' ) );
			return false;
		}

		try {
			// Check if sync is in progress (unless force clearing).
			if ( ! $force ) {
				$progress = get_option( self::OPTION_SYNC_PROGRESS );
				if ( $progress && 'in_progress' === ( $progress['status'] ?? '' ) ) {
					WCH_Logger::warning( 'Cannot clear sync progress while sync is in progress', array( 'category' => 'product-sync' ) );
					return false;
				}
			}

			return delete_option( self::OPTION_SYNC_PROGRESS );
		} finally {
			$wpdb->query(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK(%s)',
					$lock_name
				)
			);
		}
	}

	/**
	 * Mark a sync as failed with an error reason.
	 *
	 * @param string $sync_id Sync session ID.
	 * @param string $reason  Failure reason.
	 * @return bool True if marked successfully.
	 */
	public function fail_bulk_sync( string $sync_id, string $reason ): bool {
		global $wpdb;

		// Acquire lock to prevent race conditions.
		$lock_name     = 'wch_sync_progress_lock';
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, 30)',
				$lock_name
			)
		);

		if ( ! $lock_acquired ) {
			WCH_Logger::warning(
				'Failed to acquire lock for fail_bulk_sync',
				array( 'category' => 'product-sync' )
			);
			return false;
		}

		try {
			$progress = get_option( self::OPTION_SYNC_PROGRESS );

			if ( ! $progress || $progress['sync_id'] !== $sync_id ) {
				return false;
			}

			$progress['status']         = 'failed';
			$progress['failure_reason'] = $reason;
			$progress['completed_at']   = current_time( 'mysql', true );

			WCH_Logger::error(
				'Bulk sync failed',
				array(
					'category' => 'product-sync',
					'sync_id'  => $sync_id,
					'reason'   => $reason,
				)
			);

			return update_option( self::OPTION_SYNC_PROGRESS, $progress, false );
		} finally {
			$wpdb->query(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK(%s)',
					$lock_name
				)
			);
		}
	}

	/**
	 * Retry failed items from a previous sync.
	 *
	 * Uses locking to prevent race conditions between validation, clearing,
	 * and starting a new sync.
	 *
	 * @return string|null New sync ID or null if no failed items.
	 */
	public function retry_failed_items(): ?string {
		global $wpdb;

		// Acquire lock to prevent race conditions.
		$lock_name     = 'wch_sync_progress_lock';
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, 30)',
				$lock_name
			)
		);

		if ( ! $lock_acquired ) {
			WCH_Logger::warning(
				'Failed to acquire lock for retry_failed_items',
				array( 'category' => 'product-sync' )
			);
			return null;
		}

		$product_ids = array();

		try {
			// Get and validate progress inside lock.
			$progress = get_option( self::OPTION_SYNC_PROGRESS );

			if ( ! $progress || empty( $progress['failed_items'] ) ) {
				return null;
			}

			$product_ids = array_column( $progress['failed_items'], 'product_id' );

			if ( empty( $product_ids ) ) {
				return null;
			}

			// Clear old progress while still holding lock.
			delete_option( self::OPTION_SYNC_PROGRESS );
		} finally {
			$wpdb->query(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK(%s)',
					$lock_name
				)
			);
		}

		// Start new sync with just the failed items (outside lock since start_bulk_sync acquires its own lock).
		$sync_id = $this->start_bulk_sync( count( $product_ids ) );

		$batches = array_chunk( $product_ids, self::BATCH_SIZE );

		foreach ( $batches as $batch_index => $batch ) {
			WCH_Job_Dispatcher::dispatch(
				'wch_sync_product_batch',
				array(
					'product_ids'   => $batch,
					'batch_index'   => $batch_index,
					'total_batches' => count( $batches ),
					'sync_id'       => $sync_id,
					'is_retry'      => true,
				)
			);
		}

		WCH_Logger::info(
			'Retrying failed sync items',
			array(
				'category'    => 'product-sync',
				'sync_id'     => $sync_id,
				'retry_count' => count( $product_ids ),
			)
		);

		return $sync_id;
	}

	/**
	 * Get product IDs to sync based on settings.
	 *
	 * Uses paginated queries to avoid memory exhaustion with large catalogs.
	 *
	 * @return array Array of product IDs.
	 */
	private function get_products_to_sync() {
		$sync_products = $this->settings->get( 'catalog.sync_products', 'all' );

		// If specific products are configured.
		if ( 'all' !== $sync_products && is_array( $sync_products ) ) {
			return $sync_products;
		}

		// Get published products using pagination to avoid memory issues.
		$all_product_ids = array();
		$page            = 1;
		$per_page        = 100;

		$base_args = array(
			'status' => 'publish',
			'return' => 'ids',
			'limit'  => $per_page,
		);

		// Exclude out of stock if setting enabled.
		$include_out_of_stock = $this->settings->get( 'catalog.include_out_of_stock', false );
		if ( ! $include_out_of_stock ) {
			$base_args['stock_status'] = 'instock';
		}

		do {
			$args        = array_merge( $base_args, array( 'page' => $page ) );
			$product_ids = wc_get_products( $args );

			if ( empty( $product_ids ) ) {
				break;
			}

			$all_product_ids = array_merge( $all_product_ids, $product_ids );
			++$page;

			// Safety limit to prevent infinite loops (max 100,000 products).
			if ( $page > 1000 ) {
				WCH_Logger::warning(
					'Product sync hit safety limit of 100,000 products',
					array(
						'category' => 'product-sync',
						'fetched'  => count( $all_product_ids ),
					)
				);
				break;
			}
		} while ( count( $product_ids ) === $per_page );

		return $all_product_ids;
	}

	/**
	 * Process a batch of products.
	 *
	 * This is called by the queue handler.
	 *
	 * @param array $args Job arguments.
	 */
	public static function process_product_batch( $args ) {
		$instance      = self::instance();
		$product_ids   = $args['product_ids'] ?? array();
		$batch_index   = $args['batch_index'] ?? 0;
		$total_batches = $args['total_batches'] ?? 1;
		$sync_id       = $args['sync_id'] ?? null;

		WCH_Logger::info(
			'Processing product batch',
			array(
				'category'      => 'product-sync',
				'sync_id'       => $sync_id,
				'batch_index'   => $batch_index,
				'total_batches' => $total_batches,
				'product_count' => count( $product_ids ),
			)
		);

		$processed  = 0;
		$successful = 0;
		$failed     = 0;

		foreach ( $product_ids as $product_id ) {
			$result = $instance->sync_product_to_whatsapp( $product_id );
			$processed++;

			if ( ! empty( $result['success'] ) ) {
				$successful++;
			} else {
				$failed++;

				// Record failure details if sync_id is available.
				if ( $sync_id ) {
					$error_message = $result['error'] ?? 'Unknown error';
					$instance->add_sync_failure( $sync_id, $product_id, $error_message );
				}
			}
		}

		// Update progress tracking if sync_id is available.
		if ( $sync_id ) {
			$instance->update_sync_progress( $sync_id, $processed, $successful, $failed );
		}

		WCH_Logger::info(
			'Completed product batch',
			array(
				'category'    => 'product-sync',
				'sync_id'     => $sync_id,
				'batch_index' => $batch_index,
				'processed'   => $processed,
				'successful'  => $successful,
				'failed'      => $failed,
			)
		);
	}

	/**
	 * Delete product from WhatsApp catalog.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result array.
	 */
	public function delete_from_catalog( $product_id ) {
		// Check if product has catalog ID.
		$catalog_item_id = get_post_meta( $product_id, self::META_CATALOG_ID, true );

		if ( empty( $catalog_item_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Product not synced to catalog',
			);
		}

		// Get API client and catalog ID.
		$api_client = $this->get_api_client();
		$catalog_id = $this->get_catalog_id();

		if ( ! $api_client || ! $catalog_id ) {
			return array(
				'success' => false,
				'error'   => 'WhatsApp API not configured',
			);
		}

		try {
			// Call WhatsApp Catalog API to delete.
			$api_client->delete_catalog_product( $catalog_id, $catalog_item_id );

			// Remove meta data.
			delete_post_meta( $product_id, self::META_CATALOG_ID );
			delete_post_meta( $product_id, self::META_LAST_SYNCED );
			delete_post_meta( $product_id, self::META_SYNC_STATUS );
			delete_post_meta( $product_id, self::META_SYNC_HASH );

			WCH_Logger::info(
				'Product removed from WhatsApp catalog',
				array(
					'category'        => 'product-sync',
					'product_id'      => $product_id,
					'catalog_item_id' => $catalog_item_id,
				)
			);

			return array( 'success' => true );
		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Failed to delete product from WhatsApp catalog',
				array(
					'category'   => 'product-sync',
					'product_id' => $product_id,
					'error'      => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Handle product update.
	 *
	 * @param int $product_id Product ID.
	 */
	public function handle_product_update( $product_id ) {
		// Skip if sync is not enabled.
		if ( ! $this->is_sync_enabled() ) {
			return;
		}

		// Skip auto-saves and revisions.
		if ( wp_is_post_autosave( $product_id ) || wp_is_post_revision( $product_id ) ) {
			return;
		}

		// Check if product data changed.
		if ( ! $this->has_product_changed( $product_id ) ) {
			return;
		}

		// Queue sync.
		WCH_Job_Dispatcher::dispatch(
			'wch_sync_single_product',
			array( 'product_id' => $product_id )
		);

		WCH_Logger::debug(
			'Product update queued for sync',
			array(
				'category'   => 'product-sync',
				'product_id' => $product_id,
			)
		);
	}

	/**
	 * Check if product data has changed.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True if product changed.
	 */
	private function has_product_changed( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		// Generate hash from key product data.
		$data_to_hash = array(
			'name'  => $product->get_name(),
			'price' => $product->get_price(),
			'stock' => $product->get_stock_status(),
			'image' => $product->get_image_id(),
		);

		$current_hash = md5( wp_json_encode( $data_to_hash ) );
		$stored_hash  = get_post_meta( $product_id, self::META_SYNC_HASH, true );

		// Update stored hash.
		update_post_meta( $product_id, self::META_SYNC_HASH, $current_hash );

		// Return true if hash changed or no previous hash.
		return empty( $stored_hash ) || $stored_hash !== $current_hash;
	}

	/**
	 * Handle product delete.
	 *
	 * @param int $post_id Post ID.
	 */
	public function handle_product_delete( $post_id ) {
		// Check if this is a product.
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		// Skip if sync is not enabled.
		if ( ! $this->is_sync_enabled() ) {
			return;
		}

		// Delete from catalog.
		$this->delete_from_catalog( $post_id );
	}

	/**
	 * Process single product sync job.
	 *
	 * Called by queue handler.
	 *
	 * @param array $args Job arguments.
	 */
	public static function process_single_product( $args ) {
		$instance   = self::instance();
		$product_id = $args['product_id'] ?? null;

		if ( ! $product_id ) {
			return;
		}

		$instance->sync_product_to_whatsapp( $product_id );
	}

	/**
	 * Update product sync status.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $status     Status (synced, error, partial, pending).
	 * @param string $message    Optional message.
	 */
	private function update_sync_status( $product_id, $status, $message = '' ) {
		update_post_meta( $product_id, self::META_SYNC_STATUS, $status );
		update_post_meta( $product_id, self::META_LAST_SYNCED, current_time( 'mysql' ) );

		if ( ! empty( $message ) ) {
			update_post_meta( $product_id, '_wch_sync_message', $message );
		} else {
			delete_post_meta( $product_id, '_wch_sync_message' );
		}
	}

	/**
	 * Add sync status column to products list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_sync_status_column( $columns ) {
		// Insert after product name column.
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'name' === $key ) {
				$new_columns['wch_sync_status'] = __( 'WhatsApp Sync', 'whatsapp-commerce-hub' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render sync status column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_sync_status_column( $column, $post_id ) {
		if ( 'wch_sync_status' !== $column ) {
			return;
		}

		if ( ! $this->is_sync_enabled() ) {
			echo '<span style="color: #999;" title="' . esc_attr__( 'Sync disabled', 'whatsapp-commerce-hub' ) . '">—</span>';
			return;
		}

		$status       = get_post_meta( $post_id, self::META_SYNC_STATUS, true );
		$catalog_id   = get_post_meta( $post_id, self::META_CATALOG_ID, true );
		$last_synced  = get_post_meta( $post_id, self::META_LAST_SYNCED, true );
		$sync_message = get_post_meta( $post_id, '_wch_sync_message', true );

		$icon  = '';
		$title = '';
		$color = '';

		switch ( $status ) {
			case 'synced':
				$icon  = '✓';
				$color = '#46b450';
				$title = __( 'Synced', 'whatsapp-commerce-hub' );
				if ( $last_synced ) {
					$title .= ' - ' . human_time_diff( strtotime( $last_synced ), current_time( 'timestamp' ) ) . ' ago';
				}
				break;

			case 'error':
				$icon  = '✗';
				$color = '#dc3232';
				$title = __( 'Sync error', 'whatsapp-commerce-hub' );
				if ( $sync_message ) {
					$title .= ': ' . $sync_message;
				}
				break;

			case 'partial':
				$icon  = '◐';
				$color = '#ffb900';
				$title = __( 'Partially synced', 'whatsapp-commerce-hub' );
				if ( $sync_message ) {
					$title .= ': ' . $sync_message;
				}
				break;

			default:
				$icon  = '○';
				$color = '#999';
				$title = __( 'Not synced', 'whatsapp-commerce-hub' );
				break;
		}

		printf(
			'<span style="color: %s; font-size: 16px;" title="%s">%s</span>',
			esc_attr( $color ),
			esc_attr( $title ),
			esc_html( $icon )
		);
	}

	/**
	 * Add bulk actions to products list.
	 *
	 * @param array $actions Existing actions.
	 * @return array Modified actions.
	 */
	public function add_bulk_actions( $actions ) {
		if ( $this->is_sync_enabled() ) {
			$actions['wch_sync_to_whatsapp']     = __( 'Sync to WhatsApp', 'whatsapp-commerce-hub' );
			$actions['wch_remove_from_whatsapp'] = __( 'Remove from WhatsApp', 'whatsapp-commerce-hub' );
		}
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param array  $post_ids    Post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( 'wch_sync_to_whatsapp' === $action ) {
			$synced = 0;
			foreach ( $post_ids as $post_id ) {
				$result = $this->sync_product_to_whatsapp( $post_id );
				if ( $result['success'] ) {
					++$synced;
				}
			}

			$redirect_to = add_query_arg(
				array(
					'wch_bulk_synced' => $synced,
					'wch_bulk_total'  => count( $post_ids ),
				),
				$redirect_to
			);
		} elseif ( 'wch_remove_from_whatsapp' === $action ) {
			$removed = 0;
			foreach ( $post_ids as $post_id ) {
				$result = $this->delete_from_catalog( $post_id );
				if ( $result['success'] ) {
					++$removed;
				}
			}

			$redirect_to = add_query_arg(
				array(
					'wch_bulk_removed' => $removed,
					'wch_bulk_total'   => count( $post_ids ),
				),
				$redirect_to
			);
		}

		return $redirect_to;
	}

	/**
	 * Show bulk action admin notices.
	 */
	public function show_bulk_action_notices() {
		if ( ! empty( $_REQUEST['wch_bulk_synced'] ) ) {
			$synced = intval( $_REQUEST['wch_bulk_synced'] );
			$total  = intval( $_REQUEST['wch_bulk_total'] );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %1$d: number of synced products, %2$d: total products */
					esc_html__( 'Successfully synced %1$d of %2$d products to WhatsApp.', 'whatsapp-commerce-hub' ),
					$synced,
					$total
				)
			);
		}

		if ( ! empty( $_REQUEST['wch_bulk_removed'] ) ) {
			$removed = intval( $_REQUEST['wch_bulk_removed'] );
			$total   = intval( $_REQUEST['wch_bulk_total'] );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %1$d: number of removed products, %2$d: total products */
					esc_html__( 'Successfully removed %1$d of %2$d products from WhatsApp.', 'whatsapp-commerce-hub' ),
					$removed,
					$total
				)
			);
		}
	}
}
