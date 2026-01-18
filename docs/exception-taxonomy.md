# Exception Taxonomy

## Overview

This document defines the exception taxonomy used throughout the WhatsApp Commerce Hub plugin. The taxonomy consists of three layers that align with the application architecture: **Domain**, **Application**, and **Infrastructure**.

The primary purpose of this taxonomy is to enable **predictable error handling** where processors can automatically decide whether to retry or move failed jobs to the dead letter queue based on exception type.

## Exception Hierarchy

```
\Exception
└── WchException (base exception with error code, HTTP status, context)
    ├── DomainException (business rule violations)
    ├── ApplicationException (application layer errors)
    └── InfrastructureException (external service failures)
```

## Exception Types

### 1. DomainException

**Purpose:** Represents violations of business rules and domain invariants.

**Characteristics:**
- **NEVER retried** - The same input will always fail
- HTTP status: 400 (Bad Request) by default
- Used for business logic violations

**Examples:**
- Cart is expired
- Product out of stock
- Insufficient stock for requested quantity
- Invalid coupon code
- Order cannot be modified after payment
- Cart locked by another operation

**Concrete Implementations:**
- `CartException` - Cart operation failures
  - `CartException::outOfStock()`
  - `CartException::insufficientStock()`
  - `CartException::expired()`
  - `CartException::empty()`
  - `CartException::notFound()`

**Usage:**
```php
use WhatsAppCommerceHub\Exceptions\DomainException;
use WhatsAppCommerceHub\Exceptions\CartException;

// Generic domain exception
throw new DomainException(
    'Cannot modify order after payment',
    'order_locked',
    400,
    ['order_id' => $order_id]
);

// Specific cart exception
throw CartException::outOfStock($product_id, $product_name);
```

### 2. ApplicationException

**Purpose:** Represents errors in the application layer including validation, state management, and workflow orchestration.

**Characteristics:**
- **Retry behavior varies** - Check `isRetryable()` method
- HTTP status: 500 (Internal Server Error) by default (422 for validation)
- Used for application-level concerns

**Subtypes:**
- **Validation errors:** NOT retryable (same input always fails)
- **State errors:** MAY be retryable (race conditions, transient state)
- **Processing errors:** MAY be retryable (depends on context)

**Concrete Implementations:**
- `ValidationException` - Input validation failures
  - Field-level errors with `getErrors()`, `getFieldErrors()`
  - Factory methods: `ValidationException::forField()`, `ValidationException::withErrors()`

**Usage:**
```php
use WhatsAppCommerceHub\Exceptions\ApplicationException;
use WhatsAppCommerceHub\Exceptions\ValidationException;

// Generic application exception
throw new ApplicationException(
    'Order creation failed due to state conflict',
    'order_creation_failed',
    500,
    ['customer_phone' => $phone]
);

// Validation exception
throw ValidationException::forField('email', 'Invalid email format');

// Multiple validation errors
throw ValidationException::withErrors([
    'email' => ['Invalid email format', 'Email already exists'],
    'phone' => 'Phone number is required'
]);
```

### 3. InfrastructureException

**Purpose:** Represents failures in external dependencies and infrastructure components.

**Characteristics:**
- **Generally retryable** - Transient failures may succeed on retry
- HTTP status: 503 (Service Unavailable) by default
- Used for external service failures

**Examples:**
- API calls to external services (WhatsApp, OpenAI, payment gateways)
- Database connection failures
- Network timeouts
- File system errors
- Circuit breaker open states
- WooCommerce unavailable

**Concrete Implementations:**
- `ApiException` - WhatsApp Graph API errors
  - Detection methods: `isRateLimitError()`, `isAuthError()`
  - Smart retry logic: rate limits = yes, auth errors = no, 5xx = yes
  - Factory: `ApiException::fromApiResponse($error, $status)`
- `CircuitOpenException` - Circuit breaker is open

**Usage:**
```php
use WhatsAppCommerceHub\Exceptions\InfrastructureException;
use WhatsAppCommerceHub\Exceptions\ApiException;
use WhatsAppCommerceHub\Resilience\CircuitOpenException;

// Generic infrastructure exception
throw new InfrastructureException(
    'Database connection failed',
    'db_connection_error',
    503,
    ['host' => $host]
);

// API exception from response
throw ApiException::fromApiResponse(
    ['code' => 4, 'message' => 'Rate limit exceeded'],
    429
);

// Circuit breaker open
throw new CircuitOpenException('whatsapp_api');
```

## Retry Decision Matrix

The `AbstractQueueProcessor::shouldRetry()` method uses this taxonomy:

| Exception Type | Default Behavior | Override |
|---------------|------------------|----------|
| `DomainException` | ❌ NEVER retry | Cannot override |
| `ApplicationException` | Calls `isRetryable()` | Override in subclass |
| `InfrastructureException` | Calls `isRetryable()` | Override in subclass |
| `WchException` | Calls `isRetryable()` | Override in subclass |
| `InvalidArgumentException` | ❌ NEVER retry | Can override in processor |
| `\DomainException` (PHP std) | ❌ NEVER retry | Can override in processor |
| Other exceptions | ✅ Retry | Can override in processor |

### ApiException Retry Logic

