<?php

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Queue;

use WhatsAppCommerceHub\Infrastructure\Logging\Logger;

/**
 * Job Dispatcher
 *
 * Dispatches and manages background jobs using Action Scheduler.
 *
 * @package WhatsAppCommerceHub\Infrastructure\Queue
 */
class JobDispatcher
{
    /**
     * Action Scheduler group name
     */
    public const GROUP_NAME = 'wch';

    /**
     * Internal system hooks that bypass capability checks
     * These are only triggered by internal WordPress hooks, not user actions
     */
    private const INTERNAL_HOOKS = [
        'wch_process_webhook_message',
        'wch_process_webhook_status',
        'wch_process_webhook_error',
        'wch_send_order_notification',
        'wch_process_abandoned_cart',
        'wch_sync_product_batch',
        'wch_cleanup_expired_carts',
        'wch_send_broadcast_batch',
    ];

    /**
     * Constructor
     */
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * Dispatch a single job immediately
     */
    public function dispatch(string $hook, array $args = [], int $priority = 10): int
    {
        // Security check
        if (!$this->canDispatchJobs($hook)) {
            $this->logger->warning('Job dispatch denied', [
                'hook' => $hook,
                'user_id' => get_current_user_id(),
            ]);
            
            return 0;
        }

        // Schedule the action immediately
        $actionId = as_enqueue_async_action(
            $hook,
            $args,
            self::GROUP_NAME,
            true,
            $priority
        );

        $this->logger->debug('Job dispatched', [
            'hook' => $hook,
            'action_id' => $actionId,
            'args' => $args,
        ]);

        return $actionId;
    }

    /**
     * Schedule a job for future execution
     */
    public function schedule(string $hook, int $timestamp, array $args = [], bool $unique = false): int
    {
        // Security check
        if (!$this->canDispatchJobs($hook)) {
            $this->logger->warning('Job schedule denied', [
                'hook' => $hook,
                'user_id' => get_current_user_id(),
            ]);
            
            return 0;
        }

        $actionId = as_schedule_single_action(
            $timestamp,
            $hook,
            $args,
            self::GROUP_NAME,
            $unique
        );

        $this->logger->debug('Job scheduled', [
            'hook' => $hook,
            'timestamp' => $timestamp,
            'action_id' => $actionId,
        ]);

        return $actionId;
    }

    /**
     * Schedule a recurring job
     */
    public function scheduleRecurring(string $hook, int $timestamp, int $interval, array $args = []): int
    {
        // Security check
        if (!$this->canDispatchJobs($hook)) {
            $this->logger->warning('Recurring job schedule denied', [
                'hook' => $hook,
                'user_id' => get_current_user_id(),
            ]);
            
            return 0;
        }

        $actionId = as_schedule_recurring_action(
            $timestamp,
            $interval,
            $hook,
            $args,
            self::GROUP_NAME
        );

        $this->logger->info('Recurring job scheduled', [
            'hook' => $hook,
            'interval' => $interval,
            'action_id' => $actionId,
        ]);

        return $actionId;
    }

    /**
     * Dispatch a batch of jobs
     */
    public function dispatchBatch(string $hook, array $items, int $batchSize = 10): int
    {
        if (!$this->canDispatchJobs($hook)) {
            $this->logger->warning('Batch dispatch denied', [
                'hook' => $hook,
                'user_id' => get_current_user_id(),
            ]);
            
            return 0;
        }

        $batches = array_chunk($items, $batchSize);
        $count = 0;

        foreach ($batches as $index => $batch) {
            $args = [
                'batch' => $batch,
                'batch_index' => $index,
                'total_batches' => count($batches),
            ];

            as_enqueue_async_action($hook, $args, self::GROUP_NAME);
            $count++;
        }

        $this->logger->info('Batch jobs dispatched', [
            'hook' => $hook,
            'total_items' => count($items),
            'batches' => $count,
            'batch_size' => $batchSize,
        ]);

        return $count;
    }

