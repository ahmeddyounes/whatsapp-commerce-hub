<?php
declare(strict_types=1);


/**
 * Sync Progress Tracker Service
 *
 * Tracks bulk sync progress with atomic operations and database locking.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */


namespace WhatsAppCommerceHub\Application\Services\ProductSync;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\SyncProgressTrackerInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SyncProgressTracker
 *
 * Handles bulk sync progress tracking with atomic operations.
 */
class SyncProgressTracker implements SyncProgressTrackerInterface {

	/**
	 * Option key for sync progress.
	 */
	public const OPTION_SYNC_PROGRESS = 'wch_bulk_sync_progress';

	/**
	 * Lock name for atomic operations.
	 */
	public const LOCK_NAME = 'wch_sync_progress_lock';

	/**
	 * Lock timeout in seconds.
	 */
	public const LOCK_TIMEOUT = 30;

	/**
	 * Maximum failed items to store.
	 */
	public const MAX_FAILED_ITEMS = 100;

	/**
	 * WordPress database.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Logger service.
	 *
	 * @var LoggerInterface|null
	 */
	protected ?LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null           $wpdb   WordPress database.
	 * @param LoggerInterface|null $logger Logger service.
	 */
	public function __construct( ?\wpdb $wpdb_instance = null, ?LoggerInterface $logger = null ) {
		global $wpdb;
		$this->wpdb   = $wpdb_instance ?? $wpdb;
		$this->logger = $logger;
	}

