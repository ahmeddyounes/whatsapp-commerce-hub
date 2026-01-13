<?php
/**
 * Sync Job Handler
 *
 * Handles product/order sync jobs with retry logic.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Queue\Handlers;

use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;

/**
 * Class SyncJobHandler
 *
 * Handles product/order sync jobs with retry logic and exponential backoff.
 */
class SyncJobHandler {

	/**
	 * Maximum retry attempts
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Retry delays in seconds (exponential backoff)
	 */
	private const RETRY_DELAYS = [ 60, 300, 900 ]; // 1 min, 5 min, 15 min

	/**
	 * Constructor
	 */
	public function __construct(
		private readonly Logger $logger,
		private readonly JobDispatcher $dispatcher
	) {
	}

	/**
	 * Process a sync job
	 */
	public function process( array $args ): void {
		$jobId      = $args['job_id'] ?? uniqid( 'sync_' );
		$syncType   = $args['sync_type'] ?? 'unknown';
		$entityId   = $args['entity_id'] ?? null;
		$retryCount = $args['retry_count'] ?? 0;

		$this->logger->info(
			'Processing sync job',
			[
				'job_id'      => $jobId,
				'sync_type'   => $syncType,
				'entity_id'   => $entityId,
				'retry_count' => $retryCount,
			]
		);

		try {
			// Execute the sync
			$result = $this->executeSync( $syncType, $entityId, $args );

			if ( $result['success'] ) {
				$this->storeJobResult( $jobId, 'success', $result );

				$this->logger->info(
					'Sync job completed successfully',
					[
						'job_id'    => $jobId,
						'sync_type' => $syncType,
						'duration'  => $result['duration'] ?? 0,
					]
				);
			} else {
				$this->handleFailure( $jobId, $syncType, $entityId, $retryCount, $result );
			}
		} catch ( \Exception $e ) {
			$this->handleFailure(
				$jobId,
				$syncType,
				$entityId,
				$retryCount,
				[
					'success' => false,
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				]
			);
		}
	}

	/**
	 * Execute sync based on type
	 */
	private function executeSync( string $syncType, ?int $entityId, array $args ): array {
		$startTime = microtime( true );

		$result = match ( $syncType ) {
			'product' => $this->syncProduct( $entityId, $args ),
			'product_batch' => $this->syncProductBatch( $args ),
			'order' => $this->syncOrder( $entityId, $args ),
			'inventory' => $this->syncInventory( $entityId, $args ),
			'catalog' => $this->syncCatalog( $args ),
			default => [
				'success' => false,
				'error'   => "Unknown sync type: {$syncType}",
			],
		};

		$duration           = microtime( true ) - $startTime;
		$result['duration'] = round( $duration, 2 );

		return $result;
	}

	/**
	 * Sync a single product
	 */
	private function syncProduct( int $productId, array $args ): array {
		if ( empty( $productId ) ) {
			return [
				'success' => false,
				'error'   => 'Product ID is required',
			];
		}

		// Trigger sync action
		do_action( 'wch_sync_product', $productId, $args );

		return [
			'success'    => true,
			'product_id' => $productId,
			'synced_at'  => time(),
		];
	}

	/**
	 * Sync a batch of products
	 */
	private function syncProductBatch( array $args ): array {
		$productIds = $args['product_ids'] ?? [];

		if ( empty( $productIds ) ) {
			return [
				'success' => false,
				'error'   => 'No products to sync',
			];
		}

		$synced = 0;
		$failed = 0;

		foreach ( $productIds as $productId ) {
			try {
				do_action( 'wch_sync_product', $productId, $args );
				++$synced;
			} catch ( \Exception $e ) {
				++$failed;

				$this->logger->error(
					'Product sync failed in batch',
					[
						'product_id' => $productId,
						'error'      => $e->getMessage(),
					]
				);
			}
		}

		return [
			'success' => $failed === 0,
			'synced'  => $synced,
			'failed'  => $failed,
			'total'   => count( $productIds ),
		];
	}

