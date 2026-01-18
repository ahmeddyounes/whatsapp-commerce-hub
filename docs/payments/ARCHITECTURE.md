# Payment Architecture

## Overview

The payment system in WhatsApp Commerce Hub is built on a container-based architecture that provides a unified interface for payment gateway registration, webhook handling, refunds, and notifications.

## Architecture Components

### 1. PaymentServiceProvider

**Location:** `includes/Providers/PaymentServiceProvider.php`

The PaymentServiceProvider is the central registration point for all payment-related services. It registers:

- **Payment Gateways**: All built-in gateways (COD, Stripe, Razorpay, PIX, WhatsApp Pay)
- **Gateway Collection**: A `payment.gateways` service providing access to all registered gateways
- **Webhook Controller**: Handles incoming payment webhooks
- **Refund Service**: Processes refunds through payment gateways

#### Usage Examples

```php
// Get all gateways
$container = Container::getInstance();
$gateways = $container->get( 'payment.gateways' );

// Get specific gateway by alias
$stripeGateway = $container->get( 'payment.gateway.stripe' );

// Get available gateways for a country
$availableGateways = PaymentServiceProvider::getAvailableForCountry( 'US' );
```

### 2. Payment Gateways

**Location:** `includes/Payments/Gateways/`

Each gateway implements `PaymentGatewayInterface` and provides:

- `processPayment()`: Handle payment processing
- `processRefund()`: Handle refund requests
- `handleWebhook()`: Process webhook notifications
- `verifyWebhookSignature()`: Verify webhook authenticity
- `isAvailable()`: Check availability for a country
- `isConfigured()`: Check if gateway is properly configured

#### Built-in Gateways

1. **COD (Cash on Delivery)**: `CodGateway`
2. **Stripe**: `StripeGateway`
3. **Razorpay**: `RazorpayGateway`
4. **PIX**: `PixGateway`
5. **WhatsApp Pay**: `WhatsAppPayGateway`

### 3. Payment Webhook Controller

**Location:** `includes/Controllers/PaymentWebhookController.php`

Handles incoming webhooks from payment gateways with:

#### Features

- **Automatic Gateway Detection**: Detects gateway from request headers/payload
- **Signature Verification**: Validates webhook authenticity
- **Idempotency**: Prevents duplicate processing using database-backed event tracking
- **Timeout Handling**: Reclaims stale processing events after 5 minutes

#### Webhook Flow

```
POST /wch/v1/payment-webhook
  ↓
Detect Gateway (headers/payload)
  ↓
Verify Signature
  ↓
Extract Event ID
  ↓
Claim Event (atomic via UNIQUE constraint)
  ↓
Process Webhook → Gateway::handleWebhook()
  ↓
Mark Completed / Clear on Error
```

#### Idempotency Schema

```sql
CREATE TABLE wch_webhook_events (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY event_id (event_id),
    KEY status (status),
    KEY created_at (created_at),
    KEY updated_at (updated_at)
)
```

**Status Values:**
- `processing`: Event is being processed
- `completed`: Event successfully processed

**Timeout Handling:**
If an event has status `processing` but `updated_at` is older than 5 minutes, it's considered stale and can be reclaimed by another request.

### 4. Refund Service

**Location:** `includes/Application/Services/RefundService.php`

Handles refund processing with:

- **Gateway Integration**: Routes refunds to appropriate payment gateway
- **WooCommerce Hooks**: Automatically processes refunds when created
- **Customer Notifications**: Sends WhatsApp notifications about refunds
- **Race Condition Prevention**: Uses MySQL locks to prevent double refunds

#### Usage

```php
$refundService = wch( RefundService::class );
$result = $refundService->refund( $orderId, $amount, $reason );

if ( $result->isSuccess() ) {
    // Refund processed
    $transactionId = $result->getTransactionId();
}
```

### 5. Notification Service

**Location:** `includes/Application/Services/NotificationService.php`
**Provider:** `includes/Providers/NotificationServiceProvider.php`

Sends WhatsApp notifications for:

- Order status changes
- Payment confirmations
- Refund notifications
- Shipping updates

**Note:** NotificationService is registered by NotificationServiceProvider, NOT PaymentServiceProvider. This ensures a single registration point with proper dependency injection including the logger.

## Migration from PaymentGatewayRegistry

`PaymentGatewayRegistry` is **deprecated as of version 3.1.0**. Use container-based resolution instead.

### Before (Deprecated)

```php
$registry = wch( PaymentGatewayRegistry::class );
$gateways = $registry->getAvailable( 'US' );
$stripe = $registry->get( 'stripe' );
```

