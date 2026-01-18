# Uninstall Documentation

## Overview

When the WhatsApp Commerce Hub plugin is uninstalled from WordPress, it performs a complete cleanup of all plugin data to ensure no traces are left behind in your database.

## What Gets Removed

### 1. Database Tables

All custom database tables created by the plugin are dropped:

- `wp_wch_conversations` - WhatsApp conversation history
- `wp_wch_messages` - Individual messages sent and received
- `wp_wch_carts` - Shopping cart data
- `wp_wch_customer_profiles` - Customer information and preferences
- `wp_wch_broadcast_recipients` - Broadcast campaign recipients
- `wp_wch_sync_queue` - Product/order sync queue
- `wp_wch_notification_log` - Notification delivery logs
- `wp_wch_product_views` - Product view tracking
- `wp_wch_reengagement` - Re-engagement campaign data
- `wp_wch_rate_limits` - Rate limiting records
- `wp_wch_security_log` - Security event logs
- `wp_wch_webhook_idempotency` - Webhook deduplication
- `wp_wch_webhook_events` - Webhook event tracking

### 2. WordPress Options

All configuration and settings stored in WordPress options:

**Core Settings:**
- `wch_settings` - Main plugin settings
- `wch_settings_schema_version` - Settings schema version
- `wch_db_version` - Database schema version

**API & Integration:**
- `wch_catalog_id` - WhatsApp catalog ID
- `wch_whatsapp_catalog_id` - Alternative catalog ID
- `wch_openai_api_key` - OpenAI API key for AI features
- `wch_admin_email` - Admin notification email
- `wch_notify_admin_on_errors` - Error notification preference

**Payment Gateways:**
- `wch_default_payment_gateway` - Default payment method
- `wch_enabled_payment_methods` - Active payment methods
- `wch_stripe_secret_key` - Stripe API key
- `wch_stripe_webhook_secret` - Stripe webhook secret
- `wch_razorpay_key_id` - Razorpay key ID
- `wch_razorpay_key_secret` - Razorpay secret
- `wch_razorpay_webhook_secret` - Razorpay webhook secret
- `wch_pix_processor` - PIX payment processor
- `wch_pix_api_token` - PIX API token
- `wch_whatsapppay_config_name` - WhatsApp Pay configuration
- `wch_payment_*` - Dynamic payment session data (all)

**Features & Tracking:**
- `wch_ai_enabled` - AI features toggle
- `wch_catalog_sync_enabled` - Catalog sync toggle
- `wch_checkout_timeout` - Checkout session timeout
- `wch_sync_out_of_stock` - Sync out-of-stock products
- `wch_cod_fee_amount` - Cash on delivery fee
- `wch_cod_disabled_countries` - COD restricted countries
- `wch_message_templates` - Message templates
- `wch_templates_last_sync` - Template sync timestamp
- `wch_bulk_sync_progress` - Bulk sync progress tracker
- `wch_sync_history` - Sync operation history
- `wch_cart_cleanup_last_result` - Cart cleanup results
- `wch_stock_discrepancies` - Stock discrepancy data
- `wch_stock_discrepancy_count` - Discrepancy count
- `wch_stock_discrepancy_last_check` - Last check timestamp
- `wch_broadcast_campaigns` - Broadcast campaign data

**Security:**
- `wch_encryption_hkdf_salt` - Encryption salt
- `wch_encryption_key_versions` - Key version tracking

### 3. Transients (Temporary Cache)

All transient data with the `wch_` prefix:

- `wch_fallback_*` - Fallback cache data
- `wch_settings_backup` - Settings backup
- `wch_webhook_*` - Webhook response cache
- `wch_circuit_*` - Circuit breaker state
- `wch_template_usage_*` - Template usage statistics
- `wch_stock_sync_debounce_*` - Stock sync debouncing
- `wch_cart_*` - Cart session locks

### 4. Post Meta (Product Metadata)

All product metadata fields with the `_wch_` prefix:

- `_wch_sync_status` - Product sync status
- `_wch_last_synced` - Last sync timestamp
- `_wch_sync_error` - Last sync error message
- `_wch_sync_message` - Last sync status message
- `_wch_last_stock_sync` - Last stock sync timestamp
- `_wch_previous_stock` - Previous stock level

### 5. Scheduled Events (Cron Jobs)

All WordPress cron jobs scheduled by the plugin:

- `wch_cleanup_expired_carts` - Cart cleanup task
- `wch_rate_limit_cleanup` - Rate limit data cleanup
- `wch_security_log_cleanup` - Security log cleanup
- `wch_recover_pending_sagas` - Saga recovery task
- `wch_cleanup_dead_letter_queue` - Dead letter cleanup
- `wch_cleanup_idempotency_keys` - Idempotency cleanup
- `wch_detect_stock_discrepancies` - Stock validation
- `wch_schedule_recovery_reminders` - Cart recovery reminders
- `wch_process_reengagement_campaigns` - Re-engagement processing
- `wch_check_back_in_stock` - Back-in-stock notifications
- `wch_check_price_drops` - Price drop alerts

## What Does NOT Get Removed

The uninstall process preserves core WooCommerce data that may have been created through WhatsApp interactions:

### Preserved Data:
- **WooCommerce Orders** - Orders created through WhatsApp remain as regular WooCommerce orders
- **WooCommerce Customers** - Customer accounts are preserved
- **WooCommerce Products** - Products remain unchanged (only WhatsApp sync metadata is removed)
- **Order Notes** - Order notes and history are preserved
- **Customer Purchase History** - Remains intact in WooCommerce

This ensures that your business data and customer records are not lost when the plugin is removed.

## Uninstall Process

The uninstall is triggered automatically when you delete the plugin through WordPress admin:

1. Navigate to **Plugins** in WordPress admin
2. **Deactivate** the WhatsApp Commerce Hub plugin (if active)
3. Click **Delete** on the plugin
4. WordPress will execute `uninstall.php`
5. All plugin data is permanently removed

**WARNING:** This action cannot be undone. All WhatsApp conversations, messages, and plugin-specific data will be permanently deleted.

## Manual Cleanup (If Needed)

If you need to manually verify cleanup, you can run these SQL queries in phpMyAdmin or similar:

```sql
-- Check for remaining options
SELECT * FROM wp_options WHERE option_name LIKE 'wch_%';

-- Check for remaining transients
SELECT * FROM wp_options WHERE option_name LIKE '_transient_wch_%';

-- Check for remaining post meta
SELECT * FROM wp_postmeta WHERE meta_key LIKE '_wch_%';

-- Check for remaining tables
SHOW TABLES LIKE 'wp_wch_%';
```

All queries should return zero results after a successful uninstall.

## Code Reference

The uninstall logic is implemented in:
- `uninstall.php` - Main uninstall handler (line 45)
- `includes/Infrastructure/Database/DatabaseManager.php` - `uninstall()` method (line 649)

The uninstall process includes:
- `dropTables()` - Removes all database tables (line 600)
- `deleteOptions()` - Removes all WordPress options (line 673)
- `deleteTransients()` - Removes all transient cache (line 749)
- `deletePostMeta()` - Removes all product metadata (line 777)
- `clearScheduledEvents()` - Removes all cron jobs (line 802)

## Testing

Comprehensive unit tests verify complete cleanup:
- `tests/Unit/Infrastructure/Database/DatabaseManagerUninstallTest.php`

Run tests with:
```bash
composer test -- --filter DatabaseManagerUninstallTest
```

## Support

If you encounter any issues with the uninstall process or find remaining data after uninstall, please report it at:
https://github.com/your-repo/whatsapp-commerce-hub/issues
