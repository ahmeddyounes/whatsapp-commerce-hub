# Application

Application services and use cases that orchestrate domain logic.

## Purpose

The application layer:
- Orchestrates domain objects to fulfill use cases
- Contains application-specific business rules
- Coordinates transactions and events
- Implements service layer pattern for use cases

## Structure

```
Application/
└── Services/             # Application services (43 files)
    ├── CheckoutService.php
    ├── CartService.php
    ├── OrderSyncService.php
    ├── ProductSyncService.php
    ├── ... (and more)
    ├── Broadcasts/       # Broadcast-related services
    ├── Checkout/         # Checkout workflow services
    ├── ProductSync/      # Product sync services
    └── Reengagement/     # Reengagement services
```

## Namespace

```php
WhatsAppCommerceHub\Application
```

## Examples

### Using Application Service
```php
use WhatsAppCommerceHub\Application\Services\CheckoutService;

$checkout = wch(CheckoutService::class);
$order = $checkout->processCheckout($cart, $paymentMethod, $address);
```

### Using Multiple Services
```php
use WhatsAppCommerceHub\Application\Services\CartService;
use WhatsAppCommerceHub\Application\Services\OrderSyncService;

$cartService = wch(CartService::class);
$orderSync = wch(OrderSyncService::class);

$cart = $cartService->getCart($customerId);
$order = $orderSync->syncOrder($orderId);
```

## Principles

1. **Orchestration** - Coordinates domain objects
2. **Transaction Boundaries** - Defines transactional contexts
3. **Use Case Focused** - Each service represents a use case
4. **Thin Layer** - Delegates to domain for business logic
5. **External Communication** - Interfaces with infrastructure

## Application Services vs Domain Services

- **Application Services**: Orchestrate workflows, coordinate multiple aggregates
- **Domain Services**: Contain business logic that spans multiple entities

## Implementation Status

✅ **Complete** - Service Layer Pattern
- ✅ 43 application service files
- ✅ 4 subdirectories for feature-specific services
- ✅ All use cases covered
- ✅ Clean separation from domain logic

## Architecture Decision

This project uses the **Service Layer Pattern** instead of CQRS (Command Query Responsibility Segregation):
- **Simpler** - Less boilerplate, faster development
- **Sufficient** - Handles all use cases effectively
- **Maintainable** - Easier to understand and modify
- **Flexible** - Can evolve to CQRS if needed in future

CQRS was evaluated but deemed unnecessary for the current requirements.
