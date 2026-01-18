# Module Map

This document provides a comprehensive overview of all modules in the WhatsApp Commerce Hub plugin, including their provider entrypoints, key contracts, database tables, scheduled jobs, and WordPress hooks.

## Table of Contents

- [Webhooks](#webhooks)
- [Conversations/FSM](#conversationsfsm)
- [Catalog](#catalog)
- [Product Sync](#product-sync)
- [Checkout](#checkout)
- [Payments](#payments)
- [Broadcasts](#broadcasts)
- [Reengagement](#reengagement)
- [Admin](#admin)
- [Monitoring](#monitoring)
- [Security](#security)

---

## Webhooks

**Purpose**: Receives and processes incoming WhatsApp webhook events (messages, statuses, errors).

### Provider Entrypoint
- **Provider**: `ControllerServiceProvider` ([includes/Providers/ControllerServiceProvider.php:66](includes/Providers/ControllerServiceProvider.php#L66))
- **Controller**: `WebhookController` ([includes/Controllers/WebhookController.php](includes/Controllers/WebhookController.php))

### Key Contracts
- `WebhookController::verifyWebhook()` - Handles Meta webhook verification challenge
- `WebhookController::handleWebhook()` - Processes incoming webhook payloads
- `QueueProcessorInterface` - Interface for webhook processors

### Tables
- **wch_webhook_idempotency** - Prevents duplicate webhook processing
  - Fields: `message_id`, `scope`, `processed_at`, `expires_at`
- **wch_webhook_events** - Tracks payment webhook processing status
  - Fields: `id`, `event_id`, `status`, `created_at`, `completed_at`
- **wch_messages** - Stores all WhatsApp messages
  - Fields: `id`, `conversation_id`, `direction`, `type`, `wa_message_id`, `content`, `status`, `created_at`

### Jobs
- `wch_process_webhook_messages` - Process incoming messages (URGENT priority)
- `wch_process_webhook_statuses` - Process message status updates (NORMAL priority)
- `wch_process_webhook_errors` - Process webhook errors (NORMAL priority)
- `wch_cleanup_idempotency_keys` - Clean up old idempotency records

### Hooks
- `rest_api_init` - Registers webhook REST endpoints
- **Actions**:
  - `wch_process_webhook_messages` - Triggered to process message payload
  - `wch_process_webhook_statuses` - Triggered to process status update
  - `wch_process_webhook_errors` - Triggered to process error payload

---

## Conversations/FSM

**Purpose**: Manages conversation state using finite state machine (FSM) pattern for customer interactions.

### Provider Entrypoint
- **Provider**: `ControllerServiceProvider` ([includes/Providers/ControllerServiceProvider.php:54](includes/Providers/ControllerServiceProvider.php#L54))
- **Controller**: `ConversationsController` ([includes/Controllers/ConversationsController.php](includes/Controllers/ConversationsController.php))
- **Domain**: `StateMachine` ([includes/Domain/Conversation/StateMachine.php](includes/Domain/Conversation/StateMachine.php))

### Key Contracts
- `ConversationRepositoryInterface` - Conversation data persistence
- `StateMachine` - FSM for conversation state transitions

### FSM States
- `initial` - Starting state
- `browsing` - Customer viewing products
- `cart` - Managing shopping cart
- `checkout` - Providing shipping information
- `payment` - Processing payment
- `completed` - Order completed successfully
- `abandoned` - Conversation abandoned

### Tables
- **wch_conversations** - Stores conversation metadata and state
  - Fields: `id`, `customer_phone`, `wa_conversation_id`, `status`, `state`, `context`, `message_count`, `unread_count`, `last_message_at`, `created_at`, `updated_at`
- **wch_messages** - All conversation messages (shared with Webhooks module)

### Jobs
None directly scheduled (state transitions occur during message processing)

### Hooks
- **Actions**:
  - `wch_conversation_state_changed` - Fired when conversation state transitions
  - `wch_conversation_created` - Fired when new conversation starts
  - `wch_conversation_closed` - Fired when conversation ends

---

## Catalog

**Purpose**: Manages WhatsApp Business Catalog integration with WooCommerce products.

### Provider Entrypoint
- **Provider**: `BusinessServiceProvider` ([includes/Providers/BusinessServiceProvider.php](includes/Providers/BusinessServiceProvider.php))
- **Service**: `CatalogBrowser` ([includes/Features/CatalogBrowser](includes/Features/CatalogBrowser))

### Key Contracts
- `CatalogApiInterface` - WhatsApp Catalog API operations
- `CatalogTransformerInterface` - Transforms WooCommerce products to WhatsApp format
- `ProductValidatorInterface` - Validates products for catalog sync

### Tables
- **WooCommerce Product Meta** (`wp_postmeta`)
  - `_wch_sync_status` - Product sync status
  - `_wch_last_synced` - Last sync timestamp
  - `_wch_sync_error` - Sync error message

### Jobs
None (uses Product Sync module for scheduling)

### Hooks
- **Filters**:
  - `wch_catalog_product_data` - Filter product data before catalog sync
  - `wch_catalog_transform_product` - Customize product transformation

---

## Product Sync

**Purpose**: Synchronizes WooCommerce products with WhatsApp Business Catalog in real-time and batch operations.

### Provider Entrypoint
- **Provider**: `ProductSyncServiceProvider` ([includes/Providers/ProductSyncServiceProvider.php](includes/Providers/ProductSyncServiceProvider.php))
- **Orchestrator**: `ProductSyncOrchestrator` ([includes/Application/Services/ProductSync/ProductSyncOrchestrator.php](includes/Application/Services/ProductSync/ProductSyncOrchestrator.php))

### Key Contracts
- `ProductSyncOrchestratorInterface` - Coordinates product sync operations
- `CatalogApiInterface` - WhatsApp Catalog API client
- `CatalogTransformerInterface` - Product data transformation
- `ProductValidatorInterface` - Product validation for sync
- `SyncProgressTrackerInterface` - Tracks sync progress and status

### Tables
- **wch_sync_queue** - Queue for product sync operations
  - Fields: `id`, `entity_type`, `entity_id`, `action`, `status`, `attempts`, `error_message`, `created_at`, `processed_at`
- **WooCommerce Product Meta** (`wp_postmeta`)
  - `_wch_sync_status`, `_wch_last_synced`, `_wch_sync_error`, `_wch_last_stock_sync`, `_wch_previous_stock`

### Jobs
- `wch_sync_product_batch` - Process batch product sync
- `wch_sync_single_product` - Sync individual product
- `wch_detect_stock_discrepancies` - Check for inventory discrepancies

### Hooks
- **Actions**:
  - `woocommerce_update_product` - Triggered when product is updated
  - `woocommerce_new_product` - Triggered when new product is created
  - `before_delete_post` - Triggered before product deletion
- **Filters**:
  - `wch_sync_product_enabled` - Control if product should be synced
  - `wch_sync_batch_size` - Customize batch size

---

## Checkout

**Purpose**: Orchestrates the checkout process including address collection, shipping calculation, and order creation.

### Provider Entrypoint
- **Provider**: `CheckoutServiceProvider` ([includes/Providers/CheckoutServiceProvider.php](includes/Providers/CheckoutServiceProvider.php))
- **Orchestrator**: `CheckoutOrchestrator` ([includes/Application/Services/Checkout/CheckoutOrchestrator.php](includes/Application/Services/Checkout/CheckoutOrchestrator.php))

### Key Contracts
- `CheckoutOrchestratorInterface` - Coordinates checkout flow
- `CheckoutStateManagerInterface` - Manages checkout session state
- `AddressHandlerInterface` - Handles address parsing and validation
- `ShippingCalculatorInterface` - Calculates shipping costs
- `PaymentHandlerInterface` - Processes payment selection
- `CheckoutTotalsCalculatorInterface` - Calculates order totals
- `CouponHandlerInterface` - Applies and validates coupons

### Tables
- **wch_carts** - Shopping cart data
  - Fields: `id`, `customer_phone`, `items`, `total`, `coupon_code`, `shipping_address`, `status`, `abandoned_at`, `recovered`, `created_at`

### Jobs
None directly scheduled (orchestrated through Actions)

### Hooks
- **Actions**:
  - `wch_checkout_started` - Fired when checkout begins
  - `wch_checkout_cancelled` - Fired when checkout is cancelled
  - `wch_checkout_address_collected` - After address is collected
  - `wch_checkout_payment_selected` - After payment method selected
  - `wch_order_created` - After WooCommerce order is created

---

## Payments

**Purpose**: Processes payments through multiple gateways (COD, Stripe, Razorpay, PIX, WhatsApp Pay).

### Provider Entrypoint
- **Provider**: `PaymentServiceProvider` ([includes/Providers/PaymentServiceProvider.php](includes/Providers/PaymentServiceProvider.php))
- **Controller**: `PaymentWebhookController` ([includes/Controllers/PaymentWebhookController.php](includes/Controllers/PaymentWebhookController.php))

### Key Contracts
- `PaymentGatewayInterface` - Standard interface for all payment gateways
- `PaymentResult` - Payment processing result value object
- `RefundResult` - Refund processing result value object
- `WebhookResult` - Webhook processing result value object

### Payment Gateways
- **CodGateway** - Cash on Delivery
- **StripeGateway** - Stripe payments
- **RazorpayGateway** - Razorpay payments
- **PixGateway** - PIX (Brazilian instant payments)
- **WhatsAppPayGateway** - WhatsApp Pay

### Tables
- **wch_webhook_events** - Payment webhook tracking (shared with Webhooks module)
- **WooCommerce Orders** - Standard WooCommerce order tables

### Jobs
None directly scheduled (webhooks processed in real-time)

### Hooks
- **Actions**:
  - `wch_register_payment_gateways` - Allow custom gateway registration
  - `wch_payment_completed` - Fired after successful payment
  - `wch_payment_failed` - Fired after payment failure
  - `wch_refund_processed` - Fired after refund processing
- **Filters**:
  - `wch_payment_gateways` - Modify available payment gateways
  - `wch_default_gateway` - Set default payment gateway

---

## Broadcasts

**Purpose**: Send targeted marketing messages to customer segments using WhatsApp templates.

### Provider Entrypoint
- **Provider**: `BroadcastsServiceProvider` ([includes/Providers/BroadcastsServiceProvider.php](includes/Providers/BroadcastsServiceProvider.php))
- **Dispatcher**: `CampaignDispatcher` ([includes/Application/Services/Broadcasts/CampaignDispatcher.php](includes/Application/Services/Broadcasts/CampaignDispatcher.php))

### Key Contracts
- `CampaignRepositoryInterface` - Campaign data persistence
- `AudienceCalculatorInterface` - Calculate target audience for campaigns
- `CampaignDispatcherInterface` - Dispatches broadcast campaigns
- `BroadcastTemplateBuilder` - Builds WhatsApp template messages
- `BroadcastBatchProcessor` - Processes broadcast batches

### Tables
- **wch_broadcast_recipients** - Tracks broadcast message delivery
  - Fields: `id`, `campaign_id`, `phone`, `wa_message_id`, `status`, `sent_at`, `created_at`
- **WordPress Options** (`wp_options`)
  - `wch_broadcast_campaigns` - Stores campaign configurations

### Jobs
- `wch_send_broadcast_batch` - Send batch of broadcast messages

### Hooks
- **Actions**:
  - `wch_broadcast_campaign_started` - Fired when campaign starts
  - `wch_broadcast_campaign_completed` - Fired when campaign completes
  - `wch_broadcast_message_sent` - Fired for each message sent
- **Filters**:
  - `wch_broadcast_audience_filters` - Customize audience targeting
  - `wch_broadcast_batch_size` - Customize batch size

---

## Reengagement

**Purpose**: Re-engage inactive customers with personalized campaigns (abandoned cart, back-in-stock, price drops, loyalty).

### Provider Entrypoint
- **Provider**: `ReengagementServiceProvider` ([includes/Providers/ReengagementServiceProvider.php](includes/Providers/ReengagementServiceProvider.php))
- **Orchestrator**: `ReengagementOrchestrator` ([includes/Application/Services/Reengagement/ReengagementOrchestrator.php](includes/Application/Services/Reengagement/ReengagementOrchestrator.php))

### Key Contracts
- `ReengagementOrchestratorInterface` - Coordinates reengagement campaigns
- `InactiveCustomerIdentifierInterface` - Identifies inactive customers
- `CampaignTypeResolverInterface` - Determines campaign type for customer
- `ProductTrackingServiceInterface` - Tracks product views and inventory
- `ReengagementMessageBuilderInterface` - Builds personalized messages
- `LoyaltyCouponGeneratorInterface` - Generates loyalty coupons
- `FrequencyCapManagerInterface` - Prevents message fatigue
- `ReengagementAnalyticsInterface` - Tracks campaign performance

### Tables
- **wch_reengagement** - Tracks reengagement campaigns
  - Fields: `id`, `customer_phone`, `campaign_type`, `product_id`, `sent_at`, `converted`, `conversion_order_id`, `conversion_revenue`, `converted_at`, `created_at`
- **wch_product_views** - Tracks product views by customers
  - Fields: `id`, `customer_phone`, `product_id`, `viewed_at`
- **wch_carts** - Tracks abandoned carts (shared with Checkout module)
  - Fields: `reminder_1_sent_at`, `reminder_2_sent_at`, `reminder_3_sent_at`, `recovery_coupon_code`, `recovered`, `recovered_at`

### Jobs
- `wch_process_reengagement_campaigns` - Process all reengagement campaigns
- `wch_send_reengagement_message` - Send individual reengagement message
- `wch_check_back_in_stock` - Check for back-in-stock products
- `wch_check_price_drops` - Check for price drops
- `wch_schedule_recovery_reminders` - Schedule abandoned cart reminders

### Hooks
- **Actions**:
  - `wch_reengagement_campaign_sent` - Fired when campaign message sent
  - `wch_reengagement_converted` - Fired when customer converts
  - `wch_abandoned_cart_recovered` - Fired when cart is recovered
- **Filters**:
  - `wch_reengagement_inactive_days` - Customize inactive threshold
  - `wch_reengagement_frequency_cap` - Customize frequency limits

---

## Admin

**Purpose**: WordPress admin interface including settings, analytics, inbox, logs, and catalog management.

### Provider Entrypoint
- **Provider**: `AdminServiceProvider` ([includes/Providers/AdminServiceProvider.php](includes/Providers/AdminServiceProvider.php))
- **Settings Provider**: `AdminSettingsServiceProvider` ([includes/Providers/AdminSettingsServiceProvider.php](includes/Providers/AdminSettingsServiceProvider.php))

### Key Components
- **Admin Pages**:
  - `AnalyticsPage` - Conversion and revenue analytics
  - `CatalogSyncPage` - Product sync management
  - `InboxPage` - Conversation inbox
  - `JobsPage` - Queue jobs monitoring
  - `LogsPage` - System logs viewer
  - `TemplatesPage` - WhatsApp template management
- **Dashboard Widgets**: `DashboardWidgets` - WordPress dashboard widgets
- **AJAX Handlers**:
  - `SettingsAjaxHandler` - Settings import/export
  - `BroadcastsAjaxHandler` - Broadcast campaign management

### Tables
Uses tables from other modules for data display and management.

### Jobs
None (admin interface only)

### Hooks
- **Actions**:
  - `admin_menu` - Register admin menu pages
  - `admin_enqueue_scripts` - Enqueue admin assets
  - `wp_ajax_wch_*` - Various AJAX handlers
- **Filters**:
  - `wch_admin_capability` - Customize required capability
  - `wch_settings_tabs` - Add custom settings tabs

---

## Monitoring

**Purpose**: Health checks, system monitoring, and observability for production deployments.

### Provider Entrypoint
- **Provider**: `MonitoringServiceProvider` ([includes/Providers/MonitoringServiceProvider.php](includes/Providers/MonitoringServiceProvider.php))
- **Service**: `HealthCheck` ([includes/Monitoring/HealthCheck.php](includes/Monitoring/HealthCheck.php))

### Key Contracts
- `HealthCheck::check()` - Full system health check
- `HealthCheck::liveness()` - Liveness probe for load balancers
- `HealthCheck::readiness()` - Readiness probe for load balancers
- `HealthCheck::checkOne()` - Individual component check
- `HealthCheck::getAvailableChecks()` - List all registered health checks
- `HealthCheck::register()` - Register custom health check

### Health Check Components
- **database** - Database connectivity and query latency
- **woocommerce** - WooCommerce plugin availability and version
- **action_scheduler** - Action Scheduler queue status and pending jobs
- **queue** - Job queue health, throughput, and dead letter queue (optional)
- **circuit_breakers** - Circuit breaker states for external services (optional)
- **disk** - Disk space availability in upload directory
- **memory** - PHP memory usage and limits

### Tables
None (uses existing tables for health checks)

### Jobs
None (provides on-demand health checks)

### Hooks
- **REST Endpoints**:
  - `GET /wch/v1/health` - Full health check (authenticated, requires `manage_woocommerce`)
  - `GET /wch/v1/health/live` - Liveness probe (public)
  - `GET /wch/v1/health/ready` - Readiness probe (public, returns 503 if not ready)
  - `GET /wch/v1/health/checks` - List available health checks (public)
  - `GET /wch/v1/health/{component}` - Individual component check (authenticated, requires `manage_woocommerce`)
- **Actions**:
  - `wp_dashboard_setup` - Adds health widget to dashboard
  - `rest_api_init` - Registers health check REST endpoints

---

## Security

**Purpose**: Security features including encryption, rate limiting, PII protection, and security logging.

### Provider Entrypoint
- **Provider**: `SecurityServiceProvider` ([includes/Providers/SecurityServiceProvider.php](includes/Providers/SecurityServiceProvider.php))

### Key Components
- **SecureVault** - Encrypts sensitive data (AES-256-GCM, derived keys)
- **PIIEncryptor** - Encrypts personally identifiable information (with blind indexes)
- **RateLimiter** - Prevents abuse with database-backed rate limits
- **Security Logger** - Logs security events to database (when table exists)

### Key Contracts
- `SecureVault::encrypt()` - Encrypt sensitive data
- `SecureVault::decrypt()` - Decrypt sensitive data
- `RateLimiter::check()` - Check rate limit status
- `SecurityLogger::log()` - Log security event

### Tables
- **wch_rate_limits** - Rate limiting data
  - Fields: `identifier_hash`, `limit_type`, `request_count`, `window_start`, `created_at`, `expires_at`, `metadata`
- **wch_security_log** - Security events log
  - Fields: `id`, `event`, `level`, `context`, `ip_address`, `user_id`, `created_at`

### Jobs
- `wch_rate_limit_cleanup` - Clean up expired rate limit records (hourly)
- `wch_security_log_cleanup` - Clean up old security logs (daily, keeps 90 days)

### Hooks
- **Actions**:
  - `wch_security_log` - Log a security event
  - `wch_rate_limit_exceeded` - Fired when rate limit exceeded
- **Filters**:
  - `wch_rate_limit_threshold` - Customize rate limit thresholds

---

## Cross-Module Dependencies

### Shared Tables
- **wch_messages** - Used by Webhooks and Conversations modules
- **wch_webhook_events** - Used by Webhooks and Payments modules
- **wch_carts** - Used by Checkout and Reengagement modules

### Shared Services
- **Queue (PriorityQueue, DeadLetterQueue)** - Used by all async operations
- **Logger** - Used by all modules for logging
- **Settings Service** - Used by all modules for configuration
- **WhatsAppApiClient** - Used by all modules that send messages

### Event Flow Example (New Message)
1. **Webhooks** receives message → enqueues to priority queue
2. **Queue** processes → triggers message processor
3. **Conversations/FSM** determines state and transitions
4. **Actions** (ShowProduct, AddToCart, etc.) execute based on state
5. **Catalog/Checkout/Payments** handle business logic
6. **Monitoring** tracks health and performance
7. **Security** logs events and enforces rate limits

---

## Related Documentation

- [Boot Sequence](boot-sequence.md) - Plugin initialization flow
- [Webhooks Ingestion Pipeline](webhooks-ingestion-pipeline.md) - Complete webhook flow documentation
- [Queue System](queue-system.md) - Priority queue and payload format details
- [API Reference](api-reference.md) - REST API endpoints
- [Hooks Reference](hooks-reference.md) - WordPress actions and filters
- [Architecture Improvement Plan](architecture-improvement-plan.md) - Planned enhancements
