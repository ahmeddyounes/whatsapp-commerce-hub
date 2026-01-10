<?php
/**
 * Priority Queue Manager
 *
 * Manages priority-based job scheduling with Action Scheduler.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Queue;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PriorityQueue
 *
 * Provides priority-based job scheduling with rate limiting.
 */
class PriorityQueue {

	/**
	 * Priority levels (lower = higher priority).
	 */
	public const PRIORITY_CRITICAL    = 1;
	public const PRIORITY_URGENT      = 2;
	public const PRIORITY_NORMAL      = 3;
	public const PRIORITY_BULK        = 4;
	public const PRIORITY_MAINTENANCE = 5;

	/**
	 * Queue group prefix.
	 */
	private const GROUP_PREFIX = 'wch-';

	/**
	 * Priority to group mapping.
	 *
	 * @var array<int, string>
	 */
	private const PRIORITY_GROUPS = array(
		self::PRIORITY_CRITICAL    => 'critical',
		self::PRIORITY_URGENT      => 'urgent',
		self::PRIORITY_NORMAL      => 'normal',
		self::PRIORITY_BULK        => 'bulk',
		self::PRIORITY_MAINTENANCE => 'maintenance',
	);

	/**
	 * Rate limits per group (jobs per minute).
	 *
	 * @var array<string, int>
	 */
	private array $rate_limits = array(
		'critical'    => 1000, // No practical limit for critical.
		'urgent'      => 100,
		'normal'      => 50,
		'bulk'        => 20,
		'maintenance' => 10,
	);

	/**
	 * Dead letter queue instance.
	 *
	 * @var DeadLetterQueue|null
	 */
	private ?DeadLetterQueue $dead_letter_queue = null;

	/**
	 * Constructor.
	 *
	 * @param DeadLetterQueue|null $dead_letter_queue Dead letter queue for failed jobs.
	 */
	public function __construct( ?DeadLetterQueue $dead_letter_queue = null ) {
		$this->dead_letter_queue = $dead_letter_queue;
	}

	/**
	 * Schedule a job with priority.
	 *
	 * @param string $hook      The action hook name.
	 * @param array  $args      Arguments to pass to the hook.
	 * @param int    $priority  Priority level (1-5).
	 * @param int    $delay     Delay in seconds before execution.
	 *
	 * @return int|false Action ID or false on failure.
	 */
	public function schedule(
		string $hook,
		array $args = array(),
		int $priority = self::PRIORITY_NORMAL,
		int $delay = 0
	): int|false {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$group     = $this->getGroup( $priority );
		$timestamp = time() + $delay;

		// Wrap user args and metadata separately to avoid polluting user data.
		$job_payload = $this->wrapPayload( $args, $priority );

		return as_schedule_single_action(
			$timestamp,
			$hook,
			array( $job_payload ),
			$group
		);
	}

	/**
	 * Wrap user args with job metadata.
	 *
	 * Keeps metadata separate from user args to prevent pollution.
	 *
	 * @param array $args     User arguments.
	 * @param int   $priority Job priority.
	 * @param int   $attempt  Current attempt number.
	 *
	 * @return array Wrapped payload with separate metadata.
	 */
	private function wrapPayload( array $args, int $priority, int $attempt = 1 ): array {
		return array(
			'_wch_version' => 2, // Payload version for migration support.
			'_wch_meta'    => array(
				'priority'     => $priority,
				'scheduled_at' => time(),
				'attempt'      => $attempt,
			),
			'args'         => $args,
		);
	}

	/**
	 * Unwrap job payload to extract user args.
	 *
	 * Handles both v2 wrapped format and legacy v1 inline format.
	 *
	 * @param array $payload The job payload from Action Scheduler.
	 *
	 * @return array{args: array, meta: array} User args and metadata.
	 */
	public static function unwrapPayload( array $payload ): array {
		// v2 format: separate args and metadata.
		if ( isset( $payload['_wch_version'] ) && 2 === $payload['_wch_version'] ) {
			return array(
				'args' => $payload['args'] ?? array(),
				'meta' => $payload['_wch_meta'] ?? array(),
			);
		}

		// v1 format (legacy): metadata was inline with args.
		$meta = $payload['_wch_job_meta'] ?? array();
		$args = $payload;
		unset( $args['_wch_job_meta'] );

		return array(
			'args' => $args,
			'meta' => $meta,
		);
	}

