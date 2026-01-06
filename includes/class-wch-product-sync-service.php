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
			WCH_Logger::error( 'Failed to initialize API client', 'product-sync', array( 'error' => $e->getMessage() ) );
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
				'product-sync',
				array(
					'product_id' => $product_id,
					'catalog_id' => $catalog_id,
				)
			);

			return array(
				'success'        => true,
				'catalog_item_id' => $response['id'] ?? null,
			);
		} catch ( Exception $e ) {
			$this->update_sync_status( $product_id, 'error', $e->getMessage() );

			WCH_Logger::error(
				'Failed to sync product to WhatsApp catalog',
				'product-sync',
				array(
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
	 * @param WC_Product_Variable       $product     Variable product.
	 * @param WCH_WhatsApp_API_Client   $api_client  API client.
	 * @param string                    $catalog_id  Catalog ID.
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
				$synced++;
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
			'success'       => $synced > 0,
			'synced_count'  => $synced,
			'total_count'   => count( $variations ),
			'errors'        => $errors,
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
			'retailer_id' => (string) $product_id,
			'name'        => $name,
			'description' => $description,
			'price'       => $price,
			'currency'    => $currency,
			'url'         => $url,
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
	 */
	public function sync_all_products() {
		if ( ! $this->is_sync_enabled() ) {
			WCH_Logger::warning( 'Attempted to sync all products but sync is disabled', 'product-sync' );
			return;
		}

		// Get products to sync.
		$product_ids = $this->get_products_to_sync();

		WCH_Logger::info(
			'Starting bulk product sync',
			'product-sync',
			array( 'total_products' => count( $product_ids ) )
		);

		// Process in batches.
		$batches = array_chunk( $product_ids, self::BATCH_SIZE );

		foreach ( $batches as $batch_index => $batch ) {
			// Queue batch for processing.
			WCH_Job_Dispatcher::dispatch(
				'wch_sync_product_batch',
				array(
					'product_ids'  => $batch,
					'batch_index'  => $batch_index,
					'total_batches' => count( $batches ),
				)
			);
		}

		WCH_Logger::info(
			'Queued all product batches for sync',
			'product-sync',
			array( 'total_batches' => count( $batches ) )
		);
	}

	/**
	 * Get product IDs to sync based on settings.
	 *
	 * @return array Array of product IDs.
	 */
	private function get_products_to_sync() {
		$sync_products = $this->settings->get( 'catalog.sync_products', 'all' );

		// If specific products are configured.
		if ( 'all' !== $sync_products && is_array( $sync_products ) ) {
			return $sync_products;
		}

		// Get all published products.
		$args = array(
			'status' => 'publish',
			'limit'  => -1,
			'return' => 'ids',
		);

		// Exclude out of stock if setting enabled.
		$include_out_of_stock = $this->settings->get( 'catalog.include_out_of_stock', false );
		if ( ! $include_out_of_stock ) {
			$args['stock_status'] = 'instock';
		}

		return wc_get_products( $args );
	}

	/**
	 * Process a batch of products.
	 *
	 * This is called by the queue handler.
	 *
	 * @param array $args Job arguments.
	 */
	public static function process_product_batch( $args ) {
		$instance    = self::instance();
		$product_ids = $args['product_ids'] ?? array();
		$batch_index = $args['batch_index'] ?? 0;

		WCH_Logger::info(
			'Processing product batch',
			'product-sync',
			array(
				'batch_index' => $batch_index,
				'product_count' => count( $product_ids ),
			)
		);

		foreach ( $product_ids as $product_id ) {
			$instance->sync_product_to_whatsapp( $product_id );
		}

		WCH_Logger::info(
			'Completed product batch',
			'product-sync',
			array( 'batch_index' => $batch_index )
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
				'product-sync',
				array(
					'product_id'      => $product_id,
					'catalog_item_id' => $catalog_item_id,
				)
			);

			return array( 'success' => true );
		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Failed to delete product from WhatsApp catalog',
				'product-sync',
				array(
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
			'product-sync',
			array( 'product_id' => $product_id )
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
			$actions['wch_sync_to_whatsapp']   = __( 'Sync to WhatsApp', 'whatsapp-commerce-hub' );
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
					$synced++;
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
					$removed++;
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
