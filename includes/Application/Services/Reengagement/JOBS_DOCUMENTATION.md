# Reengagement Module - Scheduled Jobs Documentation

This document describes the scheduled jobs used by the Reengagement module, including their schedules, handlers, and payload schemas.

## Scheduled Jobs Overview

The Reengagement module uses Action Scheduler to manage three recurring jobs:

| Job Name | Schedule | Handler | Purpose |
|----------|----------|---------|---------|
| `wch_process_reengagement_campaigns` | Daily at 9:00 AM | `ReengagementServiceProvider::handleProcessCampaigns()` | Identifies inactive customers and queues personalized campaign messages |
| `wch_check_back_in_stock` | Hourly | `ReengagementServiceProvider::handleCheckBackInStock()` | Checks for products that are back in stock and notifies interested customers |
| `wch_send_reengagement_message` | Queued (on-demand) | `ReengagementServiceProvider::handleSendMessage()` | Sends individual campaign messages to customers |

---

## Job 1: `wch_process_reengagement_campaigns`

### Description
Daily job that identifies inactive customers and queues personalized reengagement messages based on customer behavior and preferences.

### Schedule
- **Frequency:** Once daily
- **Time:** 9:00 AM (site time)
- **First Run:** Tomorrow at 9:00 AM (from plugin activation)
- **Recurrence:** `DAY_IN_SECONDS` (86400 seconds)

### Handler
`ReengagementServiceProvider::handleProcessCampaigns()`

Calls: `ReengagementOrchestrator::processCampaigns()`

### Payload Schema
No payload required. This is a parameterless scheduled action.

```php
// Example scheduling
as_schedule_recurring_action(
    strtotime( 'tomorrow 9:00am' ),
    DAY_IN_SECONDS,
    'wch_process_reengagement_campaigns',
    [],  // No arguments
    'wch'
);
```

### Process Flow
1. Check if reengagement is enabled via settings
2. Identify inactive customers (default: no purchase in 60 days)
3. For each inactive customer:
   - Resolve best campaign type (back-in-stock, price drop, loyalty, etc.)
   - Queue a `wch_send_reengagement_message` job with customer details
4. Return count of queued messages

### Related Settings
- `reengagement.enabled` (bool) - Master switch for reengagement
- `reengagement.inactivity_threshold` (int) - Days of inactivity before customer is targeted (default: 60)

---

## Job 2: `wch_check_back_in_stock`

### Description
Hourly job that checks for products that were out of stock and are now available, then notifies customers who viewed those products.

### Schedule
- **Frequency:** Hourly
- **First Run:** Immediately (from plugin activation)
- **Recurrence:** `HOUR_IN_SECONDS` (3600 seconds)

### Handler
`ReengagementServiceProvider::handleCheckBackInStock()`

Calls: `ProductTrackingService::processBackInStockNotifications()`

### Payload Schema
No payload required. This is a parameterless scheduled action.

```php
// Example scheduling
as_schedule_recurring_action(
    time(),
    HOUR_IN_SECONDS,
    'wch_check_back_in_stock',
    [],  // No arguments
    'wch'
);
```

### Process Flow
1. Query `product_views` table for products marked as out of stock (`in_stock = 0`)
2. For each product, check current WooCommerce stock status
3. If product is now in stock:
   - Get all customers who viewed this product
   - Check frequency cap for each customer
   - Queue `wch_send_reengagement_message` with campaign type `back_in_stock`
   - Update `product_views` table to mark product as in stock
4. Return count of notifications queued

### Database Tables Used
- `{prefix}_product_views` - Tracks customer product views with stock status

---

## Job 3: `wch_send_reengagement_message`

### Description
On-demand job that sends an individual reengagement message to a specific customer. This job is queued by other processes (campaign processor, back-in-stock checker).

### Schedule
- **Frequency:** On-demand (queued by other jobs)
- **Delay:** 0 seconds (immediate processing)

