<?php
/**
 * Dead Letter Queue
 *
 * Stores failed jobs for later analysis and potential replay.
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
 * Class DeadLetterQueue
 *
 * Persists failed jobs with metadata for debugging and replay.
 */
class DeadLetterQueue {

	/**
	 * Database table name (without prefix).
	 */
	private const TABLE_NAME = 'wch_dead_letter_queue';

	/**
	 * SECURITY: Check if current context is authorized for DLQ operations.
	 *
	 * Allows operations when:
	 * - Running from CLI (WP-CLI, cron)
	 * - Running from Action Scheduler callback
	 * - User has manage_woocommerce capability
	 *
	 * @return bool True if authorized.
	 */
	private function isAuthorized(): bool {
		// Allow CLI context (WP-CLI, cron jobs).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		// Allow cron context.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}

		// Allow Action Scheduler callbacks (internal job chaining).
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
			sprintf( 'Unauthorized DLQ %s attempt blocked', $operation ),
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
	 * Failure reasons.
	 */
	public const REASON_MAX_RETRIES  = 'max_retries_exceeded';
	public const REASON_TIMEOUT      = 'timeout';
	public const REASON_EXCEPTION    = 'exception';
	public const REASON_VALIDATION   = 'validation_failed';
	public const REASON_CIRCUIT_OPEN = 'circuit_breaker_open';

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $wpdb WordPress database instance.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Get the full table name.
	 *
	 * @return string Full table name with prefix.
	 */
	private function getTableName(): string {
		return $this->wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Push a failed job to the dead letter queue.
	 *
	 * @param string      $hook       The action hook name.
	 * @param array       $args       Job arguments.
	 * @param string      $reason     Failure reason.
	 * @param string|null $error_msg  Error message if available.
	 * @param array       $metadata   Additional metadata.
	 *
	 * @return int|false Inserted ID or false on failure.
	 */
	public function push(
		string $hook,
		array $args,
		string $reason,
		?string $error_msg = null,
		array $metadata = array()
	): int|false {
		$table = $this->getTableName();

		// Extract job meta if present (check both v1 and v2 formats).
		$job_meta = $args['_wch_job_meta'] ?? $args['_wch_meta'] ?? array();

		// Encode JSON with strict error handling - fail instead of losing data.
		$args_json = wp_json_encode( $args );
		if ( false === $args_json ) {
			do_action(
				'wch_log_error',
				'Failed to encode DLQ args for hook: ' . $hook,
				array(
					'args_type'  => gettype( $args ),
					'last_error' => json_last_error_msg(),
				)
			);
			return false; // Don't insert invalid data - fail explicitly.
		}

		$metadata_merged = array_merge(
			$metadata,
			array(
				'original_scheduled_at' => $job_meta['scheduled_at'] ?? null,
				'last_retry'            => $job_meta['last_retry'] ?? null,
			)
		);
		$metadata_json   = wp_json_encode( $metadata_merged );
		if ( false === $metadata_json ) {
			do_action(
				'wch_log_error',
				'Failed to encode DLQ metadata for hook: ' . $hook,
				array(
					'metadata_type' => gettype( $metadata_merged ),
					'last_error'    => json_last_error_msg(),
				)
			);
			return false; // Don't insert invalid data - fail explicitly.
		}

		$data = array(
			'hook'          => $hook,
			'args'          => $args_json,
			'reason'        => $reason,
			'error_message' => $error_msg,
			'attempts'      => $job_meta['attempt'] ?? 1,
			'priority'      => $job_meta['priority'] ?? 3,
			'metadata'      => $metadata_json,
			'created_at'    => current_time( 'mysql', true ),
			'status'        => 'pending',
		);

		$result = $this->wpdb->insert( $table, $data );

		if ( false === $result ) {
			do_action( 'wch_log_error', 'Failed to insert into dead letter queue: ' . $this->wpdb->last_error );
			return false;
		}

		do_action( 'wch_dead_letter_queued', $this->wpdb->insert_id, $hook, $reason );

		return $this->wpdb->insert_id;
	}

	/**
	 * Get pending dead letter entries.
	 *
	 * @param int    $limit  Number of entries to retrieve.
	 * @param int    $offset Offset for pagination.
	 * @param string $reason Optional filter by reason.
	 *
	 * @return array<object> Dead letter entries.
	 */
	public function getPending( int $limit = 50, int $offset = 0, string $reason = '' ): array {
		$table = $this->getTableName();

		$where = "status = 'pending'";
		if ( $reason ) {
			$where .= $this->wpdb->prepare( ' AND reason = %s', $reason );
		}

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return array_map( fn( $row ) => $this->hydrateEntry( $row ), $results );
	}

	/**
	 * Replay a dead letter entry.
	 *
	 * @param int $id           Dead letter entry ID.
	 * @param int $delay        Delay in seconds before execution.
	 * @param int $new_priority New priority level (optional).
	 *
	 * @return bool True on success.
	 * @throws \RuntimeException If entry data is corrupted.
	 */
	public function replay( int $id, int $delay = 0, ?int $new_priority = null ): bool {
		// SECURITY: Verify authorization before replaying jobs.
		if ( ! $this->isAuthorized() ) {
			$this->logUnauthorizedAttempt( 'replay', array( 'dlq_id' => $id ) );
			return false;
		}

		$table = $this->getTableName();

		$entry = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		if ( ! $entry || 'pending' !== $entry->status ) {
			return false;
		}

		// Decode args with strict error checking - don't mask data corruption.
		$args       = json_decode( $entry->args, true );
		$json_error = json_last_error();

		if ( JSON_ERROR_NONE !== $json_error ) {
			$error_msg = json_last_error_msg();
			do_action(
				'wch_log_error',
				'DLQ replay failed: corrupted args JSON',
				array(
					'dlq_id'     => $id,
					'hook'       => $entry->hook,
					'json_error' => $error_msg,
					'raw_length' => strlen( $entry->args ?? '' ),
				)
			);

			// Mark as dismissed with corruption reason rather than silently failing.
			$this->dismiss( $id, 'Data corruption: ' . $error_msg );

			throw new \RuntimeException(
				"Cannot replay DLQ entry {$id}: args JSON is corrupted ({$error_msg})"
			);
		}

		if ( ! is_array( $args ) ) {
			do_action(
				'wch_log_error',
				'DLQ replay failed: args is not an array',
				array(
					'dlq_id'    => $id,
					'hook'      => $entry->hook,
					'args_type' => gettype( $args ),
				)
			);

			$this->dismiss( $id, 'Data corruption: args decoded to ' . gettype( $args ) );

			throw new \RuntimeException(
				"Cannot replay DLQ entry {$id}: args decoded to " . gettype( $args ) . ' instead of array'
			);
		}

		$priority = $new_priority ?? $entry->priority;

		// Ensure _wch_job_meta exists and reset attempt counter for replay.
		if ( ! isset( $args['_wch_job_meta'] ) || ! is_array( $args['_wch_job_meta'] ) ) {
			$args['_wch_job_meta'] = array();
		}
		$args['_wch_job_meta']['attempt']           = 1;
		$args['_wch_job_meta']['replayed_from_dlq'] = $id;
		$args['_wch_job_meta']['replayed_at']       = time();

		// Schedule the job again.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$group = 'wch-' . match ( $priority ) {
				1 => 'critical',
				2 => 'urgent',
				4 => 'bulk',
				5 => 'maintenance',
				default => 'normal',
			};

			$action_id = as_schedule_single_action(
				time() + $delay,
				$entry->hook,
				array( $args ),
				$group
			);

			if ( $action_id ) {
				// Decode existing metadata with error handling.
				$existing_metadata = array();
				if ( ! empty( $entry->metadata ) ) {
					$decoded = json_decode( $entry->metadata, true );
					if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
						$existing_metadata = $decoded;
					}
					// Non-critical: log but continue if metadata is corrupted.
					// The replay itself is more important than preserving metadata.
					elseif ( JSON_ERROR_NONE !== json_last_error() ) {
						do_action(
							'wch_log_warning',
							'DLQ metadata corrupted during replay',
							array(
								'dlq_id'     => $id,
								'json_error' => json_last_error_msg(),
							)
						);
					}
				}

				// Mark as replayed.
				$this->wpdb->update(
					$table,
					array(
						'status'      => 'replayed',
						'replayed_at' => current_time( 'mysql', true ),
						'metadata'    => wp_json_encode(
							array_merge(
								$existing_metadata,
								array( 'replay_action_id' => $action_id )
							)
						),
					),
					array( 'id' => $id )
				);

				do_action( 'wch_dead_letter_replayed', $id, $action_id );
				return true;
			}
		}

