# Domain

Business domain models, entities, and domain services. This is the heart of the application's business logic.

## Purpose

The domain layer contains:
- **Entities** - Business objects with identity (Order, Cart, Customer)
- **Value Objects** - Immutable objects defined by their values (Money, Address)
- **Aggregates** - Clusters of entities and value objects
- **Domain Services** - Business logic that doesn't belong to a single entity
- **Repository Interfaces** - Contracts for data access (implementations in Infrastructure)

## Structure

```
Domain/
├── Catalog/        # Product catalog management
├── Cart/           # Shopping cart functionality
├── Order/          # Order processing
├── Customer/       # Customer management
├── Payment/        # Payment domain logic
└── Conversation/   # WhatsApp conversation state
```

## Namespace

```php
WhatsAppCommerceHub\Domain
```

## Examples

### Cart Aggregate
```php
use WhatsAppCommerceHub\Domain\Cart\Cart;
use WhatsAppCommerceHub\Domain\Cart\CartItem;

$cart = Cart::create($customerId);
$cart->addItem(new CartItem($productId, $quantity, $price));
$total = $cart->calculateTotal();
```

### Using Domain Service
```php
use WhatsAppCommerceHub\Domain\Cart\CartService;

$cartService = wch(CartService::class);
$cart = $cartService->getCart($phoneNumber);
```

## Principles

1. **Business Logic First** - Domain objects contain business rules
2. **Independence** - No dependencies on infrastructure or presentation
3. **Rich Models** - Entities have behavior, not just data
4. **Ubiquitous Language** - Code reflects business terminology
5. **Immutability** - Value objects are immutable

## Domain-Driven Design

This layer follows DDD principles:
- **Aggregates** protect business invariants
- **Repositories** abstract persistence
- **Domain Events** communicate changes
- **Entities** have identity and lifecycle
- **Value Objects** are compared by value

## Migration Status

Phase 3 - Not Started
- 🔴 Cart domain
- 🔴 Order domain
- 🔴 Catalog domain
- 🔴 Customer domain
- 🔴 Payment domain
- 🔴 Conversation domain