### Handler
`ReengagementServiceProvider::handleSendMessage( array $args )`

Calls: `ReengagementOrchestrator::sendMessage( array $args )`

### Payload Schema

The job supports both **legacy** and **v2 wrapped** payload formats.

#### Legacy Format (Direct Arguments)
```php
[
    'customer_phone' => '+1234567890',  // Required: Customer phone number
    'campaign_type'  => 'back_in_stock' // Required: Campaign type constant
]
```

#### V2 Wrapped Format (Recommended)
```php
[
    '_wch_version' => 2,                // Version marker
    'args' => [
        'customer_phone' => '+1234567890',
        'campaign_type'  => 'back_in_stock'
    ],
    '_wch_meta' => [                    // Optional metadata
        'queued_at'   => '2024-01-15 10:30:00',
        'source'      => 'back_in_stock_checker',
        'priority'    => 'high'
    ]
]
```

#### Payload Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `customer_phone` | string | Yes | Customer's phone number in E.164 format (e.g., +1234567890) |
| `campaign_type` | string | Yes | Campaign type constant (see Campaign Types below) |

### Campaign Types

Available campaign types (defined in `CampaignTypeResolverInterface`):

| Constant | Value | Description |
|----------|-------|-------------|
| `TYPE_WE_MISS_YOU` | `we_miss_you` | Generic reengagement message |
| `TYPE_NEW_ARRIVALS` | `new_arrivals` | New products in customer's purchase categories |
| `TYPE_BACK_IN_STOCK` | `back_in_stock` | Previously viewed items back in stock |
| `TYPE_PRICE_DROP` | `price_drop` | Price reductions on viewed products |
| `TYPE_LOYALTY_REWARD` | `loyalty_reward` | Discount code for high-value customers (LTV-based) |

### Process Flow
1. Unwrap v2 payload if present
2. Validate required fields (`customer_phone`, `campaign_type`)
3. Get customer profile from database
4. Check frequency cap (max 1 per 7 days, 4 per 30 days)
5. Build personalized message based on campaign type
6. Send message via WhatsApp API
7. Log message in `reengagement_log` table
8. Return result with success status

### Example Usage

```php
// Queue a back-in-stock message (legacy format)
wch( JobDispatcher::class )->dispatch(
    'wch_send_reengagement_message',
    [
        'customer_phone' => '+1234567890',
        'campaign_type'  => 'back_in_stock',
    ],
    0  // Immediate
);

// Queue a loyalty reward message (v2 format)
wch( JobDispatcher::class )->dispatch(
    'wch_send_reengagement_message',
    [
        '_wch_version' => 2,
        'args' => [
            'customer_phone' => '+1234567890',
            'campaign_type'  => 'loyalty_reward',
        ],
        '_wch_meta' => [
            'source' => 'campaign_processor',
            'priority' => 'high',
        ]
    ],
    0
);
```

### Error Handling

The handler returns an array with the following structure:

```php
// Success
[
    'success'    => true,
    'message_id' => 'wamid.xxx...'  // WhatsApp message ID
]

// Failure
[
    'success' => false,
    'error'   => 'Error description'
]
```

Common errors:
- `Missing customer phone` - Required field not provided
- `Customer not found` - Profile doesn't exist in database
- `Frequency cap reached` - Customer has received too many messages recently
- `Failed to build message content` - Message builder returned null
- `WhatsApp API client not initialized` - API client not available

### Database Tables Used
- `{prefix}_customer_profiles` - Customer profile data
- `{prefix}_reengagement_log` - Message tracking and analytics
- `{prefix}_product_views` - Product tracking data (for back-in-stock and price drop campaigns)

---

## Frequency Cap Rules

To prevent over-messaging, the Reengagement module enforces frequency caps:

| Cap Type | Limit | Window |
|----------|-------|--------|
| Weekly | 1 message | 7 days |
| Monthly | 4 messages | 30 days |

