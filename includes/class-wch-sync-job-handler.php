<?php
/**
 * Sync Job Handler Class
 *
 * Handles product/order sync jobs with retry logic.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Sync_Job_Handler
 */
class WCH_Sync_Job_Handler {
	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Retry delays in seconds (exponential backoff).
	 *
	 * @var array
	 */
	const RETRY_DELAYS = array( 60, 300, 900 );

	/**
	 * Process a sync job.
	 *
	 * @param array $args Job arguments.
	 */
	public static function process( $args ) {
		$job_id      = $args['job_id'] ?? uniqid( 'sync_' );
		$sync_type   = $args['sync_type'] ?? 'unknown';
		$entity_id   = $args['entity_id'] ?? null;
		$retry_count = $args['retry_count'] ?? 0;

		WCH_Logger::log(
			'info',
			'Processing sync job',
			'queue',
			array(
				'job_id'      => $job_id,
				'sync_type'   => $sync_type,
				'entity_id'   => $entity_id,
				'retry_count' => $retry_count,
			)
		);

		try {
			// Process based on sync type.
			$result = self::execute_sync( $sync_type, $entity_id, $args );

			if ( $result['success'] ) {
				// Store successful result.
				self::store_job_result( $job_id, 'success', $result );

				WCH_Logger::log(
					'info',
					'Sync job completed successfully',
					'queue',
					array(
						'job_id'    => $job_id,
						'sync_type' => $sync_type,
					)
				);
			} else {
				// Job failed, check if we should retry.
				self::handle_failure( $job_id, $sync_type, $entity_id, $retry_count, $result );
			}
		} catch ( Exception $e ) {
			// Exception occurred, handle failure.
			self::handle_failure(
				$job_id,
				$sync_type,
				$entity_id,
				$retry_count,
				array(
					'success' => false,
					'error'   => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Execute the sync operation.
	 *
	 * @param string $sync_type Sync type (product, order, etc.).
	 * @param int    $entity_id Entity ID.
	 * @param array  $args      Additional arguments.
	 * @return array Result array with 'success' key.
	 */
	private static function execute_sync( $sync_type, $entity_id, $args ) {
		switch ( $sync_type ) {
			case 'product':
				return self::sync_product( $entity_id, $args );

			case 'order':
				return self::sync_order( $entity_id, $args );

			case 'inventory':
				return self::sync_inventory( $entity_id, $args );

			default:
				return array(
					'success' => false,
					'error'   => 'Unknown sync type: ' . $sync_type,
				);
		}
	}

	/**
	 * Sync a product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $args       Additional arguments.
	 * @return array Result array.
	 */
	private static function sync_product( $product_id, $args ) {
		// Placeholder for actual product sync logic.
		// This will be implemented when the WhatsApp API integration is available.

		if ( ! $product_id ) {
			return array(
				'success' => false,
				'error'   => 'Invalid product ID',
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array(
				'success' => false,
				'error'   => 'Product not found',
			);
		}

		// Simulate sync operation.
		return array(
			'success'    => true,
			'product_id' => $product_id,
			'synced_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * Sync an order.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $args     Additional arguments.
	 * @return array Result array.
	 */
	private static function sync_order( $order_id, $args ) {
		// Placeholder for actual order sync logic.

		if ( ! $order_id ) {
			return array(
				'success' => false,
				'error'   => 'Invalid order ID',
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array(
				'success' => false,
				'error'   => 'Order not found',
			);
		}

		// Simulate sync operation.
		return array(
			'success'   => true,
			'order_id'  => $order_id,
			'synced_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Sync inventory.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $args       Additional arguments.
	 * @return array Result array.
	 */
	private static function sync_inventory( $product_id, $args ) {
		// Placeholder for actual inventory sync logic.

		if ( ! $product_id ) {
			return array(
				'success' => false,
				'error'   => 'Invalid product ID',
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return array(
				'success' => false,
				'error'   => 'Product not found',
			);
		}

		// Simulate inventory sync.
		return array(
			'success'    => true,
			'product_id' => $product_id,
			'stock'      => $product->get_stock_quantity(),
			'synced_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * Handle job failure with retry logic.
	 *
	 * @param string $job_id      Job ID.
	 * @param string $sync_type   Sync type.
	 * @param int    $entity_id   Entity ID.
	 * @param int    $retry_count Current retry count.
	 * @param array  $result      Failure result.
	 */
	private static function handle_failure( $job_id, $sync_type, $entity_id, $retry_count, $result ) {
		WCH_Logger::log(
			'warning',
			'Sync job failed',
			'queue',
			array(
				'job_id'      => $job_id,
				'sync_type'   => $sync_type,
				'retry_count' => $retry_count,
				'error'       => $result['error'] ?? 'Unknown error',
			)
		);

		// Check if we should retry.
		if ( $retry_count < self::MAX_RETRIES ) {
			// Schedule retry with exponential backoff.
			$delay = self::RETRY_DELAYS[ $retry_count ];

			WCH_Job_Dispatcher::dispatch(
				'wch_process_sync_job',
				array(
					'job_id'      => $job_id,
					'sync_type'   => $sync_type,
					'entity_id'   => $entity_id,
					'retry_count' => $retry_count + 1,
				),
				$delay
			);

			WCH_Logger::log(
				'info',
				'Sync job retry scheduled',
				'queue',
				array(
					'job_id'      => $job_id,
					'retry_count' => $retry_count + 1,
					'delay'       => $delay,
				)
			);
		} else {
			// Max retries reached, store failure.
			self::store_job_result( $job_id, 'failed', $result );

			WCH_Logger::log(
				'error',
				'Sync job failed after max retries',
				'queue',
				array(
					'job_id'    => $job_id,
					'sync_type' => $sync_type,
					'error'     => $result['error'] ?? 'Unknown error',
				)
			);
		}
	}

	/**
	 * Store job result in transient for 1 hour.
	 *
	 * @param string $job_id Job ID.
	 * @param string $status Job status (success, failed).
	 * @param array  $result Result data.
	 */
	private static function store_job_result( $job_id, $status, $result ) {
		$job_result = array(
			'job_id'     => $job_id,
			'status'     => $status,
			'result'     => $result,
			'timestamp'  => current_time( 'timestamp' ),
			'expires_at' => current_time( 'timestamp' ) + HOUR_IN_SECONDS,
		);

		set_transient( 'wch_job_result_' . $job_id, $job_result, HOUR_IN_SECONDS );
	}

	/**
	 * Get job result.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job result or null if not found.
	 */
	public static function get_job_result( $job_id ) {
		return get_transient( 'wch_job_result_' . $job_id );
	}
}