`ApiException::isRetryable()` implements smart retry logic:

```php
// Auth errors (190, 200, 10, 100) → NO retry (config issue)
// Rate limits (4, 17, 32, 613, etc.) → YES retry
// 5xx errors → YES retry (server-side issues)
// Recipient errors (131000-131999) → NO retry (user unreachable)
// 4xx errors → NO retry (client errors)
```

### Dead Letter Queue Integration

When a job fails and should NOT be retried, it's moved to the DLQ with a reason:

| Reason | When Used |
|--------|-----------|
| `REASON_MAX_RETRIES` | Exceeded retry attempts (3 by default) |
| `REASON_EXCEPTION` | Non-retryable exception thrown |
| `REASON_VALIDATION` | Validation failure (not currently used) |
| `REASON_CIRCUIT_OPEN` | Circuit breaker open (not currently used) |
| `REASON_TIMEOUT` | Job execution timeout |

## Implementation Guide

### Creating a New Exception Type

1. **Determine the layer:**
   - Business rule violation? → `DomainException`
   - Validation or app logic? → `ApplicationException`
   - External service call? → `InfrastructureException`

2. **Extend the appropriate base:**
```php
namespace WhatsAppCommerceHub\Exceptions;

class PaymentException extends DomainException {
    public const ERROR_INSUFFICIENT_FUNDS = 'insufficient_funds';

    public static function insufficientFunds(float $required, float $available): static {
        return new static(
            sprintf('Insufficient funds: %.2f required, %.2f available', $required, $available),
            self::ERROR_INSUFFICIENT_FUNDS,
            400,
            ['required' => $required, 'available' => $available]
        );
    }
}
```

3. **Override `isRetryable()` if needed:**
```php
public function isRetryable(): bool {
    // Custom logic based on error code or context
    if ($this->errorCode === self::ERROR_TRANSIENT_FAILURE) {
        return true;
    }
    return parent::isRetryable();
}
```

### Throwing Exceptions in Services

**DO:**
```php
// Use specific exception types
throw new DomainException('Cart expired', 'cart_expired', 410);

// Use factory methods
throw CartException::expired($cart_identifier);

// Add context
throw new InfrastructureException(
    'Payment gateway timeout',
    'payment_timeout',
    503,
    ['gateway' => 'stripe', 'transaction_id' => $id]
);
```

**DON'T:**
```php
// Don't use generic RuntimeException
throw new \RuntimeException('Something went wrong');

// Don't lose context
throw new Exception($e->getMessage());

// Don't use wrong layer
throw new DomainException('Database connection failed'); // Use Infrastructure!
```

### Custom Retry Logic in Processors

Override `shouldRetry()` for custom logic:

```php
public function shouldRetry(\Throwable $exception): bool {
    // Check for idempotency
    if (str_contains($exception->getMessage(), 'already processed')) {
        return false;
    }

    // Custom handling for specific exceptions
    if ($exception instanceof CustomException) {
        return $exception->getErrorCode() !== 'permanent_failure';
    }

    // Fall back to parent taxonomy logic
    return parent::shouldRetry($exception);
}
```

## Migration Guide

### Converting RuntimeException to Taxonomy

**Before:**
```php
if (!function_exists('wc_create_order')) {
    throw new \RuntimeException('WooCommerce is not available');
}
```

**After:**
```php
if (!function_exists('wc_create_order')) {
    throw new InfrastructureException(
        'WooCommerce is not available',
        'woocommerce_unavailable',
        503
    );
}
```

### Converting InvalidArgumentException

**Before:**
```php
if (empty($cart_items)) {
    throw new \InvalidArgumentException('Cart items are required');
}
```

**After (if validation):**
```php
if (empty($cart_items)) {
    throw ValidationException::forField('cart_items', 'Cart items are required');
}
```

**After (if domain rule):**
```php
if (empty($cart_items)) {
    throw CartException::empty();
}
```

## Benefits

1. **Predictable Error Handling:** Processors automatically know whether to retry based on exception type
2. **Clear Separation of Concerns:** Exception type indicates which layer failed
3. **Better Observability:** Error codes and context enable better logging and monitoring
4. **Reduced Boilerplate:** Factory methods and inheritance eliminate repetitive code
5. **Type Safety:** Static analysis can catch incorrect exception usage
6. **Consistent DLQ Behavior:** Failed jobs are categorized consistently

## Related Files

- Base exceptions: `includes/Exceptions/`
  - `WchException.php` - Base exception
  - `DomainException.php` - Business rules
  - `ApplicationException.php` - Application layer
  - `InfrastructureException.php` - External services
- Concrete exceptions:
  - `CartException.php` - Cart operations
  - `ValidationException.php` - Input validation
  - `ApiException.php` - WhatsApp API errors
- Queue processing: `includes/Queue/Processors/AbstractQueueProcessor.php`
- Circuit breaker: `includes/Resilience/CircuitBreaker.php`
- Dead letter queue: `includes/Queue/DeadLetterQueue.php`

## See Also

- [Queue Processing Documentation](./queue-processing.md)
- [Circuit Breaker Pattern](./circuit-breaker.md)
- [Dead Letter Queue](./dead-letter-queue.md)
