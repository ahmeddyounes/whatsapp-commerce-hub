# WhatsApp Commerce Hub - Architecture Improvement & PSR-4 Migration Plan

## Executive Summary

This document outlines a comprehensive plan to improve the plugin's architecture and complete the migration to PSR-4 autoloading standards. The plugin currently has a **hybrid architecture** with ~72 legacy `WCH_` prefixed classes alongside modern namespaced PSR-4 classes, creating maintenance challenges and architectural inconsistencies.

**Current State:**
- 303 PHP files in the includes directory
- 72 legacy `class-wch-*.php` files using WordPress naming conventions
- Modern PSR-4 namespaced classes in subdirectories (Actions, Services, Repositories, etc.)
- Dual autoloader system (custom `wch_autoloader` + `wch_psr4_autoloader`)
- Inconsistent naming patterns and directory organization
- Mixed responsibility boundaries

**Target State:**
- 100% PSR-4 compliant architecture
- Single Composer-based autoloader
- Clear separation of concerns using Domain-Driven Design principles
- Modern dependency injection throughout
- Improved testability and maintainability

---

## 1. Current Architecture Analysis

### 1.1 File Structure Overview

```
includes/
├── Actions/                     ✅ PSR-4 namespaced
├── Admin/                       ✅ PSR-4 namespaced
├── Checkout/                    ✅ PSR-4 namespaced
├── Clients/                     ✅ PSR-4 namespaced
├── Container/                   ✅ PSR-4 namespaced
├── Contracts/                   ✅ PSR-4 namespaced
├── Controllers/                 ✅ PSR-4 namespaced
├── Entities/                    ✅ PSR-4 namespaced
├── Events/                      ✅ PSR-4 namespaced
├── Exceptions/                  ✅ PSR-4 namespaced
├── Monitoring/                  ✅ PSR-4 namespaced
├── Providers/                   ✅ PSR-4 namespaced
├── Queue/                       ✅ PSR-4 namespaced
├── Repositories/                ✅ PSR-4 namespaced
├── Resilience/                  ✅ PSR-4 namespaced
├── Sagas/                       ✅ PSR-4 namespaced
├── Security/                    ✅ PSR-4 namespaced
├── Services/                    ✅ PSR-4 namespaced
├── Validation/                  ✅ PSR-4 namespaced
├── ValueObjects/                ✅ PSR-4 namespaced
├── payments/                    ⚠️  Mixed (legacy + PSR-4)
└── class-wch-*.php (72 files)  ❌ Legacy naming
```

### 1.2 Legacy Classes to Migrate

**Core Infrastructure:**
- `class-wch-logger.php` → `Core/Logger.php`
- `class-wch-encryption.php` → `Security/Encryption.php`
- `class-wch-database-manager.php` → `Database/DatabaseManager.php`
- `class-wch-error-handler.php` → `Core/ErrorHandler.php`
- `class-wch-queue.php` → `Queue/QueueManager.php`

**API & Communication:**
- `class-wch-whatsapp-api-client.php` → Already has `Clients/WhatsAppApiClient.php` ✅
- `class-wch-rest-api.php` → `Api/RestApi.php`
- `class-wch-rest-controller.php` → `Api/RestController.php`
- `class-wch-webhook-handler.php` → `Api/WebhookHandler.php`

**Business Logic:**
- `class-wch-cart-manager.php` → Already has `Services/CartService.php` (merge needed)
- `class-wch-customer-service.php` → Already has `Services/CustomerService.php` ✅
- `class-wch-intent-classifier.php` → Already has `Services/IntentClassifierService.php` ✅
- `class-wch-context-manager.php` → Already has `Services/ContextManagerService.php` ✅
- `class-wch-response-parser.php` → Already has `Services/ResponseParserService.php` ✅
- `class-wch-message-builder.php` → Already has `Services/MessageBuilderService.php` ✅

**Sync Services:**
- `class-wch-product-sync-service.php` → Already has `Services/ProductSyncService.php` (merge needed)
- `class-wch-order-sync-service.php` → Already has `Services/OrderSyncService.php` (merge needed)
- `class-wch-inventory-sync-handler.php` → `Services/InventorySyncService.php`

**Feature Services:**
- `class-wch-abandoned-cart-recovery.php` → `Features/AbandonedCart/RecoveryService.php`
- `class-wch-abandoned-cart-handler.php` → `Features/AbandonedCart/CartHandler.php`
- `class-wch-reengagement-service.php` → Already has `Services/Reengagement/` ✅
- `class-wch-order-notifications.php` → `Services/NotificationService.php`
- `class-wch-catalog-browser.php` → `Features/Catalog/CatalogBrowser.php`
- `class-wch-template-manager.php` → `Templates/TemplateManager.php`

**Payment Gateway:**
- `class-wch-payment-manager.php` → Already has `payments/PaymentGatewayRegistry.php` (merge)
- `class-wch-payment-*.php` → Already migrated to `payments/Gateways/` ✅
- `class-wch-refund-handler.php` → `Services/RefundService.php` or Payment domain
- `class-wch-payment-webhook-handler.php` → `Payments/WebhookHandler.php`

