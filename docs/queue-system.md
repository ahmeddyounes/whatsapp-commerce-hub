# Queue System and Payload Format

Complete documentation for the WhatsApp Commerce Hub priority queue system, including the unified v2 payload format, extension points, and best practices.

## Table of Contents

- [Overview](#overview)
- [Queue Architecture](#queue-architecture)
- [Unified Payload Format v2](#unified-payload-format-v2)
- [Priority Queue](#priority-queue)
- [Queue Service API](#queue-service-api)
- [Extension Points](#extension-points)
- [Creating Custom Processors](#creating-custom-processors)
- [Advanced Usage](#advanced-usage)
- [Monitoring and Operations](#monitoring-and-operations)
- [Best Practices](#best-practices)

## Overview

The queue system provides reliable, priority-based asynchronous job processing with:

- **Priority-Based Scheduling**: 5 priority levels with different rate limits
- **Unified Payload Format**: Standardized v2 format with metadata wrapping
- **Retry Logic**: Exponential backoff with configurable retry strategies
- **Dead Letter Queue**: Capture and replay failed jobs
- **Idempotency**: Prevent duplicate processing
- **Extensibility**: Hooks and interfaces for customization

## Queue Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                    QUEUE SYSTEM ARCHITECTURE                  │
└──────────────────────────────────────────────────────────────┘

Application Code
       ↓
QueueService::dispatch()
       ↓
PriorityQueue::schedule()
       ↓
Wrap Payload (v2 format)
       ↓
Action Scheduler (WordPress)
       ├─ wch-critical (1000/min)
       ├─ wch-urgent (100/min)
       ├─ wch-normal (50/min)
       ├─ wch-bulk (20/min)
       └─ wch-maintenance (10/min)
       ↓
Worker Process
       ↓
AbstractQueueProcessor::execute()
       ├─ Unwrap payload
       ├─ Validate
       ├─ Process
       └─ Error handling
              ├─ Retry (if transient)
              └─ DLQ (if permanent)
```

## Unified Payload Format v2

### Format Specification

All queue jobs MUST use this standardized format:

```php
[
    '_wch_version' => 2,           // Format version (int)
    '_wch_meta' => [
        'priority'     => int,      // Priority level (1-5)
        'scheduled_at' => int,      // Unix timestamp
        'attempt'      => int,      // Retry attempt (1-indexed)
    ],
    'args' => [
        // Application-specific payload (any structure)
    ]
]
```

### Version Field

- **Purpose**: Enable future format evolution
- **Type**: Integer
- **Current Version**: 2
- **Backward Compatibility**: Version 1 (unwrapped) still supported

### Metadata Fields

#### priority

**Type**: Integer (1-5)

**Values**:
- `1` - CRITICAL: System failures, critical alerts
- `2` - URGENT: Customer messages, payments
- `3` - NORMAL: Status updates, logging
- `4` - BULK: Batch operations, broadcasts
- `5` - MAINTENANCE: Cleanup, sync, analytics

**Usage**: Determines processing queue and rate limits

#### scheduled_at

**Type**: Integer (Unix timestamp)

**Purpose**: Track when job was originally scheduled

**Usage**:
- Job scheduling
- Delay calculation
- Performance metrics

#### attempt

**Type**: Integer (1-indexed)

**Purpose**: Track retry attempts

**Usage**:
- Retry logic
- Exponential backoff calculation
- Max retry enforcement

### Args Field

**Type**: Array (any structure)

**Purpose**: Application-specific job payload

**Guidelines**:
- Keep serializable (no objects, resources, closures)
- Include all data needed for processing
- Avoid large binary data
- Use references (IDs) instead of full objects

**Example Payloads**:

```php
// Webhook message
[
    'message_id' => 'wamid.ABC123',
    'from' => '+1234567890',
    'type' => 'text',
    'text' => ['body' => 'Hello'],
    'timestamp' => 1699999999,
]

// Payment webhook
[
    'gateway' => 'stripe',
    'event_id' => 'evt_123',
    'event_type' => 'payment_intent.succeeded',
    'order_id' => 456,
]

// Broadcast message
[
    'campaign_id' => 789,
    'recipient_phone' => '+1234567890',
    'template_name' => 'cart_reminder',
    'parameters' => ['customer_name' => 'John'],
]
```

### Legacy Format Support

The system supports legacy unwrapped payloads for backward compatibility:

```php
// Legacy format (v1 - unwrapped)
[
    'message_id' => 'wamid.ABC123',
    'from' => '+1234567890',
    // ... direct payload
]

// Automatically detected and handled
```

**Detection Logic**:

```php
public static function unwrapPayload(array $payload): array {
    if (isset($payload['_wch_version']) && $payload['_wch_version'] === 2) {
        // V2 format
        return [
            'args' => $payload['args'],
            'meta' => $payload['_wch_meta'],
        ];
    }

    // Legacy format - treat entire payload as args
    return [
        'args' => $payload,
        'meta' => [
            'priority' => 3,
            'scheduled_at' => time(),
            'attempt' => 1,
        ],
    ];
}
```

## Priority Queue

### Class: PriorityQueue

**Location**: `includes/Queue/PriorityQueue.php`

**Purpose**: Low-level queue operations with priority support

### Priority Constants

```php
const PRIORITY_CRITICAL = 1;     // 1000 jobs/min
const PRIORITY_URGENT = 2;       // 100 jobs/min
const PRIORITY_NORMAL = 3;       // 50 jobs/min
const PRIORITY_BULK = 4;         // 20 jobs/min
const PRIORITY_MAINTENANCE = 5;  // 10 jobs/min
```

### Methods

#### schedule()

Schedule a one-time job.

```php
public function schedule(
    string $hook,
    array $args,
    int $priority = self::PRIORITY_NORMAL,
    int $delay = 0
): int
```

**Parameters**:
- `$hook` - Action hook name
- `$args` - User payload (will be wrapped)
- `$priority` - Priority level (1-5)
- `$delay` - Delay in seconds before execution

**Returns**: Action ID

**Example**:

```php
$queue = new PriorityQueue();

$actionId = $queue->schedule(
    'wch_process_webhook_messages',
    [
        'message_id' => 'wamid.123',
        'from' => '+1234567890',
        'text' => ['body' => 'Hello'],
    ],
    PriorityQueue::PRIORITY_URGENT,
    0  // No delay
);
```

#### scheduleRecurring()

Schedule a recurring job.

```php
public function scheduleRecurring(
    string $hook,
    array $args,
    int $interval,
    int $priority = self::PRIORITY_NORMAL
): int
```

**Parameters**:
- `$hook` - Action hook name
- `$args` - User payload (will be wrapped)
- `$interval` - Recurrence interval in seconds
- `$priority` - Priority level (1-5)

**Returns**: Action ID

**Example**:

```php
// Run every hour
$actionId = $queue->scheduleRecurring(
    'wch_cleanup_expired_carts',
    [],
    HOUR_IN_SECONDS,
    PriorityQueue::PRIORITY_MAINTENANCE
);
```

#### wrapPayload()

Wrap user payload in v2 format (private method, used internally).

```php
private function wrapPayload(
    array $args,
    int $priority,
    int $attempt = 1
): array
```

#### unwrapPayload()

Unwrap v2 payload to extract args and meta (static public method).

```php
public static function unwrapPayload(array $payload): array
```

**Returns**:
```php
[
    'args' => array,  // User payload
    'meta' => array,  // Metadata
]
```

**Example**:

```php
$unwrapped = PriorityQueue::unwrapPayload($wrappedPayload);
$userArgs = $unwrapped['args'];
$meta = $unwrapped['meta'];

echo "Priority: " . $meta['priority'];
echo "Attempt: " . $meta['attempt'];
```

### Group Mapping

Priority levels map to Action Scheduler groups:

| Priority | Value | Group | Rate Limit |
|----------|-------|-------|------------|
| CRITICAL | 1 | wch-critical | 1000/min |
| URGENT | 2 | wch-urgent | 100/min |
| NORMAL | 3 | wch-normal | 50/min |
| BULK | 4 | wch-bulk | 20/min |
| MAINTENANCE | 5 | wch-maintenance | 10/min |

## Queue Service API

### Class: QueueService

**Location**: `includes/Application/Services/QueueService.php`

**Purpose**: High-level queue API for application use

### Priority Constants (String)

```php
const PRIORITY_CRITICAL = 'critical';
const PRIORITY_URGENT = 'urgent';
const PRIORITY_NORMAL = 'normal';
const PRIORITY_BULK = 'bulk';
const PRIORITY_MAINTENANCE = 'maintenance';
```

### Scheduling Methods

#### dispatch()

Schedule job for immediate execution.

```php
public function dispatch(
    string $hook,
    array $args,
    string $priority = self::PRIORITY_NORMAL
): int
```

**Example**:

```php
$queueService = container()->get(QueueService::class);

$queueService->dispatch(
    'wch_send_message',
    ['phone' => '+1234567890', 'text' => 'Hello'],
    QueueService::PRIORITY_URGENT
);
```

#### schedule()

Schedule job for future execution.

```php
public function schedule(
    string $hook,
    array $args,
    int $timestamp,
    string $priority = self::PRIORITY_NORMAL
): int
```

**Example**:

```php
// Schedule for 1 hour from now
$queueService->schedule(
    'wch_send_reminder',
    ['cart_id' => 123],
    time() + HOUR_IN_SECONDS,
    QueueService::PRIORITY_NORMAL
);
```

#### scheduleRecurring()

Schedule recurring job.

```php
public function scheduleRecurring(
    string $hook,
    array $args,
    int $interval,
    string $priority = self::PRIORITY_NORMAL
): int
```

### Query Methods

#### isScheduled()

Check if job is scheduled.

```php
public function isScheduled(string $hook, array $args = []): bool
```

**Example**:

```php
if (!$queueService->isScheduled('wch_daily_cleanup')) {
    $queueService->scheduleRecurring(
        'wch_daily_cleanup',
        [],
        DAY_IN_SECONDS,
        QueueService::PRIORITY_MAINTENANCE
    );
}
```

#### getNextScheduled()

Get next execution time for job.

```php
public function getNextScheduled(string $hook, array $args = []): ?int
```

**Returns**: Unix timestamp or null if not scheduled

#### getPendingJobs()

Get pending jobs for a hook.

```php
public function getPendingJobs(string $hook, int $limit = 100): array
```

#### getFailedJobs()

Get failed jobs for a hook.

```php
public function getFailedJobs(string $hook, int $limit = 100): array
```

### Management Methods

#### cancel()

Cancel scheduled job.

```php
public function cancel(string $hook, array $args = []): bool
```

**Example**:

```php
$queueService->cancel('wch_send_reminder', ['cart_id' => 123]);
```

#### retryJob()

Retry a failed job.

```php
public function retryJob(int $jobId): bool
```

#### dismissJob()

Dismiss a failed job (won't be retried).

```php
public function dismissJob(int $jobId): bool
```

### Monitoring Methods

#### getStats()

Get overall queue statistics.

```php
public function getStats(): array
```

**Returns**:
```php
[
    'pending' => int,
    'failed' => int,
    'completed' => int,
]
```

#### getStatsByPriority()

Get statistics per priority level.

```php
public function getStatsByPriority(): array
```

**Returns**:
```php
[
    'critical' => ['pending' => 0, 'failed' => 0],
    'urgent' => ['pending' => 42, 'failed' => 3],
    'normal' => ['pending' => 128, 'failed' => 1],
    // ...
]
```

#### healthCheck()

Perform queue health check.

```php
public function healthCheck(): array
```

**Returns**:
```php
[
    'status' => 'healthy',      // healthy|degraded|unhealthy
    'pending_jobs' => int,
    'failed_jobs' => int,
    'oldest_pending' => int,    // Age in seconds
    'processing_lag' => int,    // Lag in seconds
]
```

### Cleanup Methods

#### clearCompleted()

Clear completed jobs older than specified age.

```php
public function clearCompleted(int $ageSeconds = 7 * DAY_IN_SECONDS): int
```

**Returns**: Number of jobs cleared

#### clearFailed()

Clear failed jobs older than specified age.

```php
public function clearFailed(int $ageSeconds = 30 * DAY_IN_SECONDS): int
```

## Extension Points

### Action Hooks

#### wch_queue_job_scheduled

Fires when job is scheduled.

```php
do_action('wch_queue_job_scheduled', $hook, $args, $priority, $actionId);
```

**Example**:

```php
add_action('wch_queue_job_scheduled', function($hook, $args, $priority, $actionId) {
    error_log("Job scheduled: {$hook} (priority: {$priority}, ID: {$actionId})");
}, 10, 4);
```

#### wch_queue_job_started

Fires when job processing starts.

```php
do_action('wch_queue_job_started', $hook, $args, $attempt);
```

#### wch_queue_job_completed

Fires when job completes successfully.

```php
do_action('wch_queue_job_completed', $hook, $args, $duration);
```

**Parameters**:
- `$duration` (float) - Processing time in seconds

#### wch_queue_job_failed

Fires when job fails and is sent to DLQ.

```php
do_action('wch_queue_job_failed', $hook, $args, $reason, $errorMessage);
```

**Example** (send alert on critical job failure):

```php
add_action('wch_queue_job_failed', function($hook, $args, $reason, $errorMessage) {
    if (strpos($hook, 'critical') !== false) {
        wp_mail(
            'admin@example.com',
            'Critical Job Failed',
            "Hook: {$hook}\nReason: {$reason}\nError: {$errorMessage}"
        );
    }
}, 10, 4);
```

#### wch_queue_job_retry

Fires when job is retried.

```php
do_action('wch_queue_job_retry', $hook, $args, $attempt, $delay);
```

**Parameters**:
- `$attempt` (int) - Retry attempt number
- `$delay` (int) - Delay before retry in seconds

### Filter Hooks

#### wch_queue_payload_wrapper

Modify payload before wrapping.

```php
apply_filters('wch_queue_payload_wrapper', $args, $hook, $priority);
```

**Example** (add custom tracing):

```php
add_filter('wch_queue_payload_wrapper', function($args, $hook, $priority) {
    $args['_trace_id'] = wp_generate_uuid4();
    $args['_source'] = 'custom_plugin';
    return $args;
}, 10, 3);
```

#### wch_queue_priority_mapping

Customize priority to group mapping.

```php
apply_filters('wch_queue_priority_mapping', $group, $priority);
```

**Example** (custom group):

```php
add_filter('wch_queue_priority_mapping', function($group, $priority) {
    if ($priority === 0) {
        return 'wch-emergency';
    }
    return $group;
}, 10, 2);
```

#### wch_queue_max_retries

Override max retry attempts per job.

```php
apply_filters('wch_queue_max_retries', $maxRetries, $hook, $args);
```

**Example** (more retries for payments):

```php
add_filter('wch_queue_max_retries', function($maxRetries, $hook, $args) {
    if (strpos($hook, 'payment') !== false) {
        return 5;  // More retries for payment jobs
    }
    return $maxRetries;
}, 10, 3);
```

#### wch_queue_retry_delay

Customize retry delay calculation.

```php
apply_filters('wch_queue_retry_delay', $delay, $attempt, $hook);
```

**Example** (custom backoff):

```php
add_filter('wch_queue_retry_delay', function($delay, $attempt, $hook) {
    // Fibonacci backoff: 1, 1, 2, 3, 5, 8, 13...
    return fibonacci($attempt) * 30;
}, 10, 3);
```

## Creating Custom Processors

### Step 1: Implement Processor Interface

```php
<?php

namespace MyPlugin\Queue;

use WhatsAppCommerceHub\Queue\Processors\AbstractQueueProcessor;

class MyCustomProcessor extends AbstractQueueProcessor {

    /**
     * Get unique processor name.
     */
    public function getName(): string {
        return 'my_custom_processor';
    }

    /**
     * Get hook name for this processor.
     */
    public function getHookName(): string {
        return 'my_plugin_process_custom_event';
    }

    /**
     * Process the job payload.
     *
     * @param array $args User payload.
     */
    protected function process(array $args): void {
        // Validate payload
        if (!isset($args['event_id'])) {
            throw new \InvalidArgumentException('Missing event_id');
        }

        // Process event
        $eventId = $args['event_id'];
        $eventData = $args['data'] ?? [];

        // Your custom logic here
        $this->processEvent($eventId, $eventData);

        // Fire completion hook
        do_action('my_plugin_event_processed', $eventId);
    }

    /**
     * Determine if exception should trigger retry.
     */
    protected function shouldRetry(\Throwable $e): bool {
        // Don't retry validation errors
        if ($e instanceof \InvalidArgumentException) {
            return false;
        }

        // Don't retry not found errors
        if ($e instanceof \NotFoundException) {
            return false;
        }

        // Retry everything else
        return parent::shouldRetry($e);
    }

    /**
     * Get maximum retry attempts.
     */
    protected function getMaxRetries(): int {
        return 5;  // Custom retry limit
    }

    /**
     * Calculate retry delay with custom backoff.
     */
    protected function getRetryDelay(int $attempt): int {
        // Linear backoff: 60s, 120s, 180s, 240s, 300s
        return 60 * $attempt;
    }

    /**
     * Process event (your custom logic).
     */
    private function processEvent(string $eventId, array $data): void {
        // Implementation here
    }
}
```

### Step 2: Register Processor

```php
add_action('wch_register_queue_processors', function($registry) {
    $registry->register(new \MyPlugin\Queue\MyCustomProcessor());
});
```

### Step 3: Schedule Jobs

```php
$queueService = container()->get(QueueService::class);

$queueService->dispatch(
    'my_plugin_process_custom_event',
    [
        'event_id' => 'evt_123',
        'data' => ['key' => 'value'],
    ],
    QueueService::PRIORITY_NORMAL
);
```

## Advanced Usage

### Batch Processing

Process multiple items in batches:

```php
class BatchProcessor extends AbstractQueueProcessor {

    public function getHookName(): string {
        return 'my_plugin_process_batch';
    }

    protected function process(array $args): void {
        $batchId = $args['batch_id'];
        $items = $this->getBatchItems($batchId);

        foreach ($items as $item) {
            try {
                $this->processItem($item);
            } catch (\Throwable $e) {
                // Log error but continue batch
                error_log("Item {$item['id']} failed: " . $e->getMessage());
            }
        }
    }
}
```

### Priority Escalation

Escalate job priority on retry:

```php
protected function getRetryDelay(int $attempt): int {
    // Escalate priority on 3rd attempt
    if ($attempt >= 3) {
        add_filter('wch_queue_priority_mapping', function($group, $priority) {
            return 'wch-urgent';
        }, 10, 2);
    }

    return parent::getRetryDelay($attempt);
}
```

### Conditional Processing

Skip processing based on conditions:

```php
protected function process(array $args): void {
    // Check if processing is needed
    if ($this->isAlreadyProcessed($args['entity_id'])) {
        // Already processed, skip silently
        return;
    }

    // Process normally
    $this->processEntity($args);
}
```

### Chained Jobs

Schedule dependent jobs:

```php
protected function process(array $args): void {
    // Process step 1
    $result = $this->processStep1($args);

    // Schedule step 2
    $queueService = container()->get(QueueService::class);
    $queueService->dispatch(
        'my_plugin_process_step2',
        array_merge($args, ['step1_result' => $result]),
        QueueService::PRIORITY_NORMAL
    );
}
```

## Monitoring and Operations

### Queue Dashboard

Display queue metrics in WordPress admin:

```php
add_action('admin_menu', function() {
    add_menu_page(
        'Queue Monitor',
        'Queue',
        'manage_options',
        'wch-queue-monitor',
        'render_queue_dashboard'
    );
});

function render_queue_dashboard() {
    $queueService = container()->get(QueueService::class);

    $stats = $queueService->getStats();
    $health = $queueService->healthCheck();
    $statsByPriority = $queueService->getStatsByPriority();

    // Render dashboard
    echo '<div class="wrap">';
    echo '<h1>Queue Monitor</h1>';

    echo '<div class="card">';
    echo '<h2>Health Status</h2>';
    echo '<p>Status: ' . esc_html($health['status']) . '</p>';
    echo '<p>Pending: ' . esc_html($stats['pending']) . '</p>';
    echo '<p>Failed: ' . esc_html($stats['failed']) . '</p>';
    echo '</div>';

    // Priority breakdown table
    echo '<table class="widefat">';
    echo '<thead><tr><th>Priority</th><th>Pending</th><th>Failed</th></tr></thead>';
    echo '<tbody>';
    foreach ($statsByPriority as $priority => $counts) {
        echo '<tr>';
        echo '<td>' . esc_html($priority) . '</td>';
        echo '<td>' . esc_html($counts['pending']) . '</td>';
        echo '<td>' . esc_html($counts['failed']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';

    echo '</div>';
}
```

### WP-CLI Commands

Manage queue via WP-CLI:

```php
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wch queue', 'WCH_Queue_Command');
}

class WCH_Queue_Command {

    /**
     * Get queue statistics.
     */
    public function stats($args, $assocArgs) {
        $queueService = container()->get(QueueService::class);
        $stats = $queueService->getStats();

        WP_CLI::line('Queue Statistics:');
        WP_CLI::line('  Pending: ' . $stats['pending']);
        WP_CLI::line('  Failed: ' . $stats['failed']);
        WP_CLI::line('  Completed: ' . $stats['completed']);
    }

    /**
     * Clear completed jobs.
     */
    public function clear_completed($args, $assocArgs) {
        $queueService = container()->get(QueueService::class);
        $age = $assocArgs['age'] ?? 7 * DAY_IN_SECONDS;

        $count = $queueService->clearCompleted($age);
        WP_CLI::success("Cleared {$count} completed jobs");
    }

    /**
     * Health check.
     */
    public function health($args, $assocArgs) {
        $queueService = container()->get(QueueService::class);
        $health = $queueService->healthCheck();

        WP_CLI::line('Health Status: ' . $health['status']);
        WP_CLI::line('Pending Jobs: ' . $health['pending_jobs']);
        WP_CLI::line('Failed Jobs: ' . $health['failed_jobs']);

        if ($health['status'] !== 'healthy') {
            WP_CLI::warning('Queue is not healthy!');
        }
    }
}
```

## Best Practices

### 1. Choose Appropriate Priorities

- **CRITICAL**: Only for system-critical operations
- **URGENT**: Customer-facing operations (messages, payments)
- **NORMAL**: Standard operations (status updates)
- **BULK**: Batch operations (broadcasts, sync)
- **MAINTENANCE**: Background tasks (cleanup)

### 2. Keep Payloads Small

- Store references (IDs) instead of full objects
- Avoid large binary data
- Keep payload < 1KB when possible

### 3. Implement Idempotency

Always use idempotency checks:

```php
protected function process(array $args): void {
    $idempotency = container()->get(IdempotencyService::class);

    if (!$idempotency->claim($args['id'], 'my_scope')) {
        return;  // Already processed
    }

    // Process...
}
```

### 4. Handle Failures Gracefully

Distinguish between retryable and permanent failures:

```php
protected function shouldRetry(\Throwable $e): bool {
    // Permanent failures
    if ($e instanceof ValidationException) return false;
    if ($e instanceof NotFoundException) return false;
    if ($e instanceof AuthenticationException) return false;

    // Retryable failures
    return true;
}
```

### 5. Monitor Queue Health

Set up alerts for:
- Pending jobs > threshold
- Failed jobs increasing
- Processing lag > acceptable limit

### 6. Cleanup Old Jobs

Schedule regular cleanup:

```php
wp_schedule_event(time(), 'daily', 'wch_cleanup_old_jobs');

add_action('wch_cleanup_old_jobs', function() {
    $queueService = container()->get(QueueService::class);

    // Clear completed jobs older than 7 days
    $queueService->clearCompleted(7 * DAY_IN_SECONDS);

    // Clear failed jobs older than 30 days
    $queueService->clearFailed(30 * DAY_IN_SECONDS);
});
```

### 7. Use Appropriate Retry Delays

Balance between:
- **Fast retries**: Better UX but higher load
- **Slow retries**: Lower load but worse UX

Recommended defaults:
- Transient errors: 30s, 90s, 270s
- Rate limits: 60s, 300s, 900s
- External APIs: 120s, 600s, 1800s

### 8. Log Important Events

```php
protected function process(array $args): void {
    do_action('wch_queue_job_started', $this->getHookName(), $args);

    try {
        $result = $this->processJob($args);

        do_action('wch_queue_job_completed', $this->getHookName(), $args);

        return $result;
    } catch (\Throwable $e) {
        do_action('wch_queue_job_error', $this->getHookName(), $args, $e);
        throw $e;
    }
}
```

## Related Documentation

- [Webhooks Ingestion Pipeline](webhooks-ingestion-pipeline.md)
- [Module Map](module-map.md)
- [Hooks Reference](hooks-reference.md)

---

**Last Updated**: 2026-01-18
**Version**: 2.0.0
