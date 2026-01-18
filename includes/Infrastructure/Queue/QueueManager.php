<?php
/**
 * Queue Manager
 *
 * Manages async job processing using Action Scheduler.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Queue;

use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Queue\DeadLetterQueue;

/**
 * Class QueueManager
 *
 * Manages async job processing using Action Scheduler bundled with WooCommerce.
 *
 * @deprecated Since 3.1.0 - Now delegates to PriorityQueue for all job scheduling.
 *             Use PriorityQueue directly for new code.
 */
class QueueManager {
	/**
	 * Priority queue instance
	 */
	private PriorityQueue $priority_queue;

	/**
	 * Registered action hooks
	 */
	private array $registeredHooks = [
		'wch_process_sync_job',
		'wch_send_broadcast_batch',
		'wch_cleanup_expired_carts',
		'wch_process_abandoned_cart',
		'wch_retry_failed_message',
		'wch_process_webhook_messages',
		'wch_process_webhook_statuses',
		'wch_process_webhook_errors',
		'wch_sync_single_product',
		'wch_sync_product_batch',
		'wch_send_order_notification',
		'wch_process_stock_sync',
		'wch_detect_stock_discrepancies',
		'wch_schedule_recovery_reminders',
		'wch_process_recovery_message',
		'wch_process_reengagement_campaigns',
		'wch_send_reengagement_message',
		'wch_check_back_in_stock',
		'wch_check_price_drops',
	];

	/**
	 * Constructor
	 */
	public function __construct(
		private readonly Logger $logger,
		?PriorityQueue $priority_queue = null
	) {
		// Initialize priority queue if not provided
		if ( null === $priority_queue ) {
			$dlq = new DeadLetterQueue();
			$this->priority_queue = new PriorityQueue( $dlq );
		} else {
			$this->priority_queue = $priority_queue;
		}

		$this->init();
	}

	/**
	 * Initialize the queue system
	 */
	private function init(): void {
		// Register action hooks on init
		add_action( 'init', [ $this, 'registerActionHooks' ] );

		// Register custom cron schedules
		add_filter( 'cron_schedules', [ $this, 'addCustomCronSchedules' ] );

		// Schedule recurring jobs
		add_action( 'init', [ $this, 'scheduleRecurringJobs' ] );
	}

	/**
	 * Register WordPress action hooks for job processing
	 */
	public function registerActionHooks(): void {
		foreach ( $this->registeredHooks as $hook ) {
			if ( ! has_action( $hook ) ) {
				add_action( $hook, [ $this, 'processJob' ], 10, 1 );
			}
		}

		$this->logger->debug(
			'Queue action hooks registered',
			[
				'hooks_count' => count( $this->registeredHooks ),
			]
		);
	}

	/**
	 * Add custom cron schedules
	 */
	public function addCustomCronSchedules( array $schedules ): array {
		if ( ! isset( $schedules['wch_hourly'] ) ) {
			$schedules['wch_hourly'] = [
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'Every Hour (WhatsApp Commerce Hub)', 'whatsapp-commerce-hub' ),
			];
		}

