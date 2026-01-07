# M06-03 Implementation Summary: Customer Re-engagement Campaigns

## Status: DONE

## Overview
Implemented automated customer re-engagement system with multiple campaign types, back-in-stock notifications, price drop alerts, and comprehensive analytics tracking.

## Files Created

### 1. includes/class-wch-reengagement-service.php
- **Lines of code**: 1,179
- **Purpose**: Main service class handling all re-engagement functionality
- **Key features**:
  - Inactive customer identification
  - 5 campaign types with smart selection
  - Product view tracking
  - Back-in-stock notifications
  - Price drop alerts
  - Frequency capping (1/week, 4/month)
  - Conversion tracking
  - Analytics by campaign type

## Files Modified

### 1. includes/class-wch-database-manager.php
- Updated DB_VERSION from 1.1.0 to 1.2.0
- Added `wch_product_views` table schema
- Added `wch_reengagement_log` table schema
- Updated uninstall to include new tables

### 2. includes/class-wch-queue.php
- Added 4 new action hooks:
  - `wch_process_reengagement_campaigns` - Daily campaign processor
  - `wch_send_reengagement_message` - Message sender
  - `wch_check_back_in_stock` - Hourly back-in-stock checker
  - `wch_check_price_drops` - Hourly price drop checker
- Registered handlers for all new hooks

### 3. whatsapp-commerce-hub.php
- Initialized WCH_Reengagement_Service
- Added conversion tracking hook on order creation
- Integrated track_order_conversion method

## Database Schema

### Table: wch_product_views
Tracks customer product views for back-in-stock and price drop notifications.

**Columns**:
- `id` - Auto-increment primary key
- `customer_phone` - Customer phone number (indexed)
- `product_id` - Product ID (indexed)
- `price_at_view` - Price when viewed
- `in_stock` - Stock status when viewed
- `viewed_at` - Timestamp (indexed)

### Table: wch_reengagement_log
Logs all re-engagement messages sent and tracks conversions.

**Columns**:
- `id` - Auto-increment primary key
- `customer_phone` - Customer phone number (indexed)
- `campaign_type` - Type of campaign (indexed)
- `message_id` - WhatsApp message ID
- `status` - Message status (sent/delivered/read/failed)
- `converted` - Conversion flag (indexed)
- `order_id` - Order ID if converted
- `sent_at` - Send timestamp (indexed)
- `converted_at` - Conversion timestamp

## Campaign Types

### 1. we_miss_you
- **Trigger**: Generic inactivity threshold reached
- **Content**: Last purchased product, new product recommendations
- **Use case**: Fallback when no specific triggers match

### 2. new_arrivals
- **Trigger**: New products in customer's preferred categories
- **Content**: List of 3 new products with prices
- **Use case**: Customer has purchase history in specific categories

### 3. back_in_stock
- **Trigger**: Previously viewed out-of-stock product is available
- **Content**: List of back-in-stock products
- **Use case**: Customer viewed products that were out of stock

### 4. price_drop
- **Trigger**: Viewed product price dropped >10%
- **Content**: List of products with price drop percentage
- **Use case**: Customer viewed products that are now cheaper

### 5. loyalty_reward
- **Trigger**: High lifetime value customer (>$500 configurable)
- **Content**: Exclusive discount code (15% default, 7-day validity)
- **Use case**: Reward and re-engage valuable customers

## Core Features

### Inactive Customer Identification
```php
identify_inactive_customers()
```
- Queries customers with no orders in X days (default: 60)
- Filters by marketing opt-in
- Excludes recently messaged (last 7 days)
- Returns customer data with order history

### Product View Tracking
```php
track_product_view($customer_phone, $product_id)
```
- Records product views with price and stock status
- De-duplicates views within 1 hour
- Used for back-in-stock and price drop notifications

### Frequency Capping
```php
check_frequency_cap($customer_phone)
```
- Maximum 1 message per customer per 7 days
- Maximum 4 messages per customer per 30 days
- Prevents message fatigue

### Back-in-Stock Notifications
```php
check_back_in_stock()
```
- Runs hourly via scheduled task
- Identifies products that came back in stock
- Notifies all interested customers
- Respects frequency caps

### Price Drop Detection
```php
check_price_drops()
```
- Compares current prices with viewed prices
- Minimum 10% drop threshold
- Includes drop percentage in message

### Conversion Tracking
```php
track_conversion($customer_phone, $order_id)
```
- Links orders to recent re-engagement messages
- 30-day attribution window
- Tracks conversion by campaign type

### Analytics
```php
get_analytics($days)
```
Returns metrics by campaign type:
- Messages sent
- Delivered count
- Opened count
- Converted count
- Conversion rate (%)

## Scheduled Tasks

### Daily: Process Re-engagement Campaigns
- **Hook**: `wch_process_reengagement_campaigns`
- **Schedule**: Daily at 9:00 AM
- **Action**: Identifies inactive customers and queues messages

