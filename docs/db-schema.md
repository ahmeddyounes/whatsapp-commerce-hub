# Database Schema Documentation

This document provides a comprehensive inventory of all database tables used by the WhatsApp Commerce Hub plugin, including ownership, lifecycle management, and references.

## Table of Contents

1. [Core Tables](#core-tables)
2. [Queue & Background Processing Tables](#queue--background-processing-tables)
3. [Resilience & Distributed Transaction Tables](#resilience--distributed-transaction-tables)
4. [Table Prefix](#table-prefix)
5. [Installation & Lifecycle](#installation--lifecycle)
6. [Table Reference by Module](#table-reference-by-module)

---

## Core Tables

All core tables are managed by `DatabaseManager` class and are created/dropped during plugin activation/uninstallation.

**Owner:** `includes/Infrastructure/Database/DatabaseManager.php`

### 1. wch_conversations

**Purpose:** Stores WhatsApp conversation threads and their metadata.

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `customer_phone` (varchar 20, indexed)
- `wa_conversation_id` (varchar 255)
- `status` (varchar 20, indexed) - e.g., 'active', 'resolved'
- `state` (varchar 50, indexed) - conversation state machine
- `assigned_agent_id` (bigint)
- `context` (longtext) - JSON context data
- `message_count` (int)
- `unread_count` (int, indexed)
- `last_message_at` (datetime, indexed)
- `created_at` (datetime)
- `updated_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `customer_phone` (`customer_phone`)
- KEY `last_message_at` (`last_message_at`)
- KEY `status` (`status`)
- KEY `state` (`state`)
- KEY `unread_count` (`unread_count`)

**Created:** `DatabaseManager::install()` line 185-206
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 2. wch_messages

**Purpose:** Stores individual WhatsApp messages (inbound and outbound).

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `conversation_id` (bigint, indexed)
- `direction` (enum: 'inbound', 'outbound')
- `type` (varchar 50) - message type (text, image, etc.)
- `wa_message_id` (varchar 255, unique indexed)
- `content` (longtext)
- `raw_payload` (longtext) - original webhook payload
- `status` (varchar 20, indexed)
- `retry_count` (int)
- `error_message` (text)
- `created_at` (datetime, indexed)
- `updated_at` (datetime)
- `sent_at` (datetime)
- `delivered_at` (datetime)
- `read_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `wa_message_id` (`wa_message_id`)
- KEY `conversation_id` (`conversation_id`)
- KEY `created_at` (`created_at`)
- KEY `status` (`status`)

**Created:** `DatabaseManager::install()` line 214-237
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 3. wch_carts

**Purpose:** Stores customer shopping carts and abandoned cart recovery data.

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `customer_phone` (varchar 20, unique indexed)
- `items` (longtext) - JSON array of cart items
- `total` (decimal 10,2)
- `coupon_code` (varchar 50)
- `shipping_address` (longtext) - JSON
- `status` (varchar 20, indexed) - 'active', 'abandoned', 'recovered', 'expired'
- `reminder_1_sent_at` (datetime)
- `reminder_2_sent_at` (datetime)
- `reminder_3_sent_at` (datetime)
- `abandoned_at` (datetime)
- `recovery_coupon_code` (varchar 50)
- `recovered` (tinyint 1, indexed)
- `recovered_order_id` (bigint)
- `recovered_revenue` (decimal 10,2)
- `recovered_at` (datetime)
- `expires_at` (datetime, indexed)
- `created_at` (datetime)
- `updated_at` (datetime, indexed)

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `customer_phone` (`customer_phone`)
- KEY `status` (`status`)
- KEY `updated_at` (`updated_at`)
- KEY `expires_at` (`expires_at`)
- KEY `recovered` (`recovered`)

**Created:** `DatabaseManager::install()` line 245-274
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 4. wch_customer_profiles

**Purpose:** Stores customer profile data and preferences.

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `phone` (varchar 20, unique indexed)
- `wc_customer_id` (bigint, indexed) - WooCommerce customer ID
- `name` (varchar 255)
- `email` (varchar 255)
- `last_known_address` (longtext) - JSON
- `saved_addresses` (longtext) - JSON array
- `preferences` (longtext) - JSON
- `tags` (longtext) - JSON array
- `total_orders` (int, indexed)
- `total_spent` (decimal 10,2, indexed)
- `opt_in_marketing` (tinyint 1)
- `notification_opt_out` (tinyint 1)
- `last_interaction_at` (datetime)
- `marketing_opted_at` (datetime)
- `created_at` (datetime)
- `updated_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `phone` (`phone`)
- KEY `wc_customer_id` (`wc_customer_id`)
- KEY `total_orders` (`total_orders`)
- KEY `total_spent` (`total_spent`)

**Created:** `DatabaseManager::install()` line 283-307
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 5. wch_broadcast_recipients

**Purpose:** Tracks broadcast campaign recipients and delivery status.

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `campaign_id` (bigint, indexed)
- `phone` (varchar 20, indexed)
- `wa_message_id` (varchar 255)
- `status` (varchar 20)
- `sent_at` (datetime, indexed)
- `created_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `campaign_phone` (`campaign_id`, `phone`)
- KEY `phone` (`phone`)
- KEY `sent_at` (`sent_at`)
- KEY `campaign_id` (`campaign_id`)

**Created:** `DatabaseManager::install()` line 316-330
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 6. wch_sync_queue

**Purpose:** Manages product/order/inventory synchronization queue.

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `entity_type` (varchar 50) - 'product', 'order', 'inventory'
- `entity_id` (bigint)
- `action` (varchar 50) - 'create', 'update', 'delete'
- `status` (varchar 20, indexed) - 'pending', 'processing', 'completed', 'failed'
- `attempts` (int)
- `error_message` (text)
- `created_at` (datetime, indexed)
- `processed_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `entity_type_id` (`entity_type`, `entity_id`, `action`)
- KEY `status` (`status`)
- KEY `created_at` (`created_at`)

**Created:** `DatabaseManager::install()` line 339-354
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 7. wch_notification_log

**Purpose:** Logs order notification delivery (order confirmations, shipping updates, etc.).

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `order_id` (bigint, indexed)
- `notification_type` (varchar 50, indexed) - e.g., 'order_confirmed', 'shipped'
- `customer_phone` (varchar 20, indexed)
- `template_name` (varchar 100)
- `wa_message_id` (varchar 255)
- `status` (varchar 20, indexed)
- `retry_count` (int)
- `sent_at` (datetime)
- `delivered_at` (datetime)
- `read_at` (datetime)
- `error_message` (text)
- `created_at` (datetime, indexed)
- `updated_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `order_id` (`order_id`)
- KEY `customer_phone` (`customer_phone`)
- KEY `notification_type` (`notification_type`)
- KEY `status` (`status`)
- KEY `created_at` (`created_at`)

**Created:** `DatabaseManager::install()` line 363-385
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 8. wch_product_views

**Purpose:** Tracks product views by customers for reengagement targeting.

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `customer_phone` (varchar 20, indexed)
- `product_id` (bigint, indexed)
- `viewed_at` (datetime, indexed)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `customer_phone` (`customer_phone`)
- KEY `product_id` (`product_id`)
- KEY `viewed_at` (`viewed_at`)

**Created:** `DatabaseManager::install()` line 394-404
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

### 9. wch_reengagement

**Purpose:** Tracks reengagement campaigns and conversion metrics.

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `customer_phone` (varchar 20, indexed)
- `campaign_type` (varchar 50, indexed) - e.g., 'product_reminder', 'cart_reminder'
- `product_id` (bigint)
- `sent_at` (datetime, indexed)
- `converted` (tinyint 1, indexed)
- `conversion_order_id` (bigint)
- `conversion_revenue` (decimal 10,2)
- `converted_at` (datetime)
- `created_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `customer_phone` (`customer_phone`)
- KEY `campaign_type` (`campaign_type`)
- KEY `sent_at` (`sent_at`)
- KEY `converted` (`converted`)

**Created:** `DatabaseManager::install()` line 413-430
**Dropped:** `DatabaseManager::dropTables()` line 450-469

---

## Queue & Background Processing Tables

### 10. wch_dead_letter_queue

**Purpose:** Stores failed background jobs for replay, analysis, and debugging.

**Owner:** `includes/Queue/DeadLetterQueue.php`

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `hook` (varchar 255, indexed)
- `args` (longtext) - serialized job arguments
- `reason` (varchar 100)
- `error_message` (longtext)
- `attempts` (int)
- `priority` (int)
- `metadata` (longtext) - JSON
- `status` (varchar 20) - 'pending', 'replayed', 'dismissed'
- `created_at` (datetime, indexed)
- `replayed_at` (datetime)
- `dismissed_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `idx_status_reason` (`status`, `reason`)
- KEY `idx_hook` (`hook`)
- KEY `idx_created_at` (`created_at`)

**Created:** `DeadLetterQueue::createTable()` line 557-584
**Installation:** Must be called manually (static method)
**Dropped:** Not included in `DatabaseManager::dropTables()` - may need manual cleanup

---

### 11. wch_webhook_idempotency

**Purpose:** Prevents duplicate webhook processing using idempotency keys.

**Owner:** `includes/Queue/IdempotencyService.php`

**Key Columns:**
- `message_id` (varchar 255)
- `scope` (varchar 100) - e.g., 'webhook', 'notification', 'broadcast'
- `processed_at` (datetime)
- `expires_at` (datetime)

**Indexes:**
- PRIMARY KEY (`message_id`, `scope`)
- KEY `idx_expires` (`expires_at`)

**Created:** `DatabaseManager::getWebhookIdempotencyTableSchema()` line 517-526
**Dropped:** `DatabaseManager::dropTables()` line 600-623
**Usage:** Atomic INSERT IGNORE pattern for idempotency claims

---

### 12. wch_rate_limits

**Purpose:** Tracks rate limiting counters per identifier and limit type (sliding window).

**Owner (shared):**
- `includes/Queue/PriorityQueue.php` - Rate limiting by priority group
- `includes/Security/RateLimiter.php` - Security rate limiting

**Key Columns:**
- `identifier_hash` (varchar 64) - SHA-256 hash of identifier
- `limit_type` (varchar 32) - e.g., 'webhook', 'api', 'admin', 'message_send'
- `request_count` (int unsigned)
- `window_start` (varchar 16)
- `created_at` (datetime)
- `expires_at` (datetime)
- `metadata` (json)

**Indexes:**
- PRIMARY KEY (`identifier_hash`, `limit_type`, `window_start`)
- KEY `idx_window` (`window_start`)
- KEY `idx_expires` (`expires_at`)

**Created:** `DatabaseManager::getRateLimitsTableSchema()` line 468-481
**Dropped:** `DatabaseManager::dropTables()` line 600-623

**Rate Limit Configurations:**
- webhook: 1000 requests / 60s
- api: 100 requests / 60s
- admin: 60 requests / 60s
- auth: 5 requests / 300s
- message_send: 30 requests / 60s
- broadcast: 10 requests / 3600s
- export: 5 requests / 3600s

---

### 13. wch_security_log

**Purpose:** Tracks security events for auditing and monitoring.

**Owner:** `includes/Providers/SecurityServiceProvider.php`

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `event` (varchar 100) - Event type (e.g., 'rate_limit_block', 'vault_access')
- `level` (varchar 20) - Log level ('info', 'warning', 'error')
- `context` (json) - Event context data
- `ip_address` (varchar 45) - Client IP address
- `user_id` (bigint) - WordPress user ID (if applicable)
- `created_at` (datetime)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `event` (`event`)
- KEY `level` (`level`)
- KEY `created_at` (`created_at`)
- KEY `user_id` (`user_id`)
- KEY `ip_address` (`ip_address`)

**Created:** `DatabaseManager::getSecurityLogTableSchema()` line 491-507
**Dropped:** `DatabaseManager::dropTables()` line 600-623
**Usage:** Logs important security events (warnings and errors) to database for audit trail

---

### 14. wch_webhook_events

**Purpose:** Tracks payment webhook processing status for idempotency and debugging.

**Owner:** `includes/Controllers/PaymentWebhookController.php`

**Key Columns:**
- `id` (bigint, auto-increment, primary key)
- `event_id` (varchar 255, unique) - External event ID from payment gateway
- `status` (varchar 20) - Processing status ('processing', 'completed')
- `created_at` (datetime) - When event was first received
- `completed_at` (datetime) - When processing completed

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `event_id` (`event_id`)
- KEY `status` (`status`)
- KEY `created_at` (`created_at`)

**Created:** `DatabaseManager::getWebhookEventsTableSchema()` line 536-548
**Dropped:** `DatabaseManager::dropTables()` line 600-623
**Usage:** Atomic INSERT IGNORE for webhook processing claim, prevents duplicate payment webhook processing

---

## Resilience & Distributed Transaction Tables

### 15. wch_circuit_breakers

**Purpose:** Implements circuit breaker pattern for external service fault tolerance.

**Owner:** `includes/Resilience/CircuitBreaker.php`

**Key Columns:**
- `service` (varchar 100, primary key)
- `state` (varchar 20, indexed) - 'closed', 'open', 'half_open'
- `failures` (bigint unsigned)
- `successes` (bigint unsigned)
- `opened_at` (datetime)
- `updated_at` (datetime)

**Indexes:**
- PRIMARY KEY (`service`)
- KEY `idx_state` (`state`)

**Created:** `CircuitBreaker::createTable()` line 814-833
**Installation:** Must be called manually (static method)
**Dropped:** Not included in `DatabaseManager::dropTables()` - may need manual cleanup

---

### 16. wch_saga_state

**Purpose:** Manages distributed transaction (saga) orchestration state.

**Owner:** `includes/Sagas/SagaOrchestrator.php`

**Key Columns:**
- `saga_id` (varchar 100, primary key)
- `saga_type` (varchar 100, indexed) - e.g., 'OrderPlacement'
- `state` (varchar 50, indexed) - 'running', 'completed', 'failed', 'compensating'
- `context` (longtext) - JSON saga context
- `log` (longtext) - JSON execution log
- `created_at` (datetime)
- `updated_at` (datetime, indexed)

**Indexes:**
- PRIMARY KEY (`saga_id`)
- KEY `idx_state` (`state`)
- KEY `idx_saga_type` (`saga_type`)
- KEY `idx_updated` (`updated_at`)

**Created:** `SagaOrchestrator::createTable()` line 852-874
**Installation:** Must be called manually (static method)
**Dropped:** Not included in `DatabaseManager::dropTables()` - may need manual cleanup

---

## Table Prefix

All plugin tables use the prefix: `{$wpdb->prefix}wch_`

- WordPress prefix (e.g., `wp_`) + plugin prefix (`wch_`)
- Example: On a default WordPress install, tables would be named `wp_wch_conversations`, `wp_wch_messages`, etc.

**Character Set & Collation:** Uses WordPress defaults via `$wpdb->get_charset_collate()`

---

## Installation & Lifecycle

### Activation Hook

**File:** `whatsapp-commerce-hub.php`
**Function:** `wch_activate_plugin()` (line 343-364)
**Action:** Calls `DatabaseManager::install()` to create core tables

### Migration Check

**File:** `whatsapp-commerce-hub.php`
**Hook:** `admin_init` (line 229)
**Action:** Calls `DatabaseManager::run_migrations()` to check and apply schema updates

### Uninstallation Hook

**File:** `uninstall.php`
**Action:** Instantiates `DatabaseManager` and calls `uninstall()` method
**Tables Dropped:** All 9 core tables via `DatabaseManager::dropTables()`

### Database Version Management

- **Current DB Version:** `2.6.0` (constant: `DatabaseManager::DB_VERSION`)
- **Version Option Key:** `wch_db_version` (stored in WordPress options table)
- **Version Check:** Performed on `admin_init` to trigger migrations if version mismatch

### Migration Summary

**Previously Missing Tables (now integrated):**
1. `wch_rate_limits` - ✓ Now in DatabaseManager (was in PriorityQueue::createRateLimitsTable())
2. `wch_webhook_idempotency` - ✓ Now in DatabaseManager (was missing)
3. `wch_security_log` - ✓ Now in DatabaseManager (was missing)
4. `wch_webhook_events` - ✓ Now in DatabaseManager (was missing)

**Remaining Auxiliary Tables:**
The following tables still have `createTable()` static methods and are NOT called from the main installation routine:

1. `wch_dead_letter_queue` - `DeadLetterQueue::createTable()`
2. `wch_circuit_breakers` - `CircuitBreaker::createTable()`
3. `wch_saga_state` - `SagaOrchestrator::createTable()`

These tables are optional/advanced features and may be created on-demand when those features are first used.

---

## Table Reference by Module

### Security Module

**File:** `includes/Security/RateLimiter.php`

**Tables:**
- `wch_rate_limits` (shared with PriorityQueue)

**Usage:** Sliding window rate limiting for webhook, api, admin, auth, message_send, broadcast, export operations

---

### Queue Module

**Files:**
- `includes/Queue/DeadLetterQueue.php`
- `includes/Queue/IdempotencyService.php`
- `includes/Queue/PriorityQueue.php`

**Tables:**
- `wch_dead_letter_queue` - Failed job storage and replay
- `wch_webhook_idempotency` - Idempotency claim for webhooks, notifications, orders, broadcasts, sync
- `wch_rate_limits` - Rate limiting by priority group

**Usage:** Background job processing, webhook deduplication, rate limiting

---

### Payment Webhooks

**Files:**
- `includes/Webhooks/WebhookController.php`
- `includes/Actions/ConfirmOrderAction.php`
- Order notification handlers

**Tables (direct references):**
- `wch_webhook_idempotency` - Prevents duplicate webhook processing
- `wch_messages` - Stores webhook-triggered messages
- `wch_notification_log` - Logs order notification delivery
- `wch_sync_queue` - Queues order sync operations

**Usage:** Webhook processing, order confirmations, payment notifications

---

### Resilience Module

**Files:**
- `includes/Resilience/CircuitBreaker.php`
- `includes/Sagas/SagaOrchestrator.php`

**Tables:**
- `wch_circuit_breakers` - External service fault tolerance
- `wch_saga_state` - Distributed transaction orchestration

**Usage:** Fault-tolerant external API calls, distributed transaction management

---

### Core Features

**Files:**
- Conversation management: `includes/Conversation/`, `includes/Messages/`
- Cart management: `includes/Cart/`
- Customer profiles: `includes/Customer/`
- Broadcasts: `includes/Broadcast/`
- Sync: `includes/Sync/`
- Notifications: `includes/Notifications/`
- Reengagement: `includes/Reengagement/`

**Tables:**
- `wch_conversations` - Conversation threads
- `wch_messages` - Message storage
- `wch_carts` - Shopping carts & abandoned cart recovery
- `wch_customer_profiles` - Customer data & preferences
- `wch_broadcast_recipients` - Broadcast tracking
- `wch_sync_queue` - Product/order/inventory sync
- `wch_notification_log` - Notification delivery tracking
- `wch_product_views` - Product view tracking
- `wch_reengagement` - Reengagement campaign tracking

**Usage:** Primary plugin functionality - conversations, carts, customers, broadcasts, notifications, reengagement

---

## Summary

**Total Tables:** 16

**Core Tables (managed by DatabaseManager):** 13
- Created on activation via `DatabaseManager::install()`
- Dropped on uninstallation via `DatabaseManager::dropTables()`
- Version-controlled migrations

**Auxiliary Tables (static createTable() methods):** 3
- Created manually or on-demand
- Not integrated into main installation flow
- Not automatically dropped on uninstallation

**Core Tables List:**
1. wch_conversations
2. wch_messages
3. wch_carts
4. wch_customer_profiles
5. wch_broadcast_recipients
6. wch_sync_queue
7. wch_notification_log
8. wch_product_views
9. wch_reengagement
10. wch_rate_limits
11. wch_webhook_idempotency
12. wch_security_log
13. wch_webhook_events

**Auxiliary Tables List:**
14. wch_dead_letter_queue (DeadLetterQueue::createTable())
15. wch_circuit_breakers (CircuitBreaker::createTable())
16. wch_saga_state (SagaOrchestrator::createTable())

**Shared Tables:**
- `wch_rate_limits` - Used by both PriorityQueue and RateLimiter
- `wch_webhook_idempotency` - Used across multiple modules (webhooks, notifications, orders, broadcasts, sync)

**Table Ownership Distribution:**
- DatabaseManager: 13 tables
- Queue module: 2 tables (1 shared, 1 auxiliary)
- Security module: 2 tables (1 shared)
- Payments module: 1 table
- Resilience module: 2 tables (auxiliary)
- Total unique tables: 16