	/**
	 * {@inheritdoc}
	 */
	public function startSync( int $totalItems ): string {
		$syncId = wp_generate_uuid4();

		$progress = array(
			'sync_id'         => $syncId,
			'status'          => 'in_progress',
			'total_items'     => $totalItems,
			'processed_count' => 0,
			'success_count'   => 0,
			'failed_count'    => 0,
			'failed_items'    => array(),
			'started_at'      => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
			'completed_at'    => null,
		);

		update_option( self::OPTION_SYNC_PROGRESS, $progress, false );

		$this->log(
			'info',
			'Initialized bulk sync progress tracking',
			array(
				'sync_id'     => $syncId,
				'total_items' => $totalItems,
			)
		);

		return $syncId;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateProgress( string $syncId, int $processed, int $successful, int $failed ): bool {
		$lockAcquired = $this->acquireLock();

		if ( ! $lockAcquired ) {
			$this->log(
				'warning',
				'Failed to acquire sync progress lock',
				array(
					'sync_id' => $syncId,
				)
			);
			return false;
		}

		try {
			$progress = get_option( self::OPTION_SYNC_PROGRESS );

			if ( ! $progress || $progress['sync_id'] !== $syncId ) {
				$this->log(
					'warning',
					'Sync progress not found or ID mismatch',
					array(
						'expected_sync_id' => $syncId,
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

				$this->log(
					'info',
					'Bulk sync completed',
					array(
						'sync_id'       => $syncId,
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
			$this->releaseLock();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function addFailure( string $syncId, int $productId, string $errorMessage ): bool {
		$lockAcquired = $this->acquireLock();

		if ( ! $lockAcquired ) {
			return false;
		}

		try {
			$progress = get_option( self::OPTION_SYNC_PROGRESS );

			if ( ! $progress || $progress['sync_id'] !== $syncId ) {
				return false;
			}

			// Limit stored failures to prevent memory bloat.
			if ( count( $progress['failed_items'] ) >= self::MAX_FAILED_ITEMS ) {
				array_shift( $progress['failed_items'] );
			}

			$progress['failed_items'][] = array(
				'product_id' => $productId,
				'error'      => mb_substr( $errorMessage, 0, 255 ),
				'failed_at'  => current_time( 'mysql', true ),
			);

			update_option( self::OPTION_SYNC_PROGRESS, $progress, false );

			return true;
		} finally {
			$this->releaseLock();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getProgress(): ?array {
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
			$endTime                     = $progress['completed_at'] ?? current_time( 'mysql', true );
			$progress['elapsed_seconds'] = strtotime( $endTime ) - strtotime( $progress['started_at'] );
		}

		// Estimate remaining time based on current rate.
		if ( $progress['processed_count'] > 0 && 'in_progress' === $progress['status'] ) {
			$rate                                    = $progress['processed_count'] / max( 1, $progress['elapsed_seconds'] );
			$remainingItems                          = $progress['total_items'] - $progress['processed_count'];
			$progress['estimated_remaining_seconds'] = $rate > 0 ? (int) round( $remainingItems / $rate ) : null;
		}

		return $progress;
	}

	/**
	 * {@inheritdoc}
	 */
	public function failSync( string $syncId, string $reason ): bool {
		$lockAcquired = $this->acquireLock();

		if ( ! $lockAcquired ) {
			$this->log( 'warning', 'Failed to acquire lock for fail_bulk_sync', array() );
			return false;
		}

		try {
			$progress = get_option( self::OPTION_SYNC_PROGRESS );

			if ( ! $progress || $progress['sync_id'] !== $syncId ) {
				return false;
			}

			$progress['status']         = 'failed';
			$progress['failure_reason'] = $reason;
			$progress['completed_at']   = current_time( 'mysql', true );

			$this->log(
				'error',
				'Bulk sync failed',
				array(
					'sync_id' => $syncId,
					'reason'  => $reason,
				)
			);

			return update_option( self::OPTION_SYNC_PROGRESS, $progress, false );
		} finally {
			$this->releaseLock();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function clearProgress( bool $force = false ): bool {
		$lockAcquired = $this->acquireLock();

		if ( ! $lockAcquired ) {
			$this->log( 'warning', 'Failed to acquire lock for clear_sync_progress', array() );
			return false;
		}

		try {
			if ( ! $force ) {
				$progress = get_option( self::OPTION_SYNC_PROGRESS );
				if ( $progress && 'in_progress' === ( $progress['status'] ?? '' ) ) {
					$this->log( 'warning', 'Cannot clear sync progress while sync is in progress', array() );
					return false;
				}
			}

			return delete_option( self::OPTION_SYNC_PROGRESS );
		} finally {
			$this->releaseLock();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFailedItems(): array {
		$progress = get_option( self::OPTION_SYNC_PROGRESS );

		if ( ! $progress || empty( $progress['failed_items'] ) ) {
			return array();
		}

		return array_column( $progress['failed_items'], 'product_id' );
	}

	/**
	 * Check if a sync is currently in progress.
	 *
	 * @return bool True if sync is in progress.
	 */
	public function isSyncInProgress(): bool {
		$progress = get_option( self::OPTION_SYNC_PROGRESS );
		return $progress && 'in_progress' === ( $progress['status'] ?? '' );
	}

	/**
	 * Get current sync ID if sync is in progress.
	 *
	 * @return string|null Sync ID or null.
	 */
	public function getCurrentSyncId(): ?string {
		$progress = get_option( self::OPTION_SYNC_PROGRESS );

		if ( ! $progress || 'in_progress' !== ( $progress['status'] ?? '' ) ) {
			return null;
		}

		return $progress['sync_id'] ?? null;
	}

	/**
	 * Acquire database lock.
	 *
	 * @return bool True if lock acquired.
	 */
	protected function acquireLock(): bool {
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				self::LOCK_NAME,
				self::LOCK_TIMEOUT
			)
		);

		return (bool) $result;
	}

	/**
	 * Release database lock.
	 *
	 * @return void
	 */
	protected function releaseLock(): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'SELECT RELEASE_LOCK(%s)',
				self::LOCK_NAME
			)
		);
	}

	/**
	 * Log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	protected function log( string $level, string $message, array $context = array() ): void {
		$context['category'] = 'product-sync';

		if ( null !== $this->logger ) {
			$this->logger->log( $level, $message, 'product_sync', $context );
			return;
		}

		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $context, $level );
		}
	}
}