**Admin Pages:**
- `class-wch-admin-*.php` (10 files) → Already have `Admin/` directory (consolidate)
- `class-wch-dashboard-widgets.php` → Already has `Admin/DashboardWidgets.php` ✅

**Actions & Flow:**
- `class-wch-action-*.php` (7 files) → Already have `Actions/` directory (merge)
- `class-wch-flow-action.php` → `Actions/AbstractAction.php` ✅

**Job Handlers:**
- `class-wch-job-dispatcher.php` → `Queue/JobDispatcher.php`
- `class-wch-sync-job-handler.php` → `Queue/Handlers/SyncJobHandler.php`
- `class-wch-broadcast-job-handler.php` → `Queue/Handlers/BroadcastJobHandler.php`
- `class-wch-cart-cleanup-handler.php` → `Queue/Handlers/CartCleanupHandler.php`

**Analytics & Monitoring:**
- `class-wch-analytics-controller.php` → `Controllers/AnalyticsController.php`
- `class-wch-analytics-data.php` → `Analytics/AnalyticsData.php`
- `class-wch-conversations-controller.php` → Already in `Controllers/` ✅

**Domain Models:**
- `class-wch-intent.php` → `Domain/Intent.php` or `ValueObjects/Intent.php`
- `class-wch-parsed-response.php` → `ValueObjects/ParsedResponse.php`
- `class-wch-action-result.php` → `ValueObjects/ActionResult.php`
- `class-wch-conversation-context.php` → `Entities/ConversationContext.php`
- `class-wch-conversation-fsm.php` → `Domain/ConversationStateMachine.php`
- `class-wch-customer-profile.php` → `Entities/CustomerProfile.php`

**Utilities:**
- `class-wch-address-parser.php` → `Utilities/AddressParser.php`
- `class-wch-settings.php` → Already has `Services/SettingsService.php` (merge)

**Test Files (to be moved):**
- `class-wch-test.php` → `tests/` directory
- `class-wch-settings-test.php` → `tests/` directory
- `class-wch-rest-api-test.php` → `tests/` directory

---

## 2. Proposed Architecture (Domain-Driven Design)

### 2.1 Directory Structure

