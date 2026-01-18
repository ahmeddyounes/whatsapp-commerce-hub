# Queue System Unification - Migration Guide

## Overview

As of version 3.1.0, the WhatsApp Commerce Hub queue system has been unified to use a single async pipeline based on `PriorityQueue`. This document outlines the changes and provides migration guidance.

## What Changed

### Unified Queue System

All job scheduling now flows through `PriorityQueue`, which provides:
- **Priority-based scheduling** (CRITICAL, URGENT, NORMAL, BULK, MAINTENANCE)
- **Consistent v2 payload format** with metadata separation
- **Advanced retry logic** with exponential backoff
- **Dead Letter Queue (DLQ)** integration for failed jobs
- **Rate limiting** per priority group
- **Atomic operations** to prevent race conditions

### Deprecated Components

The following components are now **deprecated** but remain functional for backward compatibility:

1. **JobDispatcher** (`includes/Infrastructure/Queue/JobDispatcher.php`)
   - Now wraps `PriorityQueue` internally
   - All methods delegate to `PriorityQueue`
   - Use `PriorityQueue` directly for new code

2. **QueueManager** (`includes/Infrastructure/Queue/QueueManager.php`)
   - Now uses `PriorityQueue` for all job scheduling
   - Maintains backward compatibility for existing hooks
   - Use `PriorityQueue` directly for new code

### Payload Format Changes

All jobs now use the v2 wrapped payload format:

```php
[
    '_wch_version' => 2,
    '_wch_meta' => [
        'priority' => int,
        'scheduled_at' => timestamp,
        'attempt' => int,
        'last_retry' => timestamp (optional),
        'recurring' => bool (optional),
        'interval' => int (optional)
    ],
    'args' => [...user_args...]
]
```

**Important**: Job handlers automatically receive unwrapped payloads (just the `args` portion) for backward compatibility.

## Migration Steps

### For New Code

**Use PriorityQueue directly:**

```php
// Old way (deprecated)
$dispatcher = new JobDispatcher($logger);
$dispatcher->dispatch('wch_process_task', ['task_id' => 123]);

// New way (recommended)
$priorityQueue = new PriorityQueue($deadLetterQueue);
$priorityQueue->schedule(
    'wch_process_task',
    ['task_id' => 123],
    PriorityQueue::PRIORITY_NORMAL,
    0 // delay in seconds
);
```

### For Existing Code

No immediate action required. Deprecated components will continue to work:

1. **JobDispatcher** automatically uses `PriorityQueue` internally
2. **QueueManager** automatically uses `PriorityQueue` internally
3. All existing job handlers receive unwrapped payloads via backward compatibility layer

### Priority Selection Guidelines

Choose the appropriate priority for your jobs:

```php
// CRITICAL (1) - System-critical operations, highest priority
PriorityQueue::PRIORITY_CRITICAL
// Examples: webhook processing, payment confirmations

// URGENT (2) - Time-sensitive user operations
PriorityQueue::PRIORITY_URGENT
// Examples: order notifications, cart updates

// NORMAL (3) - Standard operations (default)
PriorityQueue::PRIORITY_NORMAL
// Examples: product syncs, message processing

// BULK (4) - Large batch operations
PriorityQueue::PRIORITY_BULK
// Examples: catalog imports, bulk syncs

// MAINTENANCE (5) - Background maintenance tasks
PriorityQueue::PRIORITY_MAINTENANCE
// Examples: cleanups, stock checks
```

### Updating Job Handlers

Job handlers that extend `AbstractQueueProcessor` automatically handle both payload formats:

```php
class MyJobProcessor extends AbstractQueueProcessor {
    public function process(array $payload): void {
        // $payload is automatically unwrapped
        $taskId = $payload['task_id'];
        // ... process job
    }
}
```

For standalone handlers (not extending AbstractQueueProcessor), use the compatibility helper:

```php
add_action('wch_my_custom_hook', function($args) {
    // Handle both wrapped and unwrapped payloads
    $unwrapped = PriorityQueue::unwrapPayloadCompat($args);
    $payload = $unwrapped['args'];
    $meta = $unwrapped['meta'];

    // Process the job
    $taskId = $payload['task_id'];
    // ...
});
```

