# Features

Bounded contexts and feature modules. Each feature is a self-contained module.

## Purpose

Features are cohesive modules that group related functionality:
- **Abandoned Cart** - Cart recovery and reminders
- **Reengagement** - Customer re-engagement campaigns
- **Broadcasts** - Bulk messaging campaigns
- **Analytics** - Data analysis and reporting
- **Notifications** - Order and system notifications
- **Payments** - Payment gateway integrations

## Structure

```
Features/
├── AbandonedCart/    # Cart abandonment recovery
├── Reengagement/     # Customer re-engagement
├── Broadcasts/       # Broadcast messaging
├── Analytics/        # Analytics and reporting
├── Notifications/    # Notification system
└── Payments/         # Payment gateways
    └── Gateways/     # Gateway implementations
```

## Namespace

```php
WhatsAppCommerceHub\Features
```

## Examples

### Using Feature Service
```php
use WhatsAppCommerceHub\Features\AbandonedCart\RecoveryService;

$recovery = wch(RecoveryService::class);
$recovery->sendReminder($cartId);
```

### Payment Gateway
```php
use WhatsAppCommerceHub\Features\Payments\PaymentGatewayRegistry;

$registry = wch(PaymentGatewayRegistry::class);
$gateway = $registry->get('stripe');
$result = $gateway->charge($amount, $token);
```

## Principles

1. **Bounded Context** - Each feature is independent
2. **Feature Toggling** - Can be enabled/disabled
3. **Self-Contained** - Minimal dependencies on other features
4. **Clear Boundaries** - Well-defined interfaces
5. **Single Responsibility** - Each feature does one thing well

## Feature Module Pattern

Each feature typically contains:
- Service classes (business logic)
- Repositories (if needed)
- Events (feature-specific events)
- Configuration (feature settings)

## Migration Status

Phase 7 - Not Started
- 🔴 Abandoned cart
- 🔴 Reengagement
- 🔴 Broadcasts
- 🔴 Analytics
- 🔴 Notifications
- 🔴 Payments