```
includes/
├── Core/                        # Core infrastructure
│   ├── Bootstrap.php
│   ├── Plugin.php
│   ├── Logger.php
│   ├── ErrorHandler.php
│   └── ServiceFactory.php
│
├── Domain/                      # Business domain logic
│   ├── Catalog/
│   │   ├── Product.php
│   │   ├── Category.php
│   │   ├── ProductRepository.php
│   │   └── CatalogService.php
│   ├── Cart/
│   │   ├── Cart.php
│   │   ├── CartItem.php
│   │   ├── CartRepository.php
│   │   └── CartService.php
│   ├── Order/
│   │   ├── Order.php
│   │   ├── OrderLine.php
│   │   ├── OrderRepository.php
│   │   └── OrderService.php
│   ├── Customer/
│   │   ├── Customer.php
│   │   ├── CustomerProfile.php
│   │   ├── CustomerRepository.php
│   │   └── CustomerService.php
│   ├── Payment/
│   │   ├── Payment.php
│   │   ├── PaymentMethod.php
│   │   ├── Refund.php
│   │   ├── PaymentRepository.php
│   │   └── PaymentService.php
│   └── Conversation/
│       ├── Conversation.php
│       ├── Message.php
│       ├── Intent.php
│       ├── Context.php
│       ├── ConversationRepository.php
│       └── StateMachine.php
│
├── Application/                 # Application services (use cases)
│   ├── Commands/
│   │   ├── CreateOrderCommand.php
│   │   ├── AddToCartCommand.php
│   │   ├── ProcessPaymentCommand.php
│   │   └── SyncProductCommand.php
│   ├── Queries/
│   │   ├── GetCartQuery.php
│   │   ├── GetProductQuery.php
│   │   ├── GetOrderQuery.php
│   │   └── GetCustomerQuery.php
│   ├── Handlers/
│   │   ├── CommandHandlers/
│   │   └── QueryHandlers/
│   └── Services/
│       ├── CheckoutService.php
│       ├── ProductSyncService.php
│       ├── OrderSyncService.php
│       └── InventorySyncService.php
│
├── Infrastructure/              # External concerns
│   ├── Api/
│   │   ├── Rest/
│   │   │   ├── RestApi.php
│   │   │   ├── RestController.php
│   │   │   └── Controllers/
│   │   │       ├── WebhookController.php
│   │   │       ├── ProductsController.php
│   │   │       └── OrdersController.php
│   │   └── Clients/
│   │       ├── WhatsAppApiClient.php
│   │       ├── OpenAIClient.php
│   │       └── HttpClient.php
│   ├── Database/
│   │   ├── DatabaseManager.php
│   │   ├── Migrations/
│   │   │   ├── Migration001.php
│   │   │   └── Migration002.php
│   │   └── Repositories/         # Infrastructure implementations
│   │       ├── WpDbCartRepository.php
│   │       ├── WpDbOrderRepository.php
│   │       └── WpDbCustomerRepository.php
│   ├── Queue/
│   │   ├── QueueManager.php
│   │   ├── JobDispatcher.php
│   │   └── Handlers/
│   │       ├── SyncJobHandler.php
│   │       ├── BroadcastJobHandler.php
│   │       └── CartCleanupHandler.php
│   ├── Security/
│   │   ├── Encryption.php
│   │   ├── RateLimiter.php
│   │   └── InputValidator.php
│   └── Persistence/
│       ├── EntityManager.php
│       └── UnitOfWork.php
│
├── Presentation/                # UI & interaction layer
│   ├── Admin/
│   │   ├── Pages/
│   │   │   ├── DashboardPage.php
│   │   │   ├── SettingsPage.php
│   │   │   ├── AnalyticsPage.php
│   │   │   ├── InboxPage.php
│   │   │   ├── JobsPage.php
│   │   │   ├── LogsPage.php
│   │   │   ├── TemplatesPage.php
│   │   │   └── CatalogSyncPage.php
│   │   ├── Widgets/
│   │   │   └── DashboardWidgets.php
│   │   └── Settings/
│   │       ├── SettingsManager.php
│   │       ├── GeneralSettings.php
│   │       ├── PaymentSettings.php
│   │       └── ApiSettings.php
│   ├── Actions/                 # User actions in WhatsApp
│   │   ├── AbstractAction.php
│   │   ├── AddToCartAction.php
│   │   ├── ShowCartAction.php
│   │   ├── ShowProductAction.php
│   │   ├── ShowCategoryAction.php
│   │   ├── ShowMainMenuAction.php
│   │   ├── RequestAddressAction.php
│   │   ├── ConfirmOrderAction.php
│   │   ├── ProcessPaymentAction.php
│   │   └── ActionRegistry.php
│   └── Templates/
│       ├── TemplateManager.php
│       └── TemplateRenderer.php
│
├── Features/                    # Feature modules (bounded contexts)
│   ├── AbandonedCart/
│   │   ├── RecoveryService.php
│   │   ├── CartHandler.php
│   │   ├── ReminderScheduler.php
│   │   └── RecoveryRepository.php
│   ├── Reengagement/
│   │   ├── ReengagementService.php
│   │   ├── CampaignManager.php
│   │   └── CustomerSegmentation.php
│   ├── Broadcasts/
│   │   ├── BroadcastService.php
│   │   ├── BroadcastScheduler.php
│   │   └── BroadcastRepository.php
│   ├── Analytics/
│   │   ├── AnalyticsService.php
│   │   ├── AnalyticsData.php
│   │   └── MetricsCollector.php
│   ├── Notifications/
│   │   ├── NotificationService.php
│   │   ├── OrderNotifications.php
│   │   └── NotificationTemplates.php
│   └── Payments/
│       ├── PaymentGatewayRegistry.php
│       ├── WebhookHandler.php
│       └── Gateways/
│           ├── AbstractGateway.php
│           ├── StripeGateway.php
│           ├── RazorpayGateway.php
│           ├── PixGateway.php
│           ├── WhatsAppPayGateway.php
│           └── CodGateway.php
│
├── Support/                     # Shared utilities
│   ├── Utilities/
│   │   ├── AddressParser.php
│   │   ├── DateFormatter.php
│   │   └── StringHelper.php
│   ├── AI/
│   │   ├── IntentClassifier.php
│   │   ├── ResponseParser.php
│   │   └── ConversationContext.php
│   ├── Messaging/
│   │   ├── MessageBuilder.php
│   │   └── MessageFormatter.php
│   └── Validation/
│       ├── Validator.php
│       └── Rules/
│
├── Container/                   # Dependency injection (keep as is)
│   ├── Container.php
│   ├── ContainerInterface.php
│   ├── ServiceProviderInterface.php
│   └── Exceptions/
│
├── Providers/                   # Service providers (reorganize)
│   ├── CoreServiceProvider.php
│   ├── DomainServiceProvider.php
│   ├── ApplicationServiceProvider.php
│   ├── InfrastructureServiceProvider.php
│   ├── PresentationServiceProvider.php
│   └── FeatureServiceProvider.php
│
├── Contracts/                   # Interfaces (keep organized)
├── ValueObjects/                # Value objects (keep as is)
├── Entities/                    # Data entities (keep as is)
├── Events/                      # Event system (keep as is)
├── Exceptions/                  # Exception hierarchy (keep as is)
├── Sagas/                       # Saga orchestration (keep as is)
├── Monitoring/                  # Monitoring & observability (keep as is)
└── Resilience/                  # Circuit breakers, retry (keep as is)
```

