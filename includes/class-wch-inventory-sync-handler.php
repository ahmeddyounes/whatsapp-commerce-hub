<?php
/**
 * Inventory Sync Handler for WhatsApp Commerce Hub.
 *
 * Handles real-time inventory synchronization between WooCommerce and WhatsApp Catalog.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Inventory_Sync_Handler
 *
 * Manages inventory synchronization with debouncing and discrepancy detection.
 */
class WCH_Inventory_Sync_Handler {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Inventory_Sync_Handler|null
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * Debounce delay in seconds.
	 *
	 * @var int
	 */
	const DEBOUNCE_DELAY = 5;

	/**
	 * Transient prefix for debouncing.
	 *
	 * @var string
	 */
	const DEBOUNCE_TRANSIENT_PREFIX = 'wch_stock_sync_debounce_';

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Inventory_Sync_Handler
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
		$this->settings = WCH_Settings::getInstance();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Stock change hooks.
		add_action( 'woocommerce_product_set_stock', array( $this, 'handle_stock_change' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'handle_stock_change' ), 10, 1 );

		// Order stock reduction hook.
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_stock_reduction' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_stock_reduction' ), 10, 1 );

		// Process debounced sync action.
		add_action( 'wch_process_stock_sync', array( $this, 'process_stock_sync' ), 10, 1 );

		// Discrepancy detection cron.
		add_action( 'wch_detect_stock_discrepancies', array( $this, 'detect_stock_discrepancies' ), 10, 0 );
	}

	/**
	 * Handle stock change events with debouncing.
	 *
	 * @param WC_Product|WC_Product_Variation $product Product or variation object.
	 */
	public function handle_stock_change( $product ) {
		// Check if real-time sync is enabled.
		if ( ! $this->is_realtime_sync_enabled() ) {
			return;
		}

		$product_id = $product->get_id();

		// Get current and previous stock quantities.
		$new_stock = $product->get_stock_quantity();
		$old_stock = (int) get_post_meta( $product_id, '_wch_previous_stock', true );

		// Store new stock as previous for next comparison.
		update_post_meta( $product_id, '_wch_previous_stock', $new_stock );

		// Determine if availability changed.
		$availability_changed = false;
		$new_availability     = null;

		if ( $new_stock <= 0 && $old_stock > 0 ) {
			// Stock went from in-stock to out-of-stock.
			$availability_changed = true;
			$new_availability     = 'out_of_stock';
		} elseif ( $new_stock > 0 && $old_stock <= 0 ) {
			// Stock went from out-of-stock to in-stock.
			$availability_changed = true;
			$new_availability     = 'in_stock';
		}

		// Check low stock threshold.
		$low_stock_threshold = $this->get_low_stock_threshold();
		$low_stock_reached   = false;

		if ( $new_stock !== null && $new_stock > 0 && $new_stock <= $low_stock_threshold && $old_stock > $low_stock_threshold ) {
			$low_stock_reached = true;
		}

		WCH_Logger::info(
			'Stock change detected',
			array(
				'product_id'           => $product_id,
				'old_stock'            => $old_stock,
				'new_stock'            => $new_stock,
				'availability_changed' => $availability_changed,
				'new_availability'     => $new_availability,
				'low_stock_reached'    => $low_stock_reached,
			)
		);

		// Debounce: store pending sync.
		if ( $availability_changed || $low_stock_reached ) {
			$this->schedule_debounced_sync( $product_id, $new_availability, $low_stock_reached );
		}
	}

	/**
	 * Schedule a debounced sync for a product.
	 *
	 * @param int         $product_id       Product ID.
	 * @param string|null $new_availability New availability status.
	 * @param bool        $low_stock_reached Whether low stock threshold was reached.
	 */
	private function schedule_debounced_sync( $product_id, $new_availability, $low_stock_reached ) {
		$transient_key = self::DEBOUNCE_TRANSIENT_PREFIX . $product_id;

		// Check if already scheduled.
		$existing = get_transient( $transient_key );

		if ( false === $existing ) {
			// Store sync data in transient.
			set_transient(
				$transient_key,
				array(
					'product_id'        => $product_id,
					'new_availability'  => $new_availability,
					'low_stock_reached' => $low_stock_reached,
				),
				self::DEBOUNCE_DELAY
			);

			// Schedule the actual sync job.
			WCH_Job_Dispatcher::dispatch(
				'wch_process_stock_sync',
				array(
					'product_id' => $product_id,
				),
				self::DEBOUNCE_DELAY
			);

			WCH_Logger::debug(
				'Debounced stock sync scheduled',
				array(
					'product_id'    => $product_id,
					'delay_seconds' => self::DEBOUNCE_DELAY,
				)
			);
		} else {
			// Update existing transient data (in case stock changed multiple times).
			set_transient(
				$transient_key,
				array(
					'product_id'        => $product_id,
					'new_availability'  => $new_availability,
					'low_stock_reached' => $low_stock_reached,
				),
				self::DEBOUNCE_DELAY
			);

			WCH_Logger::debug(
				'Debounced stock sync updated',
				array( 'product_id' => $product_id )
			);
		}
	}

	/**
	 * Process debounced stock sync job.
	 *
	 * @param array $args Job arguments with product_id.
	 */
	public function process_stock_sync( $args ) {
		$product_id = $args['product_id'] ?? null;

		if ( ! $product_id ) {
			WCH_Logger::error( 'Stock sync job missing product_id', array( 'args' => $args ) );
			return;
		}

		$transient_key = self::DEBOUNCE_TRANSIENT_PREFIX . $product_id;
		$sync_data     = get_transient( $transient_key );

		if ( false === $sync_data ) {
			WCH_Logger::warning(
				'Stock sync transient expired or already processed',
				array( 'product_id' => $product_id )
			);
			return;
		}

		// Delete transient to prevent duplicate processing.
		delete_transient( $transient_key );

		// Perform the actual sync.
		$this->sync_stock_from_woocommerce( $product_id );

		// Handle low stock notification if enabled.
		if ( $sync_data['low_stock_reached'] && $this->is_low_stock_notification_enabled() ) {
			$this->send_low_stock_notification( $product_id );
		}
	}

	/**
	 * Sync stock status from WooCommerce to WhatsApp catalog.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True on success, false on failure.
	 */
	public function sync_stock_from_woocommerce( $product_id ) {
		try {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				WCH_Logger::error( 'Product not found for stock sync', array( 'product_id' => $product_id ) );
				return false;
			}

			// Get WhatsApp catalog ID.
			$catalog_product_id = get_post_meta( $product_id, '_wch_catalog_id', true );

			if ( empty( $catalog_product_id ) ) {
				WCH_Logger::warning(
					'Product not synced to WhatsApp catalog, skipping stock sync',
					array( 'product_id' => $product_id )
				);
				return false;
			}

			// Determine availability.
			$stock_quantity = $product->get_stock_quantity();
			$availability   = ( $stock_quantity > 0 ) ? 'in_stock' : 'out_of_stock';

			// Get catalog ID from settings.
			$catalog_id = $this->settings->get( 'catalog.catalog_id' );

			if ( empty( $catalog_id ) ) {
				WCH_Logger::error( 'Catalog ID not configured' );
				return false;
			}

			// Prepare API client.
			$api_client = $this->get_api_client();

			// Update product availability in catalog.
			$update_data = array(
				'availability' => $availability,
			);

			$response = $api_client->update_catalog_product( $catalog_id, $catalog_product_id, $update_data );

			WCH_Logger::info(
				'Stock synced to WhatsApp catalog',
				array(
					'product_id'         => $product_id,
					'catalog_product_id' => $catalog_product_id,
					'availability'       => $availability,
					'stock_quantity'     => $stock_quantity,
				)
			);

			// Update last synced timestamp.
			update_post_meta( $product_id, '_wch_stock_last_synced', time() );

			return true;

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Stock sync failed',
				array(
					'product_id' => $product_id,
					'error'      => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Handle stock reduction after order is placed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order_stock_reduction( $order_id ) {
		if ( ! $this->is_realtime_sync_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Check each ordered product's stock.
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$product    = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$stock_quantity = $product->get_stock_quantity();

			// If product went out of stock, sync immediately.
			if ( $stock_quantity <= 0 ) {
				$this->sync_stock_from_woocommerce( $product_id );
			}
		}
	}

	/**
	 * Detect stock discrepancies between WooCommerce and WhatsApp catalog.
	 *
	 * Runs as a daily cron job.
	 */
	public function detect_stock_discrepancies() {
		WCH_Logger::info( 'Starting stock discrepancy detection' );

		try {
			// Get catalog ID.
			$catalog_id = $this->settings->get( 'catalog.catalog_id' );

			if ( empty( $catalog_id ) ) {
				WCH_Logger::error( 'Catalog ID not configured for discrepancy detection' );
				return;
			}

			// Get API client.
			$api_client = $this->get_api_client();

			// Get all products synced to WhatsApp.
			$args = array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_wch_catalog_id',
						'compare' => 'EXISTS',
					),
				),
			);

			$products = get_posts( $args );

			$discrepancies = array();
			$total_checked = 0;

			foreach ( $products as $post ) {
				$product_id         = $post->ID;
				$product            = wc_get_product( $product_id );
				$catalog_product_id = get_post_meta( $product_id, '_wch_catalog_id', true );

				if ( ! $product || ! $catalog_product_id ) {
					continue;
				}

				++$total_checked;

				// Get WooCommerce stock status.
				$wc_stock_quantity = $product->get_stock_quantity();
				$wc_availability   = ( $wc_stock_quantity > 0 ) ? 'in_stock' : 'out_of_stock';

				// Get WhatsApp catalog stock status.
				try {
					$catalog_product       = $api_client->get_catalog_product( $catalog_id, $catalog_product_id );
					$whatsapp_availability = $catalog_product['availability'] ?? 'unknown';

					// Compare availability.
					if ( $wc_availability !== $whatsapp_availability ) {
						$discrepancy = array(
							'product_id'            => $product_id,
							'product_name'          => $product->get_name(),
							'wc_availability'       => $wc_availability,
							'wc_stock_quantity'     => $wc_stock_quantity,
							'whatsapp_availability' => $whatsapp_availability,
						);

						$discrepancies[] = $discrepancy;

						WCH_Logger::warning(
							'Stock discrepancy detected',
							$discrepancy
						);

						// Auto-fix if enabled.
						if ( $this->is_auto_fix_enabled() ) {
							$this->sync_stock_from_woocommerce( $product_id );
							WCH_Logger::info( 'Auto-fixed stock discrepancy', array( 'product_id' => $product_id ) );
						}
					}
				} catch ( Exception $e ) {
					WCH_Logger::error(
						'Failed to get catalog product for discrepancy check',
						array(
							'product_id'         => $product_id,
							'catalog_product_id' => $catalog_product_id,
							'error'              => $e->getMessage(),
						)
					);
				}
			}

			// Log summary.
			WCH_Logger::info(
				'Stock discrepancy detection completed',
				array(
					'total_checked' => $total_checked,
					'discrepancies' => count( $discrepancies ),
					'auto_fix'      => $this->is_auto_fix_enabled(),
				)
			);

			// Store discrepancy results for dashboard widget.
			update_option( 'wch_stock_discrepancy_last_check', time() );
			update_option( 'wch_stock_discrepancy_count', count( $discrepancies ) );
			update_option( 'wch_stock_discrepancies', $discrepancies );

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Stock discrepancy detection failed',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Send low stock notification to subscribed customers.
	 *
	 * @param int $product_id Product ID.
	 */
	private function send_low_stock_notification( $product_id ) {
		// This is a placeholder for future implementation.
		// Would require a customer subscription system for product notifications.
		WCH_Logger::info(
			'Low stock notification triggered',
			array( 'product_id' => $product_id )
		);

		// TODO: Implement customer notification via broadcast.
	}

	/**
	 * Get WhatsApp API client.
	 *
	 * @return WCH_WhatsApp_API_Client
	 * @throws WCH_Exception If API is not configured.
	 */
	private function get_api_client() {
		$config = array(
			'phone_number_id' => $this->settings->get( 'api.whatsapp_phone_number_id' ),
			'access_token'    => $this->settings->get( 'api.access_token' ),
			'api_version'     => $this->settings->get( 'api.api_version', 'v18.0' ),
		);

		return new WCH_WhatsApp_API_Client( $config );
	}

	/**
	 * Check if real-time sync is enabled.
	 *
	 * @return bool
	 */
	private function is_realtime_sync_enabled() {
		return (bool) $this->settings->get( 'inventory.enable_realtime_sync', false );
	}

	/**
	 * Check if low stock notification is enabled.
	 *
	 * @return bool
	 */
	private function is_low_stock_notification_enabled() {
		return (bool) $this->settings->get( 'inventory.notify_low_stock', false );
	}

	/**
	 * Check if auto-fix discrepancies is enabled.
	 *
	 * @return bool
	 */
	private function is_auto_fix_enabled() {
		return (bool) $this->settings->get( 'inventory.auto_fix_discrepancies', false );
	}

	/**
	 * Get low stock threshold.
	 *
	 * @return int
	 */
	private function get_low_stock_threshold() {
		return (int) $this->settings->get( 'inventory.low_stock_threshold', 5 );
	}

	/**
	 * Get sync statistics for dashboard widget.
	 *
	 * @return array
	 */
	public function get_sync_stats() {
		// Get total synced products.
		$total_synced = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_wch_catalog_id',
						'compare' => 'EXISTS',
					),
				),
				'fields'         => 'ids',
			)
		);

		// Get discrepancy data.
		$discrepancy_count = (int) get_option( 'wch_stock_discrepancy_count', 0 );
		$last_check        = (int) get_option( 'wch_stock_discrepancy_last_check', 0 );
		$discrepancies     = get_option( 'wch_stock_discrepancies', array() );

		// Get recent sync errors (from logs).
		$recent_errors = $this->get_recent_sync_errors();

		return array(
			'products_in_sync'  => count( $total_synced ),
			'out_of_sync_count' => $discrepancy_count,
			'last_sync_time'    => $last_check,
			'sync_errors'       => count( $recent_errors ),
			'discrepancies'     => $discrepancies,
		);
	}

	/**
	 * Get recent sync errors from logs.
	 *
	 * @return array
	 */
	private function get_recent_sync_errors() {
		// This would require querying the logs table or log files.
		// For now, return empty array.
		return array();
	}

	/**
	 * Schedule recurring discrepancy check.
	 */
	public static function schedule_discrepancy_check() {
		if ( ! as_next_scheduled_action( 'wch_detect_stock_discrepancies' ) ) {
			as_schedule_recurring_action(
				time(),
				DAY_IN_SECONDS,
				'wch_detect_stock_discrepancies',
				array(),
				'wch'
			);

			WCH_Logger::info( 'Scheduled daily stock discrepancy check' );
		}
	}
}