		return false;
	}

	/**
	 * Dismiss a dead letter entry.
	 *
	 * @param int    $id     Dead letter entry ID.
	 * @param string $reason Dismissal reason.
	 *
	 * @return bool True on success.
	 */
	public function dismiss( int $id, string $reason = '' ): bool {
		// SECURITY: Verify authorization before dismissing jobs.
		if ( ! $this->isAuthorized() ) {
			$this->logUnauthorizedAttempt( 'dismiss', array( 'dlq_id' => $id ) );
			return false;
		}

		$table = $this->getTableName();

		// Validate JSON encoding.
		$metadata = wp_json_encode( array( 'dismiss_reason' => $reason ) );
		if ( false === $metadata ) {
			do_action(
				'wch_log_error',
				'DeadLetterQueue: Failed to encode dismiss metadata',
				array(
					'id'     => $id,
					'reason' => $reason,
				)
			);
			return false;
		}

		$result = $this->wpdb->update(
			$table,
			array(
				'status'       => 'dismissed',
				'dismissed_at' => current_time( 'mysql', true ),
				'metadata'     => $metadata,
			),
			array( 'id' => $id )
		);

		if ( false !== $result ) {
			do_action( 'wch_dead_letter_dismissed', $id, $reason );
			return true;
		}

		return false;
	}

	/**
	 * Get a single entry by ID.
	 *
	 * @param int $id Entry ID.
	 *
	 * @return object|null Entry or null if not found.
	 */
	public function get( int $id ): ?object {
		$table = $this->getTableName();

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ? $this->hydrateEntry( $row ) : null;
	}

	/**
	 * Get statistics.
	 *
	 * @return array<string, int> Statistics.
	 */
	public function getStats(): array {
		$table = $this->getTableName();

		$results = $this->wpdb->get_results(
			"SELECT
				status,
				reason,
				COUNT(*) as count,
				AVG(attempts) as avg_attempts
			 FROM {$table}
			 GROUP BY status, reason"
		);

		$stats = array(
			'total'     => 0,
			'pending'   => 0,
			'replayed'  => 0,
			'dismissed' => 0,
			'by_reason' => array(),
		);

		foreach ( $results as $row ) {
			$stats['total']       += (int) $row->count;
			$stats[ $row->status ] = ( $stats[ $row->status ] ?? 0 ) + (int) $row->count;

			if ( ! isset( $stats['by_reason'][ $row->reason ] ) ) {
				$stats['by_reason'][ $row->reason ] = 0;
			}
			$stats['by_reason'][ $row->reason ] += (int) $row->count;
		}

		return $stats;
	}

	/**
	 * Cleanup old entries.
	 *
	 * @param int $days_old Delete entries older than this many days.
	 *
	 * @return int Number of deleted entries.
	 */
	public function cleanup( int $days_old = 30 ): int {
		// SECURITY: Verify authorization before cleaning up jobs.
		if ( ! $this->isAuthorized() ) {
			$this->logUnauthorizedAttempt( 'cleanup', array( 'days_old' => $days_old ) );
			return 0;
		}

		$table = $this->getTableName();

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_old} days" ) );

		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('replayed', 'dismissed') AND created_at < %s",
				$cutoff
			)
		);

		return (int) $deleted;
	}

	/**
	 * Hydrate an entry with decoded JSON fields.
	 *
	 * Adds _json_errors array if any fields failed to decode,
	 * allowing callers to detect and handle corrupted data.
	 *
	 * @param object $row Database row.
	 *
	 * @return object Hydrated entry with decoded JSON fields.
	 */
	private function hydrateEntry( object $row ): object {
		$row->_json_errors = array();

		// Decode args with error tracking.
		$row->args = json_decode( $row->args, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$row->_json_errors['args'] = json_last_error_msg();
			$row->args                 = null; // Explicitly null to indicate decode failure.
		}

		// Decode metadata with error tracking.
		$row->metadata = json_decode( $row->metadata, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$row->_json_errors['metadata'] = json_last_error_msg();
			$row->metadata                 = null;
		}

		// Log if any corruption detected.
		if ( ! empty( $row->_json_errors ) ) {
			do_action(
				'wch_log_warning',
				'DLQ entry has corrupted JSON fields',
				array(
					'dlq_id' => $row->id ?? 'unknown',
					'hook'   => $row->hook ?? 'unknown',
					'errors' => $row->_json_errors,
				)
			);
		}

		return $row;
	}

	/**
	 * Create the database table.
	 *
	 * @return void
	 */
	public static function createTable(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			hook VARCHAR(191) NOT NULL,
			args LONGTEXT NOT NULL,
			reason VARCHAR(50) NOT NULL,
			error_message TEXT,
			attempts INT UNSIGNED DEFAULT 1,
			priority TINYINT UNSIGNED DEFAULT 3,
			metadata TEXT,
			status VARCHAR(20) DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			replayed_at DATETIME DEFAULT NULL,
			dismissed_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status_reason (status, reason),
			KEY idx_hook (hook),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
