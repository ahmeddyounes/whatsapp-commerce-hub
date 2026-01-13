<?php
/**
 * Catalog Sync Service
 *
 * Handles product catalog synchronization business logic.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.1.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\CatalogSyncServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\SyncProgressTrackerInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Infrastructure\Queue\QueueManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CatalogSyncService
 *
 * Manages product catalog synchronization with WhatsApp.
 */
class CatalogSyncService implements CatalogSyncServiceInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Settings manager.
	 *
	 * @var SettingsInterface|null
	 */
	private ?SettingsInterface $settings;

	/**
	 * Queue manager.
	 *
	 * @var QueueManager|null
	 */
	private ?QueueManager $queue;

	/**
	 * History storage limit.
	 *
	 * @var int
	 */
	private const HISTORY_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null          $wpdb_instance WordPress database instance.
	 * @param SettingsInterface|null $settings Settings manager.
	 * @param QueueManager|null   $queue Queue manager.
	 */
	public function __construct( ?\wpdb $wpdb_instance = null, ?SettingsInterface $settings = null, ?QueueManager $queue = null ) {
		if ( null === $wpdb_instance ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		} else {
			$this->wpdb = $wpdb_instance;
		}

		$this->settings = $settings;
		$this->queue    = $queue;
	}

	/**
	 * Get sync status overview.
	 *
	 * @return array Sync status data.
	 */
	public function getSyncStatusOverview(): array {
		// Count synced products.
		$total_synced = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'synced'"
		);

		// Get last sync time.
		$last_sync = '';
		if ( $this->settings ) {
			$last_sync = $this->settings->get( 'sync.last_full_sync', '' );
		}

		// Count errors.
		$error_count = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'error'"
		);

		return [
			'total_synced' => (int) $total_synced,
			'last_sync'    => $last_sync,
			'error_count'  => (int) $error_count,
		];
	}

	/**
	 * Get products with filtering and pagination.
	 *
	 * @param array $filters Filter criteria.
	 * @return array Products data with pagination info.
	 */
	public function getProducts( array $filters ): array {
		$page        = $filters['page'] ?? 1;
		$per_page    = $filters['per_page'] ?? 20;
		$search      = $filters['search'] ?? '';
		$category    = $filters['category'] ?? 0;
		$stock       = $filters['stock'] ?? '';
		$sync_status = $filters['sync_status'] ?? '';

		$args = [
			'post_type'      => 'product',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
			's'              => $search,
		];

		if ( $category ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category,
				],
			];
		}

		if ( $stock ) {
			$args['meta_query'] = [
				'relation' => 'AND',
				[
					'key'   => '_stock_status',
					'value' => $stock,
				],
			];
		}

		if ( $sync_status ) {
			if ( ! isset( $args['meta_query'] ) ) {
				$args['meta_query'] = [ 'relation' => 'AND' ];
			}
			$args['meta_query'][] = [
				'key'   => '_wch_sync_status',
				'value' => $sync_status,
			];
		}

		$query    = new \WP_Query( $args );
		$products = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$product = wc_get_product( get_the_ID() );

				if ( $product ) {
					$product_sync_status = get_post_meta( $product->get_id(), '_wch_sync_status', true );
					$last_synced         = get_post_meta( $product->get_id(), '_wch_last_synced', true );
					$sync_error          = get_post_meta( $product->get_id(), '_wch_sync_error', true );

					$products[] = [
						'id'          => $product->get_id(),
						'name'        => $product->get_name(),
						'sku'         => $product->get_sku(),
						'price'       => $product->get_price_html(),
						'stock'       => $product->get_stock_status(),
						'sync_status' => $product_sync_status ?: 'not_selected',
						'last_synced' => $last_synced ? human_time_diff( strtotime( $last_synced ), time() ) . ' ago' : '-',
						'image_url'   => get_the_post_thumbnail_url( $product->get_id(), 'thumbnail' ),
						'error'       => $sync_error,
					];
				}
			}
			wp_reset_postdata();
		}

		return [
			'products'    => $products,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
		];
	}

	/**
	 * Sync multiple products.
	 *
	 * @param array $product_ids Product IDs to sync.
	 * @param bool  $sync_all Whether to sync all products.
	 * @return array Result with status and count.
	 */
	public function bulkSync( array $product_ids, bool $sync_all = false ): array {
		if ( empty( $product_ids ) && ! $sync_all ) {
			return [
				'success' => false,
				'message' => __( 'No products selected', 'whatsapp-commerce-hub' ),
			];
		}

		$sync_service = wch( ProductSyncOrchestratorInterface::class );

		if ( $sync_all ) {
			$sync_service->syncAllProducts();
			$count = 'all';
		} else {
			foreach ( $product_ids as $product_id ) {
				update_post_meta( $product_id, '_wch_sync_status', 'pending' );
			}

			// Queue sync jobs.
			$queue = $this->queue ?? wch( QueueManager::class );
			$queue->schedule_bulk_action( 'wch_sync_single_product', $product_ids );

			$count = count( $product_ids );
		}

		// Record sync history.
		$this->recordSyncHistory( is_numeric( $count ) ? $count : 0, 'manual' );

		return [
			'success' => true,
			'message' => __( 'Products queued for sync', 'whatsapp-commerce-hub' ),
			'count'   => $count,
		];
	}

	/**
	 * Sync a single product.
	 *
	 * @param int $product_id Product ID.
	 * @return array Result with success status.
	 */
	public function syncProduct( int $product_id ): array {
		if ( ! $product_id ) {
			return [
				'success' => false,
				'message' => __( 'Invalid product ID', 'whatsapp-commerce-hub' ),
			];
		}

		try {
			$sync_service = wch( ProductSyncOrchestratorInterface::class );
			$result       = $sync_service->syncProduct( $product_id );

			if ( ! empty( $result['success'] ) ) {
				update_post_meta( $product_id, '_wch_sync_status', 'synced' );
				update_post_meta( $product_id, '_wch_last_synced', current_time( 'mysql' ) );
				delete_post_meta( $product_id, '_wch_sync_error' );

				return [
					'success' => true,
					'message' => __( 'Product synced successfully', 'whatsapp-commerce-hub' ),
				];
			}

			return [
				'success' => false,
				'message' => __( 'Sync failed', 'whatsapp-commerce-hub' ),
			];
		} catch ( \Throwable $e ) {
			update_post_meta( $product_id, '_wch_sync_status', 'error' );
			update_post_meta( $product_id, '_wch_sync_error', $e->getMessage() );

			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Remove products from catalog.
	 *
	 * @param array $product_ids Product IDs to remove.
	 * @return array Result with count.
	 */
	public function removeFromCatalog( array $product_ids ): array {
		if ( empty( $product_ids ) ) {
			return [
				'success' => false,
				'message' => __( 'No products selected', 'whatsapp-commerce-hub' ),
			];
		}

		foreach ( $product_ids as $product_id ) {
			delete_post_meta( $product_id, '_wch_sync_status' );
			delete_post_meta( $product_id, '_wch_last_synced' );
			delete_post_meta( $product_id, '_wch_sync_error' );
		}

		return [
			'success' => true,
			'message' => __( 'Products removed from catalog', 'whatsapp-commerce-hub' ),
			'count'   => count( $product_ids ),
		];
	}

	/**
	 * Get sync history.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Items per page.
	 * @return array History entries with pagination info.
	 */
	public function getSyncHistory( int $page = 1, int $per_page = 20 ): array {
		$history = get_option( 'wch_sync_history', [] );
		$total   = count( $history );

		// Sort by timestamp descending.
		usort(
			$history,
			function ( $a, $b ) {
				return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
			}
		);

		// Paginate.
		$offset  = ( $page - 1 ) * $per_page;
		$history = array_slice( $history, $offset, $per_page );

		// Guard against division by zero.
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return [
			'history'     => $history,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * Record sync history entry.
	 *
	 * @param int    $product_count Products affected.
	 * @param string $triggered_by Who triggered the sync.
	 * @param string $status Sync status.
	 * @param int    $duration Duration in seconds.
	 * @param array  $errors List of errors.
	 * @return void
	 */
	public function recordSyncHistory(
		int $product_count,
		string $triggered_by = 'manual',
		string $status = 'success',
		int $duration = 0,
		array $errors = []
	): void {
		$history = get_option( 'wch_sync_history', [] );

		$entry = [
			'timestamp'      => current_time( 'mysql' ),
			'products_count' => $product_count,
			'status'         => $status,
			'duration'       => $duration,
			'error_count'    => count( $errors ),
			'errors'         => $errors,
			'triggered_by'   => $triggered_by,
		];

		array_unshift( $history, $entry );

		// Keep only last entries up to limit.
		$history = array_slice( $history, 0, self::HISTORY_LIMIT );

		update_option( 'wch_sync_history', $history );
	}

	/**
	 * Save sync settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool True if saved successfully.
	 */
	public function saveSyncSettings( array $settings ): bool {
		if ( ! $this->settings ) {
			return false;
		}

		$sync_mode          = $settings['sync_mode'] ?? 'manual';
		$sync_frequency     = $settings['sync_frequency'] ?? 'daily';
		$categories_include = $settings['categories_include'] ?? [];
		$categories_exclude = $settings['categories_exclude'] ?? [];

		$this->settings->set( 'sync.mode', $sync_mode );
		$this->settings->set( 'sync.frequency', $sync_frequency );
		$this->settings->set( 'sync.categories_include', $categories_include );
		$this->settings->set( 'sync.categories_exclude', $categories_exclude );

		return true;
	}

	/**
	 * Perform dry run sync (preview).
	 *
	 * @param int $limit Maximum number of products to preview.
	 * @return array Products that would be synced.
	 */
	public function dryRunSync( int $limit = 100 ): array {
		// First get the total count efficiently.
		$count_args  = [
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		];
		$count_query = new \WP_Query( $count_args );
		$total_count = $count_query->found_posts;

		// Now get the limited preview sample.
		$preview_limit = min( $limit, 1000 ); // Hard cap at 1000 for memory safety.
		$args          = [
			'post_type'      => 'product',
			'posts_per_page' => $preview_limit,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		];

		$query       = new \WP_Query( $args );
		$product_ids = $query->posts;

		$products_info = [];
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$products_info[] = [
					'id'   => $product->get_id(),
					'name' => $product->get_name(),
					'sku'  => $product->get_sku(),
				];
			}
		}

		return [
			'count'         => $total_count,
			'preview_count' => count( $products_info ),
			'products'      => $products_info,
			'truncated'     => $total_count > $preview_limit,
		];
	}

	/**
	 * Retry failed products.
	 *
	 * @return array Result with count of retried products.
	 */
	public function retryFailed(): array {
		// Get all products with error status.
		$product_ids = $this->wpdb->get_col(
			"SELECT post_id FROM {$this->wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'error'"
		);

		if ( empty( $product_ids ) ) {
			return [
				'success' => false,
				'message' => __( 'No failed products to retry', 'whatsapp-commerce-hub' ),
			];
		}

		// Reset status to pending.
		foreach ( $product_ids as $product_id ) {
			update_post_meta( $product_id, '_wch_sync_status', 'pending' );
			delete_post_meta( $product_id, '_wch_sync_error' );
		}

		// Queue sync jobs.
		$queue = $this->queue ?? wch( QueueManager::class );
		$queue->schedule_bulk_action( 'wch_sync_single_product', $product_ids );

		return [
			'success' => true,
			'message' => __( 'Failed products queued for retry', 'whatsapp-commerce-hub' ),
			'count'   => count( $product_ids ),
		];
	}

	/**
	 * Get bulk sync progress.
	 *
	 * @return array|null Progress data or null if no sync in progress.
	 */
	public function getBulkSyncProgress(): ?array {
		$progressTracker = wch( SyncProgressTrackerInterface::class );
		$progress        = $progressTracker->getProgress();

		if ( ! $progress ) {
			return null;
		}

		// Format timestamps for display.
		$progress['started_at_formatted'] = ! empty( $progress['started_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $progress['started_at'] ) )
			: '';

		$progress['completed_at_formatted'] = ! empty( $progress['completed_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $progress['completed_at'] ) )
			: '';

		// Format elapsed time.
		if ( isset( $progress['elapsed_seconds'] ) ) {
			$progress['elapsed_formatted'] = $this->formatDuration( $progress['elapsed_seconds'] );
		}

		// Format ETA.
		if ( isset( $progress['estimated_remaining_seconds'] ) ) {
			$progress['eta_formatted'] = $this->formatDuration( $progress['estimated_remaining_seconds'] );
		}

		return $progress;
	}

	/**
	 * Clear sync progress.
	 *
	 * @return bool True if cleared, false if sync in progress.
	 */
	public function clearSyncProgress(): bool {
		$progressTracker = wch( SyncProgressTrackerInterface::class );
		return $progressTracker->clearProgress();
	}

	/**
	 * Format duration in seconds to human-readable string.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration.
	 */
	private function formatDuration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return __( '0 seconds', 'whatsapp-commerce-hub' );
		}

		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'whatsapp-commerce-hub' ), $seconds );
		}

		$minutes           = floor( $seconds / 60 );
		$remaining_seconds = $seconds % 60;

		if ( $minutes < 60 ) {
			if ( $remaining_seconds > 0 ) {
				/* translators: 1: number of minutes, 2: number of seconds */
				return sprintf(
					__( '%1$d min %2$d sec', 'whatsapp-commerce-hub' ),
					$minutes,
					$remaining_seconds
				);
			}
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'whatsapp-commerce-hub' ), $minutes );
		}

		$hours             = floor( $minutes / 60 );
		$remaining_minutes = $minutes % 60;

		/* translators: 1: number of hours, 2: number of minutes */
		return sprintf(
			__( '%1$dh %2$dm', 'whatsapp-commerce-hub' ),
			$hours,
			$remaining_minutes
		);
	}
}