### 2.2 Namespace Structure

```php
WhatsAppCommerceHub\
├── Core\
├── Domain\
│   ├── Catalog\
│   ├── Cart\
│   ├── Order\
│   ├── Customer\
│   ├── Payment\
│   └── Conversation\
├── Application\
│   ├── Commands\
│   ├── Queries\
│   ├── Handlers\
│   └── Services\
├── Infrastructure\
│   ├── Api\
│   ├── Database\
│   ├── Queue\
│   ├── Security\
│   └── Persistence\
├── Presentation\
│   ├── Admin\
│   ├── Actions\
│   └── Templates\
├── Features\
│   ├── AbandonedCart\
│   ├── Reengagement\
│   ├── Broadcasts\
│   ├── Analytics\
│   ├── Notifications\
│   └── Payments\
├── Support\
│   ├── Utilities\
│   ├── AI\
│   ├── Messaging\
│   └── Validation\
└── (existing namespaces: Container, Providers, Contracts, etc.)
```

---

## 3. Migration Strategy

### Phase 1: Foundation & Planning (Week 1-2)
**Goal:** Set up foundation without breaking existing functionality

#### 3.1 Create New Directory Structure
- [ ] Create all new directories under `includes/`
- [ ] Update `.gitignore` if needed
- [ ] Document directory purposes in README

#### 3.2 Update Composer Configuration
```json
{
  "autoload": {
    "psr-4": {
      "WhatsAppCommerceHub\\": "includes/"
    }
  }
}
```

#### 3.3 Create Migration Tracker
- [ ] Create `MIGRATION_STATUS.md` to track progress
- [ ] List all 72 legacy classes with status
- [ ] Priority ranking (critical path dependencies first)

#### 3.4 Set Up Deprecation System
```php
// Core/Deprecation.php
namespace WhatsAppCommerceHub\Core;

class Deprecation {
    public static function trigger(string $old, string $new, string $version): void {
        if (WP_DEBUG) {
            trigger_error(
                sprintf(
                    '%s is deprecated since version %s. Use %s instead.',
                    $old, $version, $new
                ),
                E_USER_DEPRECATED
            );
        }
    }
}
```

### Phase 2: Core Infrastructure Migration (Week 2-3)
**Goal:** Migrate foundational classes that everything else depends on

#### Priority 1: Core Services
1. **Container & DI** (already done ✅)
2. **Logger** - Migrate `class-wch-logger.php`
   ```php
   // includes/Core/Logger.php
   namespace WhatsAppCommerceHub\Core;
   
   class Logger {
       // Migrate WCH_Logger functionality
   }
   
   // Keep legacy class as wrapper
   class WCH_Logger {
       public static function log(...$args) {
           Deprecation::trigger('WCH_Logger', 'Logger::class', '2.0.0');
           return wch(Logger::class)->log(...$args);
       }
   }
   ```

3. **Error Handler** - Migrate `class-wch-error-handler.php`
4. **Encryption** - Move to `Infrastructure/Security/Encryption.php`
5. **Database Manager** - Move to `Infrastructure/Database/DatabaseManager.php`

#### Priority 2: Settings & Configuration
- Merge `class-wch-settings.php` with `Services/SettingsService.php`
- Create unified settings system in `Infrastructure/Configuration/`

### Phase 3: Domain Layer Migration (Week 4-5)
**Goal:** Build out domain models and services

#### 3.1 Cart Domain
- Merge `class-wch-cart-manager.php` + `Services/CartService.php`
- Create `Domain/Cart/` with:
  - `Cart.php` (entity)
  - `CartItem.php` (value object)
  - `CartRepository.php` (interface)
  - `CartService.php` (domain service)

#### 3.2 Order Domain
- Merge `class-wch-order-sync-service.php` + `Services/OrderSyncService.php`
- Create `Domain/Order/` structure

#### 3.3 Product/Catalog Domain
- Merge `class-wch-product-sync-service.php` + `Services/ProductSyncService.php`
- Migrate `class-wch-catalog-browser.php`
- Create `Domain/Catalog/` structure

#### 3.4 Customer Domain
- Already mostly done with `Services/CustomerService.php`
- Migrate `class-wch-customer-profile.php` to `Domain/Customer/CustomerProfile.php`

#### 3.5 Payment Domain
- Move payment gateway classes to `Features/Payments/`
- Merge payment managers
- Create unified payment domain

#### 3.6 Conversation Domain
- Migrate conversation-related classes:
  - `class-wch-conversation-context.php`
  - `class-wch-conversation-fsm.php`
  - `class-wch-intent.php`
- Create `Domain/Conversation/` with FSM and intent handling

### Phase 4: Infrastructure Layer (Week 5-6)
**Goal:** Consolidate all infrastructure concerns

