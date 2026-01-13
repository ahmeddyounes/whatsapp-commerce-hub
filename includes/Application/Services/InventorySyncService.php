<?php
/**
 * Inventory Sync Service
 *
 * Handles real-time inventory synchronization between WooCommerce and WhatsApp Catalog.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WC_Product;
use WC_Product_Variation;

/**
 * Class InventorySyncService
 *
 * Handles real-time inventory synchronization between WooCommerce and WhatsApp Catalog
 * with debouncing and discrepancy detection.
 */
class InventorySyncService {

	private const DEBOUNCE_DELAY            = 5;
	private const DEBOUNCE_TRANSIENT_PREFIX = 'wch_stock_sync_debounce_';

	/**
	 * Constructor.
	 *
	 * @param SettingsManager $settings Plugin settings.
	 * @param Logger          $logger   Logger service.
	 */
	public function __construct(
		private readonly SettingsManager $settings,
		private readonly Logger $logger
	) {
		$this->initHooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function initHooks(): void {
		// Stock change hooks
		add_action( 'woocommerce_product_set_stock', [ $this, 'handleStockChange' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'handleStockChange' ], 10, 1 );

		// Order stock reduction hook
		add_action( 'woocommerce_order_status_processing', [ $this, 'handleOrderStockReduction' ], 10, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'handleOrderStockReduction' ], 10, 1 );

		// Process debounced sync action
		add_action( 'wch_process_stock_sync', [ $this, 'processStockSync' ], 10, 1 );

		// Discrepancy detection cron
		add_action( 'wch_detect_stock_discrepancies', [ $this, 'detectStockDiscrepancies' ], 10, 0 );
	}

	/**
	 * Handle stock change events with debouncing
	 */
	public function handleStockChange( WC_Product|WC_Product_Variation $product ): void {
		if ( ! $this->isRealtimeSyncEnabled() ) {
			return;
		}

		$productId = $product->get_id();
		$newStock  = $product->get_stock_quantity();
		$oldStock  = (int) get_post_meta( $productId, '_wch_previous_stock', true );

		// Store new stock as previous for next comparison
		update_post_meta( $productId, '_wch_previous_stock', $newStock );

		// Determine if availability changed
		$availabilityChanged = false;
		$newAvailability     = null;

		if ( $newStock <= 0 && $oldStock > 0 ) {
			$availabilityChanged = true;
			$newAvailability     = 'out_of_stock';
		} elseif ( $newStock > 0 && $oldStock <= 0 ) {
			$availabilityChanged = true;
			$newAvailability     = 'in_stock';
		}

		// Check low stock threshold
		$lowStockThreshold = $this->getLowStockThreshold();
		$lowStockReached   = false;

		if ( $newStock !== null && $newStock > 0 && $newStock <= $lowStockThreshold && $oldStock > $lowStockThreshold ) {
			$lowStockReached = true;
		}

		$this->logger->info(
			'Stock change detected',
			[
				'product_id'           => $productId,
				'old_stock'            => $oldStock,
				'new_stock'            => $newStock,
				'availability_changed' => $availabilityChanged,
				'new_availability'     => $newAvailability,
				'low_stock_reached'    => $lowStockReached,
			]
		);

		// Debounce: store pending sync
		if ( $availabilityChanged || $lowStockReached ) {
			$this->scheduleDebouncedSync( $productId, $newAvailability, $lowStockReached );
		}
	}

	/**
	 * Schedule a debounced sync for a product
	 */
	private function scheduleDebouncedSync( int $productId, ?string $newAvailability, bool $lowStockReached ): void {
		$transientKey = self::DEBOUNCE_TRANSIENT_PREFIX . $productId;

		// Store debounce data
		set_transient(
			$transientKey,
			[
				'product_id'        => $productId,
				'new_availability'  => $newAvailability,
				'low_stock_reached' => $lowStockReached,
				'timestamp'         => time(),
			],
			self::DEBOUNCE_DELAY + 5
		);

		// Schedule the sync action if not already scheduled
		if ( ! as_next_scheduled_action( 'wch_process_stock_sync', [ 'product_id' => $productId ] ) ) {
			as_schedule_single_action(
				time() + self::DEBOUNCE_DELAY,
				'wch_process_stock_sync',
				[ 'product_id' => $productId ],
				'wch'
			);
		}
	}

