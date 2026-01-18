# Broadcasts Module - Architecture & Implementation Guide

**Module:** C02-08 - Broadcasts persistence and async processing
**Version:** 3.0.0
**Last Updated:** 2026-01-18

---

## Table of Contents

1. [Overview](#overview)
2. [Persistence Strategy](#persistence-strategy)
3. [Queue Processing](#queue-processing)
4. [Admin UI Boundaries](#admin-ui-boundaries)
5. [Data Flow](#data-flow)
6. [Testing](#testing)
7. [Configuration](#configuration)
8. [Troubleshooting](#troubleshooting)

---

## Overview

The Broadcasts module enables store administrators to send bulk WhatsApp messages to customers using approved templates. The system supports:

- Campaign creation with multi-step wizard
- Audience segmentation
- Template personalization
- Scheduled and immediate sending
- Batch processing with rate limiting
- Real-time delivery tracking
- Campaign analytics

### Key Components

| Component | Purpose | Location |
|-----------|---------|----------|
| CampaignRepository | CRUD operations for campaigns | `includes/Application/Services/Broadcasts/CampaignRepository.php` |
| CampaignDispatcher | Campaign scheduling and dispatch | `includes/Application/Services/Broadcasts/CampaignDispatcher.php` |
| BroadcastBatchProcessor | Batch message sending | `includes/Application/Services/Broadcasts/BroadcastBatchProcessor.php` |
| AudienceCalculator | Recipient selection | `includes/Application/Services/Broadcasts/AudienceCalculator.php` |
| BroadcastTemplateBuilder | WhatsApp template components | `includes/Application/Services/Broadcasts/BroadcastTemplateBuilder.php` |
| AdminBroadcastsController | Admin page routing | `includes/Admin/Broadcasts/AdminBroadcastsController.php` |
| BroadcastsAjaxHandler | AJAX request handling | `includes/Admin/Broadcasts/BroadcastsAjaxHandler.php` |

---

## Persistence Strategy

### Storage Decision: WordPress Options API

**Decision:** Campaigns are stored using WordPress `wp_options` table with the option name `wch_broadcast_campaigns`.

**Rationale:**

1. **Simplicity**: No custom tables needed for campaign metadata
2. **Low Volume**: Expected campaign count is low-to-medium (< 1000)
3. **WordPress Native**: Leverages built-in caching and optimization
4. **Easy Backup**: Campaigns included in standard WordPress backups
5. **Migration-Free**: No schema changes required

**Trade-offs:**

| Advantage | Disadvantage |
|-----------|--------------|
| Zero migration overhead | Full array loaded on each read |
| WordPress caching benefits | Not suitable for high volume (> 1000 campaigns) |
| Simple querying via `get_option()` | No relational queries |
| Automatic serialization | Limited search capabilities |

### Campaign Data Structure

```php
[
    'id'              => int,         // Microtime-based unique ID
    'name'            => string,      // Campaign name
    'template_name'   => string,      // WhatsApp template name
    'template_data'   => array,       // Template metadata
    'audience'        => array,       // Segmentation criteria
    'audience_size'   => int,         // Calculated recipient count
    'personalization' => array,       // Variable mappings
    'schedule'        => array,       // Timing configuration
    'status'          => string,      // draft|scheduled|sending|completed|failed|cancelled
    'created_at'      => datetime,    // Creation timestamp
    'updated_at'      => datetime,    // Last modification timestamp
    'scheduled_at'    => datetime,    // Scheduled execution time (optional)
    'sent_at'         => datetime,    // Actual send time (optional)
    'stats'           => array,       // Execution statistics
    'job_id'          => string,      // Queue job identifier (optional)
]
```

### Recipient Tracking

**Separate Table:** `wp_wch_broadcast_recipients`

Delivery tracking uses a custom table for scalability:

```sql
CREATE TABLE wp_wch_broadcast_recipients (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id BIGINT(20) UNSIGNED NOT NULL,
    phone VARCHAR(20) NOT NULL,
    wa_message_id VARCHAR(100) NULL,
    status ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY campaign_phone (campaign_id, phone),
    KEY phone (phone),
    KEY sent_at (sent_at),
    KEY campaign_id (campaign_id)
)
```

**Why separate table?**
- Scalability: Thousands of recipients per campaign
- Deduplication: Prevent duplicate sends via unique constraint
- Performance: Indexed queries for recipient lookup
- Webhooks: Track delivery/read status updates

### When to Consider Migration

Consider migrating to custom tables if:
- Campaign count exceeds 1,000
- Frequent campaign list queries causing performance issues
- Need for complex relational queries (JOIN operations)
- Campaign data size causing wp_options bloat

---

## Queue Processing

### Architecture: Unified Queue Convention

Broadcasts use the **WhatsAppCommerceHub\Queue\PriorityQueue** wrapper around Action Scheduler.

#### Queue Hook

```php
'wch_send_broadcast_batch' // Registered in BroadcastsServiceProvider
```

#### Priority Level

```php
PriorityQueue::PRIORITY_NORMAL // Standard priority (50 jobs/minute)
```

#### Batching Strategy

1. **Batch Size**: 50 recipients per batch (configurable)
2. **Delay**: 1-second stagger between batches
3. **Parallelism**: Multiple batches process concurrently
4. **Rate Limiting**: Respects WhatsApp API rate limits

### Dispatch Flow

```
CampaignDispatcher::schedule()
    ↓
AudienceCalculator::getRecipients()
    ↓
Split into batches (50 each)
    ↓
For each batch:
    JobDispatcher::dispatch('wch_send_broadcast_batch', $args, $batchDelay)
        ↓
    PriorityQueue::schedule($hook, $args, PRIORITY_NORMAL, $delay)
        ↓
    Action Scheduler enqueues job
```

### Batch Processing

```
Action Scheduler executes 'wch_send_broadcast_batch'
    ↓
BroadcastBatchProcessor::handle($args)
    ↓
For each recipient in batch:
    1. Fetch customer profile
    2. Build personalized template
    3. WhatsAppApiClient::sendTemplate()
    4. Record in wch_broadcast_recipients
    5. Update campaign stats
    ↓
Update campaign status (scheduled → sending → completed)
```

### Job Payload Structure

```php
[
    'job_id'        => 'broadcast_{campaign_id}_{timestamp}',
    'batch'         => ['+1234567890', '+1234567891', ...], // 50 phones
    'batch_num'     => 0,                                    // 0-based index
    'total_batches' => 10,
    'campaign_id'   => 123,
    'message'       => [
        'template_name' => 'welcome_message',
        'template_data' => [...],
        'variables'     => [...],
    ],
]
```

### Status Transitions

```
draft → scheduled (on schedule with delay)
draft → sending   (on immediate send)
scheduled → sending (on first batch execution)
sending → completed (after last batch)
sending → failed (on critical error)
scheduled → cancelled (on manual cancel)
```

### Reliability Features

1. **Atomic Status Updates**: Repository methods use database transactions
2. **Idempotency**: Batch processing handles duplicate executions
3. **Error Tracking**: Last 100 errors stored in campaign stats
4. **Dead Letter Queue**: Failed jobs moved to DLQ for manual retry
5. **Job Cancellation**: Scheduled campaigns can be cancelled before execution

---

## Admin UI Boundaries

### Access Control

**Required Capability:** `manage_woocommerce`

All broadcast operations require WooCommerce admin permission.

### Menu Structure

```
WooCommerce
    └─ Broadcasts (wch-broadcasts)
        ├─ List View (default)
        ├─ Create Campaign (?action=create)
        ├─ Edit Campaign (?action=edit&campaign_id=X)
        └─ View Report (?action=report&campaign_id=X)
```

### AJAX Endpoints

All endpoints require `manage_woocommerce` capability and nonce verification.

| Action | Hook | Purpose |
|--------|------|---------|
| `wch_get_campaigns` | List all campaigns | Campaign list page |
| `wch_get_campaign` | Get single campaign | Edit form population |
| `wch_save_campaign` | Save draft campaign | Campaign creation/editing |
| `wch_delete_campaign` | Delete campaign | Campaign deletion |
| `wch_duplicate_campaign` | Duplicate campaign | Campaign copying |
| `wch_get_audience_count` | Calculate recipients | Real-time audience preview |
| `wch_send_campaign` | Schedule/send campaign | Campaign dispatch |
| `wch_send_test_broadcast` | Send test message | Template testing |
| `wch_get_campaign_report` | Fetch statistics | Report generation |
| `wch_get_approved_templates` | List templates | Template selection |

### Campaign Wizard

**5-Step Process:**

1. **Template Selection**
   - Fetches approved WhatsApp templates via AJAX
   - Validates template status (must be APPROVED)
   - Displays template preview

2. **Audience Selection**
   - Checkboxes for segmentation criteria:
     - All customers
     - Recent orders (1-365 days)
     - Product category
     - Cart abandoners
   - Exclusion: Recently messaged customers (1-30 days)
   - Real-time count calculation via AJAX

3. **Personalization**
   - Maps template variables to data sources:
     - `customer_name`: From customer profile
     - `static`: Fixed text value
     - `product_name`: From order data
     - `coupon_code`: From WooCommerce coupon

4. **Schedule**
   - Send immediately or schedule for future
   - Date/time picker with timezone selection
   - Cost estimation display

5. **Review**
   - Summary of all selections
   - Cost breakdown
   - Confirmation button

### UI Assets

| Asset | Purpose | Location |
|-------|---------|----------|
| admin-broadcasts.js | Wizard logic, AJAX handling | `assets/admin-broadcasts.js` |
| admin-broadcasts.css | Admin styling | `assets/admin-broadcasts.css` |
| BroadcastWizardRenderer | Server-side HTML generation | `includes/Admin/Broadcasts/BroadcastWizardRenderer.php` |

### Security Measures

1. **Nonce Verification**: All AJAX requests verified
2. **Capability Checks**: `manage_woocommerce` required
3. **Input Sanitization**: All user input sanitized via `sanitize_text_field()`, `absint()`, etc.
4. **SQL Injection Prevention**: Prepared statements for all queries
5. **XSS Protection**: Output escaped via `esc_html()`, `esc_attr()`, etc.

---

## Data Flow

### Campaign Creation Flow

```
Admin UI (Wizard)
    ↓
AJAX: wch_save_campaign
    ↓
BroadcastsAjaxHandler::saveCampaign()
    ↓
CampaignRepository::save()
    ↓
WordPress Options API
    ↓
update_option('wch_broadcast_campaigns', $campaigns)
```

### Campaign Send Flow

```
Admin UI (Send Button)
    ↓
AJAX: wch_send_campaign
    ↓
BroadcastsAjaxHandler::sendCampaign()
    ↓
CampaignDispatcher::schedule()
    ↓
AudienceCalculator::getRecipients()
    ↓
JobDispatcher::dispatch() (for each batch)
    ↓
PriorityQueue::schedule()
    ↓
Action Scheduler
```

### Batch Processing Flow

```
Action Scheduler (cron)
    ↓
BroadcastBatchProcessor::handle()
    ↓
getRecipientProfiles() (DB query)
    ↓
For each recipient:
    BroadcastTemplateBuilder::buildComponents()
    WhatsAppApiClient::sendTemplate()
    recordRecipient() (DB insert)
    ↓
updateCampaignStats()
    ↓
CampaignRepository::updateStats()
```

### Webhook Status Update Flow

```
WhatsApp Webhook
    ↓
WebhookProcessor
    ↓
UPDATE wch_broadcast_recipients
    SET status = 'delivered'/'read'
    ↓
Campaign stats recalculated (async)
```

---

## Testing

### Test Coverage

| Component | Test File | Status |
|-----------|-----------|--------|
| CampaignRepository | `tests/Unit/Services/Broadcasts/CampaignRepositoryTest.php` | ✅ Implemented |
| CampaignDispatcher | `tests/Unit/Services/Broadcasts/CampaignDispatcherTest.php` | ✅ Implemented |
| BroadcastBatchProcessor | `tests/Unit/Services/Broadcasts/BroadcastBatchProcessorTest.php` | ⚠️ TODO |
| AudienceCalculator | `tests/Unit/Services/Broadcasts/AudienceCalculatorTest.php` | ⚠️ TODO |
| Admin AJAX Handlers | `tests/Unit/Admin/Broadcasts/BroadcastsAjaxHandlerTest.php` | ⚠️ TODO |

### Running Tests

```bash
# Run all broadcast tests
vendor/bin/phpunit --group broadcasts

# Run specific test
vendor/bin/phpunit tests/Unit/Services/Broadcasts/CampaignRepositoryTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/ --group broadcasts
```

### Test Scenarios Covered

**CampaignRepository:**
- ✅ Create new campaign
- ✅ Update existing campaign
- ✅ Retrieve by ID
- ✅ Delete campaign
- ✅ Duplicate campaign
- ✅ Update status
- ✅ Update stats
- ✅ Data sanitization
- ✅ Sorting by created_at

**CampaignDispatcher:**
- ✅ Schedule with recipients
- ✅ Schedule with no recipients (returns null)
- ✅ Schedule with delay (scheduled status)
- ✅ Schedule without delay (sending status)
- ✅ Build message structure
- ✅ Calculate estimated cost
- ✅ Cancel scheduled campaign
- ✅ Cancel non-scheduled campaign (returns false)

---

## Configuration

### Constants

```php
// CampaignDispatcher
protected const BATCH_SIZE = 50;           // Recipients per batch
protected const COST_PER_MESSAGE = 0.0058; // USD per message

// AudienceCalculator
protected const MAX_RECIPIENTS = 100000;   // Safety limit

// PriorityQueue
public const PRIORITY_NORMAL = 3;          // Queue priority level
```

### Hooks & Filters

```php
// Queue hook (registered in BroadcastsServiceProvider)
add_action( 'wch_send_broadcast_batch', 'handler', 10, 1 );

// Admin init (registered in AdminBroadcastsController)
add_action( 'admin_menu', 'register_menu' );
add_action( 'admin_enqueue_scripts', 'enqueue_assets' );
```

### Options

```php
// Campaign storage
'wch_broadcast_campaigns' => array(
    // Array of campaigns
)

// Settings
'wch_settings' => [
    'api' => [
        'test_phone' => '+1234567890', // For test sends
    ],
]
```

---

## Troubleshooting

### Common Issues

#### 1. Campaigns Not Sending

**Symptoms:** Campaign stuck in "scheduled" status

**Causes:**
- Action Scheduler not running (WP-Cron disabled)
- Job queue paused
- WhatsApp API credentials invalid

**Solutions:**
```bash
# Check Action Scheduler status
wp action-scheduler status

# Process queue manually
wp action-scheduler run

# Check queue for pending jobs
wp action-scheduler list --status=pending --hook=wch_send_broadcast_batch
```

#### 2. High Failure Rate

**Symptoms:** Many recipients in "failed" status

**Causes:**
- Invalid phone numbers
- Template not approved
- WhatsApp API rate limiting

**Solutions:**
- Verify template approval status in Meta Business Manager
- Check phone number format (E.164 with country code)
- Review error log in campaign stats
- Reduce batch size or add delays

#### 3. Slow Performance

**Symptoms:** Campaign list page loads slowly

**Causes:**
- Large number of campaigns (> 1000)
- wp_options query slow

**Solutions:**
- Consider migrating to custom table
- Implement pagination in getAll()
- Add transient caching for campaign list

#### 4. Duplicate Messages

**Symptoms:** Recipients receive same message multiple times

**Causes:**
- Race condition in batch processing
- Job retry without deduplication

**Solutions:**
- Check wch_broadcast_recipients for duplicates
- Verify unique constraint on (campaign_id, phone)
- Review Action Scheduler for duplicate jobs

### Debug Mode

Enable debug logging:

```php
// wp-config.php
define( 'WCH_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Log location: `wp-content/debug.log`

### Monitoring Queries

```sql
-- Check campaign count
SELECT COUNT(*) FROM wp_options WHERE option_name = 'wch_broadcast_campaigns';

-- Check recipient tracking
SELECT
    status,
    COUNT(*) as count
FROM wp_wch_broadcast_recipients
WHERE campaign_id = 123
GROUP BY status;

-- Check failed jobs
SELECT
    action_id,
    hook,
    status,
    scheduled_date_gmt
FROM wp_actionscheduler_actions
WHERE hook = 'wch_send_broadcast_batch'
    AND status = 'failed'
ORDER BY scheduled_date_gmt DESC
LIMIT 20;
```

---

## Future Enhancements

### Potential Improvements

1. **Custom Table Migration**
   - Implement if campaign count exceeds 1,000
   - Add migration script in DatabaseManager
   - Maintain backward compatibility

2. **Advanced Segmentation**
   - Customer lifetime value
   - Purchase frequency
   - Product preferences
   - Geographic location

3. **A/B Testing**
   - Split campaigns with different templates
   - Compare delivery/read rates
   - Statistical significance testing

4. **Scheduled Optimization**
   - Best time to send analysis
   - Timezone-aware scheduling
   - Delivery rate optimization

5. **Enhanced Analytics**
   - Conversion tracking
   - Revenue attribution
   - Campaign ROI calculation
   - Heatmap visualization

---

## References

### Code References

- **CampaignRepository**: `includes/Application/Services/Broadcasts/CampaignRepository.php:27`
- **CampaignDispatcher**: `includes/Application/Services/Broadcasts/CampaignDispatcher.php:33`
- **BroadcastBatchProcessor**: `includes/Application/Services/Broadcasts/BroadcastBatchProcessor.php:29`
- **Queue Hook Registration**: `includes/Providers/BroadcastsServiceProvider.php:150`
- **Admin Controller**: `includes/Admin/Broadcasts/AdminBroadcastsController.php`

### Related Documentation

- [Queue System Architecture](../docs/queue-architecture.md)
- [WhatsApp API Integration](../docs/whatsapp-api.md)
- [Database Schema](../docs/database-schema.md)
- [Testing Guide](../docs/testing-guide.md)

---

**Document Version:** 1.0
**Last Review:** 2026-01-18
**Next Review:** 2026-04-18
