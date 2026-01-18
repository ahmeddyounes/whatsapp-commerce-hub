# Webhooks Ingestion Pipeline

Complete documentation for the WhatsApp Commerce Hub webhooks ingestion pipeline, covering the flow from REST controller through queue processing to action execution.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Unified Queue Payload Format](#unified-queue-payload-format)
- [Pipeline Flow](#pipeline-flow)
- [REST Controllers](#rest-controllers)
- [Queue Processors](#queue-processors)
- [Extension Hooks](#extension-hooks)
- [Error Handling](#error-handling)
- [Testing](#testing)
- [Performance Considerations](#performance-considerations)

## Overview

The webhooks ingestion pipeline is the core mechanism for processing incoming events from WhatsApp and payment gateway webhooks. It provides:

- **Reliable Message Processing**: Queue-based architecture with retry logic and dead-letter handling
- **Idempotency**: Prevents duplicate processing of webhook events
- **Priority-Based Processing**: Critical events are processed before lower-priority ones
- **Extensibility**: Hooks and interfaces for customization

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     WEBHOOK INGESTION PIPELINE                  │
└─────────────────────────────────────────────────────────────────┘

1. WEBHOOK RECEIVED
   ├─ POST to /wch/v1/webhook (WhatsApp)
   ├─ POST to /wch/v1/payment-webhook/{gateway} (Payments)
   ├─ Signature validation via X-Hub-Signature-256
   ├─ Rate limit check
   └─ Return 200 OK immediately

2. WEBHOOK PARSING & ROUTING
   ├─ Parse entry[].changes[] structure
   ├─ Extract messages, statuses, errors
   └─ Route to appropriate queue

3. QUEUE SCHEDULING
   ├─ Messages → wch_process_webhook_messages (PRIORITY_URGENT)
   ├─ Statuses → wch_process_webhook_statuses (PRIORITY_NORMAL)
   ├─ Errors → wch_process_webhook_errors (PRIORITY_NORMAL)
   └─ Payments → wch_process_payment_webhook (PRIORITY_URGENT)

4. ASYNC PROCESSING
   ├─ Payload unwrapping (v2 format)
   ├─ Idempotency check
   ├─ Business logic execution
   ├─ Response generation
   └─ Event dispatching

5. ERROR HANDLING
   ├─ Validation errors → No retry, DLQ
   ├─ Idempotency errors → No retry, skip
   ├─ Transient errors → Retry with exponential backoff
   └─ Max retries exceeded → DLQ
```

## Unified Queue Payload Format

All queue jobs use a standardized v2 payload format with metadata wrapping.

### V2 Payload Structure

```php
[
    '_wch_version' => 2,
    '_wch_meta' => [
        'priority'     => int,        // 1=CRITICAL, 2=URGENT, 3=NORMAL, 4=BULK, 5=MAINTENANCE
        'scheduled_at' => int,        // Unix timestamp
        'attempt'      => int,        // Retry attempt number (1-indexed)
    ],
    'args' => [
        // User payload (application-specific data)
    ]
]
```

### Priority Levels

| Priority | Value | Rate Limit | Use Case |
|----------|-------|------------|----------|
| CRITICAL | 1 | 1000/min | System failures, critical alerts |
| URGENT | 2 | 100/min | Incoming messages, payment webhooks |
| NORMAL | 3 | 50/min | Status updates, error logging |
| BULK | 4 | 20/min | Batch operations, broadcasts |
| MAINTENANCE | 5 | 10/min | Cleanup, sync, analytics |

### Wrapping and Unwrapping

**Wrapping** (done automatically by `PriorityQueue::schedule()`):

```php
$queue = new PriorityQueue();
$queue->schedule('wch_process_webhook_messages', $messageData, PriorityQueue::PRIORITY_URGENT);
```

**Unwrapping** (done automatically by processors):

```php
class WebhookMessageProcessor extends AbstractQueueProcessor {
    public function execute($wrappedPayload) {
        $unwrapped = PriorityQueue::unwrapPayload($wrappedPayload);
        $userArgs = $unwrapped['args'];
        $meta = $unwrapped['meta'];

        return $this->process($userArgs);
    }
}
```

### Legacy Support

The pipeline supports legacy unwrapped payloads for backward compatibility. If `_wch_version` is not present, the payload is treated as args directly.

## Pipeline Flow

### 1. WhatsApp Message Flow

```php
// Entry point: WebhookController::handleWebhook()
POST /wch/v1/webhook
↓
Validate signature (X-Hub-Signature-256)
↓
Parse webhook payload
↓
Extract messages → enqueueMessage()
Extract statuses → enqueueStatus()
Extract errors → enqueueError()
↓
Return 200 OK
↓
[ASYNC QUEUE PROCESSING]
↓
WebhookMessageProcessor::execute()
├─ Unwrap payload
├─ Idempotency check (message_id, SCOPE_WEBHOOK)
├─ Find/create conversation
├─ Store message in database
├─ Dispatch MessageReceivedEvent
├─ Extract message content
├─ Classify intent (AI)
├─ Resolve action from intent/context
├─ Execute action via ActionRegistry
├─ Send response messages
├─ Store outbound messages
└─ Dispatch MessageSentEvent
```

### 2. Payment Webhook Flow

```php
// Entry point: PaymentWebhookController::handleWebhook()
POST /wch/v1/payment-webhook/{gateway}
↓
Detect gateway (header or payload analysis)
↓
Validate payload size (<2MB)
↓
Extract event ID for idempotency
↓
Atomic claim (INSERT IGNORE)
↓
Validate gateway signature
↓
Return 200 OK
↓
[ASYNC QUEUE PROCESSING]
↓
PaymentWebhookProcessor::execute()
├─ Unwrap payload
├─ Route to gateway handler
├─ Update order status
├─ Dispatch PaymentCompletedEvent
└─ Notify customer
```

### 3. Status Update Flow

```php
// Entry point: WebhookController::handleWebhook()
POST /wch/v1/webhook (with statuses)
↓
Extract statuses → enqueueStatus()
↓
Return 200 OK
↓
[ASYNC QUEUE PROCESSING]
↓
WebhookStatusProcessor::execute()
├─ Unwrap payload
├─ Idempotency check (message_id)
├─ Find message by message_id
├─ Update message status (sent|delivered|read|failed)
└─ Dispatch MessageStatusUpdatedEvent
```

## REST Controllers

### WebhookController

**Location**: `includes/Controllers/WebhookController.php`

**Routes**:
- `GET /wch/v1/webhook` - Webhook verification
- `POST /wch/v1/webhook` - Webhook handler

**Key Methods**:

```php
class WebhookController {
    /**
     * Verify webhook challenge from Meta.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function verifyWebhook(WP_REST_Request $request): WP_REST_Response;

    /**
     * Handle incoming webhook payload.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function handleWebhook(WP_REST_Request $request): WP_REST_Response;

    /**
     * Enqueue message for async processing.
     *
     * @param array $message Message data from webhook.
     */
    private function enqueueMessage(array $message): void;

    /**
     * Enqueue status update for async processing.
     *
     * @param array $status Status data from webhook.
     */
    private function enqueueStatus(array $status): void;

    /**
     * Enqueue error for async processing.
     *
     * @param array $error Error data from webhook.
     */
    private function enqueueError(array $error): void;
}
```

**Permission Callbacks**:
- `checkRateLimit('webhook')` - Rate limiting
- `checkWebhookSignature()` - HMAC-SHA256 signature validation

**Webhook Verification**:

Meta sends a GET request with these query parameters:
- `hub.mode` - Must be "subscribe"
- `hub.verify_token` - Must match configured token
- `hub.challenge` - Random string to echo back

Example verification:
```php
GET /wch/v1/webhook?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=CHALLENGE_STRING
↓
Response: CHALLENGE_STRING (200 OK)
```

### PaymentWebhookController

**Location**: `includes/Controllers/PaymentWebhookController.php`

**Routes**:
- `POST /wch/v1/payment-webhook` - Generic handler
- `POST /wch/v1/payment-webhook/{gateway}` - Gateway-specific handler

**Gateway Detection**:

```php
// Stripe
Header: Stripe-Signature
Payload: type contains "payment_intent"

// Razorpay
Header: X-Razorpay-Signature
Payload: event contains "payment."

// PIX (Mercado Pago)
Payload: type === "payment" && data.id exists

// Fallback
Event ID: hash(payload)
```

**Security Features**:
- 2MB payload size limit (DoS prevention)
- Atomic idempotency claim via INSERT IGNORE
- Gateway-specific signature validation
- Rate limiting per IP/gateway

## Queue Processors

### AbstractQueueProcessor

**Location**: `includes/Queue/Processors/AbstractQueueProcessor.php`

**Interface**: `QueueProcessorInterface`

**Base implementation for all processors**:

```php
abstract class AbstractQueueProcessor implements QueueProcessorInterface {
    /**
     * Execute the queue job.
     *
     * @param array $payload Wrapped or unwrapped payload.
     */
    public function execute(array $payload): void {
        $unwrapped = PriorityQueue::unwrapPayload($payload);
        $args = $unwrapped['args'];
        $meta = $unwrapped['meta'];

        try {
            $this->process($args);
        } catch (\Throwable $e) {
            $this->handleFailure($e, $args, $meta['attempt']);
        }
    }

    /**
     * Process the job payload (implemented by subclasses).
     *
     * @param array $args User payload.
     */
    abstract protected function process(array $args): void;

    /**
     * Get hook name for this processor.
     *
     * @return string Hook name.
     */
    abstract public function getHookName(): string;

    /**
     * Determine if exception should trigger retry.
     *
     * @param \Throwable $e Exception.
     * @return bool True to retry, false to send to DLQ.
     */
    protected function shouldRetry(\Throwable $e): bool;

    /**
     * Get maximum retry attempts.
     *
     * @return int Max retries.
     */
    protected function getMaxRetries(): int;

    /**
     * Calculate retry delay with exponential backoff.
     *
     * @param int $attempt Attempt number.
     * @return int Delay in seconds.
     */
    protected function getRetryDelay(int $attempt): int;
}
```

**Retry Strategy**:
- Default max retries: 3 attempts
- Base delay: 30 seconds
- Exponential backoff: delay = 30 × (3 ^ (attempt - 1))
- Example delays: 30s → 90s → 270s

### WebhookMessageProcessor

**Location**: `includes/Queue/Processors/WebhookMessageProcessor.php`

**Hook**: `wch_process_webhook_messages`

**Priority**: `PRIORITY_URGENT`

**Payload Structure**:

```php
[
    'message_id'  => string,    // WhatsApp message ID
    'from'        => string,    // Sender phone (E.164 format)
    'type'        => string,    // text|button|interactive|location|image|video|audio|document
    'timestamp'   => int,       // Unix timestamp
    'metadata'    => [
        'display_phone_number' => string,
        'phone_number_id'      => string,
    ],
    'contacts'    => [
        [
            'profile' => ['name' => string],
            'wa_id'   => string,
        ]
    ],
    // Type-specific fields...
]
```

**Processing Steps**:

1. **Idempotency Check**: Claim message_id in SCOPE_WEBHOOK
2. **Conversation Lookup**: Find or create conversation by phone
3. **Store Message**: Persist to wch_messages table
4. **Event Dispatch**: Fire MessageReceivedEvent
5. **Content Extraction**: Parse message type for text content
6. **Intent Classification**: Use AI to classify intent
7. **Action Resolution**: Map intent+context to action name
8. **Action Execution**: Execute via ActionRegistry
9. **Response Generation**: Build response messages
10. **Send Responses**: Call WhatsApp API
11. **Store Responses**: Persist outbound messages
12. **Event Dispatch**: Fire MessageSentEvent

**Message Type Handling**:

| Type | Content Extraction |
|------|-------------------|
| text | `text.body` |
| button | `button.text` or `button.payload` |
| interactive | `list_reply.id/title` or `button_reply.id/title` |
| location | Format: "Location: lat, lng" |
| image/video/audio/document | Extract `caption` if present |

**No-Retry Conditions**:
- `InvalidArgumentException` (validation errors)
- "already processed" error (idempotency duplicate)

### WebhookStatusProcessor

**Location**: `includes/Queue/Processors/WebhookStatusProcessor.php`

**Hook**: `wch_process_webhook_statuses`

**Priority**: `PRIORITY_NORMAL`

**Valid Statuses**: sent, delivered, read, failed

**Payload Structure**:

```php
[
    'message_id'   => string,    // WhatsApp message ID
    'status'       => string,    // sent|delivered|read|failed
    'timestamp'    => int,       // Status update time
    'metadata'     => array,     // display_phone_number, phone_number_id
    'recipient_id' => string,    // Optional recipient phone
]
```

**Processing Steps**:

1. Idempotency check on message_id
2. Find message via MessageRepository
3. Update message status in database
4. Dispatch MessageStatusUpdatedEvent

### WebhookErrorProcessor

**Location**: `includes/Queue/Processors/WebhookErrorProcessor.php`

**Hook**: `wch_process_webhook_errors`

**Priority**: `PRIORITY_NORMAL`

**Payload Structure**:

```php
[
    'code'       => int,        // Error code (e.g., 131047)
    'title'      => string,     // Error title
    'message'    => string,     // Error description
    'error_data' => array,      // Additional error details
    'timestamp'  => int,
    'metadata'   => array,
]
```

**Processing Steps**:

1. Log error with full context
2. Alert admin if critical error code
3. Optionally retry message delivery (based on error type)

## Extension Hooks

### Action Hooks

#### wch_webhook_received

Fires when webhook is received (before queueing).

```php
do_action('wch_webhook_received', $payload, $type);
```

**Parameters**:
- `$payload` (array) - Raw webhook payload
- `$type` (string) - Webhook type: 'message', 'status', 'error', 'payment'

**Example**:

```php
add_action('wch_webhook_received', function($payload, $type) {
    // Log all incoming webhooks
    error_log("Webhook received: {$type}");
}, 10, 2);
```

#### wch_webhook_message_processed

Fires after message processing completes successfully.

```php
do_action('wch_webhook_message_processed', $message, $conversation, $actionResult);
```

**Parameters**:
- `$message` (array) - Processed message data
- `$conversation` (Conversation) - Conversation object
- `$actionResult` (ActionResult) - Result from action execution

**Example**:

```php
add_action('wch_webhook_message_processed', function($message, $conversation, $actionResult) {
    // Track message processing in analytics
    MyAnalytics::track('message_processed', [
        'conversation_id' => $conversation->getId(),
        'message_type' => $message['type'],
        'action_success' => $actionResult->isSuccess(),
    ]);
}, 10, 3);
```

#### wch_webhook_status_updated

Fires after message status is updated.

```php
do_action('wch_webhook_status_updated', $messageId, $oldStatus, $newStatus);
```

**Parameters**:
- `$messageId` (string) - WhatsApp message ID
- `$oldStatus` (string) - Previous status
- `$newStatus` (string) - New status

#### wch_queue_job_failed

Fires when job fails and is sent to dead-letter queue.

```php
do_action('wch_queue_job_failed', $hook, $args, $reason, $errorMessage);
```

**Parameters**:
- `$hook` (string) - Job hook name
- `$args` (array) - Job payload
- `$reason` (string) - Failure reason constant
- `$errorMessage` (string) - Error message

### Filter Hooks

#### wch_webhook_signature_validation

Control whether to validate webhook signature.

```php
apply_filters('wch_webhook_signature_validation', $validate, $request);
```

**Parameters**:
- `$validate` (bool) - Whether to validate (default: true)
- `$request` (WP_REST_Request) - Request object

**Example** (disable for testing):

```php
add_filter('wch_webhook_signature_validation', function($validate) {
    return defined('WCH_TESTING') && WCH_TESTING ? false : $validate;
});
```

#### wch_queue_payload_wrapper

Modify payload before wrapping.

```php
apply_filters('wch_queue_payload_wrapper', $args, $hook, $priority);
```

**Parameters**:
- `$args` (array) - User payload
- `$hook` (string) - Job hook name
- `$priority` (int) - Priority level

**Example** (add custom metadata):

```php
add_filter('wch_queue_payload_wrapper', function($args, $hook, $priority) {
    $args['_custom_trace_id'] = wp_generate_uuid4();
    return $args;
}, 10, 3);
```

#### wch_processor_should_retry

Override retry decision for failed jobs.

```php
apply_filters('wch_processor_should_retry', $shouldRetry, $exception, $processor);
```

**Parameters**:
- `$shouldRetry` (bool) - Default retry decision
- `$exception` (\Throwable) - Exception thrown
- `$processor` (QueueProcessorInterface) - Processor instance

**Example** (never retry 404 errors):

```php
add_filter('wch_processor_should_retry', function($shouldRetry, $exception, $processor) {
    if ($exception instanceof NotFoundException) {
        return false;
    }
    return $shouldRetry;
}, 10, 3);
```

#### wch_processor_retry_delay

Customize retry delay calculation.

```php
apply_filters('wch_processor_retry_delay', $delay, $attempt, $processor);
```

**Parameters**:
- `$delay` (int) - Default delay in seconds
- `$attempt` (int) - Retry attempt number
- `$processor` (QueueProcessorInterface) - Processor instance

**Example** (faster retries):

```php
add_filter('wch_processor_retry_delay', function($delay, $attempt, $processor) {
    // Linear backoff: 10s, 20s, 30s
    return 10 * $attempt;
}, 10, 3);
```

### Custom Processors

You can register custom webhook processors for new event types.

**Step 1**: Create processor class:

```php
use WhatsAppCommerceHub\Queue\Processors\AbstractQueueProcessor;

class CustomEventProcessor extends AbstractQueueProcessor {

    public function getHookName(): string {
        return 'wch_process_custom_event';
    }

    public function getName(): string {
        return 'custom_event';
    }

    protected function process(array $args): void {
        // Process custom event
        $eventData = $args['event_data'];

        // Your custom logic here
        do_action('wch_custom_event_processed', $eventData);
    }

    protected function shouldRetry(\Throwable $e): bool {
        // Don't retry validation errors
        if ($e instanceof InvalidArgumentException) {
            return false;
        }
        return parent::shouldRetry($e);
    }
}
```

**Step 2**: Register processor:

```php
add_action('wch_register_queue_processors', function($registry) {
    $registry->register(new CustomEventProcessor());
});
```

**Step 3**: Queue custom events from webhook controller:

```php
add_action('wch_webhook_received', function($payload, $type) {
    if ($type === 'custom') {
        $queue = container()->get(QueueService::class);
        $queue->dispatch(
            'wch_process_custom_event',
            ['event_data' => $payload],
            QueueService::PRIORITY_NORMAL
        );
    }
}, 10, 2);
```

## Error Handling

### Idempotency Service

**Location**: `includes/Queue/IdempotencyService.php`

**Purpose**: Prevent duplicate processing of webhook events

**Scopes**:
- `SCOPE_WEBHOOK` - WhatsApp webhooks
- `SCOPE_NOTIFICATION` - Notification deliveries
- `SCOPE_ORDER` - Order processing
- `SCOPE_BROADCAST` - Broadcast campaigns
- `SCOPE_SYNC` - Catalog sync operations

**Usage**:

```php
$idempotency = container()->get(IdempotencyService::class);

// Try to claim processing rights
$claimed = $idempotency->claim($messageId, IdempotencyService::SCOPE_WEBHOOK);

if (!$claimed) {
    // Already processed, skip
    return;
}

// Process the message
processMessage($messageData);
```

**Atomic Claim Mechanism**:

```php
// Uses INSERT IGNORE for atomic first-writer-wins
INSERT IGNORE INTO wch_webhook_idempotency (message_id, scope, processed_at, expires_at)
VALUES ('msg_123', 'webhook', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))

// Returns true only if row was inserted (first claim)
```

### Dead Letter Queue

**Location**: `includes/Queue/DeadLetterQueue.php`

**Purpose**: Store failed jobs for manual review and replay

**Failure Reasons**:
- `REASON_EXCEPTION` - Unhandled exception
- `REASON_MAX_RETRIES` - Exceeded retry limit
- `REASON_INVALID_PAYLOAD` - Malformed job data
- `REASON_CIRCUIT_BREAK` - Circuit breaker activated

**Methods**:

```php
$dlq = container()->get(DeadLetterQueue::class);

// Add failed job to DLQ
$dlq->add(
    'wch_process_webhook_messages',
    $payload,
    DeadLetterQueue::REASON_MAX_RETRIES,
    'Failed after 3 attempts: API timeout'
);

// Get pending DLQ jobs
$pendingJobs = $dlq->getPending(50);

// Replay a job from DLQ
$dlq->replay($jobId);

// Dismiss a job (won't be retried)
$dlq->dismiss($jobId);

// Cleanup old entries (older than 30 days)
$dlq->cleanup(30 * DAY_IN_SECONDS);
```

### Retry Strategy

**Exponential Backoff**:

| Attempt | Delay | Total Time |
|---------|-------|------------|
| 1 | Immediate | 0s |
| 2 | 30s | 30s |
| 3 | 90s | 2m |
| 4 | 270s | 6.5m |

**No-Retry Scenarios**:
1. Validation errors (InvalidArgumentException)
2. Already processed (idempotency duplicate)
3. Resource not found (permanent failure)
4. Authentication errors (permanent failure)

**Retry Scenarios**:
1. Network timeouts
2. API rate limits (503, 429)
3. Temporary service unavailability
4. Database deadlocks

## Testing

### Unit Tests

**Location**: `tests/Unit/Queue/WebhookProcessorTest.php`

**Coverage**:
- Wrapped v2 payload handling
- Legacy unwrapped payload support
- Payload wrapping/unwrapping logic
- Message/status/error processor execution

**Example**:

```php
public function test_message_processor_handles_wrapped_payload() {
    $processor = new WebhookMessageProcessor();

    $wrappedPayload = [
        '_wch_version' => 2,
        '_wch_meta' => [
            'priority' => 1,
            'scheduled_at' => time(),
            'attempt' => 1,
        ],
        'args' => [
            'message_id' => 'wamid.test123',
            'from' => '+1234567890',
            'type' => 'text',
            'text' => ['body' => 'Hello'],
        ],
    ];

    $processor->execute($wrappedPayload);

    // Assert message was processed
    $this->assertDatabaseHas('wch_messages', [
        'wa_message_id' => 'wamid.test123',
        'direction' => 'incoming',
    ]);
}
```

### Integration Tests

**Location**: `tests/Integration/WCH_Webhook_Integration_Test.php`

**Coverage**:
- Webhook verification (valid/invalid tokens)
- Signature validation (HMAC-SHA256)
- Text message processing
- Interactive message processing
- Status update processing
- Duplicate message handling (idempotency)

**Example**:

```php
public function test_incoming_text_message_processed() {
    $payload = $this->getFixture('webhook_text_message.json');

    $request = new WP_REST_Request('POST', '/wch/v1/webhook');
    $request->set_body(wp_json_encode($payload));

    $response = $this->webhookHandler->handleWebhook($request);

    $this->assertEquals(200, $response->get_status());
    $this->assertDatabaseHas('wch_messages', [
        'type' => 'text',
        'direction' => 'incoming',
    ]);
}
```

### End-to-End Tests

**Location**: `tests/Integration/WCH_E2E_Conversation_Test.php`

**Coverage**:
- Complete conversation flows
- Message → Action → Response cycle
- State transitions
- Multi-turn conversations

**Test Fixtures**:

- `tests/fixtures/webhook_text_message.json`
- `tests/fixtures/webhook_button_reply.json`
- `tests/fixtures/webhook_status_update.json`

## Performance Considerations

### Rate Limiting

**Per-Priority Limits**:

| Priority | Jobs/Minute | Concurrent | Use Case |
|----------|-------------|------------|----------|
| CRITICAL | 1000 | Unlimited | System failures |
| URGENT | 100 | 10 | Customer messages |
| NORMAL | 50 | 5 | Status updates |
| BULK | 20 | 3 | Batch operations |
| MAINTENANCE | 10 | 1 | Cleanup jobs |

### Database Optimization

**Indexes**:

```sql
-- wch_messages
CREATE INDEX idx_wa_message_id ON wch_messages(wa_message_id);
CREATE INDEX idx_conversation_created ON wch_messages(conversation_id, created_at);

-- wch_webhook_idempotency
CREATE UNIQUE INDEX idx_message_scope ON wch_webhook_idempotency(message_id, scope);
CREATE INDEX idx_expires_at ON wch_webhook_idempotency(expires_at);

-- wch_dead_letter_queue
CREATE INDEX idx_hook_status ON wch_dead_letter_queue(hook, status);
CREATE INDEX idx_created_at ON wch_dead_letter_queue(created_at);
```

### Cleanup Jobs

**Scheduled Maintenance**:

```php
// Clean up old idempotency records (24 hours)
wp_schedule_event(time(), 'hourly', 'wch_cleanup_idempotency_keys');

// Clean up completed jobs (7 days)
wp_schedule_event(time(), 'daily', 'wch_cleanup_completed_jobs');

// Clean up failed jobs (30 days)
wp_schedule_event(time(), 'daily', 'wch_cleanup_failed_jobs');
```

### Monitoring

**Queue Health Metrics**:

```php
$queueService = container()->get(QueueService::class);

// Overall stats
$stats = $queueService->getStats();
// Returns: ['pending' => 42, 'failed' => 3, 'completed' => 1250]

// Per-priority stats
$statsByPriority = $queueService->getStatsByPriority();

// Health check
$health = $queueService->healthCheck();
// Returns: ['status' => 'healthy', 'pending_jobs' => 42, 'failed_jobs' => 3]
```

**Alert Thresholds**:
- Pending jobs > 1000: Warning
- Failed jobs > 100: Warning
- DLQ size > 500: Critical
- Processing lag > 5 minutes: Critical

## Related Documentation

- [Module Map](module-map.md) - Complete module overview
- [Boot Sequence](boot-sequence.md) - Plugin initialization
- [Hooks Reference](hooks-reference.md) - All WordPress hooks
- [Queue System](queue-system.md) - Priority queue details
- [Testing Guide](../tests/README.md) - Testing documentation

## Troubleshooting

### Common Issues

**Issue**: Messages not being processed

**Solution**:
1. Check queue health: `$queueService->healthCheck()`
2. Verify cron is running: `wp cron event list`
3. Check dead-letter queue: `$dlq->getPending()`
4. Review error logs: Check WP_DEBUG logs

**Issue**: Duplicate messages processed

**Solution**:
1. Verify idempotency service is active
2. Check database table: `wch_webhook_idempotency`
3. Ensure message IDs are unique
4. Check for clock skew issues

**Issue**: Webhook signature validation failing

**Solution**:
1. Verify app secret is correct in settings
2. Check header format: `X-Hub-Signature-256: sha256=...`
3. Ensure raw request body is used for HMAC
4. Test with signature disabled (development only)

---

**Last Updated**: 2026-01-18
**Version**: 2.0.0