    /**
     * Cancel a scheduled job
     */
    public function cancel(string $hook, array $args = []): int
    {
        $count = as_unschedule_action($hook, $args, self::GROUP_NAME);

        $this->logger->debug('Jobs cancelled', [
            'hook' => $hook,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Cancel all jobs for a specific hook
     */
    public function cancelAll(string $hook): int
    {
        $actions = as_get_scheduled_actions([
            'hook' => $hook,
            'group' => self::GROUP_NAME,
            'per_page' => -1,
        ]);

        $count = 0;
        foreach ($actions as $action) {
            as_unschedule_action($hook, $action->get_args(), self::GROUP_NAME);
            $count++;
        }

        $this->logger->info('All jobs cancelled for hook', [
            'hook' => $hook,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Check if a job is scheduled
     */
    public function isScheduled(string $hook, array $args = []): bool
    {
        return as_next_scheduled_action($hook, $args, self::GROUP_NAME) !== false;
    }

    /**
     * Get next scheduled time for a job
     */
    public function getNextScheduledTime(string $hook, array $args = []): ?int
    {
        $timestamp = as_next_scheduled_action($hook, $args, self::GROUP_NAME);
        
        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * Get pending job count for a hook
     */
    public function getPendingCount(string $hook): int
    {
        $actions = as_get_scheduled_actions([
            'hook' => $hook,
            'status' => 'pending',
            'group' => self::GROUP_NAME,
            'per_page' => -1,
        ]);

        return count($actions);
    }

    /**
     * Retry a failed job
     */
    public function retry(int $actionId, int $delay = 60): int
    {
        $action = \ActionScheduler::store()->fetch_action($actionId);
        
        if (!$action) {
            $this->logger->error('Action not found for retry', ['action_id' => $actionId]);
            return 0;
        }

        // Schedule retry with delay
        $newActionId = as_schedule_single_action(
            time() + $delay,
            $action->get_hook(),
            array_merge(
                $action->get_args(),
                ['retry_count' => ($action->get_args()['retry_count'] ?? 0) + 1]
            ),
            self::GROUP_NAME
        );

        $this->logger->info('Job retry scheduled', [
            'original_action_id' => $actionId,
            'new_action_id' => $newActionId,
            'delay' => $delay,
        ]);

        return $newActionId;
    }

    /**
     * Check if the current context is allowed to dispatch jobs
     *
     * SECURITY: Returns true if:
     * - Running from CLI (WP-CLI, cron)
     * - Running from Action Scheduler callback
     * - User has manage_woocommerce capability
     * - Hook is in the internal whitelist
     */
    private function canDispatchJobs(string $hook = ''): bool
    {
        // Allow CLI context (WP-CLI, cron jobs)
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        // Allow cron context
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        // Allow Action Scheduler callbacks (internal job chaining)
        if (did_action('action_scheduler_run_queue')) {
            return true;
        }

        // Allow internal hooks without user check (system events)
        if (!empty($hook) && in_array($hook, self::INTERNAL_HOOKS, true)) {
            return true;
        }

        // Otherwise, require admin capability
        return current_user_can('manage_woocommerce');
    }

    /**
     * Get job statistics
     */
    public function getStats(string $hook = ''): array
    {
        $query = [
            'group' => self::GROUP_NAME,
            'per_page' => -1,
        ];

        if (!empty($hook)) {
            $query['hook'] = $hook;
        }

        $pending = as_get_scheduled_actions(array_merge($query, ['status' => 'pending']));
        $running = as_get_scheduled_actions(array_merge($query, ['status' => 'in-progress']));
        $failed = as_get_scheduled_actions(array_merge($query, ['status' => 'failed']));
        $complete = as_get_scheduled_actions(array_merge($query, ['status' => 'complete']));

        return [
            'pending' => count($pending),
            'running' => count($running),
            'failed' => count($failed),
            'complete' => count($complete),
            'total' => count($pending) + count($running) + count($failed) + count($complete),
        ];
    }
}
