# Boot Sequence

This document describes the complete boot sequence of the WhatsApp Commerce Hub plugin, from requirements validation through container initialization to context-specific runtime behavior.

## Overview

The plugin uses a **three-phase initialization** approach:

1. **Requirements Phase**: Validates system dependencies before any initialization
2. **Container/Providers Phase**: Registers and boots all service providers
3. **Context-Specific Boot**: Initializes runtime context for conversations and requests

This ensures clean separation of concerns, proper dependency resolution, and fail-fast behavior when requirements aren't met.

## Phase 1: Requirements Validation

### Entry Point

File: `whatsapp-commerce-hub.php:404-426`

The bootstrap process begins when WordPress fires the `plugins_loaded` action at priority 20 (ensuring WooCommerce has already initialized at priority 10):

```php
add_action( 'plugins_loaded', 'wch_init_plugin', 20 );
```

### Requirement Checks

Function: `wch_check_requirements()` (whatsapp-commerce-hub.php:295-330)

The plugin validates three critical dependencies:

1. **PHP Version**: Must be >= 8.1
   - Uses `version_compare(PHP_VERSION, '8.1', '<')`
   - Returns localized error message if check fails

2. **WordPress Version**: Must be >= 6.0
   - Checks global `$wp_version`
   - Returns localized error message if check fails

3. **WooCommerce Status**: Must be active and >= 8.0
   - Checks if `WooCommerce` class exists
   - Validates `WC_VERSION` constant against minimum 8.0
   - Returns localized error message if check fails

### Requirement Failure Handling

File: `whatsapp-commerce-hub.php:410-415`

If any requirement check fails:

- **No container initialization occurs** (prevents WooCommerce-dependent code from loading)
- **No service providers are registered**
- **No WordPress hooks are registered**
- **Admin notice is queued** via `wch_requirements_notice()` to inform administrators
- **Plugin effectively remains inactive** for the request

### Activation-Time Requirements

File: `whatsapp-commerce-hub.php:343-364`

During plugin activation (`wch_activate_plugin()`):

1. Requirements are checked first
2. If requirements fail:
   - Plugin is immediately deactivated
   - Error message displayed via `wp_die()` with back link
3. If requirements pass:
   - Database tables are created via `DatabaseManager::install()`

## Phase 2: Container and Service Providers

### Container Initialization

Function: `wch_get_container()` (whatsapp-commerce-hub.php:85-144)

Once requirements pass, the dependency injection container is initialized:

```php
$wch_container = new \WhatsAppCommerceHub\Container\Container();
```

Container: `includes/Container/Container.php`

The container is a PSR-11 compatible implementation featuring:

- **Singleton support**: Shared instances across the application
- **Auto-wiring**: Automatic dependency resolution via reflection
- **Circular dependency detection**: Prevents infinite loops during resolution
- **Service provider pattern**: Two-phase initialization (register + boot)
- **Lazy loading**: Services only instantiated when first requested

### Service Provider Registration

File: `whatsapp-commerce-hub.php:94-130`

**CRITICAL**: Providers are registered in a specific order because dependencies must be registered before their dependents.

#### Registration Order

**Foundation Layer** (Core infrastructure services):

1. `CoreServiceProvider` - Provides: wpdb, logger, encryption, database, settings
2. `ResilienceServiceProvider` - Provides: circuit breakers, retry policies
3. `SecurityServiceProvider` - Provides: rate limiters, validation, auth

**Infrastructure Layer**:

4. `RepositoryServiceProvider` - Provides: all data repositories
5. `QueueServiceProvider` - Provides: job queue system

**Core Services Layer**:

6. `ApiClientServiceProvider` - Provides: WhatsApp API client (depends on ResilienceServiceProvider)
7. `BusinessServiceProvider` - Provides: core business logic services

**Feature Services Layer**:

8. `ActionServiceProvider` - Provides: WhatsApp message handlers
9. `ProductSyncServiceProvider` - Provides: WooCommerce catalog sync
10. `ReengagementServiceProvider` - Provides: abandoned cart recovery
11. `NotificationServiceProvider` - Provides: order status notifications
12. `PaymentServiceProvider` - Provides: payment gateway integration
13. `CheckoutServiceProvider` - Provides: checkout flow orchestration
14. `BroadcastsServiceProvider` - Provides: bulk messaging campaigns
15. `AdminSettingsServiceProvider` - Provides: settings page configuration

**Orchestration Layer**:

16. `SagaServiceProvider` - Provides: long-running workflow orchestration
17. `EventServiceProvider` - Provides: domain event dispatching
18. `MonitoringServiceProvider` - Provides: metrics and observability

**Controllers & Admin Layer**:

