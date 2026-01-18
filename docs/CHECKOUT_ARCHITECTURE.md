# Checkout Architecture

This document clarifies the checkout responsibilities across the codebase.

## Single Source of Truth

**Use `Application\Services\Checkout\CheckoutOrchestrator` for all checkout operations.**

- **Interface**: `WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutOrchestratorInterface`
- **Implementation**: `WhatsAppCommerceHub\Application\Services\Checkout\CheckoutOrchestrator`
- **Container Alias**: `wch.checkout`

## Component Responsibilities

### CheckoutOrchestrator (Application Layer)
**Location**: `includes/Application/Services/Checkout/CheckoutOrchestrator.php`

**Responsibility**: Main entry point for all checkout operations. Coordinates specialized checkout services.

**Key Methods**:
- `startCheckout(string $phone): array` - Initializes checkout session
- `processAddress(string $phone, array|string $address): array` - Handles address input
- `processShippingMethod(string $phone, string $methodId): array` - Handles shipping selection
- `processPaymentMethod(string $phone, string $methodId): array` - Handles payment selection
- `confirmOrder(string $phone): array` - Creates the final order
- `cancelCheckout(string $phone): bool` - Cancels checkout session
- `goBack(string $phone): array` - Navigates to previous step

**Dependencies**:
- CheckoutStateManager - Manages checkout state/session
- AddressHandler - Validates and stores addresses
- ShippingCalculator - Calculates shipping rates
- PaymentHandler - Manages payment methods
- CheckoutTotalsCalculator - Calculates order totals
- CouponHandler - Handles coupon validation/application
- CheckoutSaga (optional) - For order creation with transactional safety

### CheckoutSaga
**Location**: `includes/Sagas/CheckoutSaga.php`

**Responsibility**: Executes order creation as a saga with compensating transactions. Used BY the orchestrator.

**Not a separate checkout entry point** - The saga is called from within `CheckoutOrchestrator::confirmOrder()` to handle the order creation flow with automatic rollback on failure.

**Steps**:
1. Validate cart
2. Reserve inventory
3. Create order
4. Process payment
5. Send confirmation

Each step includes compensation logic for rollback if subsequent steps fail.

### CheckoutService (Legacy/Deprecated)
**Location**: `includes/Application/Services/CheckoutService.php`

**Status**: This service duplicates much of the CheckoutOrchestrator functionality and should be considered deprecated. It exists for backward compatibility but new code should use CheckoutOrchestrator.

## Migration Notes

### What Was Removed

1. **`Checkout\CheckoutOrchestrator`** (old step-based orchestrator)
   - Used a different step handler pattern
   - Returned `CheckoutResponse` value objects
   - Had methods: `startCheckout()`, `processInput()`, `goToStep()`, `goBack()`, `getSteps()`, `getStep()`

2. **`Contracts\Checkout\CheckoutOrchestratorInterface`** (old interface)
   - Defined the contract for the removed orchestrator

### What Remains

1. **`Application\Services\Checkout\CheckoutOrchestrator`** (new service-based orchestrator)
   - Uses focused service dependencies
   - Returns arrays with structured data
   - Modern, cleaner API

2. **`Contracts\Services\Checkout\CheckoutOrchestratorInterface`** (new interface)
   - Defines the contract for the current orchestrator

### For Tests

Tests previously using `Contracts\Checkout\CheckoutOrchestratorInterface` should be updated to:
- Use `Contracts\Services\Checkout\CheckoutOrchestratorInterface`
- Update method calls to match the new API (returns arrays instead of CheckoutResponse objects)
- Access the orchestrator via the container: `wch_get_container()->get(CheckoutOrchestratorInterface::class)`

## Service Provider Registration

Checkout services are registered in `CheckoutServiceProvider`:

```php
// Main orchestrator
CheckoutOrchestratorInterface::class => CheckoutOrchestrator
CheckoutOrchestrator::class => alias to interface
'wch.checkout' => alias to interface

// Dependencies
CheckoutStateManagerInterface::class => CheckoutStateManager
AddressHandlerInterface::class => AddressHandler
ShippingCalculatorInterface::class => ShippingCalculator
PaymentHandlerInterface::class => PaymentHandler
CheckoutTotalsCalculatorInterface::class => CheckoutTotalsCalculator
CouponHandlerInterface::class => CouponHandler
```

The saga is registered separately in `SagaServiceProvider`.

## Usage Example

```php
// Get orchestrator
$checkout = wch_get_container()->get('wch.checkout');

// Start checkout
$result = $checkout->startCheckout($phone);
if ($result['success']) {
    $step = $result['step']; // 'address'
    $data = $result['data']; // Step-specific data
}

// Process address
$address = [
    'address_1' => '123 Main St',
    'city' => 'New York',
    'postcode' => '10001',
    'country' => 'US',
];
$result = $checkout->processAddress($phone, $address);

// Continue through steps...
$result = $checkout->processShippingMethod($phone, 'flat_rate:1');
$result = $checkout->processPaymentMethod($phone, 'cod');

// Confirm order (triggers saga if available)
$result = $checkout->confirmOrder($phone);
if ($result['success']) {
    $orderId = $result['order_id'];
    $orderNumber = $result['order_number'];
}
```

## Architecture Diagram

```
┌─────────────────────────────────────────┐
│   CheckoutOrchestrator                  │
│   (Main Entry Point)                    │
└─────────┬───────────────────────────────┘
          │
          ├──> CheckoutStateManager
          ├──> AddressHandler
          ├──> ShippingCalculator
          ├──> PaymentHandler
          ├──> CheckoutTotalsCalculator
          ├──> CouponHandler
          │
          └──> CheckoutSaga (for confirmOrder)
                    │
                    ├──> Step: Validate Cart
                    ├──> Step: Reserve Inventory
                    ├──> Step: Create Order
                    ├──> Step: Process Payment
                    └──> Step: Send Confirmation
```

## Key Principles

1. **Single Entry Point**: Always use `CheckoutOrchestrator` for checkout operations
2. **Separation of Concerns**: Each service has a focused responsibility
3. **Saga for Transactions**: Order creation uses saga pattern for transactional safety
4. **State Management**: Checkout state is managed separately from cart state
5. **No Ambiguity**: No duplicate orchestrators or conflicting interfaces
