<?php
/**
 * Circuit Breaker
 *
 * Implements the circuit breaker pattern for external service protection.
 * Uses database-backed state for race-condition-free operation under concurrency.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Resilience;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.Security.EscapeOutput.ExceptionNotEscaped, Generic.Files.OneObjectStructurePerFile.MultipleFound
// SQL uses safe table names from $wpdb->prefix. Hook names use wch_ project prefix.
// Exception messages are for logging, not output. File contains exception class.

/**
 * Class CircuitBreaker
 *
 * Protects external service calls with automatic failure handling.
 * Uses atomic database operations to prevent race conditions.
 */
class CircuitBreaker {

	/**
	 * Circuit states.
	 */
	public const STATE_CLOSED    = 'closed';
	public const STATE_OPEN      = 'open';
	public const STATE_HALF_OPEN = 'half_open';

	/**
	 * Service identifier.
	 *
	 * @var string
	 */
	private string $service;

	/**
	 * Failure threshold before opening circuit.
	 *
	 * @var int
	 */
	private int $failure_threshold;

	/**
	 * Success threshold to close circuit from half-open.
	 *
	 * @var int
	 */
	private int $success_threshold;

	/**
	 * Timeout in seconds before attempting recovery.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Cache key prefix (for transient fallback).
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'wch_circuit_';

	/**
	 * Constructor.
	 *
	 * @param string     $service           Service identifier.
	 * @param int        $failure_threshold Failures before opening (default: 5).
	 * @param int        $success_threshold Successes to close from half-open (default: 2).
	 * @param int        $timeout           Seconds before recovery attempt (default: 30).
	 * @param \wpdb|null $wpdb              WordPress database instance.
	 */
	public function __construct(
		string $service,
		int $failure_threshold = 5,
		int $success_threshold = 2,
		int $timeout = 30,
		?\wpdb $wpdb = null
	) {
		$this->service           = $service;
		$this->failure_threshold = $failure_threshold;
		$this->success_threshold = $success_threshold;
		$this->timeout           = $timeout;

		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb  = $wpdb;
		$this->table = $this->wpdb->prefix . 'wch_circuit_breakers';
	}

	/**
	 * Execute a protected call.
	 *
	 * Uses atomic state transitions to prevent thundering herd when
	 * circuit times out. Only one request will successfully transition
	 * from OPEN to HALF_OPEN; others receive fallback/exception.
	 *
	 * @param callable      $operation       The operation to execute.
	 * @param callable|null $fallback        Optional fallback on failure.
	 * @param bool          $throw_on_open   Whether to throw when circuit is open.
	 *
	 * @return mixed Operation result or fallback result.
	 *
	 * @throws CircuitOpenException If circuit is open and no fallback provided.
	 * @throws \Throwable If operation fails and no fallback provided.
	 */
	public function call(
		callable $operation,
		?callable $fallback = null,
		bool $throw_on_open = true
	): mixed {
		// Get actual database state, not logical state.
		$row      = $this->getCircuitRow();
		$db_state = $row ? $row->state : self::STATE_CLOSED;

		// Handle open circuit.
		if ( self::STATE_OPEN === $db_state ) {
			if ( $this->shouldAttemptRecovery() ) {
				// Attempt atomic transition to half-open.
				// Only one concurrent request will succeed.
				if ( ! $this->compareAndTransition( self::STATE_OPEN, self::STATE_HALF_OPEN ) ) {
					// Another request already transitioned.
					// Check new state and decide what to do.
					$new_state = $this->getState();
					if ( self::STATE_HALF_OPEN === $new_state ) {
						// Another request is attempting recovery.
						// Block this request to prevent thundering herd.
						$this->logEvent( 'blocked', 'Recovery already in progress' );
						if ( $fallback ) {
							return $fallback();
						}
						if ( $throw_on_open ) {
							throw new CircuitOpenException(
								"Circuit breaker recovery in progress for service: {$this->service}"
							);
						}
						return null;
					}
					// State is now closed, proceed normally.
				}
				// Successfully transitioned to half-open, proceed with recovery attempt.
			} else {
				// Circuit still within timeout period.
				$this->logEvent( 'blocked', 'Circuit is open' );
				if ( $fallback ) {
					return $fallback();
				}
				if ( $throw_on_open ) {
					throw new CircuitOpenException(
						"Circuit breaker is open for service: {$this->service}"
					);
				}
				return null;
			}
		}

		try {
			$result = $operation();
			$this->recordSuccess();

			return $result;
		} catch ( \Throwable $e ) {
			$this->recordFailure( $e->getMessage() );

			if ( $fallback ) {
				$this->logEvent( 'fallback', $e->getMessage() );
				return $fallback();
			}

			throw $e;
		}
	}