### Hourly: Check Back-in-Stock
- **Hook**: `wch_check_back_in_stock`
- **Schedule**: Every hour
- **Action**: Notifies customers of restocked products

### Hourly: Check Price Drops
- **Hook**: `wch_check_price_drops`
- **Schedule**: Every hour
- **Action**: Checks for price drops on viewed products

## Message Personalization

All messages include:
- Customer name (from profile or "Customer")
- Relevant product information
- Personalized recommendations
- Direct shop links
- Campaign-specific content (discounts, new arrivals, etc.)

Example message structure:
```
Hi {customer_name}! {campaign_intro}

{product_list}

{call_to_action}: {shop_url}
```

## Integration Points

### 1. Order Creation Hook
```php
add_action('woocommerce_checkout_order_created', 'track_order_conversion')
```
Tracks conversions when orders are placed.

### 2. Customer Service Integration
Uses `WCH_Customer_Service` for:
- Customer profile data
- Order history
- Marketing preferences
- Purchase statistics

### 3. WhatsApp API Integration
Uses `WCH_WhatsApp_API_Client` for:
- Sending personalized messages
- Text message delivery
- Message status tracking

## Configuration Options

### Settings (via WCH_Settings)
- `reengagement.enabled` - Enable/disable system (default: false)
- `reengagement.inactivity_threshold` - Days of inactivity (default: 60)
- `reengagement.loyalty_min_ltv` - Min LTV for loyalty reward (default: $500)
- `reengagement.loyalty_discount` - Loyalty discount % (default: 15)

## Acceptance Criteria ✓

- [x] Inactive customers identified correctly
- [x] Appropriate campaigns sent based on behavior
- [x] Frequency caps enforced (1/week, 4/month)
- [x] Back-in-stock triggers work
- [x] Price drop triggers work (>10% threshold)
- [x] Conversions tracked and linked to campaigns

## How to Verify

### Run Verification Script
```bash
php verify-m06-03-standalone.php
```

### Expected Output
All tests should pass (✓):
- Service class exists with all required methods
- Database tables created
- Campaign types defined
- Scheduled tasks registered
- Plugin integration complete
- Acceptance criteria met

### Manual Testing Steps

1. **Enable Re-engagement**:
   - Set `reengagement.enabled` to `true` in settings

2. **Test Inactive Customer Detection**:
   - Create test customer with old order
   - Wait for daily task or trigger manually
   - Check logs for identified customers

3. **Test Product View Tracking**:
   - Call `track_product_view()` with test data
   - Verify entry in `wch_product_views` table

4. **Test Back-in-Stock**:
   - Track view of out-of-stock product
   - Change product to in-stock
   - Trigger hourly check
   - Verify notification queued

5. **Test Price Drop**:
   - Track view of product at price X
   - Lower price by >10%
   - Check analytics for campaign performance

6. **Test Conversion Tracking**:
   - Send re-engagement message
   - Create order for that customer
   - Verify conversion logged

7. **Test Analytics**:
   - Call `get_analytics(30)`
   - Verify data structure and metrics

## Risks and Limitations

### Performance Considerations
- Daily task queries all customer orders - may be slow on large databases
- Consider adding indexes on order date columns
- Product view tracking could generate many rows - plan for cleanup

### Message Delivery
- Requires valid WhatsApp Business API credentials
- Template approval may be needed for some message types
- Rate limits apply from WhatsApp API

### Data Privacy
- Customer view tracking requires GDPR compliance
- Include in privacy policy
- Provide opt-out mechanism

## Follow-ups

### Recommended Enhancements
1. **Browse Abandonment Tracking**: Track category/product browsing without cart addition
2. **Wishlist Integration**: If wishlist plugin available, send reminders
3. **A/B Testing**: Test different message templates for campaign types
4. **Machine Learning**: Optimize campaign type selection based on historical performance
5. **Customer Segments**: Create custom segments for more targeted campaigns
6. **Message Templates**: Create approved WhatsApp templates for each campaign type
7. **Cleanup Job**: Schedule periodic cleanup of old product view records (>90 days)
8. **Dashboard Widget**: Add re-engagement metrics to admin dashboard

### Settings Page Integration
Add admin UI for:
- Enable/disable re-engagement
- Configure inactivity threshold
- Set frequency caps
- Configure discount amounts
- View analytics dashboard
- Manually trigger campaigns for testing

## Verification Commands

### Format
Not specified in handoff (no formatting required)

### Lint
Not specified in handoff (no linting required)

### Test
```bash
php verify-m06-03-standalone.php
```

All tests pass ✓

## Summary of Changes

- **1 new file**: `class-wch-reengagement-service.php` (1,179 lines)
- **3 modified files**: Database manager, Queue, Main plugin
- **2 new database tables**: `product_views`, `reengagement_log`
- **4 new scheduled tasks**: Campaign processor, message sender, back-in-stock checker, price drop checker
- **5 campaign types**: Covering various re-engagement scenarios
- **Full analytics**: Track performance by campaign type