		if ( ! isset( $schedules['wch_fifteen_minutes'] ) ) {
			$schedules['wch_fifteen_minutes'] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes (WhatsApp Commerce Hub)', 'whatsapp-commerce-hub' ),
			];
		}

		return $schedules;
	}

	/**
	 * Schedule recurring jobs
	 */
	public function scheduleRecurringJobs(): void {
		// Cleanup expired carts hourly - use MAINTENANCE priority
		if ( ! $this->priority_queue->isPending( 'wch_cleanup_expired_carts' ) ) {
			$this->priority_queue->scheduleRecurring(
				'wch_cleanup_expired_carts',
				[],
				HOUR_IN_SECONDS,
				PriorityQueue::PRIORITY_MAINTENANCE
			);

			$this->logger->info( 'Scheduled recurring job via PriorityQueue: cleanup_expired_carts' );
		}

		// Check stock discrepancies daily - use MAINTENANCE priority
		if ( ! $this->priority_queue->isPending( 'wch_detect_stock_discrepancies' ) ) {
			$this->priority_queue->scheduleRecurring(
				'wch_detect_stock_discrepancies',
				[],
				DAY_IN_SECONDS,
				PriorityQueue::PRIORITY_MAINTENANCE
			);

			$this->logger->info( 'Scheduled recurring job via PriorityQueue: detect_stock_discrepancies' );
		}

		// Process abandoned cart recovery every 15 minutes - use NORMAL priority
		if ( ! $this->priority_queue->isPending( 'wch_schedule_recovery_reminders' ) ) {
			$this->priority_queue->scheduleRecurring(
				'wch_schedule_recovery_reminders',
				[],
				15 * MINUTE_IN_SECONDS,
				PriorityQueue::PRIORITY_NORMAL
			);

			$this->logger->info( 'Scheduled recurring job via PriorityQueue: schedule_recovery_reminders' );
		}
	}

	/**
	 * Schedule a bulk action for a list of items.
	 *
	 * @param string $hook     Action hook name.
	 * @param array  $items    Items to schedule.
	 * @param array  $baseArgs Shared arguments for each action.
	 * @param string $itemKey  Optional key to assign scalar item values.
	 * @return int Number of scheduled actions.
	 */
	public function schedule_bulk_action( string $hook, array $items, array $baseArgs = [], string $itemKey = '' ): int {
		if ( empty( $items ) ) {
			return 0;
		}

		$itemKey = $itemKey ?: $this->resolveBulkItemKey( $hook );
		$scheduled = 0;

		// Use BULK priority for batch operations
		$priority = PriorityQueue::PRIORITY_BULK;

		foreach ( $items as $item ) {
			$args = $baseArgs;

			if ( is_array( $item ) ) {
				$args = array_merge( $args, $item );
			} elseif ( '' !== $itemKey ) {
				$args[ $itemKey ] = $item;
			} else {
				$args[] = $item;
			}

			$actionId = $this->priority_queue->schedule( $hook, $args, $priority, 0 );

			if ( false !== $actionId ) {
				++$scheduled;
			}
		}

		if ( $scheduled > 0 ) {
			$this->logger->info(
				'Bulk actions scheduled via PriorityQueue',
				[
					'hook'     => $hook,
					'count'    => $scheduled,
					'priority' => $priority,
				]
			);
		}

		return $scheduled;
	}

	/**
	 * Resolve the default item key for bulk scheduling.
	 *
	 * @param string $hook Action hook name.
	 * @return string
	 */
	private function resolveBulkItemKey( string $hook ): string {
		return match ( $hook ) {
			'wch_sync_product', 'wch_sync_single_product' => 'product_id',
			default => 'item',
		};
	}

	/**
	 * Process a queued job
	 */
	public function processJob( array $args = [] ): void {
		$hook = current_action();

		// Use compatibility unwrapper to handle both v2 wrapped and legacy unwrapped payloads
		$unwrapped = PriorityQueue::unwrapPayloadCompat( $args );
		$unwrapped_args = $unwrapped['args'];

		$this->logger->debug(
			"Processing queue job: {$hook}",
			[
				'args'     => $unwrapped_args,
				'is_v2'    => PriorityQueue::isWrappedPayload( $args ),
			]
		);

		// Dispatch to appropriate handler based on hook name
		do_action( "wch_queue_process_{$hook}", $unwrapped_args );
	}

	/**
	 * Retry a failed message
	 */
	public function retryFailedMessage( array $args ): void {
		$messageId  = $args['message_id'] ?? '';
		$retryCount = $args['retry_count'] ?? 0;

		if ( empty( $messageId ) ) {
			return;
		}

		$this->logger->info(
			'Retrying failed message',
			[
				'message_id'  => $messageId,
				'retry_count' => $retryCount,
			]
		);

		// Trigger message retry
		do_action( 'wch_retry_message', $messageId, $retryCount );
	}

	/**
	 * Process webhook messages
	 */
	public function processWebhookMessages( array $args ): void {
		$messages = $args['messages'] ?? [];
		$metadata = $args['metadata'] ?? [];

		foreach ( $messages as $message ) {
			do_action( 'wch_process_incoming_message', $message, $metadata );
		}

		$this->logger->info(
			'Processed webhook messages batch',
			[
				'count' => count( $messages ),
			]
		);
	}

	/**
	 * Process webhook status updates
	 */
	public function processWebhookStatuses( array $args ): void {
		$statuses = $args['statuses'] ?? [];
		$metadata = $args['metadata'] ?? [];

		foreach ( $statuses as $status ) {
			do_action( 'wch_process_status_update', $status, $metadata );
		}

		$this->logger->debug(
			'Processed webhook statuses batch',
			[
				'count' => count( $statuses ),
			]
		);
	}

	/**
	 * Process webhook errors
	 */
	public function processWebhookErrors( array $args ): void {
		$errors   = $args['errors'] ?? [];
		$metadata = $args['metadata'] ?? [];

		foreach ( $errors as $error ) {
			do_action( 'wch_process_webhook_error', $error, $metadata );
		}

		$this->logger->warning(
			'Processed webhook errors batch',
			[
				'count' => count( $errors ),
			]
		);
	}

	/**
	 * Send order notification
	 */
	public function sendOrderNotification( array $args ): void {
		$orderId          = $args['order_id'] ?? 0;
		$notificationType = $args['notification_type'] ?? 'order_confirmation';

		if ( empty( $orderId ) ) {
			return;
		}

		$this->logger->info(
			'Sending order notification',
			[
				'order_id' => $orderId,
				'type'     => $notificationType,
			]
		);

		do_action( 'wch_send_notification', $orderId, $notificationType );
	}

	/**
	 * Get registered hooks
	 */
	public function getRegisteredHooks(): array {
		return $this->registeredHooks;
	}

	/**
	 * Get queue statistics
	 */
	public function getQueueStats(): array {
		$pending = as_get_scheduled_actions(
			[
				'status'   => 'pending',
				'group'    => 'wch',
				'per_page' => -1,
			]
		);

		$running = as_get_scheduled_actions(
			[
				'status'   => 'in-progress',
				'group'    => 'wch',
				'per_page' => -1,
			]
		);

		$failed = as_get_scheduled_actions(
			[
				'status'   => 'failed',
				'group'    => 'wch',
				'per_page' => -1,
			]
		);

		return [
			'pending' => count( $pending ),
			'running' => count( $running ),
			'failed'  => count( $failed ),
			'total'   => count( $pending ) + count( $running ) + count( $failed ),
		];
	}

	/**
	 * Clear all pending jobs
	 */
	public function clearPendingJobs(): int {
		$actions = as_get_scheduled_actions(
			[
				'status'   => 'pending',
				'group'    => 'wch',
				'per_page' => -1,
			]
		);

		$count = 0;
		foreach ( $actions as $action ) {
			as_unschedule_action( $action->get_hook(), $action->get_args(), 'wch' );
			++$count;
		}

		$this->logger->warning( 'Cleared pending queue jobs', [ 'count' => $count ] );

		return $count;
	}
}