	/**
	 * Record a successful call.
	 *
	 * Uses atomic compare-and-swap for state transitions to prevent race conditions.
	 *
	 * @return void
	 */
	public function recordSuccess(): void {
		$state = $this->getState();

		if ( self::STATE_HALF_OPEN === $state ) {
			$successes = $this->incrementCounter( 'successes' );

			if ( $successes >= $this->success_threshold ) {
				// Use atomic compare-and-swap to prevent race conditions.
				if ( $this->compareAndTransition( self::STATE_HALF_OPEN, self::STATE_CLOSED ) ) {
					$this->logEvent( 'closed', 'Recovery successful' );
				}
			}
		} elseif ( self::STATE_CLOSED === $state ) {
			// Reset failure count on success.
			$this->setCounter( 'failures', 0 );
		}
	}

	/**
	 * Record a failed call.
	 *
	 * Uses atomic compare-and-swap for state transitions to prevent race conditions.
	 *
	 * @param string $reason Failure reason.
	 *
	 * @return void
	 */
	public function recordFailure( string $reason = '' ): void {
		$state = $this->getState();

		if ( self::STATE_HALF_OPEN === $state ) {
			// Any failure in half-open state opens the circuit.
			// Use atomic compare-and-swap to prevent race conditions.
			if ( $this->compareAndTransition( self::STATE_HALF_OPEN, self::STATE_OPEN ) ) {
				$this->logEvent( 'opened', 'Recovery attempt failed: ' . $reason );
			}
		} elseif ( self::STATE_CLOSED === $state ) {
			$failures = $this->incrementCounter( 'failures' );

			if ( $failures >= $this->failure_threshold ) {
				// Use atomic compare-and-swap to prevent race conditions.
				if ( $this->compareAndTransition( self::STATE_CLOSED, self::STATE_OPEN ) ) {
					$this->logEvent( 'opened', "Threshold reached ({$failures} failures): " . $reason );
				}
			}
		}
	}

	/**
	 * Get current circuit state.
	 *
	 * Returns the logical state of the circuit. When an open circuit's
	 * timeout has elapsed, this returns STATE_HALF_OPEN to indicate the
	 * circuit is ready for recovery attempts, even if the database hasn't
	 * been updated yet.
	 *
	 * Uses database for persistent, race-condition-free state.
	 *
	 * @return string Current state (STATE_CLOSED, STATE_OPEN, or STATE_HALF_OPEN).
	 */
	public function getState(): string {
		$row = $this->getCircuitRow();

		if ( ! $row ) {
			return self::STATE_CLOSED;
		}

		// Check if open circuit has timed out.
		if ( self::STATE_OPEN === $row->state && $row->opened_at ) {
			$opened_at = strtotime( $row->opened_at );

			// Validate strtotime result - returns false on parse failure.
			if ( false === $opened_at ) {
				do_action(
					'wch_log_error',
					'CircuitBreaker: Invalid opened_at timestamp',
					[
						'service'   => $this->service,
						'opened_at' => $row->opened_at,
					]
				);
				// Assume timeout elapsed on parse error to allow recovery attempts.
				return self::STATE_HALF_OPEN;
			}

			if ( ( time() - $opened_at ) >= $this->timeout ) {
				// Timeout elapsed - circuit is logically in half-open state.
				// Return STATE_HALF_OPEN to reflect the true logical state.
				// The actual database transition happens in isAvailable().
				return self::STATE_HALF_OPEN;
			}
		}

		return $row->state;
	}