	/**
	 * Schedule a recurring job with priority.
	 *
	 * @param string $hook      The action hook name.
	 * @param array  $args      Arguments to pass to the hook.
	 * @param int    $interval  Interval in seconds.
	 * @param int    $priority  Priority level (1-5).
	 *
	 * @return int|false Action ID or false on failure.
	 */
	public function scheduleRecurring(
		string $hook,
		array $args = array(),
		int $interval = 3600,
		int $priority = self::PRIORITY_NORMAL
	): int|false {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return false;
		}

		$group = $this->getGroup( $priority );

		// Use wrapped payload format.
		$job_payload = array(
			'_wch_version' => 2,
			'_wch_meta'    => array(
				'priority'     => $priority,
				'scheduled_at' => time(),
				'recurring'    => true,
				'interval'     => $interval,
			),
			'args'         => $args,
		);

		return as_schedule_recurring_action(
			time(),
			$interval,
			$hook,
			array( $job_payload ),
			$group
		);
	}

	/**
	 * Schedule a unique job (only if not already scheduled).
	 *
	 * Uses database locking to prevent TOCTOU race conditions where
	 * multiple concurrent requests could both schedule the same job.
	 *
	 * @param string $hook      The action hook name.
	 * @param array  $args      Arguments to pass to the hook.
	 * @param int    $priority  Priority level (1-5).
	 * @param int    $delay     Delay in seconds before execution.
	 *
	 * @return int|false Action ID or false if already scheduled.
	 */
	public function scheduleUnique(
		string $hook,
		array $args = array(),
		int $priority = self::PRIORITY_NORMAL,
		int $delay = 0
	): int|false {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		global $wpdb;

		// Create a unique lock key based on hook and args.
		$args_json = wp_json_encode( $args );
		$lock_key  = 'wch_unique_job_' . md5( $hook . $args_json );

		// Acquire advisory lock to prevent concurrent scheduling.
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key )
		);

		// GET_LOCK returns 1 on success, 0 if held by another session, NULL on error.
		if ( '1' !== (string) $lock_acquired ) {
			// Could not acquire lock, assume job is being scheduled by another process.
			return false;
		}

		try {
			// Check if already scheduled (inside lock) by searching for matching args.
			if ( $this->isPendingWithArgs( $hook, $args ) ) {
				return false;
			}

			// Schedule the job.
			return $this->schedule( $hook, $args, $priority, $delay );
		} finally {
			// Always release the lock.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		}
	}

	/**
	 * Check if a job with specific args is pending.
	 *
	 * Searches Action Scheduler directly for jobs with wrapped payload
	 * matching the given args.
	 *
	 * @param string $hook The action hook name.
	 * @param array  $args Arguments to match.
	 *
	 * @return bool True if a matching job is pending.
	 */
	private function isPendingWithArgs( string $hook, array $args ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		// Build the expected wrapped payload to search for.
		// We need to match the args portion within the wrapped payload.
		$args_json = wp_json_encode( $args );

		// Search for pending/in-progress jobs with matching hook that contain our args.
		// The args are stored as a JSON array where the first element is the wrapped payload.
		// We search for the args JSON substring within the stored args.
		$like_pattern = '%"args":' . $wpdb->esc_like( $args_json ) . '%';

		// Also check all WCH groups.
		$group_like = $wpdb->esc_like( self::GROUP_PREFIX ) . '%';

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 WHERE a.hook = %s
				 AND a.status IN ('pending', 'in-progress')
				 AND g.slug LIKE %s
				 AND a.args LIKE %s
				 LIMIT 1",
				$hook,
				$group_like,
				$like_pattern
			)
		);

		return null !== $exists;
	}

	/**
	 * Check if a job is pending.
	 *
	 * Note: Due to payload wrapping (args are stored in a metadata structure),
	 * this method can only check at the hook level, not for specific args.
	 * For args-level uniqueness, use scheduleUnique() which uses DB locking.
	 *
	 * @param string $hook The action hook name.
	 * @param array  $args Arguments (currently unused - see note above).
	 *
	 * @return bool True if any job with this hook is pending.
	 */
	public function isPending( string $hook, array $args = array() ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		// Check all priority groups.
		// Note: We pass null for args because the actual args are wrapped in
		// a job payload structure that we can't reconstruct here. This means
		// isPending() checks if ANY job with this hook is pending, not a
		// specific one with matching args.
		foreach ( self::PRIORITY_GROUPS as $group_suffix ) {
			$group  = self::GROUP_PREFIX . $group_suffix;
			$result = as_next_scheduled_action( $hook, null, $group );
			if ( false !== $result ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cancel a scheduled job.
	 *
	 * Note: Due to payload wrapping (args are stored in a metadata structure),
	 * this method cancels ALL jobs with the given hook, not just those with
	 * matching args. For fine-grained cancellation, use Action Scheduler directly.
	 *
	 * @param string $hook The action hook name.
	 * @param array  $args Arguments (currently unused - see note above).
	 *
	 * @return int Number of cancelled actions.
	 */
	public function cancel( string $hook, array $args = array() ): int {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return 0;
		}

		$cancelled = 0;

		// Note: We pass null for args because the actual args are wrapped in
		// a job payload structure. This cancels ALL jobs with this hook.
		foreach ( self::PRIORITY_GROUPS as $group_suffix ) {
			$group  = self::GROUP_PREFIX . $group_suffix;
			$result = as_unschedule_all_actions( $hook, null, $group );
			// as_unschedule_all_actions returns int|null, null means unlimited were unscheduled.
			if ( is_int( $result ) ) {
				$cancelled += $result;
			}
		}

		return $cancelled;
	}

	/**
	 * Cancel all jobs in a group.
	 *
	 * @param int $priority Priority level.
	 *
	 * @return void
	 */
	public function cancelByPriority( int $priority ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$group = $this->getGroup( $priority );
		as_unschedule_all_actions( '', array(), $group );
	}

	/**
	 * Get pending job count by priority.
	 *
	 * @param int|null $priority Priority level or null for all.
	 *
	 * @return int Job count.
	 */
	public function getPendingCount( ?int $priority = null ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		if ( null !== $priority ) {
			$group = $this->getGroup( $priority );
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} a
					 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
					 WHERE g.slug = %s AND a.status = 'pending'",
					$group
				)
			);
		}

		// Count all WCH groups.
		// Use esc_like() to escape special LIKE characters (_, %) in prefix.
		$like = $wpdb->esc_like( self::GROUP_PREFIX ) . '%';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} a
				 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 WHERE g.slug LIKE %s AND a.status = 'pending'",
				$like
			)
		);
	}

	/**
	 * Retry a failed job.
	 *
	 * Handles both v1 (legacy) and v2 (wrapped) payload formats.
	 * Uses atomic locking to prevent race conditions where multiple
	 * concurrent retry calls could result in duplicate DLQ entries
	 * or duplicate retry jobs.
	 *
	 * @param string $hook       The action hook name.
	 * @param array  $payload    Original payload (may be v1 or v2 format).
	 * @param int    $attempt    Current attempt number.
	 * @param int    $max_retries Maximum retry attempts.
	 *
	 * @return bool True if rescheduled, false if max retries reached or already processed.
	 */
	public function retry(
		string $hook,
		array $payload,
		int $attempt = 1,
		int $max_retries = 3
	): bool {
		global $wpdb;

		// Unwrap to get user args and metadata.
		$unwrapped = self::unwrapPayload( $payload );
		$user_args = $unwrapped['args'];
		$meta      = $unwrapped['meta'];

		// SECURITY: Atomic lock to prevent race conditions.
		// Create unique key based on hook, args, and attempt to prevent duplicate retries.
		$retry_key = 'wch_retry_' . md5( $hook . wp_json_encode( $user_args ) . '_attempt_' . $attempt );

		// Acquire advisory lock (5 second timeout).
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $retry_key )
		);

		// GET_LOCK returns 1 on success, 0 if held by another session, NULL on error.
		if ( '1' !== (string) $lock_acquired ) {
			// Another process is handling this retry - let it proceed.
			do_action(
				'wch_log_info',
				'Retry skipped - another process is handling',
				array(
					'hook'    => $hook,
					'attempt' => $attempt,
				)
			);
			return false;
		}

		try {
			// Check idempotency - has this retry already been processed?
			$idempotency_table = $wpdb->prefix . 'wch_webhook_idempotency';
			$idempotency_hash  = hash( 'sha256', $retry_key );
			$now               = current_time( 'mysql' );

			// Atomic claim using INSERT IGNORE.
			$claim_result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$idempotency_table} (message_id, scope, processed_at, expires_at)
					 VALUES (%s, %s, %s, DATE_ADD(%s, INTERVAL 1 HOUR))",
					$idempotency_hash,
					'queue_retry',
					$now,
					$now
				)
			);

			// If 0 rows affected, this retry was already processed.
			if ( 0 === $claim_result ) {
				do_action(
					'wch_log_info',
					'Retry already processed by another request',
					array(
						'hook'    => $hook,
						'attempt' => $attempt,
					)
				);
				return false;
			}

			if ( $attempt >= $max_retries ) {
				// Move to dead letter queue with original user args.
				if ( $this->dead_letter_queue ) {
					// Reconstruct for DLQ (include meta for debugging).
					$dlq_args                  = $user_args;
					$dlq_args['_wch_job_meta'] = $meta;
					$dlq_result                = $this->dead_letter_queue->push( $hook, $dlq_args, DeadLetterQueue::REASON_MAX_RETRIES );

					// If DLQ push fails, log critical error - job data may be lost.
					if ( false === $dlq_result ) {
						do_action(
							'wch_log_critical',
							'Failed to push failed job to DLQ - job data may be lost',
							array(
								'hook'    => $hook,
								'attempt' => $attempt,
								'meta'    => $meta,
							)
						);
					}
				} else {
					// No DLQ configured - log that job is being dropped.
					do_action(
						'wch_log_warning',
						'Job exceeded max retries with no DLQ configured',
						array(
							'hook'    => $hook,
							'attempt' => $attempt,
						)
					);
				}
				return false;
			}

			// Exponential backoff: 30s, 90s, 270s.
			$delay = 30 * pow( 3, $attempt );

			// Get priority from meta.
			$priority = $meta['priority'] ?? self::PRIORITY_NORMAL;

			// Schedule with incremented attempt using wrapped format.
			$job_payload = array(
				'_wch_version' => 2,
				'_wch_meta'    => array(
					'priority'     => $priority,
					'scheduled_at' => $meta['scheduled_at'] ?? time(),
					'attempt'      => $attempt + 1,
					'last_retry'   => time(),
				),
				'args'         => $user_args,
			);

			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return false;
			}

			$group = $this->getGroup( $priority );
			as_schedule_single_action(
				time() + $delay,
				$hook,
				array( $job_payload ),
				$group
			);

			return true;
		} finally {
			// Always release the lock.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $retry_key ) );
		}
	}

	/**
	 * Get the Action Scheduler group name for a priority.
	 *
	 * @param int $priority Priority level.
	 *
	 * @return string Group name.
	 */
	private function getGroup( int $priority ): string {
		$priority = max( 1, min( 5, $priority ) );
		$suffix   = self::PRIORITY_GROUPS[ $priority ] ?? 'normal';
		return self::GROUP_PREFIX . $suffix;
	}

	/**
	 * Check if rate limit allows execution (atomic version).
	 *
	 * Uses database-backed atomic conditional increment to prevent
	 * TOCTOU race conditions. The increment only succeeds if the
	 * count is below the limit.
	 *
	 * @param int $priority Priority level.
	 *
	 * @return bool True if within rate limit.
	 */
	public function checkRateLimit( int $priority ): bool {
		global $wpdb;

		$group = self::PRIORITY_GROUPS[ $priority ] ?? 'normal';
		$limit = $this->rate_limits[ $group ] ?? 50;
		$table = $wpdb->prefix . 'wch_rate_limits';

		// Current minute window identifier.
		$window          = gmdate( 'Y-m-d H:i' );
		$identifier      = 'queue_' . $group;
		$identifier_hash = hash( 'sha256', $identifier );

		// Use a fully atomic approach: conditionally increment only if under limit.
		// Step 1: Ensure record exists (atomic upsert with count=0).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (identifier_hash, limit_type, request_count, window_start)
				VALUES (%s, %s, 0, %s)
				ON DUPLICATE KEY UPDATE
				window_start = IF(window_start = VALUES(window_start), window_start, VALUES(window_start)),
				request_count = IF(window_start = VALUES(window_start), request_count, 0)",
				$identifier_hash,
				$group,
				$window
			)
		);

		// Step 2: Atomically increment ONLY if under limit.
		// This single query checks AND increments in one atomic operation.
		$rows_affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET request_count = request_count + 1
				WHERE identifier_hash = %s
				AND limit_type = %s
				AND window_start = %s
				AND request_count < %d",
				$identifier_hash,
				$group,
				$window,
				$limit
			)
		);

		// If rows_affected is false, there was a DB error - allow request to proceed.
		if ( false === $rows_affected ) {
			return true;
		}

		// If no rows were updated, we're at or over the limit.
		// If 1 row was updated, we successfully claimed a slot.
		return $rows_affected > 0;
	}

	/**
	 * Create the rate limits table.
	 *
	 * @return void
	 */
	public static function createRateLimitsTable(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'wch_rate_limits';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			identifier_hash VARCHAR(64) NOT NULL,
			limit_type VARCHAR(32) NOT NULL,
			request_count INT UNSIGNED DEFAULT 0,
			window_start VARCHAR(16) NOT NULL,
			PRIMARY KEY (identifier_hash, limit_type, window_start),
			KEY idx_window (window_start)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Clean up old rate limit entries.
	 *
	 * Should be called periodically (e.g., via cron) to prevent table bloat.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function cleanupRateLimits(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wch_rate_limits';

		// Delete entries older than 5 minutes.
		$cutoff = gmdate( 'Y-m-d H:i', strtotime( '-5 minutes' ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE window_start < %s",
				$cutoff
			)
		);
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array<string, mixed> Queue statistics.
	 */
	public function getStats(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';
		$stats = array();

		foreach ( self::PRIORITY_GROUPS as $priority => $group_suffix ) {
			$group = self::GROUP_PREFIX . $group_suffix;

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) as pending,
						SUM(CASE WHEN a.status = 'in-progress' THEN 1 ELSE 0 END) as running,
						SUM(CASE WHEN a.status = 'complete' THEN 1 ELSE 0 END) as completed,
						SUM(CASE WHEN a.status = 'failed' THEN 1 ELSE 0 END) as failed
					 FROM {$table} a
					 INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
					 WHERE g.slug = %s",
					$group
				),
				ARRAY_A
			);

			$stats[ $group_suffix ] = array(
				'priority'  => $priority,
				'pending'   => (int) ( $row['pending'] ?? 0 ),
				'running'   => (int) ( $row['running'] ?? 0 ),
				'completed' => (int) ( $row['completed'] ?? 0 ),
				'failed'    => (int) ( $row['failed'] ?? 0 ),
			);
		}

		return $stats;
	}
}
