<?php
/**
 * Queue Processor Interface
 *
 * Defines the contract for all queue job processors.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Queue\Contracts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface QueueProcessorInterface
 *
 * All queue processors must implement this interface to ensure
 * consistent behavior across the queue system.
 */
interface QueueProcessorInterface {

	/**
	 * Process a job payload.
	 *
	 * @param array<string, mixed> $payload The job data to process.
	 * @return void
	 * @throws \Exception If processing fails and should be retried.
	 */
	public function process( array $payload ): void;

	/**
	 * Get the maximum number of retry attempts for this processor.
	 *
	 * @return int The maximum retry count.
	 */
	public function getMaxRetries(): int;

	/**
	 * Calculate the delay before the next retry attempt.
	 *
	 * @param int $attempt The current attempt number (1-based).
	 * @return int The delay in seconds before retrying.
	 */
	public function getRetryDelay( int $attempt ): int;

	/**
	 * Determine if a failed job should be retried based on the exception.
	 *
	 * @param \Throwable $exception The exception that caused the failure.
	 * @return bool True if the job should be retried, false otherwise.
	 */
	public function shouldRetry( \Throwable $exception ): bool;

	/**
	 * Get the processor's unique identifier/name.
	 *
	 * @return string The processor name.
	 */
	public function getName(): string;

	/**
	 * Get the Action Scheduler hook name for this processor.
	 *
	 * @return string The hook name.
	 */
	public function getHookName(): string;
}
