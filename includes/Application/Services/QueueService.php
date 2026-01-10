<?php
/**
 * Queue Service Implementation
 *
 * Service wrapper for async job queue operations using Action Scheduler.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\QueueServiceInterface;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Queue\DeadLetterQueue;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class QueueService
 *
 * Queue service implementation wrapping PriorityQueue and Action Scheduler.
 */
class QueueService implements QueueServiceInterface {

	/**
	 * Priority queue instance.
	 *
	 * @var PriorityQueue
	 */
	private PriorityQueue $priority_queue;

	/**
	 * Dead letter queue instance.
	 *
	 * @var DeadLetterQueue|null
	 */
	private ?DeadLetterQueue $dead_letter_queue;

	/**
	 * Registered handlers.
	 *
	 * @var array<string, callable>
	 */
	private array $handlers = array();

	/**
	 * Priority string to PriorityQueue constant mapping.
	 *
	 * @var array<string, int>
	 */
	private const PRIORITY_MAP = array(
		self::PRIORITY_CRITICAL    => PriorityQueue::PRIORITY_CRITICAL,
		self::PRIORITY_URGENT      => PriorityQueue::PRIORITY_URGENT,
		self::PRIORITY_NORMAL      => PriorityQueue::PRIORITY_NORMAL,
		self::PRIORITY_BULK        => PriorityQueue::PRIORITY_BULK,
		self::PRIORITY_MAINTENANCE => PriorityQueue::PRIORITY_MAINTENANCE,
	);

	/**
	 * Constructor.
	 *
	 * @param PriorityQueue|null    $priority_queue    Priority queue instance.
	 * @param DeadLetterQueue|null  $dead_letter_queue Dead letter queue instance.
	 */
	public function __construct(
		?PriorityQueue $priority_queue = null,
		?DeadLetterQueue $dead_letter_queue = null
	) {
		$this->dead_letter_queue = $dead_letter_queue;
		$this->priority_queue    = $priority_queue ?? new PriorityQueue( $dead_letter_queue );
	}

	/**
	 * SECURITY: Check if current context is authorized for job management.
	 *
	 * @return bool True if authorized.
	 */
	private function isAuthorizedForJobManagement(): bool {
		// Allow CLI context (WP-CLI, cron jobs).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		// Allow cron context.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}

		// Allow Action Scheduler callbacks.
		if ( did_action( 'action_scheduler_run_queue' ) ) {
			return true;
		}

		// Otherwise, require admin capability.
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * SECURITY: Log an unauthorized access attempt.
	 *
	 * @param string $operation The operation that was attempted.
	 * @param array  $context   Additional context.
	 * @return void
	 */
	private function logUnauthorizedAttempt( string $operation, array $context = array() ): void {
		do_action(
			'wch_log_warning',
			sprintf( 'Unauthorized QueueService %s attempt blocked', $operation ),
			array_merge(
				array(
					'user_id' => get_current_user_id(),
					'ip'      => isset( $_SERVER['REMOTE_ADDR'] )
						? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
						: 'unknown',
				),
				$context
			)
		);
	}

	/**
	 * Dispatch a job to the queue.
	 *
	 * @param string $hook     Action hook name.
	 * @param array  $args     Job arguments.
	 * @param string $priority Priority group.
	 * @return int|false Action ID or false on failure.
	 */
	public function dispatch( string $hook, array $args = array(), string $priority = self::PRIORITY_NORMAL ) {
		$priority_int = $this->mapPriority( $priority );
		return $this->priority_queue->schedule( $hook, $args, $priority_int, 0 );
	}

	/**
	 * Schedule a job for later execution.
	 *
	 * @param string $hook      Action hook name.
	 * @param array  $args      Job arguments.
	 * @param int    $timestamp Unix timestamp for execution.
	 * @param string $priority  Priority group.
	 * @return int|false Action ID or false on failure.
	 */
	public function schedule( string $hook, array $args, int $timestamp, string $priority = self::PRIORITY_NORMAL ) {
		$priority_int = $this->mapPriority( $priority );
		$delay        = max( 0, $timestamp - time() );
		return $this->priority_queue->schedule( $hook, $args, $priority_int, $delay );
	}

	/**
	 * Schedule a recurring job.
	 *
	 * @param string $hook     Action hook name.
	 * @param array  $args     Job arguments.
	 * @param int    $interval Interval in seconds.
	 * @param string $priority Priority group.
	 * @return int|false Action ID or false on failure.
	 */
	public function scheduleRecurring( string $hook, array $args, int $interval, string $priority = self::PRIORITY_NORMAL ) {
		$priority_int = $this->mapPriority( $priority );
		return $this->priority_queue->scheduleRecurring( $hook, $args, $interval, $priority_int );
	}

	/**
	 * Cancel a scheduled job.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Job arguments.
	 * @return bool Success status.
	 */
	public function cancel( string $hook, array $args = array() ): bool {
		$cancelled = $this->priority_queue->cancel( $hook, $args );
		return $cancelled > 0;
	}

	/**
	 * Cancel all jobs for a hook.
	 *
	 * @param string $hook Action hook name.
	 * @return int Number of jobs cancelled.
	 */
	public function cancelAll( string $hook ): int {
		return $this->priority_queue->cancel( $hook );
	}

	/**
	 * Check if a job is scheduled.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Job arguments.
	 * @return bool True if scheduled.
	 */
	public function isScheduled( string $hook, array $args = array() ): bool {
		return $this->priority_queue->isPending( $hook, $args );
	}

	/**
	 * Get next scheduled time for a job.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Job arguments.
	 * @return int|null Unix timestamp or null if not scheduled.
	 */
	public function getNextScheduled( string $hook, array $args = array() ): ?int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}

