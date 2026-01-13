# Infrastructure

External concerns and technical implementations. This layer provides implementations for interfaces defined in the domain.

## Purpose

Infrastructure layer handles:
- **API Communication** - REST API, external clients
- **Data Persistence** - Database operations, repositories
- **Message Queuing** - Background jobs, async processing
- **Security** - Encryption, authentication, authorization
- **Configuration** - Settings management

## Structure

```
Infrastructure/
├── Api/
│   ├── Rest/           # REST API implementation
│   └── Clients/        # External API clients
├── Database/
│   ├── Migrations/     # Database schema migrations
│   └── Repositories/   # Repository implementations
├── Queue/              # Job queue system
├── Security/           # Security utilities
├── Persistence/        # Data persistence layer
└── Configuration/      # Settings management
```

## Namespace

```php
WhatsAppCommerceHub\Infrastructure
```

## Examples

### Using Repository
```php
use WhatsAppCommerceHub\Domain\Cart\CartRepository;
use WhatsAppCommerceHub\Infrastructure\Database\Repositories\WpDbCartRepository;

// Interface is injected (bound in service provider)
$cartRepo = wch(CartRepository::class);
$cart = $cartRepo->find($cartId);
```

### API Client
```php
use WhatsAppCommerceHub\Infrastructure\Api\Clients\WhatsAppApiClient;

$client = wch(WhatsAppApiClient::class);
$client->sendTextMessage($phone, $message);
```

## Principles

1. **Implements Contracts** - Implements interfaces from Domain
2. **External Dependencies** - Handles all external system interactions
3. **Technology Specific** - Contains framework/library specific code
4. **Swappable** - Can be replaced without changing domain logic

## Dependency Rule

Infrastructure depends on Domain (interfaces), but Domain never depends on Infrastructure.

```
Domain (interfaces) ← Infrastructure (implementations)
```

## Migration Status

Phase 4 - Not Started
- 🔴 API layer
- 🔴 Database layer
- 🔴 Queue system
- 🔴 Security layer
- 🔴 Configuration
