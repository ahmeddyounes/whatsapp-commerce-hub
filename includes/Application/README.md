# Application

Application services and use cases that orchestrate domain logic.

## Purpose

The application layer:
- Orchestrates domain objects to fulfill use cases
- Contains application-specific business rules
- Coordinates transactions and events
- Implements CQRS patterns (Commands and Queries)

## Structure

```
Application/
├── Commands/              # Write operations
├── Queries/               # Read operations
├── Handlers/
│   ├── CommandHandlers/  # Process commands
│   └── QueryHandlers/    # Process queries
└── Services/             # Application services
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

### Command/Query Pattern (Optional)
```php
use WhatsAppCommerceHub\Application\Commands\CreateOrderCommand;
use WhatsAppCommerceHub\Application\Handlers\CommandHandlers\CreateOrderHandler;

$command = new CreateOrderCommand($cartId, $customerId);
$handler = wch(CreateOrderHandler::class);
$order = $handler->handle($command);
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

## Migration Status

Phase 5 - Not Started
- 🔴 CQRS infrastructure
- 🔴 Checkout service
- 🔴 Sync services
- 🔴 Command handlers
- 🔴 Query handlers
