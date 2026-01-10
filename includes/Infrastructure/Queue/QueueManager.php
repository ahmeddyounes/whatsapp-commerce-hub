<?php

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Queue;

use WhatsAppCommerceHub\Infrastructure\Logging\Logger;

/**
 * Queue Manager
 *
 * Manages async job processing using Action Scheduler bundled with WooCommerce.
 *
 * @package WhatsAppCommerceHub\Infrastructure\Queue
 */
class QueueManager
{
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
        private readonly Logger $logger
    ) {
        $this->init();
    }

    /**
     * Initialize the queue system
     */
    private function init(): void
    {
        // Register action hooks on init
        add_action('init', [$this, 'registerActionHooks']);

        // Register custom cron schedules
        add_filter('cron_schedules', [$this, 'addCustomCronSchedules']);

        // Schedule recurring jobs
        add_action('init', [$this, 'scheduleRecurringJobs']);
    }

    /**
     * Register WordPress action hooks for job processing
     */
    public function registerActionHooks(): void
    {
        foreach ($this->registeredHooks as $hook) {
            if (!has_action($hook)) {
                add_action($hook, [$this, 'processJob'], 10, 1);
            }
        }

        $this->logger->debug('Queue action hooks registered', [
            'hooks_count' => count($this->registeredHooks),
        ]);
    }

    /**
     * Add custom cron schedules
     */
    public function addCustomCronSchedules(array $schedules): array
    {
        if (!isset($schedules['wch_hourly'])) {
            $schedules['wch_hourly'] = [
                'interval' => HOUR_IN_SECONDS,
                'display' => __('Every Hour (WhatsApp Commerce Hub)', 'whatsapp-commerce-hub'),
            ];
        }

        if (!isset($schedules['wch_fifteen_minutes'])) {
            $schedules['wch_fifteen_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 Minutes (WhatsApp Commerce Hub)', 'whatsapp-commerce-hub'),
            ];
        }

        return $schedules;
    }

    /**
     * Schedule recurring jobs
     */
    public function scheduleRecurringJobs(): void
    {
        // Cleanup expired carts hourly
        if (!as_next_scheduled_action('wch_cleanup_expired_carts')) {
            as_schedule_recurring_action(
                time(),
                HOUR_IN_SECONDS,
                'wch_cleanup_expired_carts',
                [],
                'wch'
            );

            $this->logger->info('Scheduled recurring job: cleanup_expired_carts');
        }

        // Check stock discrepancies daily
        if (!as_next_scheduled_action('wch_detect_stock_discrepancies')) {
            as_schedule_recurring_action(
                time(),
                DAY_IN_SECONDS,
                'wch_detect_stock_discrepancies',
                [],
                'wch'
            );

            $this->logger->info('Scheduled recurring job: detect_stock_discrepancies');
        }

        // Process abandoned cart recovery every 15 minutes
        if (!as_next_scheduled_action('wch_schedule_recovery_reminders')) {
            as_schedule_recurring_action(
                time(),
                15 * MINUTE_IN_SECONDS,
                'wch_schedule_recovery_reminders',
                [],
                'wch'
            );

            $this->logger->info('Scheduled recurring job: schedule_recovery_reminders');
        }
    }

    /**
     * Process a queued job
     */
    public function processJob(array $args = []): void
    {
        $hook = current_action();
        
        $this->logger->debug("Processing queue job: {$hook}", [
            'args' => $args,
        ]);

        // Dispatch to appropriate handler based on hook name
        do_action("wch_queue_process_{$hook}", $args);
    }

    /**
     * Retry a failed message
     */
    public function retryFailedMessage(array $args): void
    {
        $messageId = $args['message_id'] ?? '';
        $retryCount = $args['retry_count'] ?? 0;

        if (empty($messageId)) {
            return;
        }

        $this->logger->info('Retrying failed message', [
            'message_id' => $messageId,
            'retry_count' => $retryCount,
        ]);

        // Trigger message retry
        do_action('wch_retry_message', $messageId, $retryCount);
    }

    /**
     * Process webhook messages
     */
    public function processWebhookMessages(array $args): void
    {
        $messages = $args['messages'] ?? [];
        $metadata = $args['metadata'] ?? [];

        foreach ($messages as $message) {
            do_action('wch_process_incoming_message', $message, $metadata);
        }

        $this->logger->info('Processed webhook messages batch', [
            'count' => count($messages),
        ]);
    }

    /**
     * Process webhook status updates
     */
    public function processWebhookStatuses(array $args): void
    {
        $statuses = $args['statuses'] ?? [];
        $metadata = $args['metadata'] ?? [];

        foreach ($statuses as $status) {
            do_action('wch_process_status_update', $status, $metadata);
        }

        $this->logger->debug('Processed webhook statuses batch', [
            'count' => count($statuses),
        ]);
    }

    /**
     * Process webhook errors
     */
    public function processWebhookErrors(array $args): void
    {
        $errors = $args['errors'] ?? [];
        $metadata = $args['metadata'] ?? [];

        foreach ($errors as $error) {
            do_action('wch_process_webhook_error', $error, $metadata);
        }

        $this->logger->warning('Processed webhook errors batch', [
            'count' => count($errors),
        ]);
    }

    /**
     * Send order notification
     */
    public function sendOrderNotification(array $args): void
    {
        $orderId = $args['order_id'] ?? 0;
        $notificationType = $args['notification_type'] ?? 'order_confirmation';

        if (empty($orderId)) {
            return;
        }

        $this->logger->info('Sending order notification', [
            'order_id' => $orderId,
            'type' => $notificationType,
        ]);

        do_action('wch_send_notification', $orderId, $notificationType);
    }

    /**
     * Get registered hooks
     */
    public function getRegisteredHooks(): array
    {
        return $this->registeredHooks;
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        $pending = as_get_scheduled_actions([
            'status' => 'pending',
            'group' => 'wch',
            'per_page' => -1,
        ]);

        $running = as_get_scheduled_actions([
            'status' => 'in-progress',
            'group' => 'wch',
            'per_page' => -1,
        ]);

        $failed = as_get_scheduled_actions([
            'status' => 'failed',
            'group' => 'wch',
            'per_page' => -1,
        ]);

        return [
            'pending' => count($pending),
            'running' => count($running),
            'failed' => count($failed),
            'total' => count($pending) + count($running) + count($failed),
        ];
    }

    /**
     * Clear all pending jobs
     */
    public function clearPendingJobs(): int
    {
        $actions = as_get_scheduled_actions([
            'status' => 'pending',
            'group' => 'wch',
            'per_page' => -1,
        ]);

        $count = 0;
        foreach ($actions as $action) {
            as_unschedule_action($action->get_hook(), $action->get_args(), 'wch');
            $count++;
        }

        $this->logger->warning('Cleared pending queue jobs', ['count' => $count]);

        return $count;
    }
}
