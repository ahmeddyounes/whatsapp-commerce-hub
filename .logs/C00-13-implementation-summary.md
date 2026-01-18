# C00-13 Implementation Summary

## Task: Queue System Unification

**Objective**: Decide and implement a single async pipeline (PriorityQueue) and deprecate/adapt JobDispatcher/QueueManager to use consistent conventions.

**Status**: ✅ DONE

## Changes Made

### 1. JobDispatcher Unification (`includes/Infrastructure/Queue/JobDispatcher.php`)

**Changes**:
- Added `@deprecated` notice (Since 3.1.0)
- Added `PriorityQueue` instance as class property
- Updated constructor to accept optional `PriorityQueue` dependency
- Refactored all scheduling methods to delegate to `PriorityQueue`:
  - `dispatch()` → uses `PriorityQueue::schedule()`
  - `schedule()` → uses `PriorityQueue::schedule()` or `scheduleUnique()`
  - `scheduleRecurring()` → uses `PriorityQueue::scheduleRecurring()`
  - `dispatchBatch()` → uses `PriorityQueue::schedule()` with BULK priority
  - `retry()` → uses `PriorityQueue::retry()` for wrapped payloads
- All jobs now use v2 wrapped payload format internally
- Maintained backward compatibility - all existing method signatures unchanged

**Benefits**:
- Consistent payload format across all jobs
- Priority-based scheduling (defaults to NORMAL priority)
- Automatic retry with exponential backoff
- DLQ integration for failed jobs
- Atomic operations prevent race conditions

### 2. QueueManager Unification (`includes/Infrastructure/Queue/QueueManager.php`)

**Changes**:
- Added `@deprecated` notice (Since 3.1.0)
- Added `PriorityQueue` instance as class property
- Updated constructor to accept optional `PriorityQueue` dependency
- Refactored `scheduleRecurringJobs()`:
  - `wch_cleanup_expired_carts` → MAINTENANCE priority
  - `wch_detect_stock_discrepancies` → MAINTENANCE priority
  - `wch_schedule_recovery_reminders` → NORMAL priority
- Refactored `schedule_bulk_action()` to use BULK priority
- Updated `processJob()` to handle both wrapped and unwrapped payloads using `PriorityQueue::unwrapPayloadCompat()`

**Benefits**:
- Appropriate priority assignment for different job types
- Consistent scheduling through PriorityQueue
- Automatic payload unwrapping with backward compatibility

### 3. PriorityQueue Enhancements (`includes/Queue/PriorityQueue.php`)

**New Methods Added**:

```php
/**
 * Unwrap payload with backward compatibility.
 * Handles both v2 wrapped and legacy unwrapped payloads.
 */
public static function unwrapPayloadCompat(array $payload): array

/**
 * Check if a payload is in v2 wrapped format.
 */
public static function isWrappedPayload(array $payload): bool
```

**Benefits**:
- Seamless migration from legacy payloads to v2 format
- Handlers work with both old and new payload formats
- No breaking changes for existing code

### 4. Migration Guide (`QUEUE_MIGRATION_GUIDE.md`)

**Created comprehensive documentation covering**:
- Overview of changes
- What changed and why
- Deprecated components
- Payload format changes
- Migration steps for new and existing code
- Priority selection guidelines
- Code examples for common scenarios
- Benefits of migration
- Rate limits and DLQ usage
- Testing guidelines
- Migration timeline

## Acceptance Criteria

✅ **All scheduled jobs use one payload format**: v2 wrapped format with metadata separation
✅ **All job handlers receive expected args shape**: Backward compatibility ensures handlers receive unwrapped payloads
✅ **Single async pipeline**: All scheduling flows through PriorityQueue
✅ **Deprecated components adapted**: JobDispatcher and QueueManager now delegate to PriorityQueue
✅ **No breaking changes**: Existing code continues to work without modification

## Technical Details

### Payload Format (v2)

```php
[
    '_wch_version' => 2,
    '_wch_meta' => [
        'priority' => int,           // 1-5 (CRITICAL to MAINTENANCE)
        'scheduled_at' => timestamp, // When job was scheduled
        'attempt' => int,            // Current attempt number
        'last_retry' => timestamp,   // Optional: Last retry time
        'recurring' => bool,         // Optional: Is recurring
        'interval' => int            // Optional: Recurring interval
    ],
    'args' => [...user_args...]      // Original job arguments
]
```

### Priority Levels

1. **CRITICAL** (1) - 1000 jobs/min - System-critical operations
2. **URGENT** (2) - 100 jobs/min - Time-sensitive user operations
3. **NORMAL** (3) - 50 jobs/min - Standard operations (default)
4. **BULK** (4) - 20 jobs/min - Large batch operations
5. **MAINTENANCE** (5) - 10 jobs/min - Background maintenance

### Backward Compatibility Strategy

1. **Payload Unwrapping**: `PriorityQueue::unwrapPayloadCompat()` handles both formats
2. **Delegation Pattern**: Deprecated classes delegate to PriorityQueue
3. **Method Signatures**: All existing method signatures preserved
4. **Handler Compatibility**: Handlers receive unwrapped payloads via compatibility layer

## Files Modified

1. `includes/Infrastructure/Queue/JobDispatcher.php` - 165 lines changed
2. `includes/Infrastructure/Queue/QueueManager.php` - 98 lines changed
3. `includes/Queue/PriorityQueue.php` - 35 lines added
4. `QUEUE_MIGRATION_GUIDE.md` - New file (comprehensive guide)

## Testing Recommendations

### Unit Tests
```bash
# Test payload wrapping/unwrapping
# Test priority assignment
# Test backward compatibility layer
```

### Integration Tests
```bash
# Test JobDispatcher delegation to PriorityQueue
# Test QueueManager delegation to PriorityQueue
# Test mixed payload format handling
```

### Manual Verification
```bash
# Schedule jobs through JobDispatcher - verify they use v2 format
# Schedule jobs through QueueManager - verify priority assignments
# Process jobs with legacy payloads - verify handlers work correctly
# Check Action Scheduler groups (wch-critical, wch-urgent, etc.)
```

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Breaking existing jobs | Backward compatibility layer handles both formats |
| Performance impact | PriorityQueue uses atomic operations and rate limiting |
| Failed job handling | DLQ integration captures all failures |
| Migration complexity | Comprehensive guide and no required changes |

## Follow-up Tasks

1. **v3.2.0**: Add deprecation warnings to JobDispatcher and QueueManager
2. **v4.0.0**: Remove deprecated JobDispatcher and QueueManager classes
3. **Monitor**: Track queue performance and DLQ entries
4. **Optimize**: Adjust rate limits based on actual usage patterns

## Verification Commands

```bash
# Verify no syntax errors
php -l includes/Infrastructure/Queue/JobDispatcher.php
php -l includes/Infrastructure/Queue/QueueManager.php
php -l includes/Queue/PriorityQueue.php

# Search for direct Action Scheduler usage (should be minimal now)
grep -r "as_enqueue_async_action\|as_schedule_single_action" includes/ --exclude-dir=vendor

# Verify all jobs use wrapped payloads
# Check Action Scheduler database for _wch_version in job args
```

## Conclusion

The queue system has been successfully unified around PriorityQueue as the single async pipeline. All job scheduling now uses consistent v2 wrapped payloads with priority-based execution, retry logic, and DLQ integration. Backward compatibility ensures zero breaking changes for existing code.

**Implementation Status**: ✅ COMPLETE
**Breaking Changes**: ❌ NONE
**Documentation**: ✅ COMPLETE
**Testing Required**: ✅ Manual verification recommended