### Scheduling Recurring Jobs

```php
// Old way (deprecated)
$dispatcher->scheduleRecurring(
    'wch_cleanup_task',
    time(),
    HOUR_IN_SECONDS,
    []
);

// New way (recommended)
$priorityQueue->scheduleRecurring(
    'wch_cleanup_task',
    [],
    HOUR_IN_SECONDS,
    PriorityQueue::PRIORITY_MAINTENANCE
);
```

### Batch Job Scheduling

```php
// Old way (deprecated)
$dispatcher->dispatchBatch('wch_sync_products', $productIds, 50);

// New way (recommended)
$batches = array_chunk($productIds, 50);
foreach ($batches as $index => $batch) {
    $priorityQueue->schedule(
        'wch_sync_products',
        [
            'batch' => $batch,
            'batch_index' => $index,
            'total_batches' => count($batches)
        ],
        PriorityQueue::PRIORITY_BULK,
        0
    );
}
```

### Unique Job Scheduling

```php
// Prevent duplicate jobs with atomic locking
$priorityQueue->scheduleUnique(
    'wch_sync_product',
    ['product_id' => 123],
    PriorityQueue::PRIORITY_NORMAL,
    0
);
```

## Benefits of Migration

### 1. Priority-Based Execution
Jobs execute in priority order, ensuring critical operations complete first.

### 2. Better Resource Management
Rate limiting per priority group prevents system overload.

### 3. Improved Reliability
- Atomic operations prevent race conditions
- DLQ captures failed jobs for analysis and replay
- Exponential backoff prevents retry storms

### 4. Enhanced Observability
- Unified logging with priority context
- Queue statistics by priority group
- Failed job tracking in DLQ

### 5. Reduced Complexity
- Single entry point for all job scheduling
- Consistent payload format across all jobs
- Centralized retry and error handling

## Rate Limits

Default rate limits per priority group (jobs per minute):

- **CRITICAL**: 1000 (effectively unlimited)
- **URGENT**: 100
- **NORMAL**: 50
- **BULK**: 20
- **MAINTENANCE**: 10

Rate limits are enforced atomically to prevent TOCTOU race conditions.

## Dead Letter Queue

Failed jobs are automatically moved to the DLQ after max retries. Access failed jobs:

```php
$dlq = new DeadLetterQueue();

// Get pending failed jobs
$failedJobs = $dlq->getEntries(['status' => 'pending'], 20, 0);

// Replay a failed job
$dlq->replay($entryId, PriorityQueue::PRIORITY_URGENT);

// Dismiss a failed job
$dlq->dismiss($entryId);
```

## Testing

### Verify Queue Operations

```php
// Check if a job is pending
$isPending = $priorityQueue->isPending('wch_my_hook');

// Get pending count by priority
$count = $priorityQueue->getPendingCount(PriorityQueue::PRIORITY_NORMAL);

// Get queue statistics
$stats = $priorityQueue->getStats();
// Returns: ['critical' => [...], 'urgent' => [...], etc.]
```

### Verify Payload Format

```php
// Check if payload is wrapped
$isWrapped = PriorityQueue::isWrappedPayload($payload);

// Unwrap with compatibility
$unwrapped = PriorityQueue::unwrapPayloadCompat($payload);
```

## Timeline

- **v3.1.0**: Queue unification complete, deprecated components marked
- **v3.2.0** (future): Deprecated components will emit warnings
- **v4.0.0** (future): Deprecated components will be removed

## Support

For questions or issues related to queue migration:
1. Check the inline documentation in `includes/Queue/PriorityQueue.php`
2. Review examples in `includes/Queue/Processors/`
3. Consult the main README for general queue usage

## Summary

✅ **No breaking changes** - existing code continues to work
✅ **Backward compatible** - handlers receive unwrapped payloads
✅ **Recommended** - migrate to `PriorityQueue` for new code
✅ **Benefits** - priority scheduling, better reliability, improved observability