#### 4.1 API Layer
- Migrate REST API classes:
  - `class-wch-rest-api.php` → `Infrastructure/Api/Rest/RestApi.php`
  - `class-wch-rest-controller.php` → `Infrastructure/Api/Rest/RestController.php`
  - `class-wch-webhook-handler.php` → `Infrastructure/Api/Rest/Controllers/WebhookController.php`
  - Controllers already in `Controllers/` → move to `Infrastructure/Api/Rest/Controllers/`

#### 4.2 Queue System
- Migrate job handlers:
  - `class-wch-queue.php` + `Queue/` → `Infrastructure/Queue/`
  - `class-wch-job-dispatcher.php` → `Infrastructure/Queue/JobDispatcher.php`
  - All job handlers → `Infrastructure/Queue/Handlers/`

#### 4.3 Clients
- Keep `Clients/` but move to `Infrastructure/Api/Clients/`

### Phase 5: Application Services (Week 6-7)
**Goal:** Create clean application service layer

#### 5.1 Command/Query Pattern
- Introduce CQRS pattern for complex operations
- Create command handlers for:
  - Order creation
  - Cart operations
  - Payment processing
  - Product sync
  
#### 5.2 Application Services
- Move orchestration logic from domain to application layer
- Services in `Application/Services/`:
  - `CheckoutService.php`
  - `ProductSyncService.php`
  - `OrderSyncService.php`
  - `InventorySyncService.php`

### Phase 6: Presentation Layer (Week 7-8)
**Goal:** Organize all user-facing components

#### 6.1 Admin Pages
- Consolidate `class-wch-admin-*.php` files
- Merge with existing `Admin/` directory
- Create `Presentation/Admin/Pages/` structure

#### 6.2 Actions
- Merge `class-wch-action-*.php` with existing `Actions/`
- Keep in `Presentation/Actions/`

#### 6.3 Templates
- Migrate `class-wch-template-manager.php`
- Create `Presentation/Templates/` structure

### Phase 7: Feature Modules (Week 8-9)
**Goal:** Encapsulate feature-specific logic

#### 7.1 Abandoned Cart Feature
- Consolidate:
  - `class-wch-abandoned-cart-recovery.php`
  - `class-wch-abandoned-cart-handler.php`
  - `class-wch-cart-cleanup-handler.php`
- Into `Features/AbandonedCart/`

#### 7.2 Reengagement Feature
- Already good structure in `Services/Reengagement/`
- Move to `Features/Reengagement/`

#### 7.3 Broadcasts Feature
- Consolidate broadcast-related classes
- Move to `Features/Broadcasts/`

#### 7.4 Analytics Feature
- Migrate:
  - `class-wch-analytics-controller.php`
  - `class-wch-analytics-data.php`
- Into `Features/Analytics/`

#### 7.5 Notifications Feature
- Migrate `class-wch-order-notifications.php`
- Create unified notification system in `Features/Notifications/`

#### 7.6 Payments Feature
- Already has good structure in `payments/`
- Move to `Features/Payments/`
- Consolidate payment webhook handlers

### Phase 8: Support & Utilities (Week 9-10)
**Goal:** Organize shared utilities and helpers

#### 8.1 AI & NLP
- Migrate:
  - `class-wch-intent-classifier.php` (merge with `Services/IntentClassifierService.php`)
  - `class-wch-response-parser.php` (merge with `Services/ResponseParserService.php`)
- Into `Support/AI/`

#### 8.2 Messaging
- Migrate:
  - `class-wch-message-builder.php` (merge with `Services/MessageBuilderService.php`)
- Into `Support/Messaging/`

#### 8.3 Utilities
- Migrate:
  - `class-wch-address-parser.php` → `Support/Utilities/AddressParser.php`
- Create additional utilities as needed

### Phase 9: Service Provider Reorganization (Week 10)
**Goal:** Simplify service provider structure

#### Current Providers (20 files):
- CoreServiceProvider
- ResilienceServiceProvider
- SecurityServiceProvider
- RepositoryServiceProvider
- QueueServiceProvider
- ApiClientServiceProvider
- BusinessServiceProvider
- ActionServiceProvider
- ProductSyncServiceProvider
- ReengagementServiceProvider
- NotificationServiceProvider
- PaymentServiceProvider
- CheckoutServiceProvider
- BroadcastsServiceProvider
- AdminSettingsServiceProvider
- SagaServiceProvider
- EventServiceProvider
- MonitoringServiceProvider
- ControllerServiceProvider
- AdminServiceProvider

#### Proposed Providers (6-8 files):
```php
Providers/
├── CoreServiceProvider.php           # Core infrastructure
├── DomainServiceProvider.php         # All domain services
├── ApplicationServiceProvider.php    # Application services
├── InfrastructureServiceProvider.php # Database, Queue, API
├── PresentationServiceProvider.php   # Admin, Actions, Templates
├── FeatureServiceProvider.php        # Feature modules
├── EventServiceProvider.php          # Keep separate
└── MonitoringServiceProvider.php     # Keep separate
```

Each provider groups related services logically.

### Phase 10: Testing & Documentation (Week 11-12)

