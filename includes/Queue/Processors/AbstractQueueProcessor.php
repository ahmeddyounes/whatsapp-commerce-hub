<?php
/**
 * Abstract Queue Processor
 *
 * Base class for all queue job processors with retry logic,
 * dead letter queue integration, and circuit breaker support.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Queue\Processors;

use WhatsAppCommerceHub\Queue\Contracts\QueueProcessorInterface;
use WhatsAppCommerceHub\Queue\DeadLetterQueue;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Exceptions\DomainException;
use WhatsAppCommerceHub\Exceptions\ApplicationException;
use WhatsAppCommerceHub\Exceptions\InfrastructureException;
use WhatsAppCommerceHub\Exceptions\WchException;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractQueueProcessor
 *
 * Provides common functionality for all queue processors:
 * - Retry logic with exponential backoff
 * - Dead letter queue integration for failed jobs
 * - Circuit breaker awareness
 * - Structured logging
 */
abstract class AbstractQueueProcessor implements QueueProcessorInterface {

	/**
	 * Default maximum retry attempts.
	 */
	protected const DEFAULT_MAX_RETRIES = 3;

	/**
	 * Base delay in seconds for exponential backoff.
	 */
	protected const BASE_RETRY_DELAY = 30;

	/**
	 * Backoff multiplier for exponential retry delay.
	 */
	protected const BACKOFF_MULTIPLIER = 3;

	/**
	 * Constructor.
	 *
	 * @param PriorityQueue   $priorityQueue   Priority queue for retries.
	 * @param DeadLetterQueue $deadLetterQueue Dead letter queue for failures.
	 */
	public function __construct(
		protected PriorityQueue $priorityQueue,
		protected DeadLetterQueue $deadLetterQueue
	) {
	}

	/**
	 * Execute the processor with full error handling.
	 *
	 * This method wraps the actual processing logic with:
	 * - Payload unwrapping
	 * - Error handling
	 * - Retry logic
	 * - Dead letter queue routing
	 *
	 * @param array<string, mixed> $rawPayload The raw job payload from Action Scheduler.
	 * @return void
	 */
	public function execute( array $rawPayload ): void {
		// Unwrap the payload to extract user args and metadata.
		try {
			$unwrapped = PriorityQueue::unwrapPayload( $rawPayload );
		} catch ( \Throwable $e ) {
			$this->logError(
				'Invalid queue payload',
				[
					'error'        => $e->getMessage(),
					'payload_keys' => array_keys( $rawPayload ),
				]
			);
			$this->moveToDeadLetterQueue( $rawPayload, DeadLetterQueue::REASON_EXCEPTION, $e->getMessage(), [] );
			return;
		}

		$payload = $unwrapped['args'];
		$meta    = $unwrapped['meta'];

		$attempt = $meta['attempt'] ?? 1;

		$this->logDebug(
			'Processing job',
			[
				'attempt'      => $attempt,
				'payload_keys' => array_keys( $payload ),
			]
		);

		try {
			// Check circuit breaker before processing.
			if ( $this->isCircuitOpen() ) {
				$this->handleCircuitOpen( $payload, $meta );
				return;
			}

			// Execute the actual processing logic.
			$this->process( $payload );

			$this->logInfo(
				'Job processed successfully',
				[
					'attempt' => $attempt,
				]
			);

		} catch ( \Throwable $exception ) {
			$this->handleException( $exception, $rawPayload, $payload, $meta, $attempt );
		}
	}

	/**
	 * Handle an exception during processing.
	 *
	 * @param \Throwable           $exception  The caught exception.
	 * @param array<string, mixed> $rawPayload Original raw payload.
	 * @param array<string, mixed> $payload    Unwrapped user payload.
	 * @param array<string, mixed> $meta       Job metadata.
	 * @param int                  $attempt    Current attempt number.
	 * @return void
	 */
	protected function handleException(
		\Throwable $exception,
		array $rawPayload,
		array $payload,
		array $meta,
		int $attempt
	): void {
		$this->logError(
			'Job processing failed',
			[
				'attempt'   => $attempt,
				'exception' => $exception->getMessage(),
				'trace'     => $exception->getTraceAsString(),
			]
		);

		// Check if we should retry.
		if ( $this->shouldRetry( $exception ) && $attempt < $this->getMaxRetries() ) {
			$this->scheduleRetry( $rawPayload, $attempt );
			return;
		}

		// Move to dead letter queue.
		$reason = $attempt >= $this->getMaxRetries()
			? DeadLetterQueue::REASON_MAX_RETRIES
			: DeadLetterQueue::REASON_EXCEPTION;

		$this->moveToDeadLetterQueue( $rawPayload, $reason, $exception->getMessage(), $meta );
	}

	/**
	 * Handle circuit breaker being open.
	 *
	 * @param array<string, mixed> $payload User payload.
	 * @param array<string, mixed> $meta       Job metadata.
	 * @return void
	 */
	protected function handleCircuitOpen( array $payload, array $meta ): void {
		$this->logWarning(
			'Circuit breaker is open, rescheduling job',
			[
				'reschedule_delay' => 60,
			]
		);

		// Reschedule for later when circuit might be closed.
		$priority = $meta['priority'] ?? PriorityQueue::PRIORITY_NORMAL;

		$this->priorityQueue->schedule(
			$this->getHookName(),
			$payload,
			$priority,
			60 // 1 minute delay
		);
	}