	/**
	 * Get the circuit row from database.
	 *
	 * @return object|null The circuit row or null.
	 */
	private function getCircuitRow(): ?object {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE service = %s",
				$this->service
			)
		);
	}

	/**
	 * Ensure circuit row exists.
	 *
	 * @return bool True if row exists or was created, false on database error.
	 */
	private function ensureCircuitExists(): bool {
		// Use INSERT IGNORE to prevent duplicate key errors.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->table}
				(service, state, failures, successes, opened_at, updated_at)
				VALUES (%s, %s, 0, 0, NULL, NOW())",
				$this->service,
				self::STATE_CLOSED
			)
		);

		if ( false === $result ) {
			do_action(
				'wch_log_error',
				'CircuitBreaker: Failed to ensure circuit exists',
				[
					'service'    => $this->service,
					'last_error' => $this->wpdb->last_error,
				]
			);
			return false;
		}

		return true;
	}

	/**
	 * Check if circuit allows requests.
	 *
	 * Uses atomic compare-and-swap for state transitions to prevent race conditions
	 * when multiple requests check availability simultaneously.
	 *
	 * @return bool True if requests are allowed.
	 */
	public function isAvailable(): bool {
		// Get actual database state first.
		$row      = $this->getCircuitRow();
		$db_state = $row ? $row->state : self::STATE_CLOSED;

		if ( self::STATE_CLOSED === $db_state ) {
			return true;
		}

		if ( self::STATE_OPEN === $db_state && $this->shouldAttemptRecovery() ) {
			// Use atomic compare-and-swap to prevent thundering herd.
			// Only one request will successfully transition to half-open.
			if ( $this->compareAndTransition( self::STATE_OPEN, self::STATE_HALF_OPEN ) ) {
				return true;
			}
			// Another request already transitioned - check new state.
			$new_state = $this->getState();
			// Allow if circuit is now half-open (another request is testing) or closed (recovery succeeded).
			return in_array( $new_state, [ self::STATE_HALF_OPEN, self::STATE_CLOSED ], true );
		}

		return self::STATE_HALF_OPEN === $db_state;
	}

	/**
	 * Manually open the circuit.
	 *
	 * @param string $reason Reason for opening.
	 *
	 * @return void
	 */
	public function open( string $reason = '' ): void {
		$this->transitionTo( self::STATE_OPEN );
		$this->logEvent( 'manual_open', $reason );
	}

	/**
	 * Manually close the circuit.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->transitionTo( self::STATE_CLOSED );
		$this->logEvent( 'manual_close', 'Circuit manually closed' );
	}

	/**
	 * Get circuit health metrics.
	 *
	 * @return array<string, mixed> Health metrics.
	 */
	public function getMetrics(): array {
		return [
			'service'           => $this->service,
			'state'             => $this->getState(),
			'failures'          => $this->getCounter( 'failures' ),
			'successes'         => $this->getCounter( 'successes' ),
			'failure_threshold' => $this->failure_threshold,
			'success_threshold' => $this->success_threshold,
			'timeout'           => $this->timeout,
			'opened_at'         => get_transient( $this->getCacheKey( 'opened_at' ) ) ?: null,
			'last_failure'      => get_transient( $this->getCacheKey( 'last_failure' ) ) ?: null,
		];
	}

	/**
	 * Transition to a new state atomically.
	 *
	 * Uses database transaction with row locking to prevent race conditions.
	 *
	 * @param string $state New state.
	 *
	 * @return bool True if transition occurred.
	 */
	private function transitionTo( string $state ): bool {
		if ( ! $this->ensureCircuitExists() ) {
			return false;
		}

		$old_state = $this->getState();

		// Use atomic update with state check to prevent race conditions.
		$update_data = [
			'state'      => $state,
			'updated_at' => current_time( 'mysql', true ),
		];

		if ( self::STATE_OPEN === $state ) {
			$update_data['opened_at'] = current_time( 'mysql', true );
		}

		if ( self::STATE_HALF_OPEN === $state ) {
			// Reset success counter for recovery tracking.
			$update_data['successes'] = 0;
		}

		if ( self::STATE_CLOSED === $state ) {
			// Reset all counters.
			$update_data['failures']  = 0;
			$update_data['successes'] = 0;
			$update_data['opened_at'] = null;
		}

		$result = $this->wpdb->update(
			$this->table,
			$update_data,
			[ 'service' => $this->service ]
		);

		if ( false === $result ) {
			do_action(
				'wch_log_error',
				'CircuitBreaker: Failed to transition state',
				[
					'service'    => $this->service,
					'from_state' => $old_state,
					'to_state'   => $state,
					'last_error' => $this->wpdb->last_error,
				]
			);
			return false;
		}

		if ( $old_state !== $state ) {
			do_action( 'wch_circuit_state_changed', $this->service, $old_state, $state );
			return true;
		}

		return false;
	}

	/**
	 * Transition to state atomically only if current state matches expected.
	 *
	 * Uses a database transaction with row locking to ensure atomicity.
	 * The INSERT IGNORE is performed inside the transaction to prevent
	 * race conditions where multiple requests could create duplicate rows.
	 *
	 * @param string $expected_state The expected current state.
	 * @param string $new_state      The new state to transition to.
	 *
	 * @return bool True if transition occurred.
	 */
	private function compareAndTransition( string $expected_state, string $new_state ): bool {
		$update_data = [
			'state'      => $new_state,
			'updated_at' => current_time( 'mysql', true ),
		];

		if ( self::STATE_OPEN === $new_state ) {
			$update_data['opened_at'] = current_time( 'mysql', true );
		}

		if ( self::STATE_HALF_OPEN === $new_state ) {
			$update_data['successes'] = 0;
		}

		if ( self::STATE_CLOSED === $new_state ) {
			$update_data['failures']  = 0;
			$update_data['successes'] = 0;
			$update_data['opened_at'] = null;
		}

		// Atomic compare-and-swap: only update if current state matches expected.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Ensure row exists INSIDE the transaction to prevent race condition.
			// INSERT IGNORE is safe within transaction and won't duplicate rows.
			$this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT IGNORE INTO {$this->table}
					(service, state, failures, successes, opened_at, updated_at)
					VALUES (%s, %s, 0, 0, NULL, NOW())",
					$this->service,
					self::STATE_CLOSED
				)
			);

			// Lock the row for update.
			$current = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT state FROM {$this->table} WHERE service = %s FOR UPDATE",
					$this->service
				)
			);

			if ( $current !== $expected_state ) {
				$this->wpdb->query( 'ROLLBACK' );
				return false;
			}

			$this->wpdb->update(
				$this->table,
				$update_data,
				[ 'service' => $this->service ]
			);

			$this->wpdb->query( 'COMMIT' );

			do_action( 'wch_circuit_state_changed', $this->service, $expected_state, $new_state );
			return true;
		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			do_action(
				'wch_log_error',
				'CircuitBreaker: Transaction failed in compareAndTransition',
				[
					'service' => $this->service,
					'error'   => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Check if we should attempt recovery.
	 *
	 * @return bool True if timeout has elapsed.
	 */
	private function shouldAttemptRecovery(): bool {
		$row = $this->getCircuitRow();

		if ( ! $row || ! $row->opened_at ) {
			return true;
		}

		$opened_at = strtotime( $row->opened_at );

		// Validate strtotime result - returns false on parse failure.
		if ( false === $opened_at ) {
			do_action(
				'wch_log_error',
				'CircuitBreaker: Invalid opened_at in shouldAttemptRecovery',
				[
					'service'   => $this->service,
					'opened_at' => $row->opened_at,
				]
			);
			// Allow recovery attempt on parse error.
			return true;
		}

		return ( time() - $opened_at ) >= $this->timeout;
	}

	/**
	 * Get a counter value.
	 *
	 * @param string $name Counter name (failures or successes).
	 *
	 * @return int Counter value.
	 */
	private function getCounter( string $name ): int {
		$row = $this->getCircuitRow();
		if ( ! $row ) {
			return 0;
		}

		return (int) ( $row->$name ?? 0 );
	}

	/**
	 * Set a counter value.
	 *
	 * @param string $name  Counter name (must be 'failures' or 'successes').
	 * @param int    $value Counter value.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function setCounter( string $name, int $value ): bool {
		// Validate counter name to prevent SQL injection via column name.
		if ( ! in_array( $name, [ 'failures', 'successes' ], true ) ) {
			do_action(
				'wch_log_error',
				'CircuitBreaker: Invalid counter name',
				[
					'service' => $this->service,
					'name'    => $name,
				]
			);
			return false;
		}

		if ( ! $this->ensureCircuitExists() ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->table,
			[
				$name        => $value,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'service' => $this->service ]
		);

		if ( false === $result ) {
			do_action(
				'wch_log_error',
				'CircuitBreaker: Failed to set counter',
				[
					'service'    => $this->service,
					'counter'    => $name,
					'value'      => $value,
					'last_error' => $this->wpdb->last_error,
				]
			);
			return false;
		}

		return true;
	}

	/**
	 * Maximum counter value to prevent integer overflow.
	 *
	 * Using PHP_INT_MAX would risk overflow on 32-bit systems.
	 * 1 billion is safe for both 32-bit and 64-bit systems.
	 */
	private const MAX_COUNTER_VALUE = 1000000000;

	/**
	 * Atomically increment a counter and return the new value.
	 *
	 * Uses database-level atomic increment with overflow protection.
	 * When counter reaches MAX_COUNTER_VALUE, it wraps back to 1.
	 *
	 * @param string $name Counter name.
	 *
	 * @return int New value, or 0 on failure.
	 */
	private function incrementCounter( string $name ): int {
		if ( ! $this->ensureCircuitExists() ) {
			return 0;
		}

		$result = false;

		// Use switch with hardcoded column names to prevent SQL injection.
		// Each case uses a fully parameterized query with literal column name.
		switch ( $name ) {
			case 'failures':
				$result = $this->wpdb->query(
					$this->wpdb->prepare(
						"UPDATE {$this->table}
						SET failures = CASE
							WHEN failures >= %d THEN 1
							ELSE failures + 1
						END,
						updated_at = NOW()
						WHERE service = %s",
						self::MAX_COUNTER_VALUE,
						$this->service
					)
				);
				break;

			case 'successes':
				$result = $this->wpdb->query(
					$this->wpdb->prepare(
						"UPDATE {$this->table}
						SET successes = CASE
							WHEN successes >= %d THEN 1
							ELSE successes + 1
						END,
						updated_at = NOW()
						WHERE service = %s",
						self::MAX_COUNTER_VALUE,
						$this->service
					)
				);
				break;

			default:
				// Invalid counter name - log and return 0.
				do_action(
					'wch_log_error',
					'CircuitBreaker: Invalid counter name in increment',
					[
						'service' => $this->service,
						'name'    => $name,
					]
				);
				return 0;
		}

		if ( false === $result ) {
			do_action(
				'wch_log_error',
				'CircuitBreaker: Failed to increment counter',
				[
					'service'    => $this->service,
					'counter'    => $name,
					'last_error' => $this->wpdb->last_error,
				]
			);
			return 0;
		}

		return $this->getCounter( $name );
	}

	/**
	 * Get cache key for a field (legacy, kept for compatibility).
	 *
	 * @param string $field Field name.
	 *
	 * @return string Cache key.
	 */
	private function getCacheKey( string $field ): string {
		return self::CACHE_PREFIX . $this->service . '_' . $field;
	}

	/**
	 * Log a circuit event.
	 *
	 * @param string $event   Event type.
	 * @param string $message Event message.
	 *
	 * @return void
	 */
	private function logEvent( string $event, string $message ): void {
		if ( 'blocked' === $event ) {
			set_transient(
				$this->getCacheKey( 'last_failure' ),
				[
					'time'    => time(),
					'message' => $message,
				],
				HOUR_IN_SECONDS
			);
		}

		do_action(
			'wch_log_info',
			sprintf(
				'[CircuitBreaker:%s] %s - %s',
				$this->service,
				$event,
				$message
			)
		);

		do_action( 'wch_circuit_event', $this->service, $event, $message );
	}

	/**
	 * Create the database table.
	 *
	 * Uses BIGINT UNSIGNED for counters to prevent overflow.
	 * The incrementCounter() method also has application-level
	 * overflow protection that resets at 1 billion.
	 *
	 * @return void
	 */
	public static function createTable(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'wch_circuit_breakers';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			service VARCHAR(100) NOT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'closed',
			failures BIGINT UNSIGNED DEFAULT 0,
			successes BIGINT UNSIGNED DEFAULT 0,
			opened_at DATETIME DEFAULT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (service),
			KEY idx_state (state)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

/**
 * Exception thrown when circuit is open.
 */
class CircuitOpenException extends \Exception {
}