#### 10.1 Update Tests
- [ ] Update all test imports to use new namespaces
- [ ] Create tests for new structure
- [ ] Update test bootstrap
- [ ] Run full test suite

#### 10.2 Update PHPStan
```neon
parameters:
  paths:
    - includes
    - whatsapp-commerce-hub.php
  excludePaths:
    - includes/legacy/  # deprecated classes during transition
```

#### 10.3 Update PHPCS
- Already configured for PSR-4 ✅
- Update file naming rules if needed

#### 10.4 Documentation
- [ ] Update `README.md` with new architecture
- [ ] Create `ARCHITECTURE.md` documenting:
  - Directory structure
  - Namespace conventions
  - Dependency flow
  - Adding new features
- [ ] Update `CONTRIBUTING.md` with new guidelines
- [ ] Create API documentation
- [ ] Update inline code documentation

### Phase 11: Remove Legacy Code (Week 12+)

#### 11.1 Deprecation Period
- Keep legacy classes for 1-2 major versions
- All legacy classes trigger deprecation warnings
- Document migration path for each class

#### 11.2 Legacy Class Wrappers
```php
// Example wrapper pattern
class WCH_Cart_Manager {
    public function __construct() {
        _deprecated_class('WCH_Cart_Manager', '2.0.0', 'WhatsAppCommerceHub\Domain\Cart\CartService');
    }
    
    public static function instance() {
        return wch(\WhatsAppCommerceHub\Domain\Cart\CartService::class);
    }
    
    // Proxy all methods to new class
}
```

#### 11.3 Remove Custom Autoloader
Once all classes migrated:
```php
// Remove from whatsapp-commerce-hub.php
// spl_autoload_register('wch_autoloader');  // ❌ Delete
```

Use only Composer autoloader:
```php
require_once WCH_PLUGIN_DIR . 'vendor/autoload.php';
```

#### 11.4 Cleanup
- [ ] Delete `includes/class-wch-*.php` files
- [ ] Remove custom autoloader functions
- [ ] Update main plugin file
- [ ] Final testing pass

---

## 4. Detailed Migration Examples

### 4.1 Example: Cart Service Migration

**Before (Legacy):**
```php
// includes/class-wch-cart-manager.php
class WCH_Cart_Manager {
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get_cart($phone) {
        // Implementation
    }
}

// Usage:
$cart = WCH_Cart_Manager::instance()->get_cart($phone);
```

**After (PSR-4):**
```php
// includes/Domain/Cart/CartService.php
namespace WhatsAppCommerceHub\Domain\Cart;

use WhatsAppCommerceHub\Domain\Cart\CartRepository;
use WhatsAppCommerceHub\Domain\Cart\Cart;

class CartService {
    public function __construct(
        private CartRepository $repository
    ) {}
    
    public function getCart(string $phone): ?Cart {
        return $this->repository->findByPhone($phone);
    }
}

// includes/Domain/Cart/CartRepository.php
namespace WhatsAppCommerceHub\Domain\Cart;

interface CartRepository {
    public function findByPhone(string $phone): ?Cart;
    public function save(Cart $cart): void;
}

// includes/Infrastructure/Database/Repositories/WpDbCartRepository.php
namespace WhatsAppCommerceHub\Infrastructure\Database\Repositories;

use WhatsAppCommerceHub\Domain\Cart\CartRepository;
use WhatsAppCommerceHub\Domain\Cart\Cart;

class WpDbCartRepository implements CartRepository {
    public function findByPhone(string $phone): ?Cart {
        // Implementation using $wpdb
    }
}

// Usage with DI Container:
$cartService = wch(CartService::class);
$cart = $cartService->getCart($phone);
```

**Backward Compatibility Wrapper:**
```php
// includes/class-wch-cart-manager.php (kept temporarily)
class WCH_Cart_Manager {
    public static function instance() {
        _deprecated_function(__METHOD__, '2.0.0', 'wch(CartService::class)');
        return new self();
    }
    
    public function get_cart($phone) {
        return wch(\WhatsAppCommerceHub\Domain\Cart\CartService::class)
            ->getCart($phone);
    }
}
```

### 4.2 Example: Service Provider Consolidation

**Before (Multiple Providers):**
```php
// 20 separate provider files
BusinessServiceProvider.php
ActionServiceProvider.php
ProductSyncServiceProvider.php
// ... 17 more
```

**After (Consolidated):**
```php
// Providers/DomainServiceProvider.php
namespace WhatsAppCommerceHub\Providers;

class DomainServiceProvider implements ServiceProviderInterface {
    public function register(ContainerInterface $container): void {
        // Register all domain services
        $container->singleton(CartService::class, function($c) {
            return new CartService(
                $c->get(CartRepository::class)
            );
        });
        
        $container->singleton(OrderService::class, function($c) {
            return new OrderService(
                $c->get(OrderRepository::class)
            );
        });
        
        // ... all other domain services
    }
}

// Providers/FeatureServiceProvider.php
namespace WhatsAppCommerceHub\Providers;

class FeatureServiceProvider implements ServiceProviderInterface {
    public function register(ContainerInterface $container): void {
        // Register feature modules
        $container->singleton(AbandonedCartService::class);
        $container->singleton(ReengagementService::class);
        $container->singleton(BroadcastService::class);
        // ... etc
    }
}
```