### After (Current)

```php
// Get available gateways
$gateways = PaymentServiceProvider::getAvailableForCountry( 'US' );

// Get specific gateway
$container = Container::getInstance();
$stripe = $container->get( 'payment.gateway.stripe' );

// Or from collection
$gateways = $container->get( 'payment.gateways' );
$stripe = $gateways['stripe'];
```

## Security Features

### 1. Webhook Signature Verification

All webhooks are verified before processing:

```php
$gateway->verifyWebhookSignature( $payload, $signature );
```

**Important:** Default `AbstractGateway` implementation returns `true`. All production gateways MUST override this method.

### 2. Idempotency

Webhooks are deduplicated using unique event IDs:

- **Stripe**: Uses event `id` field
- **Razorpay**: Uses payment entity `id` or constructs from `event + account_id + created_at + entity_id`
- **PIX**: Uses transaction `id`
- **Other**: Uses SHA-256 hash of sorted payload

### 3. Refund Locks

Refunds use MySQL `GET_LOCK()` to prevent race conditions:

```php
$lockKey = 'wch_refund_lock_' . $orderId;
$wpdb->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lockKey ) );
```

## Configuration

### Enabled Gateways

Controlled via WordPress option:

```php
update_option( 'wch_enabled_payment_methods', [ 'cod', 'stripe' ] );
```

### Default Gateway

```php
update_option( 'wch_default_payment_gateway', 'stripe' );
```

## Extending the System

### Adding a Custom Gateway

1. **Create Gateway Class**

```php
namespace MyPlugin\Payments;

use WhatsAppCommerceHub\Payments\Gateways\AbstractGateway;
use WhatsAppCommerceHub\Payments\Contracts\PaymentResult;
use WhatsAppCommerceHub\Payments\Contracts\RefundResult;

class CustomGateway extends AbstractGateway {
    public function getId(): string {
        return 'custom';
    }

    public function getTitle(): string {
        return 'Custom Payment Gateway';
    }

    public function processPayment( int $orderId, array $context ): PaymentResult {
        // Implementation
    }

    public function processRefund( int $orderId, float $amount, string $reason, string $transactionId ): RefundResult {
        // Implementation
    }

    public function verifyWebhookSignature( string $payload, string $signature ): bool {
        // MUST implement for security
    }

    public function handleWebhook( array $data, string $signature ): array {
        // Implementation
    }
}
```

2. **Register Gateway**

```php
add_action( 'wch_register_payment_gateways', function( $container ) {
    $container->singleton(
        'payment.gateway.custom',
        fn() => new CustomGateway()
    );

    // Add to collection
    $gateways = $container->get( 'payment.gateways' );
    $gateways['custom'] = $container->get( 'payment.gateway.custom' );
}, 10, 1 );
```

## Troubleshooting

### Webhook Not Processing

1. Check webhook event log: `SELECT * FROM wp_wch_webhook_events WHERE event_id = 'xxx'`
2. Check status: If `processing` for >5 minutes, event is stale and will be reclaimed
3. Verify signature verification is implemented correctly
4. Check gateway-specific headers are present

### Refund Failing

1. Check if gateway supports refunds: `$gateway->processRefund()` may return `RefundResult::manual()`
2. Verify transaction ID is correct
3. Check refund amount doesn't exceed remaining refundable amount
4. Review gateway-specific error messages

### Gateway Not Available

1. Check if enabled: `get_option( 'wch_enabled_payment_methods' )`
2. Verify gateway is configured: `$gateway->isConfigured()`
3. Check country availability: `$gateway->isAvailable( $country )`

## Best Practices

1. **Always use container-based resolution** for gateway access
2. **Implement webhook signature verification** in custom gateways
3. **Use unique event IDs** for webhook idempotency
4. **Handle refund failures gracefully** with manual fallback
5. **Test webhook endpoints** with actual gateway payloads
6. **Monitor webhook event table** for stale processing events
7. **Log all payment operations** for audit trail

## Database Tables

### wch_webhook_events

Tracks webhook processing to prevent duplicates.

### wch_notification_log

Tracks customer notifications sent.

## Hooks and Filters

### Actions

- `wch_register_payment_gateways`: Register custom payment gateways
- `wch_payment_gateway_registered`: Fires after a gateway is registered
- `woocommerce_order_refunded`: Triggers refund processing
- `woocommerce_order_status_refunded`: Triggers refund notification

### Filters

- `wch_available_payment_gateways`: Filter available gateways for a country
- `wch_builtin_payment_gateways`: Filter list of built-in gateways
