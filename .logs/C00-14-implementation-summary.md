# C00-14 Implementation Summary

## Task
Audit every `add_action('wch_*', ...)` handler to ensure its function signature matches the scheduled payload structure (including wrapped payloads). Fix mismatches and add minimal runtime assertions/logs in processors.

## Changes Made

### 1. Fixed EventServiceProvider (includes/Providers/EventServiceProvider.php)

**Issue**: Handler expected 2 separate parameters `processAsyncEvent(string $event_name, array $event_data)` but received 1 wrapped payload containing `[$event_name, $event_data]`.

**Fix**:
- Changed signature from `processAsyncEvent(string $event_name, array $event_data)` to `processAsyncEvent(array $payload)` (line 163)
- Added unwrapping logic to extract event name and data from wrapped payload (lines 164-186)
- Added validation to ensure both event name and data are present (lines 173-183)
- Updated hook registration from `add_action(..., 10, 2)` to `add_action(..., 10, 1)` (line 149)
- Added error logging for invalid payload structures

### 2. Fixed ReengagementServiceProvider (includes/Providers/ReengagementServiceProvider.php)

**Issue**: `handleSendMessage($args)` expected unwrapped args but received wrapped payload.

**Fix**:
- Added `unwrapPayload()` helper method (lines 215-250)
- Updated `handleSendMessage()` to unwrap before passing to orchestrator (lines 197-203)
- Added debug logging to track unwrapping for both v2 and legacy payloads

### 3. Fixed RecoveryService (includes/Features/AbandonedCart/RecoveryService.php)

**Issue**: `processRecoveryMessage($args)` tried to access `$args['cart_id']` but received wrapped payload.

**Fix**:
- Added inline unwrapping logic at the start of `processRecoveryMessage()` (lines 149-159)
- Added debug logging to track unwrapping
- Preserved backward compatibility with legacy unwrapped payloads

### 4. Fixed InventorySyncService (includes/Application/Services/InventorySyncService.php)

**Issue**: `processStockSync(int $productId)` expected a single integer parameter but Action Scheduler passes `['product_id' => 123]` as an array.

**Fix**:
- Changed signature from `processStockSync(int $productId)` to `processStockSync(array $args)` (line 151)
- Added unwrapping logic to handle v2 wrapped payloads (lines 152-162)
- Added extraction of `product_id` from args array (lines 165-173)
- Added validation and error logging for missing `product_id`

### 5. Verified Other Handlers

**CheckoutServiceProvider** (includes/Providers/CheckoutServiceProvider.php):
- Hooks `wch_checkout_started` and `wch_checkout_cancelled` are regular WordPress action hooks (not Action Scheduler jobs)
- These are triggered via `do_action()` and pass simple string parameters directly
- No wrapping issue - **no changes needed**

**QueueServiceProvider** (includes/Providers/QueueServiceProvider.php):
- Webhook processor hooks already correctly use `AbstractQueueProcessor::execute()` which unwraps in the base class
- **No changes needed**

## Tests Added

Created comprehensive tests to validate webhook job processing:

### 1. WebhookProcessorTest (tests/Unit/Queue/WebhookProcessorTest.php)
Tests for:
- WebhookMessageProcessor handles wrapped v2 payload
- WebhookMessageProcessor handles legacy unwrapped payload
- WebhookStatusProcessor handles wrapped v2 payload
- WebhookErrorProcessor handles wrapped v2 payload
- PriorityQueue wraps payloads correctly
- PriorityQueue unwraps payloads correctly

### 2. EventServiceProviderTest (tests/Unit/Providers/EventServiceProviderTest.php)
Tests for:
- processAsyncEvent handles wrapped v2 payload
- processAsyncEvent handles legacy unwrapped payload
- processAsyncEvent validates payload structure and logs errors

### 3. InventorySyncServiceTest (tests/Unit/Services/InventorySyncServiceTest.php)
Tests for:
- processStockSync handles wrapped v2 payload
- processStockSync handles legacy unwrapped payload
- processStockSync validates product_id and logs errors

## Common Pattern Applied

All fixes follow this pattern:

1. Check if payload has `_wch_version` = 2
2. If wrapped, extract `$payload['args']`
3. If not wrapped (legacy), use payload as-is
4. Add debug/error logging to track unwrapping and validation
5. For multi-argument handlers, extract indexed args from unwrapped array

## Handlers Fixed Summary

| Handler | Location | Issue | Status |
|---------|----------|-------|--------|
| EventServiceProvider::processAsyncEvent | Providers/EventServiceProvider.php:163 | Expected 2 args, got wrapped array | ✅ Fixed |
| ReengagementServiceProvider::handleSendMessage | Providers/ReengagementServiceProvider.php:197 | Expected unwrapped args | ✅ Fixed |
| RecoveryService::processRecoveryMessage | Features/AbandonedCart/RecoveryService.php:148 | Expected unwrapped args | ✅ Fixed |
| InventorySyncService::processStockSync | Application/Services/InventorySyncService.php:151 | Expected int, got array | ✅ Fixed |
| Webhook processors | Queue/Processors/* | Already correct via AbstractQueueProcessor | ✅ No change |
| Checkout hooks | Providers/CheckoutServiceProvider.php | Not Action Scheduler jobs | ✅ No change |

## Acceptance Criteria Met

✅ No job crashes due to args shape - All handlers now properly unwrap v2 payloads and validate structure
✅ Minimal runtime assertions/logs added - Debug/error logging added to all processors
✅ Tests for webhook jobs - Comprehensive test suite created covering all webhook processors and fixed handlers

## Backward Compatibility

All fixes maintain backward compatibility with legacy unwrapped payloads by checking for the presence of `_wch_version` before unwrapping.