		// Check all priority groups.
		$groups = array(
			'wch-critical',
			'wch-urgent',
			'wch-normal',
			'wch-bulk',
			'wch-maintenance',
		);

		$earliest = null;

		foreach ( $groups as $group ) {
			$timestamp = as_next_scheduled_action( $hook, null, $group );
			if ( false !== $timestamp && ( null === $earliest || $timestamp < $earliest ) ) {
				$earliest = $timestamp;
			}
		}

		return $earliest;
	}

	/**
	 * Get pending jobs for a hook.
	 *
	 * @param string $hook  Action hook name.
	 * @param int    $limit Maximum jobs to return.
	 * @return array Array of job data.
	 */
	public function getPendingJobs( string $hook, int $limit = 100 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$limit = max( 1, min( 1000, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.action_id, a.hook, a.args, a.scheduled_date_gmt, a.status, g.slug as group_name
				 FROM {$table} a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 WHERE a.hook = %s
				 AND a.status = 'pending'
				 AND g.slug LIKE %s
				 ORDER BY a.scheduled_date_gmt ASC
				 LIMIT %d",
				$hook,
				'wch-%',
				$limit
			),
			ARRAY_A
		);

		$jobs = array();
		foreach ( $rows as $row ) {
			$payload   = json_decode( $row['args'], true );
			$unwrapped = PriorityQueue::unwrapPayload( $payload[0] ?? $payload );

			$jobs[] = array(
				'id'           => (int) $row['action_id'],
				'hook'         => $row['hook'],
				'args'         => $unwrapped['args'],
				'scheduled_at' => strtotime( $row['scheduled_date_gmt'] . ' UTC' ),
				'status'       => $row['status'],
				'group'        => $row['group_name'],
				'meta'         => $unwrapped['meta'],
			);
		}

		return $jobs;
	}

	/**
	 * Get failed jobs.
	 *
	 * @param string|null $hook  Filter by hook name.
	 * @param int         $limit Maximum jobs to return.
	 * @return array Array of failed job data.
	 */
	public function getFailedJobs( ?string $hook = null, int $limit = 100 ): array {
		// First check dead letter queue.
		$dlq_jobs = array();
		if ( $this->dead_letter_queue ) {
			$dlq_jobs = $this->dead_letter_queue->getPending( $limit );
			if ( $hook ) {
				$dlq_jobs = array_filter(
					$dlq_jobs,
					fn( $job ) => $job['hook'] === $hook
				);
			}
		}

		// Also check Action Scheduler failed status.
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$limit = max( 1, min( 1000, $limit ) );

		$where = "a.status = 'failed' AND g.slug LIKE 'wch-%'";
		$params = array();

		if ( $hook ) {
			$where .= ' AND a.hook = %s';
			$params[] = $hook;
		}

		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.action_id, a.hook, a.args, a.scheduled_date_gmt, a.last_attempt_gmt, g.slug as group_name
				 FROM {$table} a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 WHERE {$where}
				 ORDER BY a.last_attempt_gmt DESC
				 LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		$as_jobs = array();
		foreach ( $rows as $row ) {
			$payload   = json_decode( $row['args'], true );
			$unwrapped = PriorityQueue::unwrapPayload( $payload[0] ?? $payload );

			$as_jobs[] = array(
				'id'             => (int) $row['action_id'],
				'hook'           => $row['hook'],
				'args'           => $unwrapped['args'],
				'scheduled_at'   => strtotime( $row['scheduled_date_gmt'] . ' UTC' ),
				'last_attempt'   => strtotime( $row['last_attempt_gmt'] . ' UTC' ),
				'group'          => $row['group_name'],
				'source'         => 'action_scheduler',
				'failure_count'  => $unwrapped['meta']['attempt'] ?? 1,
			);
		}

		// Merge and return.
		return array_merge( $dlq_jobs, $as_jobs );
	}

	/**
	 * Retry a failed job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool Success status.
	 */
	public function retryJob( int $job_id ): bool {
		// SECURITY: Verify authorization before retrying jobs.
		if ( ! $this->isAuthorizedForJobManagement() ) {
			$this->logUnauthorizedAttempt( 'retryJob', array( 'job_id' => $job_id ) );
			return false;
		}

		// Try DLQ first.
		if ( $this->dead_letter_queue ) {
			$result = $this->dead_letter_queue->replay( $job_id );
			if ( $result ) {
				return true;
			}
		}

		// Try Action Scheduler.
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT hook, args FROM {$table} WHERE action_id = %d AND status = 'failed'",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return false;
		}

		$payload   = json_decode( $row['args'], true );
		$unwrapped = PriorityQueue::unwrapPayload( $payload[0] ?? $payload );
		$priority  = $unwrapped['meta']['priority'] ?? PriorityQueue::PRIORITY_NORMAL;

		// Re-schedule.
		$result = $this->priority_queue->schedule(
			$row['hook'],
			$unwrapped['args'],
			$priority,
			0
		);

		if ( $result ) {
			// Delete the failed entry.
			$wpdb->delete( $table, array( 'action_id' => $job_id ), array( '%d' ) );
		}

		return (bool) $result;
	}

	/**
	 * Dismiss a failed job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool Success status.
	 */
	public function dismissJob( int $job_id ): bool {
		// SECURITY: Verify authorization before dismissing jobs.
		if ( ! $this->isAuthorizedForJobManagement() ) {
			$this->logUnauthorizedAttempt( 'dismissJob', array( 'job_id' => $job_id ) );
			return false;
		}

		// Try DLQ first.
		if ( $this->dead_letter_queue ) {
			$result = $this->dead_letter_queue->dismiss( $job_id );
			if ( $result ) {
				return true;
			}
		}

		// Delete from Action Scheduler.
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$deleted = $wpdb->delete(
			$table,
			array(
				'action_id' => $job_id,
				'status'    => 'failed',
			),
			array( '%d', '%s' )
		);

		return $deleted > 0;
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array{pending: int, running: int, completed: int, failed: int}
	 */
	public function getStats(): array {
		$priority_stats = $this->priority_queue->getStats();

		$totals = array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
		);

		foreach ( $priority_stats as $group_stats ) {
			$totals['pending']   += $group_stats['pending'] ?? 0;
			$totals['running']   += $group_stats['running'] ?? 0;
			$totals['completed'] += $group_stats['completed'] ?? 0;
			$totals['failed']    += $group_stats['failed'] ?? 0;
		}

		// Add DLQ count to failed.
		if ( $this->dead_letter_queue ) {
			$dlq_count = $this->dead_letter_queue->getCount();
			$totals['failed'] += $dlq_count;
		}

		return $totals;
	}

	/**
	 * Get stats by priority group.
	 *
	 * @return array<string, array{pending: int, running: int}>
	 */
	public function getStatsByPriority(): array {
		$priority_stats = $this->priority_queue->getStats();

		$result = array();
		$priority_to_group = array(
			'critical'    => self::PRIORITY_CRITICAL,
			'urgent'      => self::PRIORITY_URGENT,
			'normal'      => self::PRIORITY_NORMAL,
			'bulk'        => self::PRIORITY_BULK,
			'maintenance' => self::PRIORITY_MAINTENANCE,
		);

		foreach ( $priority_stats as $name => $stats ) {
			$group_name = $priority_to_group[ $name ] ?? $name;
			$result[ $group_name ] = array(
				'pending' => $stats['pending'] ?? 0,
				'running' => $stats['running'] ?? 0,
			);
		}

		return $result;
	}

	/**
	 * Clear completed jobs older than given age.
	 *
	 * @param int $age_seconds Age threshold in seconds.
	 * @return int Number of jobs cleared.
	 */
	public function clearCompleted( int $age_seconds = 86400 ): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'actionscheduler_actions';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $age_seconds );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE a FROM {$table} a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 WHERE a.status = 'complete'
				 AND a.last_attempt_gmt < %s
				 AND g.slug LIKE %s",
				$cutoff,
				'wch-%'
			)
		);
	}

	/**
	 * Clear failed jobs older than given age.
	 *
	 * @param int $age_seconds Age threshold in seconds.
	 * @return int Number of jobs cleared.
	 */
	public function clearFailed( int $age_seconds = 604800 ): int {
		// SECURITY: Verify authorization before clearing failed jobs.
		if ( ! $this->isAuthorizedForJobManagement() ) {
			$this->logUnauthorizedAttempt( 'clearFailed', array( 'age_seconds' => $age_seconds ) );
			return 0;
		}

		global $wpdb;

		$cleared = 0;

		// Clear from Action Scheduler.
		$table  = $wpdb->prefix . 'actionscheduler_actions';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $age_seconds );

		$cleared += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE a FROM {$table} a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 WHERE a.status = 'failed'
				 AND a.last_attempt_gmt < %s
				 AND g.slug LIKE %s",
				$cutoff,
				'wch-%'
			)
		);

		// Clear from DLQ.
		if ( $this->dead_letter_queue ) {
			$cleared += $this->dead_letter_queue->cleanup( $age_seconds );
		}

		return $cleared;
	}

	/**
	 * Register action hook handler.
	 *
	 * @param string   $hook     Action hook name.
	 * @param callable $callback Handler callback.
	 */
	public function registerHandler( string $hook, callable $callback ): void {
		$this->handlers[ $hook ] = $callback;

		// Register with WordPress.
		add_action(
			$hook,
			function( $payload ) use ( $hook, $callback ) {
				$unwrapped = PriorityQueue::unwrapPayload( $payload );
				$args      = $unwrapped['args'];

				try {
					call_user_func( $callback, $args );
				} catch ( \Exception $e ) {
					// Log and optionally retry.
					do_action( 'wch_log_error', "Job handler failed for {$hook}", array(
						'error' => $e->getMessage(),
						'args'  => $args,
					) );

					// Re-throw to let Action Scheduler handle retry.
					throw $e;
				}
			}
		);
	}

	/**
	 * Check if queue is healthy.
	 *
	 * @return array{healthy: bool, issues: array}
	 */
	public function healthCheck(): array {
		$issues = array();

		// Check Action Scheduler is available.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$issues[] = 'Action Scheduler is not available';
		}

		// Check for stuck jobs (running for > 30 minutes).
		global $wpdb;
		$table  = $wpdb->prefix . 'actionscheduler_actions';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 1800 );

		$stuck = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 WHERE a.status = 'in-progress'
				 AND a.last_attempt_gmt < %s
				 AND g.slug LIKE %s",
				$cutoff,
				'wch-%'
			)
		);

		if ( $stuck > 0 ) {
			$issues[] = sprintf( '%d jobs appear to be stuck', $stuck );
		}

		// Check DLQ size.
		if ( $this->dead_letter_queue ) {
			$dlq_count = $this->dead_letter_queue->getCount();
			if ( $dlq_count > 100 ) {
				$issues[] = sprintf( '%d jobs in dead letter queue', $dlq_count );
			}
		}

		// Check failed job rate.
		$stats = $this->getStats();
		$total = $stats['pending'] + $stats['running'] + $stats['completed'] + $stats['failed'];
		if ( $total > 0 && $stats['failed'] > 0 ) {
			$failure_rate = ( $stats['failed'] / $total ) * 100;
			if ( $failure_rate > 10 ) {
				$issues[] = sprintf( 'High failure rate: %.1f%%', $failure_rate );
			}
		}

		return array(
			'healthy' => empty( $issues ),
			'issues'  => $issues,
		);
	}

	/**
	 * Map priority string to PriorityQueue constant.
	 *
	 * @param string $priority Priority string.
	 * @return int Priority constant.
	 */
	private function mapPriority( string $priority ): int {
		return self::PRIORITY_MAP[ $priority ] ?? PriorityQueue::PRIORITY_NORMAL;
	}
}