---

## 5. Architecture Principles

### 5.1 Dependency Rule
**Dependencies point inward:**
```
Presentation → Application → Domain ← Infrastructure
    ↓              ↓            ↑
    └──────────────────────────┘
         (through interfaces)
```

- **Domain** = Pure business logic, no dependencies
- **Application** = Use cases, depends on Domain
- **Infrastructure** = External concerns, implements Domain interfaces
- **Presentation** = UI/UX, uses Application services

### 5.2 SOLID Principles

#### Single Responsibility
Each class has one reason to change.
```php
// ❌ Bad - does too much
class OrderService {
    public function createOrder() {}
    public function sendEmail() {}
    public function syncToWooCommerce() {}
    public function calculateTax() {}
}

// ✅ Good - focused responsibilities
class OrderService {
    public function __construct(
        private NotificationService $notifications,
        private SyncService $sync,
        private TaxCalculator $tax
    ) {}
    
    public function createOrder(CreateOrderCommand $command): Order {
        $order = Order::create($command);
        $this->notifications->notifyOrderCreated($order);
        $this->sync->syncOrder($order);
        return $order;
    }
}
```

#### Dependency Inversion
Depend on abstractions, not concretions.
```php
// ✅ Good - depend on interface
class CartService {
    public function __construct(
        private CartRepository $repository  // Interface, not implementation
    ) {}
}

// Container binds interface to implementation
$container->bind(CartRepository::class, WpDbCartRepository::class);
```

### 5.3 Naming Conventions

#### Classes
- **Entities:** Noun (singular) - `Order`, `Customer`, `Product`
- **Services:** Noun + "Service" - `CartService`, `PaymentService`
- **Repositories:** Noun + "Repository" - `OrderRepository`
- **Controllers:** Noun + "Controller" - `WebhookController`
- **Actions:** Verb + Noun + "Action" - `AddToCartAction`
- **Commands:** Verb + Noun + "Command" - `CreateOrderCommand`
- **Queries:** Get + Noun + "Query" - `GetOrderQuery`
- **Handlers:** Noun + "Handler" - `SyncJobHandler`
- **Events:** Noun + Past Tense - `OrderCreated`, `PaymentCompleted`

#### Methods
- **Query methods:** `get*()`, `find*()`, `has*()`, `is*()`
- **Command methods:** `create*()`, `update*()`, `delete*()`, `process*()`
- **Boolean methods:** `is*()`, `has*()`, `can*()`, `should*()`

#### Files
- PSR-4: `ClassName.php` (PascalCase)
- No prefixes needed (namespace provides context)

### 5.4 Code Organization Patterns

#### Repository Pattern
```php
interface CartRepository {
    public function find(string $id): ?Cart;
    public function save(Cart $cart): void;
    public function delete(Cart $cart): void;
}
```

#### Command Pattern
```php
class CreateOrderCommand {
    public function __construct(
        public readonly string $customerId,
        public readonly array $items,
        public readonly string $paymentMethod
    ) {}
}

class CreateOrderHandler {
    public function handle(CreateOrderCommand $command): Order {
        // Create order logic
    }
}
```

#### Factory Pattern
```php
class OrderFactory {
    public function createFromCart(Cart $cart): Order {
        // Build order from cart
    }
}
```

---

## 6. Testing Strategy

### 6.1 Test Structure
```
tests/
├── Unit/                          # Fast, isolated tests
│   ├── Domain/
│   ├── Application/
│   └── Support/
├── Integration/                   # Tests with dependencies
│   ├── Infrastructure/
│   ├── Features/
│   └── Presentation/
└── Functional/                    # End-to-end tests
    └── Workflows/
```

### 6.2 Test Coverage Goals
- **Domain Layer:** 90%+ coverage (critical business logic)
- **Application Layer:** 80%+ coverage (use cases)
- **Infrastructure:** 70%+ coverage (external integrations)
- **Presentation:** 60%+ coverage (UI logic)

### 6.3 Testing Tools
- PHPUnit for all tests
- Brain Monkey for WordPress function mocking
- Mockery for object mocking
- PHPStan for static analysis (level 5+)

---

## 7. Performance Considerations

### 7.1 Autoloading Optimization
```bash
# After migration, optimize autoloader
composer dump-autoload --optimize --classmap-authoritative
```

### 7.2 Dependency Injection
- Use constructor injection for required dependencies
- Use service locator pattern sparingly (only in bootstrapping)
- Lazy-load heavy services

### 7.3 Database Optimization
- Use repository pattern for optimized queries
- Implement caching layer in repositories
- Use WordPress transients for temporary data

---