	/**
	 * Sync an order
	 */
	private function syncOrder( int $orderId, array $args ): array {
		if ( empty( $orderId ) ) {
			return [
				'success' => false,
				'error'   => 'Order ID is required',
			];
		}

		// Trigger sync action
		do_action( 'wch_sync_order', $orderId, $args );

		return [
			'success'   => true,
			'order_id'  => $orderId,
			'synced_at' => time(),
		];
	}

	/**
	 * Sync inventory for a product
	 */
	private function syncInventory( int $productId, array $args ): array {
		if ( empty( $productId ) ) {
			return [
				'success' => false,
				'error'   => 'Product ID is required',
			];
		}

		// Trigger inventory sync action
		do_action( 'wch_sync_inventory', $productId, $args );

		return [
			'success'    => true,
			'product_id' => $productId,
			'synced_at'  => time(),
		];
	}

	/**
	 * Sync entire catalog
	 */
	private function syncCatalog( array $args ): array {
		// Trigger catalog sync action
		do_action( 'wch_sync_catalog', $args );

		return [
			'success'   => true,
			'synced_at' => time(),
		];
	}

	/**
	 * Handle sync failure
	 */
	private function handleFailure(
		string $jobId,
		string $syncType,
		?int $entityId,
		int $retryCount,
		array $result
	): void {
		$this->logger->warning(
			'Sync job failed',
			[
				'job_id'      => $jobId,
				'sync_type'   => $syncType,
				'entity_id'   => $entityId,
				'retry_count' => $retryCount,
				'error'       => $result['error'] ?? 'Unknown error',
			]
		);

		// Check if we should retry
		if ( $retryCount < self::MAX_RETRIES ) {
			$delay = self::RETRY_DELAYS[ $retryCount ] ?? 900;

			// Schedule retry
			$this->dispatcher->schedule(
				'wch_process_sync_job',
				time() + $delay,
				[
					'job_id'      => $jobId,
					'sync_type'   => $syncType,
					'entity_id'   => $entityId,
					'retry_count' => $retryCount + 1,
				]
			);

			$this->logger->info(
				'Sync job retry scheduled',
				[
					'job_id'      => $jobId,
					'retry_count' => $retryCount + 1,
					'delay'       => $delay,
				]
			);
		} else {
			// Max retries exceeded
			$this->storeJobResult( $jobId, 'failed', $result );

			$this->logger->error(
				'Sync job failed permanently',
				[
					'job_id'      => $jobId,
					'sync_type'   => $syncType,
					'entity_id'   => $entityId,
					'max_retries' => self::MAX_RETRIES,
				]
			);

			// Trigger failure notification
			do_action( 'wch_sync_job_failed', $jobId, $syncType, $entityId, $result );
		}
	}

	/**
	 * Store job result
	 */
	private function storeJobResult( string $jobId, string $status, array $result ): void {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_sync_jobs';

		$wpdb->replace(
			$tableName,
			[
				'job_id'       => $jobId,
				'status'       => $status,
				'result'       => wp_json_encode( $result ),
				'completed_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Get job result
	 */
	public function getJobResult( string $jobId ): ?array {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_sync_jobs';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tableName} WHERE job_id = %s",
				$jobId
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['result'] = json_decode( $row['result'], true );

		return $row;
	}

	/**
	 * Get job statistics
	 */
	public function getJobStats( string $syncType = '' ): array {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_sync_jobs';

		$where = '';
		if ( ! empty( $syncType ) ) {
			$where = $wpdb->prepare( ' WHERE result LIKE %s', '%"sync_type":"' . $syncType . '"%' );
		}

		$stats = $wpdb->get_row(
			"SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM {$tableName}{$where}",
			ARRAY_A
		);

		return [
			'total'        => (int) ( $stats['total'] ?? 0 ),
			'success'      => (int) ( $stats['success'] ?? 0 ),
			'failed'       => (int) ( $stats['failed'] ?? 0 ),
			'success_rate' => $stats['total'] > 0
				? round( ( $stats['success'] / $stats['total'] ) * 100, 2 )
				: 0,
		];
	}
}