Frequency caps are checked before sending any reengagement message.

---

## Database Schema

### `{prefix}_reengagement_log`

Tracks all sent reengagement messages for analytics and frequency capping.

```sql
CREATE TABLE {prefix}_reengagement_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_phone VARCHAR(20) NOT NULL,
    campaign_type VARCHAR(50) NOT NULL,
    message_id VARCHAR(255),
    status VARCHAR(20) DEFAULT 'sent',
    sent_at DATETIME NOT NULL,
    converted TINYINT(1) DEFAULT 0,
    order_id BIGINT UNSIGNED,
    converted_at DATETIME,
    PRIMARY KEY (id),
    INDEX idx_customer_phone (customer_phone),
    INDEX idx_campaign_type (campaign_type),
    INDEX idx_sent_at (sent_at)
);
```

### `{prefix}_product_views`

Tracks customer product views for back-in-stock and price drop notifications.

```sql
CREATE TABLE {prefix}_product_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_phone VARCHAR(20) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    price_at_view DECIMAL(10,2),
    in_stock TINYINT(1) DEFAULT 1,
    viewed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_customer_product (customer_phone, product_id),
    INDEX idx_product_stock (product_id, in_stock),
    INDEX idx_viewed_at (viewed_at)
);
```

---

## Monitoring and Debugging

### Check Job Status

```php
// Check if jobs are scheduled
$campaigns_scheduled = as_next_scheduled_action( 'wch_process_reengagement_campaigns', [], 'wch' );
$stock_scheduled = as_next_scheduled_action( 'wch_check_back_in_stock', [], 'wch' );

// Get pending send jobs
$pending_sends = as_get_scheduled_actions([
    'hook' => 'wch_send_reengagement_message',
    'status' => ActionScheduler_Store::STATUS_PENDING,
    'group' => 'wch'
]);
```

### Logs

All reengagement activities are logged via `LoggerInterface` with category `'reengagement'`:

- Campaign processing events
- Message sending results
- Conversion tracking
- API errors

### Analytics

Use `ReengagementAnalyticsInterface` to get performance metrics:

```php
$analytics = wch( ReengagementAnalyticsInterface::class );

// Get 30-day analytics by campaign type
$stats = $analytics->getAnalytics( 30 );

// Get conversion rate for specific campaign
$conversionRate = $analytics->getConversionRate( 'back_in_stock', 30 );

// Get attributed revenue
$revenue = $analytics->getAttributedRevenue( 30 );
```

---

## Configuration

### Enable/Disable Reengagement

```php
// Enable reengagement
wch( SettingsInterface::class )->set( 'reengagement.enabled', true );

// Disable reengagement
wch( SettingsInterface::class )->set( 'reengagement.enabled', false );
```

### Adjust Settings

```php
// Set inactivity threshold to 45 days
wch( SettingsInterface::class )->set( 'reengagement.inactivity_threshold', 45 );

// Set loyalty discount percentage
wch( SettingsInterface::class )->set( 'reengagement.loyalty_discount', 20 );

// Set minimum LTV for loyalty rewards
wch( SettingsInterface::class )->set( 'reengagement.loyalty_min_ltv', 1000.0 );
```

---

## Testing

### Manual Job Trigger

```php
// Manually trigger campaign processing
do_action( 'wch_process_reengagement_campaigns' );

// Manually trigger back-in-stock check
do_action( 'wch_check_back_in_stock' );

// Manually send a message
do_action( 'wch_send_reengagement_message', [
    'customer_phone' => '+1234567890',
    'campaign_type'  => 'we_miss_you'
]);
```

### Run via WP-CLI

```bash
# Process campaigns
wp action-scheduler run --hooks=wch_process_reengagement_campaigns

# Check back-in-stock
wp action-scheduler run --hooks=wch_check_back_in_stock

# Process queued messages
wp action-scheduler run --hooks=wch_send_reengagement_message
```