## 8. Migration Checklist

### Pre-Migration
- [ ] Backup database
- [ ] Create feature branch
- [ ] Document current functionality
- [ ] Ensure test coverage for critical paths
- [ ] Set up staging environment

### During Migration
- [ ] Follow phase-by-phase plan
- [ ] Keep legacy wrappers for BC
- [ ] Update tests incrementally
- [ ] Run linting/static analysis regularly
- [ ] Document as you go

### Post-Migration
- [ ] Full regression testing
- [ ] Performance benchmarking
- [ ] Update all documentation
- [ ] Code review by team
- [ ] Plan deprecation timeline
- [ ] Release beta version

---

## 9. Risk Mitigation

### 9.1 High-Risk Areas
1. **Payment Processing** - Test thoroughly, maintain BC
2. **Order Sync** - Data integrity critical
3. **Webhook Handling** - External dependencies
4. **Cart Management** - User experience impact

### 9.2 Rollback Plan
- Keep old code alongside new during transition
- Feature flags to toggle between old/new implementations
- Database migrations must be reversible
- Comprehensive testing in staging first

### 9.3 Backward Compatibility
- Maintain legacy class wrappers for at least 2 versions
- Deprecation warnings in WP_DEBUG mode
- Clear migration guides for developers
- Version breaking changes properly

---

## 10. Timeline & Resources

### Estimated Timeline: 12 weeks
- **Weeks 1-2:** Foundation & Planning
- **Weeks 2-3:** Core Infrastructure
- **Weeks 4-5:** Domain Layer
- **Weeks 5-6:** Infrastructure Layer
- **Weeks 6-7:** Application Services
- **Weeks 7-8:** Presentation Layer
- **Weeks 8-9:** Feature Modules
- **Weeks 9-10:** Support & Utilities
- **Weeks 10:** Service Providers
- **Weeks 11-12:** Testing & Documentation

### Resources Needed
- 1-2 Senior PHP Developers (full-time)
- 1 QA Engineer (part-time)
- Code review from WordPress/WooCommerce expert
- Staging environment for testing
- Time for thorough testing before production

---

## 11. Success Metrics

### Code Quality
- [ ] 100% PSR-4 compliance
- [ ] Zero legacy autoloader usage
- [ ] PHPStan level 5+ with no errors
- [ ] PHPCS compliance (WordPress-Extra standard)

### Architecture
- [ ] Clear separation of concerns
- [ ] No circular dependencies
- [ ] All dependencies injected through container
- [ ] Feature modules are independent

### Testing
- [ ] 80%+ overall test coverage
- [ ] All critical paths have integration tests
- [ ] CI/CD pipeline passes

### Documentation
- [ ] Architecture documented
- [ ] All public APIs documented
- [ ] Migration guides complete
- [ ] Developer onboarding guide

### Performance
- [ ] No regression in page load times
- [ ] Autoloader optimized
- [ ] Memory usage same or better

---

## 12. Future Enhancements

After successful PSR-4 migration:

### 12.1 Advanced Patterns
- [ ] Event Sourcing for audit trail
- [ ] CQRS for complex operations
- [ ] Message bus for async processing
- [ ] Domain events for loose coupling

### 12.2 Package Extraction
Consider extracting modules into separate packages:
- WhatsApp API Client
- Payment Gateway Abstractions
- Conversation State Machine
- Product Sync Engine

### 12.3 API Improvements
- GraphQL API alongside REST
- Webhook system with retry logic
- API versioning strategy

### 12.4 Developer Experience
- IDE autocomplete improvements
- Better error messages
- Development toolkit/CLI
- Plugin boilerplate generator

---

## 13. References & Resources

### PSR Standards
- PSR-4: Autoloading Standard
- PSR-11: Container Interface
- PSR-12: Extended Coding Style

### Architecture Patterns
- Domain-Driven Design (Eric Evans)
- Clean Architecture (Robert C. Martin)
- Hexagonal Architecture (Alistair Cockburn)

### WordPress Standards
- WordPress Coding Standards
- WordPress Plugin Handbook
- WooCommerce Extension Guidelines

---

## 14. Conclusion

This migration plan transforms the WhatsApp Commerce Hub plugin from a hybrid legacy/modern architecture to a fully PSR-4 compliant, Domain-Driven Design structure. The phased approach ensures stability while progressively improving code quality, maintainability, and developer experience.

**Key Benefits:**
✅ Single, standard autoloading system (Composer)
✅ Clear architectural boundaries and responsibilities
✅ Improved testability through dependency injection
✅ Better code organization for team collaboration
✅ Easier onboarding for new developers
✅ Foundation for future scaling and features

**Next Steps:**
1. Review and approve this plan with the team
2. Set up tracking system (MIGRATION_STATUS.md)
3. Create feature branch for migration work
4. Begin Phase 1: Foundation & Planning

---

**Document Version:** 1.0
**Last Updated:** 2026-01-10
**Status:** Draft - Pending Review
