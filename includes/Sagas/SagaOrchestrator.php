<?php
/**
 * Saga Orchestrator
 *
 * Base saga execution engine for managing distributed transactions.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Sagas;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, Generic.Files.OneObjectStructurePerFile.MultipleFound
// SQL uses safe table names from $wpdb->prefix. Hook names use wch_ project prefix.
// File contains multiple classes for saga pattern implementation.

/**
 * Class SagaOrchestrator
 *
 * Executes saga steps with automatic compensation on failure.
 */
class SagaOrchestrator {

	/**
	 * Saga states.
	 */
	public const STATE_PENDING      = 'pending';
	public const STATE_RUNNING      = 'running';
	public const STATE_COMPLETED    = 'completed';
	public const STATE_FAILED       = 'failed';
	public const STATE_COMPENSATING = 'compensating';
	public const STATE_COMPENSATED  = 'compensated';

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * State table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $wpdb WordPress database instance.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		} else {
			$this->wpdb = $wpdb;
		}
		$this->table = $this->wpdb->prefix . 'wch_saga_state';
	}

	/**
	 * Execute a saga with idempotency protection.
	 *
	 * If a saga with the given ID already exists:
	 * - If completed/compensated: returns the existing result (idempotent)
	 * - If running/compensating: throws exception to prevent concurrent execution
	 * - If pending: attempts to acquire lock and execute
	 *
	 * @param string     $saga_id   Unique saga identifier.
	 * @param string     $saga_type Type of saga (e.g., 'checkout', 'refund').
	 * @param array      $context   Initial saga context.
	 * @param SagaStep[] $steps     Array of saga steps.
	 * @return SagaResult Saga execution result.
	 * @throws \RuntimeException If saga is already running concurrently.
	 * @throws \Throwable If saga execution fails.
	 */
	public function execute(
		string $saga_id,
		string $saga_type,
		array $context,
		array $steps
	): SagaResult {
		// Check for existing saga (idempotency protection).
		$existing = $this->getSagaState( $saga_id );

		if ( null !== $existing ) {
			return $this->handleExistingSaga( $saga_id, $existing, $context );
		}

		// Acquire lock before creating saga to prevent race conditions.
		$lock_name     = 'wch_saga_' . $saga_id;
		$lock_acquired = $this->acquireLock( $lock_name );

		if ( ! $lock_acquired ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw new \RuntimeException(
				sprintf( 'Unable to acquire lock for saga %s - concurrent execution detected', $saga_id )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			// Double-check after acquiring lock (another process may have created it).
			$existing = $this->getSagaState( $saga_id );
			if ( null !== $existing ) {
				$this->releaseLock( $lock_name );
				return $this->handleExistingSaga( $saga_id, $existing, $context );
			}

			// Initialize saga state.
			$this->createSagaState( $saga_id, $saga_type, $context );
			$this->updateState( $saga_id, self::STATE_RUNNING );

			$result = $this->executeSteps( $saga_id, $saga_type, $context, $steps );

			$this->releaseLock( $lock_name );
			return $result;
		} catch ( \Throwable $e ) {
			$this->releaseLock( $lock_name );
			throw $e;
		}
	}

	/**
	 * Handle existing saga for idempotency.
	 *
	 * @param string $saga_id  Saga ID.
	 * @param array  $existing Existing saga state.
	 * @param array  $context  Request context (reserved for future logging).
	 * @return SagaResult The saga result.
	 * @throws \RuntimeException If saga is currently running.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private function handleExistingSaga( string $saga_id, array $existing, array $context ): SagaResult {
		// Note: $context parameter kept for API consistency and future logging enhancements.
		unset( $context );

		$state = $existing['state'];

		// If completed or compensated, return the cached result (idempotent response).
		if ( in_array( $state, array( self::STATE_COMPLETED, self::STATE_COMPENSATED ), true ) ) {
			do_action(
				'wch_log_info',
				sprintf(
					'[SagaOrchestrator] Idempotent response for saga %s (state: %s)',
					$saga_id,
					$state
				)
			);

			$existing_context = $existing['context'];

			return new SagaResult(
				$saga_id,
				self::STATE_COMPLETED === $state,
				$existing_context['step_results'] ?? array(),
				self::STATE_COMPENSATED === $state ? 'Saga was previously compensated' : null,
				$existing_context
			);
		}

		// If failed, also return cached result.
		if ( self::STATE_FAILED === $state ) {
			do_action(
				'wch_log_info',
				sprintf(
					'[SagaOrchestrator] Returning failed result for saga %s',
					$saga_id
				)
			);

			$existing_context = $existing['context'];

			return new SagaResult(
				$saga_id,
				false,
				$existing_context['step_results'] ?? array(),
				'Saga previously failed',
				$existing_context
			);
		}

		// If running or compensating, reject to prevent concurrent execution.
		if ( in_array( $state, array( self::STATE_RUNNING, self::STATE_COMPENSATING ), true ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw new \RuntimeException(
				sprintf( 'Saga %s is already %s - concurrent execution not allowed', $saga_id, $state )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Pending state - should not happen normally, but try to execute.
		do_action(
			'wch_log_warning',
			sprintf(
				'[SagaOrchestrator] Found saga %s in pending state - attempting execution',
				$saga_id
			)
		);

		// We'll proceed with execution - the existing state will be overwritten.
		return $this->executePendingSaga( $saga_id, $existing );
	}

	/**
	 * Execute steps for a saga.
	 *
	 * Internal method that performs the actual step execution.
	 *
	 * @param string     $saga_id   Saga ID.
	 * @param string     $saga_type Saga type.
	 * @param array      $context   Initial context.
	 * @param SagaStep[] $steps     Steps to execute.
	 * @return SagaResult Execution result.
	 * @throws \InvalidArgumentException If step is not a SagaStep instance.
	 * @throws SagaStepException If a critical step fails.
	 */
	private function executeSteps(
		string $saga_id,
		string $saga_type,
		array $context,
		array $steps
	): SagaResult {

		$completed_steps = array();
		$step_results    = array();
		$current_context = $context;

		// Add saga metadata to context for step access.
		$current_context['saga_id']   = $saga_id;
		$current_context['saga_type'] = $saga_type;

		try {
			foreach ( $steps as $index => $step ) {
				if ( ! $step instanceof SagaStep ) {
					throw new \InvalidArgumentException(
						'Step at index ' . $index . ' must be instance of SagaStep'
					);
				}

				$step_name = $step->getName();

				// Log step start.
				$this->logStepStart( $saga_id, $step_name );

				try {
					// Execute step with retry logic.
					$result = $this->executeStepWithRetry( $step, $current_context );

					// Store result in context for subsequent steps.
					$step_results[ $step_name ]      = $result;
					$current_context['step_results'] = $step_results;
					$current_context['last_result']  = $result;

					// Track completed step for potential compensation.
					$completed_steps[] = array(
						'step'    => $step,
						'result'  => $result,
						'context' => $current_context,
					);

					$this->logStepComplete( $saga_id, $step_name, $result );
				} catch ( \Throwable $e ) {
					$this->logStepFailed( $saga_id, $step_name, $e->getMessage() );

					if ( $step->isCritical() ) {
						// Critical step failed - trigger compensation.
						throw new SagaStepException(
							$step_name,
							$e->getMessage(),
							$e
						);
					}

					// Non-critical step - continue but record failure.
					$step_results[ $step_name ] = array(
						'error'   => $e->getMessage(),
						'skipped' => true,
					);
				}
			}

			// All steps completed successfully.
			$this->updateState( $saga_id, self::STATE_COMPLETED );
			$this->updateContext( $saga_id, $current_context );

			return new SagaResult(
				$saga_id,
				true,
				$step_results,
				null,
				$current_context
			);
		} catch ( SagaStepException $e ) {
			// Saga failed - run compensation.
			$this->updateState( $saga_id, self::STATE_COMPENSATING );

			$compensation_errors = $this->compensate( $saga_id, $completed_steps );

			$final_state = empty( $compensation_errors )
				? self::STATE_COMPENSATED
				: self::STATE_FAILED;

			$this->updateState( $saga_id, $final_state );

			return new SagaResult(
				$saga_id,
				false,
				$step_results,
				$e->getMessage(),
				$current_context,
				$e->getStepName(),
				$compensation_errors
			);
		} catch ( \Throwable $e ) {
			// Unexpected error.
			$this->updateState( $saga_id, self::STATE_FAILED );
			$this->logError( $saga_id, 'Unexpected saga error: ' . $e->getMessage() );

			return new SagaResult(
				$saga_id,
				false,
				$step_results,
				$e->getMessage(),
				$current_context
			);
		}
	}

	/**
	 * Acquire a MySQL advisory lock.
	 *
	 * Uses MySQL GET_LOCK() to prevent concurrent saga execution.
	 * Lock is held at the connection level and released when connection closes.
	 *
	 * @param string $lock_name Lock name (will be hashed if > 64 chars).
	 * @param int    $timeout   Lock acquisition timeout in seconds.
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquireLock( string $lock_name, int $timeout = 10 ): bool {
		// MySQL advisory lock names are limited to 64 chars.
		if ( strlen( $lock_name ) > 64 ) {
			$lock_name = hash( 'sha256', $lock_name );
		}

		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$lock_name,
				$timeout
			)
		);

		// GET_LOCK returns: 1 = lock acquired, 0 = timeout, NULL = error.
		if ( '1' === $result || 1 === $result ) {
			do_action(
				'wch_log_debug',
				sprintf(
					'[SagaOrchestrator] Acquired lock: %s',
					$lock_name
				)
			);
			return true;
		}

		do_action(
			'wch_log_warning',
			sprintf(
				'[SagaOrchestrator] Failed to acquire lock: %s (result: %s)',
				$lock_name,
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Needed for debugging lock acquisition.
				var_export( $result, true )
			)
		);

		return false;
	}

	/**
	 * Release a MySQL advisory lock.
	 *
	 * @param string $lock_name Lock name (must match acquireLock).
	 * @return bool True if released, false if lock didn't exist.
	 */
	private function releaseLock( string $lock_name ): bool {
		// Hash if needed (same as acquireLock).
		if ( strlen( $lock_name ) > 64 ) {
			$lock_name = hash( 'sha256', $lock_name );
		}

		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT RELEASE_LOCK(%s)',
				$lock_name
			)
		);

		// RELEASE_LOCK returns: 1 = released, 0 = not owned, NULL = didn't exist.
		if ( '1' === $result || 1 === $result ) {
			do_action(
				'wch_log_debug',
				sprintf(
					'[SagaOrchestrator] Released lock: %s',
					$lock_name
				)
			);
			return true;
		}

		do_action(
			'wch_log_warning',
			sprintf(
				'[SagaOrchestrator] Lock was not owned or did not exist: %s',
				$lock_name
			)
		);

		return false;
	}

	/**
	 * Execute a saga that was found in pending state.
	 *
	 * This handles the edge case where a saga was created but execution
	 * was interrupted before it could run (e.g., process crash).
	 *
	 * Note: The caller must provide the steps array since we only store
	 * saga_type in the database. For proper recovery of abandoned sagas,
	 * a saga registry pattern should be used to lookup steps by type.
	 *
	 * @param string $saga_id  Saga ID.
	 * @param array  $existing Existing saga state.
	 * @return SagaResult Saga result.
	 * @throws \RuntimeException If saga cannot be recovered.
	 */
	private function executePendingSaga( string $saga_id, array $existing ): SagaResult {
		// For pending sagas found in handleExistingSaga, we cannot re-execute
		// without the original steps array. The saga creator should retry
		// with the full execution context.
		//
		// This is a design limitation - sagas store context but not the
		// step definitions (closures can't be serialized). True saga recovery
		// would require a registry pattern where saga_type maps to step builders.

		do_action(
			'wch_log_warning',
			sprintf(
				'[SagaOrchestrator] Cannot recover pending saga %s - steps not available. ' .
				'Marking as failed. Original caller should retry.',
				$saga_id
			)
		);

		// Mark as failed so subsequent calls don't keep hitting this.
		$this->updateState( $saga_id, self::STATE_FAILED );
		$this->appendLog( $saga_id, 'recovery_failed', 'Steps not available for recovery' );

		$existing_context = $existing['context'] ?? array();

		return new SagaResult(
			$saga_id,
			false,
			array(),
			'Saga found in pending state but cannot be recovered - steps not serialized',
			$existing_context
		);
	}

	/**
	 * Execute a step with retry logic.
	 *
	 * @param SagaStep $step    Step to execute.
	 * @param array    $context Current context.
	 * @return mixed Step result.
	 * @throws \Throwable If all retries fail.
	 */
	private function executeStepWithRetry( SagaStep $step, array $context ): mixed {
		$max_retries = $step->getMaxRetries();
		$attempt     = 0;
		$last_error  = null;

		// max_retries = 3 means: 1 initial attempt + 3 retries = 4 total attempts.
		// Loop condition uses <= to ensure we get initial + max_retries attempts.
		while ( $attempt <= $max_retries ) {
			++$attempt;

			try {
				return $step->execute( $context );
			} catch ( \Throwable $e ) {
				$last_error = $e;

				if ( $attempt <= $max_retries ) {
					// Exponential backoff before retry.
					$delay = min( pow( 2, $attempt ) * 100000, 2000000 );
					usleep( $delay );
				}
			}
		}

		throw $last_error;
	}

	/**
	 * Run compensation for completed steps in reverse order.
	 *
	 * @param string $saga_id        Saga ID.
	 * @param array  $completed_steps Completed steps to compensate.
	 * @return array Compensation errors (empty if all successful).
	 */
	private function compensate( string $saga_id, array $completed_steps ): array {
		$errors = array();

		// Compensate in reverse order.
		$reversed = array_reverse( $completed_steps );

		foreach ( $reversed as $completed ) {
			$step    = $completed['step'];
			$result  = $completed['result'];
			$context = $completed['context'];

			if ( ! $step->hasCompensation() ) {
				continue;
			}

			$step_name = $step->getName();

			try {
				$this->logCompensationStart( $saga_id, $step_name );

				// Add step result to context for compensation.
				$context['compensation_for'] = $step_name;
				$context['step_result']      = $result;

				$step->compensate( $context );

				$this->logCompensationComplete( $saga_id, $step_name );
			} catch ( \Throwable $e ) {
				$errors[ $step_name ] = $e->getMessage();
				$this->logCompensationFailed( $saga_id, $step_name, $e->getMessage() );

				do_action( 'wch_saga_compensation_failed', $saga_id, $step_name, $e );
			}
		}

		return $errors;
	}

	/**
	 * Get saga state.
	 *
	 * @param string $saga_id Saga ID.
	 * @return array|null Saga state or null if not found.
	 */
	public function getSagaState( string $saga_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE saga_id = %s",
				$saga_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Decode JSON fields with validation.
		$row['context'] = json_decode( $row['context'] ?? '{}', true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			do_action(
				'wch_log_warning',
				sprintf(
					'[SagaOrchestrator] Corrupted context JSON for saga %s: %s',
					$saga_id,
					json_last_error_msg()
				)
			);
			$row['context'] = array();
		}

		$row['log'] = json_decode( $row['log'] ?? '[]', true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			do_action(
				'wch_log_warning',
				sprintf(
					'[SagaOrchestrator] Corrupted log JSON for saga %s: %s',
					$saga_id,
					json_last_error_msg()
				)
			);
			$row['log'] = array();
		}

		return $row;
	}

	/**
	 * Get pending sagas for recovery.
	 *
	 * @param int $limit Maximum sagas to return.
	 * @return array Pending saga IDs.
	 */
	public function getPendingSagas( int $limit = 100 ): array {
		return $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT saga_id FROM {$this->table}
				WHERE state IN (%s, %s)
				AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
				ORDER BY created_at ASC
				LIMIT %d",
				self::STATE_RUNNING,
				self::STATE_COMPENSATING,
				$limit
			)
		);
	}

	/**
	 * Create saga state record.
	 *
	 * @param string $saga_id   Saga ID.
	 * @param string $saga_type Saga type.
	 * @param array  $context   Initial context.
	 * @return void
	 */
	private function createSagaState( string $saga_id, string $saga_type, array $context ): void {
		$this->wpdb->insert(
			$this->table,
			array(
				'saga_id'    => $saga_id,
				'saga_type'  => $saga_type,
				'state'      => self::STATE_PENDING,
				'context'    => wp_json_encode( $context ),
				'log'        => '[]',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update saga state.
	 *
	 * @param string $saga_id Saga ID.
	 * @param string $state   New state.
	 * @return void
	 */
	private function updateState( string $saga_id, string $state ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'state'      => $state,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'saga_id' => $saga_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		do_action( 'wch_saga_state_changed', $saga_id, $state );
	}

	/**
	 * Update saga context.
	 *
	 * @param string $saga_id Saga ID.
	 * @param array  $context Updated context.
	 * @return void
	 */
	private function updateContext( string $saga_id, array $context ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'context'    => wp_json_encode( $context ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'saga_id' => $saga_id )
		);
	}

	/**
	 * Append to saga log.
	 *
	 * Uses transaction with row lock to prevent concurrent modification race condition.
	 * Without locking, two concurrent log appends could cause one to overwrite the other.
	 *
	 * @param string $saga_id Saga ID.
	 * @param string $event   Event type.
	 * @param string $message Event message.
	 * @return void
	 */
	private function appendLog( string $saga_id, string $event, string $message ): void {
		// Use transaction with row lock to prevent concurrent modification race condition.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Lock the saga row to prevent concurrent reads during modification.
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT log FROM {$this->table} WHERE saga_id = %s FOR UPDATE",
					$saga_id
				)
			);

			if ( ! $row ) {
				$this->wpdb->query( 'ROLLBACK' );
				return;
			}

			$log = ! empty( $row->log ) ? json_decode( $row->log, true ) : array();
			if ( ! is_array( $log ) ) {
				$log = array();
			}

			$log[] = array(
				'timestamp' => current_time( 'mysql', true ),
				'event'     => $event,
				'message'   => $message,
			);

			$this->wpdb->update(
				$this->table,
				array( 'log' => wp_json_encode( $log ) ),
				array( 'saga_id' => $saga_id )
			);

			$this->wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			// Log the error but don't propagate - logging shouldn't fail the saga.
			do_action(
				'wch_log_warning',
				sprintf(
					'[SagaOrchestrator] Failed to append log: %s',
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Log step start.
	 *
	 * @param string $saga_id   Saga ID.
	 * @param string $step_name Step name.
	 * @return void
	 */
	private function logStepStart( string $saga_id, string $step_name ): void {
		$this->appendLog( $saga_id, 'step_start', $step_name );
		do_action( 'wch_log_debug', sprintf( '[Saga:%s] Step started: %s', $saga_id, $step_name ) );
	}

	/**
	 * Log step complete.
	 *
	 * @param string $saga_id   Saga ID.
	 * @param string $step_name Step name.
	 * @param mixed  $result    Step result.
	 * @return void
	 */
	private function logStepComplete( string $saga_id, string $step_name, mixed $result ): void {
		$this->appendLog( $saga_id, 'step_complete', $step_name );
		do_action( 'wch_log_debug', sprintf( '[Saga:%s] Step completed: %s', $saga_id, $step_name ) );
	}

	/**
	 * Log step failed.
	 *
	 * @param string $saga_id   Saga ID.
	 * @param string $step_name Step name.
	 * @param string $error     Error message.
	 * @return void
	 */
	private function logStepFailed( string $saga_id, string $step_name, string $error ): void {
		$this->appendLog( $saga_id, 'step_failed', $step_name . ': ' . $error );
		do_action( 'wch_log_error', sprintf( '[Saga:%s] Step failed: %s - %s', $saga_id, $step_name, $error ) );
	}

	/**
	 * Log compensation start.
	 *
	 * @param string $saga_id   Saga ID.
	 * @param string $step_name Step name.
	 * @return void
	 */
	private function logCompensationStart( string $saga_id, string $step_name ): void {
		$this->appendLog( $saga_id, 'compensate_start', $step_name );
		do_action( 'wch_log_debug', sprintf( '[Saga:%s] Compensating: %s', $saga_id, $step_name ) );
	}

	/**
	 * Log compensation complete.
	 *
	 * @param string $saga_id   Saga ID.
	 * @param string $step_name Step name.
	 * @return void
	 */
	private function logCompensationComplete( string $saga_id, string $step_name ): void {
		$this->appendLog( $saga_id, 'compensate_complete', $step_name );
		do_action( 'wch_log_debug', sprintf( '[Saga:%s] Compensation complete: %s', $saga_id, $step_name ) );
	}

	/**
	 * Log compensation failed.
	 *
	 * @param string $saga_id   Saga ID.
	 * @param string $step_name Step name.
	 * @param string $error     Error message.
	 * @return void
	 */
	private function logCompensationFailed( string $saga_id, string $step_name, string $error ): void {
		$this->appendLog( $saga_id, 'compensate_failed', $step_name . ': ' . $error );
		do_action(
			'wch_log_error',
			sprintf(
				'[Saga:%s] Compensation failed: %s - %s',
				$saga_id,
				$step_name,
				$error
			)
		);
	}

	/**
	 * Log error.
	 *
	 * @param string $saga_id Saga ID.
	 * @param string $error   Error message.
	 * @return void
	 */
	private function logError( string $saga_id, string $error ): void {
		$this->appendLog( $saga_id, 'error', $error );
		do_action( 'wch_log_error', sprintf( '[Saga:%s] Error: %s', $saga_id, $error ) );
	}

	/**
	 * Create the saga state table.
	 *
	 * @return void
	 */
	public static function createTable(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'wch_saga_state';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			saga_id VARCHAR(100) NOT NULL,
			saga_type VARCHAR(50) NOT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			context LONGTEXT NOT NULL,
			log LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (saga_id),
			KEY idx_state (state),
			KEY idx_saga_type (saga_type),
			KEY idx_updated (updated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

/**
 * Exception thrown when a saga step fails.
 */
class SagaStepException extends \Exception {

	/**
	 * Step name.
	 *
	 * @var string
	 */
	private string $step_name;

	/**
	 * Constructor.
	 *
	 * @param string          $step_name Step name.
	 * @param string          $message   Error message.
	 * @param \Throwable|null $previous  Previous exception.
	 */
	public function __construct( string $step_name, string $message, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->step_name = $step_name;
	}

	/**
	 * Get step name.
	 *
	 * @return string
	 */
	public function getStepName(): string {
		return $this->step_name;
	}
}

/**
 * Saga execution result.
 */
class SagaResult {

	/**
	 * Saga ID.
	 *
	 * @var string
	 */
	public string $saga_id;

	/**
	 * Whether saga succeeded.
	 *
	 * @var bool
	 */
	public bool $success;

	/**
	 * Step results.
	 *
	 * @var array
	 */
	public array $step_results;

	/**
	 * Error message (if failed).
	 *
	 * @var string|null
	 */
	public ?string $error;

	/**
	 * Final context.
	 *
	 * @var array
	 */
	public array $context;

	/**
	 * Failed step name (if applicable).
	 *
	 * @var string|null
	 */
	public ?string $failed_step;

	/**
	 * Compensation errors.
	 *
	 * @var array
	 */
	public array $compensation_errors;

	/**
	 * Constructor.
	 *
	 * @param string      $saga_id             Saga ID.
	 * @param bool        $success             Whether saga succeeded.
	 * @param array       $step_results        Step results.
	 * @param string|null $error               Error message.
	 * @param array       $context             Final context.
	 * @param string|null $failed_step         Failed step name.
	 * @param array       $compensation_errors Compensation errors.
	 */
	public function __construct(
		string $saga_id,
		bool $success,
		array $step_results,
		?string $error = null,
		array $context = array(),
		?string $failed_step = null,
		array $compensation_errors = array()
	) {
		$this->saga_id             = $saga_id;
		$this->success             = $success;
		$this->step_results        = $step_results;
		$this->error               = $error;
		$this->context             = $context;
		$this->failed_step         = $failed_step;
		$this->compensation_errors = $compensation_errors;
	}

	/**
	 * Check if fully compensated.
	 *
	 * @return bool
	 */
	public function isFullyCompensated(): bool {
		return ! $this->success && empty( $this->compensation_errors );
	}

	/**
	 * Get step result.
	 *
	 * @param string $step_name Step name.
	 * @return mixed|null Step result or null.
	 */
	public function getStepResult( string $step_name ): mixed {
		return $this->step_results[ $step_name ] ?? null;
	}
}
