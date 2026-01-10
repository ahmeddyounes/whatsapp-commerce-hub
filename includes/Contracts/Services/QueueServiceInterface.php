<?php
/**
 * Queue Service Interface
 *
 * Contract for async job queue operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface QueueServiceInterface
 *
 * Defines the contract for queue management operations.
 */
interface QueueServiceInterface {

	/**
	 * Queue priority groups.
	 */
	public const PRIORITY_CRITICAL    = 'wch-critical';
	public const PRIORITY_URGENT      = 'wch-urgent';
	public const PRIORITY_NORMAL      = 'wch-normal';
	public const PRIORITY_BULK        = 'wch-bulk';
	public const PRIORITY_MAINTENANCE = 'wch-maintenance';

	/**
	 * Dispatch a job to the queue.
	 *
	 * @param string $hook     Action hook name.
	 * @param array  $args     Job arguments.
	 * @param string $priority Priority group (use PRIORITY_* constants).
	 * @return int|false Action ID or false on failure.
	 */
	public function dispatch( string $hook, array $args = array(), string $priority = self::PRIORITY_NORMAL ): int|false;

	/**
	 * Schedule a job for later execution.
	 *
	 * @param string $hook      Action hook name.
	 * @param array  $args      Job arguments.
	 * @param int    $timestamp Unix timestamp for execution.
	 * @param string $priority  Priority group.
	 * @return int|false Action ID or false on failure.
	 */
	public function schedule( string $hook, array $args, int $timestamp, string $priority = self::PRIORITY_NORMAL ): int|false;

	/**
	 * Schedule a recurring job.
	 *
	 * @param string $hook      Action hook name.
	 * @param array  $args      Job arguments.
	 * @param int    $interval  Interval in seconds.
	 * @param string $priority  Priority group.
	 * @return int|false Action ID or false on failure.
	 */
	public function scheduleRecurring( string $hook, array $args, int $interval, string $priority = self::PRIORITY_NORMAL ): int|false;

	/**
	 * Cancel a scheduled job.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Job arguments (must match).
	 * @return bool Success status.
	 */
	public function cancel( string $hook, array $args = array() ): bool;

	/**
	 * Cancel all jobs for a hook.
	 *
	 * @param string $hook Action hook name.
	 * @return int Number of jobs cancelled.
	 */
	public function cancelAll( string $hook ): int;

	/**
	 * Check if a job is scheduled.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Job arguments.
	 * @return bool True if scheduled.
	 */
	public function isScheduled( string $hook, array $args = array() ): bool;

	/**
	 * Get next scheduled time for a job.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Job arguments.
	 * @return int|null Unix timestamp or null if not scheduled.
	 */
	public function getNextScheduled( string $hook, array $args = array() ): ?int;

	/**
	 * Get pending jobs for a hook.
	 *
	 * @param string $hook  Action hook name.
	 * @param int    $limit Maximum jobs to return.
	 * @return array Array of job data.
	 */
	public function getPendingJobs( string $hook, int $limit = 100 ): array;

	/**
	 * Get failed jobs.
	 *
	 * @param string|null $hook  Filter by hook name.
	 * @param int         $limit Maximum jobs to return.
	 * @return array Array of failed job data.
	 */
	public function getFailedJobs( ?string $hook = null, int $limit = 100 ): array;

	/**
	 * Retry a failed job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool Success status.
	 */
	public function retryJob( int $job_id ): bool;

	/**
	 * Dismiss a failed job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool Success status.
	 */
	public function dismissJob( int $job_id ): bool;

	/**
	 * Get queue statistics.
	 *
	 * @return array{pending: int, running: int, completed: int, failed: int}
	 */
	public function getStats(): array;

	/**
	 * Get stats by priority group.
	 *
	 * @return array<string, array{pending: int, running: int}>
	 */
	public function getStatsByPriority(): array;

	/**
	 * Clear completed jobs older than given age.
	 *
	 * @param int $age_seconds Age threshold in seconds.
	 * @return int Number of jobs cleared.
	 */
	public function clearCompleted( int $age_seconds = 86400 ): int;

	/**
	 * Clear failed jobs older than given age.
	 *
	 * @param int $age_seconds Age threshold in seconds.
	 * @return int Number of jobs cleared.
	 */
	public function clearFailed( int $age_seconds = 604800 ): int;

	/**
	 * Register action hook handler.
	 *
	 * @param string   $hook     Action hook name.
	 * @param callable $callback Handler callback.
	 * @return void
	 */
	public function registerHandler( string $hook, callable $callback ): void;

	/**
	 * Check if queue is healthy.
	 *
	 * @return array{healthy: bool, issues: array}
	 */
	public function healthCheck(): array;
}