19. `ControllerServiceProvider` - Provides: REST API controllers
20. `AdminServiceProvider` - Provides: WordPress admin pages and UI

All providers are located in: `includes/Providers/`

### Provider Pattern

Interface: `includes/Container/ServiceProviderInterface.php`

Each provider implements three methods:

1. **`register(ContainerInterface $container): void`**
   - Registers service bindings in the container
   - **No side effects allowed** (no initialization, no hooks)
   - Only defines how services should be created

2. **`boot(ContainerInterface $container): void`**
   - Initializes services after all providers are registered
   - **Side effects happen here**: register hooks, initialize schedulers, setup admin pages
   - Can safely resolve dependencies from container

3. **`provides(): array`**
   - Returns array of service identifiers the provider offers
   - Used for documentation and debugging

### Registration vs. Boot Phases

**Registration Phase** (whatsapp-commerce-hub.php:128-130):

```php
foreach ( $providers as $provider ) {
    $wch_container->register( $provider );
}
```

During registration:
- Each provider's `register()` method is called
- Service bindings (singletons and factories) are defined
- **No services are instantiated yet**
- **No WordPress hooks are registered**
- Container builds its binding map

Hook: `do_action('wch_container_registered', $wch_container)` fires after registration completes, allowing third-party extensions to register additional providers.

**Boot Phase** (whatsapp-commerce-hub.php:138):

```php
$wch_container->boot();
```

During boot:
- Each provider's `boot()` method is called in registration order
- Services may be resolved and initialized
- WordPress hooks are registered
- Admin pages are initialized
- Queue processors are scheduled
- REST API endpoints are registered
- Background job schedulers are initialized

Hook: `do_action('wch_container_booted', $wch_container)` fires after boot completes.

### Example: CoreServiceProvider

File: `includes/Providers/CoreServiceProvider.php`

**Register Phase** (lines 50-315):

Registers core infrastructure:
- WordPress `wpdb` instance
- Logger service (`Core\Logger`)
- Encryption service
- Database manager
- Settings manager
- Legacy adapters for backward compatibility

**Boot Phase** (lines 323-334):

Initializes runtime components:
- Resolves logger to ensure it's initialized
- Registers global error handler (`ErrorHandler::init()`)
- Error handler is set up **only** during boot to ensure logger exists first

## Phase 3: Context-Specific Boot

### Plugin Singleton Initialization

Class: `WhatsAppCommerceHubPlugin` (whatsapp-commerce-hub.php:187-282)

After container is booted, the plugin singleton is created:

```php
WhatsAppCommerceHubPlugin::getInstance();
```

The plugin class has **minimal initialization** because service providers already handled their services:

- Registers text domain loading hook (`load_textdomain`)
- Registers migration check on `admin_init`
- Registers WooCommerce order conversion tracking hook

Most initialization is **delegated to service providers** to maintain single responsibility.

### Conversation Context Initialization

The plugin uses context objects to maintain conversation state across WhatsApp messages.

#### ConversationContext Value Object

File: `includes/ValueObjects/ConversationContext.php`

An **immutable value object** that encapsulates:

- **Current state**: The FSM state (idle, browsing, cart, checkout, etc.)
- **State data**: Temporary data specific to current state
- **Slots**: Extracted entity values (product IDs, quantities, addresses)
- **History**: Last 10 conversation turns for AI context
- **Timestamps**: Created, updated, last activity times
- **Timeout detection**: 24-hour conversation expiration

Key methods:
- `getCurrentState()`: Get current FSM state
- `withState(string $state)`: Create new context with updated state (immutable)
- `setSlot(string $key, $value)`: Store entity value
- `getSlot(string $key)`: Retrieve entity value
- `isExpired()`: Check if context exceeded timeout (default: 24 hours)
- `buildAIContext()`: Generate context string for OpenAI prompts
- `toArray()` / `fromArray()`: Serialization for database persistence

#### ContextManagerService

File: `includes/Application/Services/ContextManagerService.php`

Manages **context persistence and lifecycle**:

**Loading Context**:
- Queries database for customer's conversation context
- Uses 5-minute cache to reduce database load
- Deserializes JSON into `ConversationContext` value object

**Saving Context**:
- Serializes `ConversationContext` to JSON
- Persists to database with customer phone as key
- Invalidates cache

**Expiration Handling**:
- Checks if context exceeded 24-hour timeout
- Archives expired conversations to history table
- Preserves slots (extracted entities) for returning customers
- Resets state to idle while maintaining customer data

**Context Merging**:
- Merges preserved slots with new conversation context
- Enables seamless experience for returning customers

### Runtime Request Flow

When a WhatsApp message is received:

1. **Webhook Handler** receives POST request (registered by `ControllerServiceProvider`)
2. **Context Manager** loads conversation context from database (with caching)
3. **Intent Classifier** analyzes message and determines customer intent
4. **FSM** (Finite State Machine) transitions state based on intent + current state
5. **Action Handlers** execute business logic for the transition
6. **Context Manager** saves updated context back to database
7. **Response Builder** formats WhatsApp message
8. **API Client** sends message via WhatsApp Business API

## Key Architectural Patterns

### 1. Fail-Fast Requirements

Requirements are checked **before** any initialization:
- If requirements fail, no container, no services, no hooks
- Prevents errors from WooCommerce-dependent code
- Clean error messages guide administrators

### 2. Two-Phase Initialization

**Registration Phase**:
- Pure binding definitions
- No side effects
- Fast and safe

**Boot Phase**:
- Service initialization
- Hook registration
- Side effects allowed

### 3. Dependency Injection

**Auto-wiring** via reflection:
```php
$service = $container->make(SomeService::class);
// Container automatically resolves constructor dependencies
```

**Explicit binding**:
```php
$container->singleton(LoggerInterface::class, function($c) {
    return new Logger($c->get(SettingsInterface::class));
});
```

### 4. Provider Ordering

Critical dependencies registered first:
- Core → Resilience → Security → Infrastructure → Features → Controllers
- Prevents "service not found" errors
- Clear dependency graph

### 5. Immutable Context

`ConversationContext` is immutable:
- State changes create new context objects
- Prevents accidental mutations
- Safe for caching and concurrent access

### 6. Singleton Pattern

Used for:
- Container instance (global `$wch_container`)
- Plugin class (`WhatsAppCommerceHubPlugin`)
- Most services (logger, settings, API client)

Ensures single source of truth and consistent state.

## Database Schema Initialization

File: `includes/Infrastructure/Database/DatabaseManager.php`

### Activation-Time Install

Function: `install()`

Called during plugin activation (`wch_activate_plugin()`):

1. Creates all required database tables
2. Sets initial schema version in options table
3. Does not run migrations (only fresh install)

Tables created:
- `wch_conversations`: Conversation contexts and state
- `wch_conversation_history`: Archived conversation turns
- `wch_messages`: WhatsApp message log
- `wch_job_queue`: Background job queue
- `wch_analytics`: Metrics and conversion tracking
- `wch_broadcasts`: Campaign message queue

### Runtime Migrations

Function: `run_migrations()`

Called on `admin_init` hook via `WhatsAppCommerceHubPlugin::check_database_migrations()`:

1. Checks current schema version
2. Runs pending migrations sequentially
3. Updates schema version after successful migration
4. **Only runs in admin context** (not on frontend/webhook requests)

## Extension Points

The boot sequence provides hooks for extensibility:

### `wch_container_registered`

Fires after all core providers are registered but before boot.

**Use case**: Register additional service providers or bindings.

```php
add_action('wch_container_registered', function($container) {
    $container->register(new MyCustomServiceProvider());
});
```

### `wch_container_booted`

Fires after all providers are booted.

**Use case**: Resolve services or perform post-boot initialization.

```php
add_action('wch_container_booted', function($container) {
    $myService = $container->get(MyService::class);
    $myService->initialize();
});
```

## Testing Bootstrap

File: `tests/bootstrap.php`

For PHPUnit tests:

1. Loads Composer autoloader
2. Detects WordPress test library location
3. Loads WooCommerce plugin
4. Loads WhatsApp Commerce Hub plugin
5. Activates plugin programmatically
6. Sets up Brain Monkey for mocking WordPress functions
7. Loads test base classes

This ensures test environment mirrors production boot sequence.

## Summary

The boot sequence follows a clear, predictable path:

```
WordPress loads plugins
    ↓
plugins_loaded (priority 20)
    ↓
wch_check_requirements()
    ↓
Requirements met? ──No──→ Show admin notice, exit
    ↓ Yes
wch_get_container()
    ↓
Create Container instance
    ↓
Register 20 service providers (in order)
    ↓
do_action('wch_container_registered')
    ↓
Container boot() - calls boot() on each provider
    ↓
Providers initialize services, hooks, schedulers
    ↓
do_action('wch_container_booted')
    ↓
WhatsAppCommerceHubPlugin::getInstance()
    ↓
Plugin ready to handle requests
    ↓
Webhook receives message
    ↓
ContextManager loads conversation context
    ↓
Message processed through FSM
    ↓
ContextManager saves updated context
    ↓
Response sent to customer
```

This architecture ensures:

- **Clean separation** between requirements, infrastructure, and features
- **Clear dependency resolution** via ordered provider registration
- **Fail-fast behavior** when requirements aren't met
- **Testability** through dependency injection
- **Extensibility** via hooks and service providers
- **State management** through immutable context objects
