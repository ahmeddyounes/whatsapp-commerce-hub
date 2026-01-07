<?php
/**
 * Job Dispatcher Class
 *
 * Dispatches and manages background jobs using Action Scheduler.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Job_Dispatcher
 */
class WCH_Job_Dispatcher {
	/**
	 * Action Scheduler group name.
	 *
	 * @var string
	 */
	const GROUP_NAME = 'wch';

	/**
	 * Dispatch a single job.
	 *
	 * @param string $hook          Action hook name.
	 * @param array  $args          Arguments to pass to the action.
	 * @param int    $delay_seconds Delay in seconds before execution (default: 0).
	 * @return int Action ID on success, 0 on failure.
	 */
	public static function dispatch( $hook, $args = array(), $delay_seconds = 0 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			WCH_Logger::log(
				'error',
				'Action Scheduler not available for job dispatch',
				'queue',
				array( 'hook' => $hook )
			);
			return 0;
		}

		try {
			$timestamp = time() + $delay_seconds;

			// Schedule the action.
			$action_id = as_schedule_single_action(
				$timestamp,
				$hook,
				$args,
				self::GROUP_NAME
			);

			WCH_Logger::log(
				'info',
				'Job dispatched successfully',
				'queue',
				array(
					'hook'      => $hook,
					'action_id' => $action_id,
					'delay'     => $delay_seconds,
				)
			);

			return $action_id;
		} catch ( Exception $e ) {
			WCH_Logger::log(
				'error',
				'Failed to dispatch job: ' . $e->getMessage(),
				'queue',
				array(
					'hook'  => $hook,
					'error' => $e->getMessage(),
				)
			);
			return 0;
		}
	}

	/**
	 * Dispatch jobs in batches.
	 *
	 * @param string $hook        Action hook name.
	 * @param array  $items_array Array of items to process.
	 * @param int    $batch_size  Number of items per batch (default: 50).
	 * @param int    $delay       Delay in seconds between batches (default: 0).
	 * @return array Array of action IDs.
	 */
	public static function dispatch_batch( $hook, $items_array, $batch_size = 50, $delay = 0 ) {
		if ( ! is_array( $items_array ) || empty( $items_array ) ) {
			WCH_Logger::log(
				'warning',
				'Empty or invalid items array for batch dispatch',
				'queue',
				array( 'hook' => $hook )
			);
			return array();
		}

		$action_ids = array();
		$batches    = array_chunk( $items_array, $batch_size );
		$batch_num  = 0;

		foreach ( $batches as $batch ) {
			// Calculate delay for this batch.
			$batch_delay = $delay * $batch_num;

			// Dispatch the batch.
			$action_id = self::dispatch(
				$hook,
				array(
					'batch'     => $batch,
					'batch_num' => $batch_num,
				),
				$batch_delay
			);

			if ( $action_id ) {
				$action_ids[] = $action_id;
			}

			++$batch_num;
		}

		WCH_Logger::log(
			'info',
			'Batch jobs dispatched',
			'queue',
			array(
				'hook'        => $hook,
				'total_items' => count( $items_array ),
				'batch_size'  => $batch_size,
				'num_batches' => count( $batches ),
			)
		);

		return $action_ids;
	}

	/**
	 * Cancel a scheduled job.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Arguments to match (optional).
	 * @return int Number of cancelled actions.
	 */
	public static function cancel( $hook, $args = array() ) {
		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return 0;
		}

		try {
			// Cancel all matching actions.
			as_unschedule_action( $hook, $args, self::GROUP_NAME );

			WCH_Logger::log(
				'info',
				'Job cancelled',
				'queue',
				array(
					'hook' => $hook,
					'args' => $args,
				)
			);

			return 1;
		} catch ( Exception $e ) {
			WCH_Logger::log(
				'error',
				'Failed to cancel job: ' . $e->getMessage(),
				'queue',
				array(
					'hook'  => $hook,
					'error' => $e->getMessage(),
				)
			);
			return 0;
		}
	}

	/**
	 * Check if a job is scheduled.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Arguments to match (optional).
	 * @return bool True if scheduled, false otherwise.
	 */
	public static function is_scheduled( $hook, $args = array() ) {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		$next_scheduled = as_next_scheduled_action( $hook, $args, self::GROUP_NAME );
		return false !== $next_scheduled;
	}

	/**
	 * Get count of pending jobs for a hook.
	 *
	 * @param string $hook Action hook name.
	 * @return int Number of pending jobs.
	 */
	public static function get_pending_count( $hook ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'status' => 'pending',
				'group'  => self::GROUP_NAME,
			),
			'ids'
		);

		return count( $actions );
	}

	/**
	 * Get all pending jobs grouped by hook.
	 *
	 * @return array Array of hook names with their pending counts.
	 */
	public static function get_all_pending_counts() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$queue  = WCH_Queue::getInstance();
		$hooks  = $queue->get_registered_hooks();
		$counts = array();

		foreach ( $hooks as $hook ) {
			$counts[ $hook ] = self::get_pending_count( $hook );
		}

		return $counts;
	}

	/**
	 * Get failed jobs.
	 *
	 * @param int $limit Maximum number of jobs to return (default: 50).
	 * @return array Array of failed job details.
	 */
	public static function get_failed_jobs( $limit = 50 ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$actions = as_get_scheduled_actions(
			array(
				'status'   => 'failed',
				'group'    => self::GROUP_NAME,
				'per_page' => $limit,
			)
		);

		$failed_jobs = array();

		foreach ( $actions as $action_id => $action ) {
			$failed_jobs[] = array(
				'id'        => $action_id,
				'hook'      => $action->get_hook(),
				'args'      => $action->get_args(),
				'scheduled' => $action->get_schedule()->get_date()->format( 'Y-m-d H:i:s' ),
			);
		}

		return $failed_jobs;
	}

	/**
	 * Retry a failed job.
	 *
	 * @param int $action_id Action Scheduler action ID.
	 * @return int New action ID on success, 0 on failure.
	 */
	public static function retry_failed_job( $action_id ) {
		if ( ! function_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		try {
			$store  = ActionScheduler_Store::instance();
			$action = $store->fetch_action( $action_id );

			if ( ! $action ) {
				return 0;
			}

			// Dispatch a new job with the same hook and args.
			return self::dispatch(
				$action->get_hook(),
				$action->get_args(),
				0
			);
		} catch ( Exception $e ) {
			WCH_Logger::log(
				'error',
				'Failed to retry job: ' . $e->getMessage(),
				'queue',
				array(
					'action_id' => $action_id,
					'error'     => $e->getMessage(),
				)
			);
			return 0;
		}
	}
}