	/**
	 * Process debounced stock sync
	 */
	public function processStockSync( int $productId ): void {
		$transientKey = self::DEBOUNCE_TRANSIENT_PREFIX . $productId;
		$syncData     = get_transient( $transientKey );

		if ( ! $syncData ) {
			$this->logger->warning( 'No sync data found for product', [ 'product_id' => $productId ] );
			return;
		}

		// Delete transient
		delete_transient( $transientKey );

		// Get product
		$product = wc_get_product( $productId );
		if ( ! $product ) {
			$this->logger->error( 'Product not found for sync', [ 'product_id' => $productId ] );
			return;
		}

		// Perform sync
		$this->syncProductStock( $product, $syncData );
	}

	/**
	 * Sync product stock to WhatsApp Catalog
	 */
	private function syncProductStock( WC_Product $product, array $syncData ): void {
		$catalogId = get_post_meta( $product->get_id(), '_wch_catalog_id', true );

		if ( ! $catalogId ) {
			$this->logger->warning(
				'Product not in WhatsApp Catalog',
				[
					'product_id' => $product->get_id(),
				]
			);
			return;
		}

		try {
			$apiClient = $this->getApiClient();

			$inventoryData = [
				'availability' => $syncData['new_availability'] ?? $this->getProductAvailability( $product ),
				'quantity'     => $product->get_stock_quantity(),
			];

			$response = $apiClient->updateProductInventory( $catalogId, $inventoryData );

			$this->logger->info(
				'Stock synced successfully',
				[
					'product_id'     => $product->get_id(),
					'catalog_id'     => $catalogId,
					'inventory_data' => $inventoryData,
				]
			);

			// Update last sync timestamp
			update_post_meta( $product->get_id(), '_wch_last_stock_sync', time() );

		} catch ( \Exception $e ) {
			$this->logger->error(
				'Stock sync failed',
				[
					'product_id' => $product->get_id(),
					'error'      => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Handle order stock reduction
	 */
	public function handleOrderStockReduction( int $orderId ): void {
		if ( ! $this->isRealtimeSyncEnabled() ) {
			return;
		}

		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$this->handleStockChange( $product );
			}
		}

		$this->logger->info(
			'Order stock reduction processed',
			[
				'order_id' => $orderId,
			]
		);
	}

	/**
	 * Detect stock discrepancies between WooCommerce and WhatsApp Catalog
	 */
	public function detectStockDiscrepancies(): void {
		if ( ! $this->isDiscrepancyDetectionEnabled() ) {
			return;
		}

		$this->logger->info( 'Starting stock discrepancy detection' );

		// Get all products synced to WhatsApp Catalog
		$syncedProducts = get_posts(
			[
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'meta_query'     => [
					[
						'key'     => '_wch_catalog_id',
						'compare' => 'EXISTS',
					],
				],
				'fields'         => 'ids',
			]
		);

		$discrepancies = [];
		$apiClient     = $this->getApiClient();

		foreach ( $syncedProducts as $productId ) {
			$product = wc_get_product( $productId );
			if ( ! $product ) {
				continue;
			}

			$catalogId = get_post_meta( $productId, '_wch_catalog_id', true );

			try {
				// Fetch catalog product data
				$catalogProduct = $apiClient->getProduct( $catalogId );

				// Compare stock quantities
				$wcStock      = $product->get_stock_quantity();
				$catalogStock = $catalogProduct['inventory']['quantity'] ?? null;

				if ( $wcStock !== $catalogStock ) {
					$discrepancies[] = [
						'product_id'    => $productId,
						'catalog_id'    => $catalogId,
						'wc_stock'      => $wcStock,
						'catalog_stock' => $catalogStock,
						'difference'    => $wcStock - $catalogStock,
					];
				}
			} catch ( \Exception $e ) {
				$this->logger->error(
					'Failed to check discrepancy for product',
					[
						'product_id' => $productId,
						'error'      => $e->getMessage(),
					]
				);
			}
		}

		// Store discrepancies
		update_option( 'wch_stock_discrepancies', $discrepancies );
		update_option( 'wch_stock_discrepancy_count', count( $discrepancies ) );
		update_option( 'wch_stock_discrepancy_last_check', time() );

		$this->logger->info(
			'Stock discrepancy detection completed',
			[
				'total_products'      => count( $syncedProducts ),
				'discrepancies_found' => count( $discrepancies ),
			]
		);

		// Send notification if threshold exceeded
		if ( count( $discrepancies ) > $this->getDiscrepancyThreshold() ) {
			$this->sendDiscrepancyNotification( count( $discrepancies ) );
		}
	}

	/**
	 * Get product availability status
	 */
	private function getProductAvailability( WC_Product $product ): string {
		$stockQuantity = $product->get_stock_quantity();

		if ( $stockQuantity === null ) {
			return 'in_stock'; // No stock management
		}

		return $stockQuantity > 0 ? 'in_stock' : 'out_of_stock';
	}

	/**
	 * Get sync statistics
	 */
	public function getSyncStats(): array {
		$totalSynced = get_posts(
			[
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'meta_query'     => [
					[
						'key'     => '_wch_catalog_id',
						'compare' => 'EXISTS',
					],
				],
				'fields'         => 'ids',
			]
		);

		$discrepancyCount = (int) get_option( 'wch_stock_discrepancy_count', 0 );
		$lastCheck        = (int) get_option( 'wch_stock_discrepancy_last_check', 0 );
		$discrepancies    = get_option( 'wch_stock_discrepancies', [] );

		return [
			'products_in_sync'  => count( $totalSynced ),
			'out_of_sync_count' => $discrepancyCount,
			'last_sync_time'    => $lastCheck,
			'discrepancies'     => $discrepancies,
		];
	}

	/**
	 * Send discrepancy notification to admin
	 */
	private function sendDiscrepancyNotification( int $count ): void {
		$adminEmail = get_option( 'admin_email' );
		$subject    = sprintf( __( 'Stock Discrepancy Alert: %d products out of sync', 'whatsapp-commerce-hub' ), $count );

		$message = sprintf(
			__( 'There are %d products with stock discrepancies between WooCommerce and WhatsApp Catalog.', 'whatsapp-commerce-hub' ),
			$count
		);

		wp_mail( $adminEmail, $subject, $message );

		$this->logger->warning( 'Discrepancy notification sent', [ 'count' => $count ] );
	}

	/**
	 * Schedule recurring discrepancy check
	 */
	public static function scheduleDiscrepancyCheck(): void {
		if ( ! as_next_scheduled_action( 'wch_detect_stock_discrepancies' ) ) {
			as_schedule_recurring_action(
				time(),
				DAY_IN_SECONDS,
				'wch_detect_stock_discrepancies',
				[],
				'wch'
			);
		}
	}

	/**
	 * Check if real-time sync is enabled
	 */
	private function isRealtimeSyncEnabled(): bool {
		return (bool) $this->settings->get( 'catalog.realtime_inventory_sync', false );
	}

	/**
	 * Check if discrepancy detection is enabled
	 */
	private function isDiscrepancyDetectionEnabled(): bool {
		return (bool) $this->settings->get( 'catalog.discrepancy_detection', true );
	}

	/**
	 * Get low stock threshold
	 */
	private function getLowStockThreshold(): int {
		return (int) $this->settings->get( 'catalog.low_stock_threshold', 5 );
	}

	/**
	 * Get discrepancy threshold for notifications
	 */
	private function getDiscrepancyThreshold(): int {
		return (int) $this->settings->get( 'catalog.discrepancy_threshold', 10 );
	}

	/**
	 * Get WhatsApp API client
	 */
	private function getApiClient(): object {
		return wch( WhatsAppApiClient::class );
	}
}