	/**
	 * Schedule a retry with exponential backoff.
	 *
	 * @param array<string, mixed> $rawPayload Original raw payload.
	 * @param int                  $attempt    Current attempt number.
	 * @return void
	 */
	protected function scheduleRetry( array $rawPayload, int $attempt ): void {
		$delay = $this->getRetryDelay( $attempt );

		$this->logInfo(
			'Scheduling retry',
			[
				'attempt'      => $attempt,
				'next_attempt' => $attempt + 1,
				'delay'        => $delay,
			]
		);

		$this->priorityQueue->retry(
			$this->getHookName(),
			$rawPayload,
			$attempt,
			$this->getMaxRetries()
		);
	}

	/**
	 * Move a failed job to the dead letter queue.
	 *
	 * @param array<string, mixed> $rawPayload Original raw payload.
	 * @param string               $reason  Failure reason.
	 * @param string|null          $error   Error message.
	 * @param array<string, mixed> $meta    Job metadata.
	 * @return void
	 */
	protected function moveToDeadLetterQueue(
		array $rawPayload,
		string $reason,
		?string $error,
		array $meta
	): void {
		$this->logWarning(
			'Moving job to dead letter queue',
			[
				'reason' => $reason,
				'error'  => $error,
			]
		);

		// Add metadata for DLQ.
		$dlqPayload = $rawPayload;
		if ( ! isset( $dlqPayload['_wch_meta'] ) || ! is_array( $dlqPayload['_wch_meta'] ) ) {
			$dlqPayload['_wch_meta'] = $meta;
			$dlqPayload['_wch_version'] = 2;
		}

		$result = $this->deadLetterQueue->push(
			$this->getHookName(),
			$dlqPayload,
			$reason,
			$error,
			[
				'processor' => $this->getName(),
			]
		);

		if ( false === $result ) {
			$this->logError(
				'Failed to push job to dead letter queue - job data may be lost',
				[
					'payload_keys' => array_keys( $payload ),
				]
			);
		}
	}

	/**
	 * Get the maximum number of retry attempts.
	 *
	 * @return int Maximum retry count.
	 */
	public function getMaxRetries(): int {
		return static::DEFAULT_MAX_RETRIES;
	}

	/**
	 * Calculate the delay before the next retry.
	 *
	 * Uses exponential backoff: 30s, 90s, 270s, etc.
	 *
	 * @param int $attempt Current attempt number (1-based).
	 * @return int Delay in seconds.
	 */
	public function getRetryDelay( int $attempt ): int {
		return (int) ( static::BASE_RETRY_DELAY * pow( static::BACKOFF_MULTIPLIER, $attempt - 1 ) );
	}

	/**
	 * Determine if the job should be retried based on the exception.
	 *
	 * Uses the exception taxonomy to make retry decisions:
	 * - DomainException: NEVER retry (business rule violations)
	 * - ApplicationException: Check isRetryable() method (validation = no, state errors = maybe)
	 * - InfrastructureException: Check isRetryable() method (transient failures = yes, auth errors = no)
	 * - WchException: Check isRetryable() method
	 * - Standard PHP exceptions: Retry RuntimeException, NOT InvalidArgumentException/DomainException
	 *
	 * Override in subclasses for custom retry logic.
	 *
	 * @param \Throwable $exception The exception that caused the failure.
	 * @return bool True if should retry.
	 */
	public function shouldRetry( \Throwable $exception ): bool {
		// Check taxonomy-based exceptions with isRetryable() method.
		if ( $exception instanceof DomainException ) {
			// Domain exceptions are NEVER retryable (business rule violations).
			return false;
		}

		if ( $exception instanceof ApplicationException ) {
			// Application exceptions have specific retry logic.
			return $exception->isRetryable();
		}

		if ( $exception instanceof InfrastructureException ) {
			// Infrastructure exceptions have specific retry logic.
			return $exception->isRetryable();
		}

		if ( $exception instanceof WchException ) {
			// Other WchExceptions may have custom isRetryable() logic.
			return $exception->isRetryable();
		}

		// Standard PHP exceptions: Retry RuntimeException, NOT validation/domain errors.
		$noRetryExceptions = [
			\InvalidArgumentException::class,
			\DomainException::class,
		];

		foreach ( $noRetryExceptions as $noRetryClass ) {
			if ( $exception instanceof $noRetryClass ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if the circuit breaker for this processor is open.
	 *
	 * Override in subclasses to integrate with specific circuit breakers.
	 *
	 * @return bool True if circuit is open (should not process).
	 */
	protected function isCircuitOpen(): bool {
		// Default: no circuit breaker.
		return false;
	}

	/**
	 * Log a debug message.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	protected function logDebug( string $message, array $context = [] ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	protected function logInfo( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	protected function logWarning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	protected function logError( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Internal logging method.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	private function log( string $level, string $message, array $context = [] ): void {
		$context['processor'] = $this->getName();

		// Use WordPress action for logging integration.
		do_action( "wch_log_{$level}", "[{$this->getName()}] {$message}", $context );
	}
}
