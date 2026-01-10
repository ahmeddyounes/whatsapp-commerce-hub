# WhatsApp Commerce Hub - Implementation Phases

This document breaks down the PSR-4 migration plan into concrete, executable phases with specific tasks, dependencies, and deliverables.

---

## Phase Overview

| Phase | Duration | Focus Area | Complexity | Risk |
|-------|----------|------------|------------|------|
| Phase 1 | 1-2 weeks | Foundation & Planning | Low | Low |
| Phase 2 | 1 week | Core Infrastructure | Medium | Medium |
| Phase 3 | 2 weeks | Domain Layer | High | Medium |
| Phase 4 | 1 week | Infrastructure Layer | Medium | Medium |
| Phase 5 | 1 week | Application Services | Medium | Low |
| Phase 6 | 1 week | Presentation Layer | Low | Low |
| Phase 7 | 1 week | Feature Modules | Medium | Medium |
| Phase 8 | 1 week | Support & Utilities | Low | Low |
| Phase 9 | 1 week | Service Providers | Medium | Low |
| Phase 10 | 2 weeks | Testing & Documentation | Medium | Low |
| Phase 11 | Ongoing | Deprecation & Cleanup | Low | Low |

**Total Timeline:** 12 weeks

---

## 📋 Phase 1: Foundation & Planning (Week 1-2)

**Status:** 🟢 Ready to Start
**Goal:** Establish foundation without breaking existing functionality
**Risk Level:** Low

### Prerequisites
- ✅ Current codebase analyzed
- ✅ Architecture plan approved
- [ ] Team aligned on approach
- [ ] Staging environment ready
- [ ] Backup procedures in place

### Tasks

#### 1.1 Project Setup
- [ ] **Create migration branch** (`feature/psr4-migration`)
  - Branch from main/develop
  - Set up protected branch rules
  - Configure CI/CD for branch
  
- [ ] **Set up tracking system**
  - Create `MIGRATION_STATUS.md`
  - Create `PLAN_PHASES.md` (this file)
  - Create `PLAN_TODO.md`
  - Set up project board/tickets

- [ ] **Document current state**
  - List all 72 legacy classes with file paths
  - Map dependencies between classes
  - Identify critical paths (payment, checkout, sync)
  - Document breaking change candidates

#### 1.2 Create New Directory Structure
```bash
# Create new directories
mkdir -p includes/Core
mkdir -p includes/Domain/{Catalog,Cart,Order,Customer,Payment,Conversation}
mkdir -p includes/Application/{Commands,Queries,Handlers,Services}
mkdir -p includes/Infrastructure/{Api,Database,Queue,Security,Persistence}
mkdir -p includes/Presentation/{Admin,Actions,Templates}
mkdir -p includes/Features/{AbandonedCart,Reengagement,Broadcasts,Analytics,Notifications,Payments}
mkdir -p includes/Support/{Utilities,AI,Messaging,Validation}
```

- [ ] **Create directory structure**
  - Execute directory creation script
  - Add `.gitkeep` files to empty directories
  - Update `.gitignore` if needed
  
- [ ] **Create README files**
  - Add `README.md` to each major directory
  - Document purpose and responsibilities
  - Include namespace examples

#### 1.3 Deprecation System
- [ ] **Create deprecation utilities**
  ```php
  includes/Core/Deprecation.php
  includes/Core/CompatibilityLayer.php
  includes/Core/LegacyClassMapper.php
  ```

- [ ] **Implement logging**
  - Log deprecated class usage
  - Create admin notice for developers
  - Add WP_DEBUG mode warnings

#### 1.4 Testing Infrastructure
- [ ] **Baseline test suite**
  - Run existing tests and document results
  - Create snapshots of critical functionality
  - Set up test coverage reporting
  
- [ ] **Create integration tests**
  - Test critical workflows end-to-end
  - Payment processing flow
  - Order creation flow
  - Product sync flow
  - Cart operations

#### 1.5 Documentation
- [ ] **Update development docs**
  - Document new architecture
  - Create contribution guidelines for new structure
  - Update coding standards

### Deliverables
- ✅ `PLAN.md` - Comprehensive migration plan
- ✅ `PLAN_PHASES.md` - This phase breakdown
- ✅ `PLAN_TODO.md` - Current progress tracker
- [ ] `MIGRATION_STATUS.md` - Detailed class migration tracking
- [ ] Directory structure in place with READMEs
- [ ] Deprecation system ready
- [ ] Baseline test results documented
- [ ] Development environment configured

### Success Criteria
- All tracking documents in place
- Directory structure created
- No existing functionality broken
- Tests passing with same results as baseline

---

## 🏗️ Phase 2: Core Infrastructure Migration (Week 2-3)

**Status:** 🔴 Not Started
**Goal:** Migrate foundational classes that everything depends on
**Risk Level:** Medium

### Prerequisites
- ✅ Phase 1 complete
- [ ] Deprecation system tested
- [ ] Team trained on new patterns
- [ ] Migration scripts ready

### Tasks

#### 2.1 Error Handler & Logging (Priority 1)
**Order matters:** Logger first, then Error Handler

- [ ] **Migrate Logger**
  - [ ] Create `includes/Core/Logger.php`
  - [ ] Move functionality from `class-wch-logger.php`
  - [ ] Update to use PSR-3 LoggerInterface patterns
  - [ ] Add structured logging support
  - [ ] Create wrapper in old `class-wch-logger.php`
  - [ ] Update service provider
  - [ ] Test all logging calls

- [ ] **Migrate Error Handler**
  - [ ] Create `includes/Core/ErrorHandler.php`
  - [ ] Move functionality from `class-wch-error-handler.php`
  - [ ] Integrate with new Logger
  - [ ] Create wrapper in old class
  - [ ] Test error handling flows

**Files:**
```
includes/Core/Logger.php
includes/Core/ErrorHandler.php
includes/Core/LogLevel.php (if needed)
```

#### 2.2 Encryption & Security (Priority 2)
- [ ] **Migrate Encryption**
  - [ ] Create `includes/Infrastructure/Security/Encryption.php`
  - [ ] Move from `class-wch-encryption.php`
  - [ ] Add key rotation support
  - [ ] Create wrapper
  - [ ] Update all encryption calls
  - [ ] Security audit

**Files:**
```
includes/Infrastructure/Security/Encryption.php
includes/Infrastructure/Security/EncryptionException.php
```

#### 2.3 Database Manager (Priority 3)
- [ ] **Migrate Database Manager**
  - [ ] Create `includes/Infrastructure/Database/DatabaseManager.php`
  - [ ] Move from `class-wch-database-manager.php`
  - [ ] Extract migrations to separate classes
  - [ ] Create migration interface
  - [ ] Move migrations to `Infrastructure/Database/Migrations/`
  - [ ] Create wrapper
  - [ ] Test migration system

**Files:**
```
includes/Infrastructure/Database/DatabaseManager.php
includes/Infrastructure/Database/MigrationInterface.php
includes/Infrastructure/Database/Migrations/Migration_*.php
```

#### 2.4 Settings & Configuration (Priority 4)
- [ ] **Consolidate Settings**
  - [ ] Merge `class-wch-settings.php` with `Services/SettingsService.php`
  - [ ] Create `includes/Infrastructure/Configuration/SettingsManager.php`
  - [ ] Move to interface-based approach
  - [ ] Add settings validation
  - [ ] Create wrapper
  - [ ] Update all settings calls

**Files:**
```
includes/Infrastructure/Configuration/SettingsManager.php
includes/Infrastructure/Configuration/SettingsInterface.php
includes/Infrastructure/Configuration/SettingsValidator.php
```

#### 2.5 Update Service Providers
- [ ] **Update CoreServiceProvider**
  - Register new Logger
  - Register new ErrorHandler
  - Register new Encryption
  - Register new DatabaseManager
  - Register new SettingsManager

- [ ] **Test integration**
  - All services resolve correctly
  - No circular dependencies
  - Backward compatibility works

### Deliverables
- New Core infrastructure classes
- Updated service providers
- Legacy wrappers with deprecation notices
- Unit tests for new classes
- Integration tests passing

### Success Criteria
- All core services migrated
- Tests passing
- No runtime errors
- Deprecation warnings working
- Performance same or better

---

## 🎯 Phase 3: Domain Layer Migration (Week 4-5)

**Status:** 🔴 Not Started
**Goal:** Build out domain models and services
**Risk Level:** High (business logic)

### Prerequisites
- ✅ Phase 2 complete
- [ ] Core infrastructure stable
- [ ] Domain models designed
- [ ] Repository interfaces defined

### Tasks

#### 3.1 Cart Domain
- [ ] **Design Cart aggregate**
  - [ ] Create `Domain/Cart/Cart.php` (entity)
  - [ ] Create `Domain/Cart/CartItem.php` (value object)
  - [ ] Create `Domain/Cart/CartId.php` (value object)
  - [ ] Create `Domain/Cart/CartRepository.php` (interface)
  - [ ] Create `Domain/Cart/CartService.php` (domain service)
  - [ ] Create `Domain/Cart/CartException.php`

- [ ] **Merge existing implementations**
  - [ ] Consolidate `class-wch-cart-manager.php`
  - [ ] Merge with `Services/CartService.php`
  - [ ] Extract business rules
  - [ ] Create cart validation rules

- [ ] **Create repository implementation**
  - [ ] `Infrastructure/Database/Repositories/WpDbCartRepository.php`
  - [ ] Implement interface
  - [ ] Add caching layer

- [ ] **Update and create tests**
  - Unit tests for Cart entity
  - Unit tests for CartService
  - Integration tests for repository

**Files:**
```
includes/Domain/Cart/
  ├── Cart.php
  ├── CartItem.php
  ├── CartId.php
  ├── CartRepository.php
  ├── CartService.php
  └── CartException.php
includes/Infrastructure/Database/Repositories/WpDbCartRepository.php
```

#### 3.2 Order Domain
- [ ] **Design Order aggregate**
  - [ ] Create `Domain/Order/Order.php`
  - [ ] Create `Domain/Order/OrderLine.php`
  - [ ] Create `Domain/Order/OrderId.php`
  - [ ] Create `Domain/Order/OrderStatus.php` (enum/value object)
  - [ ] Create `Domain/Order/OrderRepository.php` (interface)
  - [ ] Create `Domain/Order/OrderService.php`

- [ ] **Merge order sync**
  - [ ] Consolidate `class-wch-order-sync-service.php`
  - [ ] Merge with `Services/OrderSyncService.php`
  - [ ] Extract sync logic to Application layer

- [ ] **Create repository**
  - [ ] `Infrastructure/Database/Repositories/WpDbOrderRepository.php`

**Files:**
```
includes/Domain/Order/
  ├── Order.php
  ├── OrderLine.php
  ├── OrderId.php
  ├── OrderStatus.php
  ├── OrderRepository.php
  └── OrderService.php
```

#### 3.3 Product/Catalog Domain
- [ ] **Design Catalog aggregate**
  - [ ] Create `Domain/Catalog/Product.php`
  - [ ] Create `Domain/Catalog/ProductId.php`
  - [ ] Create `Domain/Catalog/Category.php`
  - [ ] Create `Domain/Catalog/Price.php` (value object)
  - [ ] Create `Domain/Catalog/ProductRepository.php`
  - [ ] Create `Domain/Catalog/CatalogService.php`

- [ ] **Migrate catalog browser**
  - [ ] Move `class-wch-catalog-browser.php`
  - [ ] Refactor to use domain models

- [ ] **Merge product sync**
  - [ ] Consolidate `class-wch-product-sync-service.php`
  - [ ] Merge with `Services/ProductSyncService.php`
  - [ ] Move to Application layer

**Files:**
```
includes/Domain/Catalog/
  ├── Product.php
  ├── ProductId.php
  ├── Category.php
  ├── Price.php
  ├── ProductRepository.php
  └── CatalogService.php
```

#### 3.4 Customer Domain
- [ ] **Design Customer aggregate**
  - [ ] Create `Domain/Customer/Customer.php`
  - [ ] Migrate `class-wch-customer-profile.php` to `CustomerProfile.php`
  - [ ] Create `Domain/Customer/CustomerId.php`
  - [ ] Create `Domain/Customer/CustomerRepository.php`
  - [ ] Merge with existing `Services/CustomerService.php`

**Files:**
```
includes/Domain/Customer/
  ├── Customer.php
  ├── CustomerProfile.php
  ├── CustomerId.php
  ├── CustomerRepository.php
  └── CustomerService.php
```

#### 3.5 Payment Domain
- [ ] **Design Payment aggregate**
  - [ ] Create `Domain/Payment/Payment.php`
  - [ ] Create `Domain/Payment/PaymentMethod.php`
  - [ ] Create `Domain/Payment/Refund.php`
  - [ ] Create `Domain/Payment/PaymentRepository.php`
  - [ ] Create `Domain/Payment/PaymentService.php`

- [ ] **Consolidate payment classes**
  - [ ] Review `payments/` directory
  - [ ] Separate domain from infrastructure
  - [ ] Move gateways to Features/Payments

**Files:**
```
includes/Domain/Payment/
  ├── Payment.php
  ├── PaymentMethod.php
  ├── Refund.php
  ├── PaymentRepository.php
  └── PaymentService.php
```

#### 3.6 Conversation Domain
- [ ] **Design Conversation aggregate**
  - [ ] Migrate `class-wch-conversation-context.php` to `Context.php`
  - [ ] Migrate `class-wch-conversation-fsm.php` to `StateMachine.php`
  - [ ] Migrate `class-wch-intent.php` to `Intent.php`
  - [ ] Create `Domain/Conversation/Conversation.php`
  - [ ] Create `Domain/Conversation/Message.php`
  - [ ] Create `Domain/Conversation/ConversationRepository.php`

**Files:**
```
includes/Domain/Conversation/
  ├── Conversation.php
  ├── Message.php
  ├── Context.php
  ├── Intent.php
  ├── StateMachine.php
  └── ConversationRepository.php
```

#### 3.7 Update Service Providers
- [ ] **Create DomainServiceProvider**
  - Register all domain services
  - Register repositories
  - Bind interfaces to implementations

### Deliverables
- Complete domain model for all aggregates
- Repository interfaces and implementations
- Domain services
- Legacy wrappers
- Comprehensive unit tests
- Integration tests for repositories

### Success Criteria
- All domain logic migrated
- Business rules preserved
- Tests passing (90%+ coverage)
- Performance benchmarks met
- No data loss in repositories

---

## 🏢 Phase 4: Infrastructure Layer (Week 5-6)

**Status:** 🔴 Not Started
**Goal:** Consolidate all infrastructure concerns
**Risk Level:** Medium

### Prerequisites
- ✅ Phase 3 complete
- [ ] Domain layer stable
- [ ] Integration points identified

### Tasks

#### 4.1 API Layer
- [ ] **REST API Migration**
  - [ ] Create `Infrastructure/Api/Rest/RestApi.php`
  - [ ] Migrate `class-wch-rest-api.php`
  - [ ] Create `Infrastructure/Api/Rest/RestController.php`
  - [ ] Migrate `class-wch-rest-controller.php`
  - [ ] Create base controller for common functionality

- [ ] **Webhook System**
  - [ ] Create `Infrastructure/Api/Rest/Controllers/WebhookController.php`
  - [ ] Migrate `class-wch-webhook-handler.php`
  - [ ] Add webhook validation
  - [ ] Add retry logic

- [ ] **Move existing controllers**
  - [ ] Move from `Controllers/` to `Infrastructure/Api/Rest/Controllers/`
  - [ ] `ConversationsController.php`
  - [ ] `AnalyticsController.php`
  - [ ] Update namespaces

**Files:**
```
includes/Infrastructure/Api/
  ├── Rest/
  │   ├── RestApi.php
  │   ├── RestController.php
  │   └── Controllers/
  │       ├── WebhookController.php
  │       ├── ConversationsController.php
  │       └── AnalyticsController.php
  └── Clients/  (move from existing Clients/)
      ├── WhatsAppApiClient.php
      ├── OpenAIClient.php
      └── HttpClient.php
```

#### 4.2 Queue System
- [ ] **Consolidate queue classes**
  - [ ] Merge `class-wch-queue.php` with `Queue/QueueManager.php`
  - [ ] Create `Infrastructure/Queue/QueueManager.php`
  - [ ] Migrate `class-wch-job-dispatcher.php` to `JobDispatcher.php`

- [ ] **Migrate job handlers**
  - [ ] Create `Infrastructure/Queue/Handlers/`
  - [ ] Move `class-wch-sync-job-handler.php` to `SyncJobHandler.php`
  - [ ] Move `class-wch-broadcast-job-handler.php` to `BroadcastJobHandler.php`
  - [ ] Move `class-wch-cart-cleanup-handler.php` to `CartCleanupHandler.php`

**Files:**
```
includes/Infrastructure/Queue/
  ├── QueueManager.php
  ├── JobDispatcher.php
  ├── JobInterface.php
  └── Handlers/
      ├── SyncJobHandler.php
      ├── BroadcastJobHandler.php
      └── CartCleanupHandler.php
```

#### 4.3 Database Layer
- [ ] **Consolidate repositories**
  - [ ] Move `Repositories/` to `Infrastructure/Database/Repositories/`
  - [ ] Ensure all implement domain interfaces
  - [ ] Add caching layer

- [ ] **Verify migrations**
  - [ ] All migrations in `Infrastructure/Database/Migrations/`
  - [ ] Test migration rollback
  - [ ] Version control for schema

#### 4.4 Update Service Providers
- [ ] **Create InfrastructureServiceProvider**
  - Register API clients
  - Register REST controllers
  - Register queue system
  - Register repositories

### Deliverables
- Consolidated infrastructure layer
- API endpoints working with new structure
- Queue system migrated
- All tests passing

### Success Criteria
- API calls work correctly
- Webhooks processed successfully
- Queue jobs execute properly
- No infrastructure failures

---

## 🎮 Phase 5: Application Services (Week 6-7)

**Status:** 🔴 Not Started
**Goal:** Create clean application service layer
**Risk Level:** Medium

### Prerequisites
- ✅ Phase 4 complete
- [ ] Domain and infrastructure layers stable

### Tasks

#### 5.1 Command/Query Pattern (Optional but Recommended)
- [ ] **Set up CQRS infrastructure**
  - [ ] Create command bus
  - [ ] Create query bus
  - [ ] Create handler registry

- [ ] **Create commands**
  - [ ] `Application/Commands/CreateOrderCommand.php`
  - [ ] `Application/Commands/AddToCartCommand.php`
  - [ ] `Application/Commands/ProcessPaymentCommand.php`
  - [ ] `Application/Commands/SyncProductCommand.php`

- [ ] **Create queries**
  - [ ] `Application/Queries/GetCartQuery.php`
  - [ ] `Application/Queries/GetProductQuery.php`
  - [ ] `Application/Queries/GetOrderQuery.php`

- [ ] **Create handlers**
  - [ ] `Application/Handlers/CommandHandlers/`
  - [ ] `Application/Handlers/QueryHandlers/`

**Files:**
```
includes/Application/
  ├── Commands/
  ├── Queries/
  └── Handlers/
      ├── CommandHandlers/
      └── QueryHandlers/
```

#### 5.2 Application Services
- [ ] **Checkout Service**
  - [ ] Merge checkout logic from domain
  - [ ] Create `Application/Services/CheckoutService.php`
  - [ ] Orchestrate order creation workflow

- [ ] **Sync Services**
  - [ ] Create `Application/Services/ProductSyncService.php`
  - [ ] Create `Application/Services/OrderSyncService.php`
  - [ ] Create `Application/Services/InventorySyncService.php`
  - [ ] Migrate `class-wch-inventory-sync-handler.php`

**Files:**
```
includes/Application/Services/
  ├── CheckoutService.php
  ├── ProductSyncService.php
  ├── OrderSyncService.php
  └── InventorySyncService.php
```

#### 5.3 Update Service Providers
- [ ] **Create ApplicationServiceProvider**
  - Register application services
  - Register command/query handlers
  - Configure service dependencies

### Deliverables
- Application service layer complete
- CQRS pattern implemented (if chosen)
- Workflow orchestration services
- Tests for all services

### Success Criteria
- Complex workflows work correctly
- Services properly orchestrate domain logic
- Clear separation from domain layer
- Tests passing

---

## 🎨 Phase 6: Presentation Layer (Week 7-8)

**Status:** 🔴 Not Started
**Goal:** Organize all user-facing components
**Risk Level:** Low

### Prerequisites
- ✅ Phase 5 complete
- [ ] Application services working

### Tasks

#### 6.1 Admin Pages
- [ ] **Consolidate admin pages**
  - [ ] Merge `class-wch-admin-analytics.php` with `Admin/AnalyticsPage.php`
  - [ ] Merge `class-wch-admin-catalog-sync.php` with `Admin/CatalogSyncPage.php`
  - [ ] Merge `class-wch-admin-inbox.php` with `Admin/InboxPage.php`
  - [ ] Merge `class-wch-admin-jobs.php` with `Admin/JobsPage.php`
  - [ ] Merge `class-wch-admin-logs.php` with `Admin/LogsPage.php`
  - [ ] Merge `class-wch-admin-templates.php` with `Admin/TemplatesPage.php`
  - [ ] Merge `class-wch-admin-broadcasts.php` with `Admin/BroadcastsPage.php`

- [ ] **Move to Presentation layer**
  - [ ] Move `Admin/` to `Presentation/Admin/Pages/`
  - [ ] Update namespaces
  - [ ] Create base page class

**Files:**
```
includes/Presentation/Admin/
  ├── Pages/
  │   ├── BasePage.php
  │   ├── DashboardPage.php
  │   ├── SettingsPage.php
  │   ├── AnalyticsPage.php
  │   ├── InboxPage.php
  │   ├── JobsPage.php
  │   ├── LogsPage.php
  │   ├── TemplatesPage.php
  │   ├── CatalogSyncPage.php
  │   └── BroadcastsPage.php
  ├── Widgets/
  │   └── DashboardWidgets.php
  └── Settings/
```

#### 6.2 Dashboard Widgets
- [ ] **Migrate dashboard widgets**
  - [ ] Move `class-wch-dashboard-widgets.php`
  - [ ] Merge with `Admin/DashboardWidgets.php`
  - [ ] Move to `Presentation/Admin/Widgets/`

#### 6.3 Actions
- [ ] **Consolidate action classes**
  - [ ] Review all `class-wch-action-*.php` files
  - [ ] Merge with existing `Actions/` directory
  - [ ] Move to `Presentation/Actions/`
  - [ ] Keep `AbstractAction.php` as base

**Files:**
```
includes/Presentation/Actions/
  ├── AbstractAction.php
  ├── ActionRegistry.php
  ├── AddToCartAction.php
  ├── ShowCartAction.php
  ├── ShowProductAction.php
  ├── ShowCategoryAction.php
  ├── ShowMainMenuAction.php
  ├── RequestAddressAction.php
  ├── ConfirmOrderAction.php
  └── ProcessPaymentAction.php
```

#### 6.4 Templates
- [ ] **Migrate template manager**
  - [ ] Move `class-wch-template-manager.php`
  - [ ] Create `Presentation/Templates/TemplateManager.php`
  - [ ] Create `Presentation/Templates/TemplateRenderer.php`

#### 6.5 Update Service Providers
- [ ] **Create PresentationServiceProvider**
  - Register admin pages
  - Register actions
  - Register template manager

### Deliverables
- Organized presentation layer
- Admin pages consolidated
- Actions consolidated
- Template system migrated

### Success Criteria
- Admin dashboard works correctly
- All actions execute properly
- Templates render correctly
- No UI/UX regressions

---

## 🎁 Phase 7: Feature Modules (Week 8-9)

**Status:** 🔴 Not Started
**Goal:** Encapsulate feature-specific logic
**Risk Level:** Medium

### Prerequisites
- ✅ Phase 6 complete
- [ ] Core functionality stable

### Tasks

#### 7.1 Abandoned Cart Feature
- [ ] **Consolidate abandoned cart**
  - [ ] Move `class-wch-abandoned-cart-recovery.php`
  - [ ] Move `class-wch-abandoned-cart-handler.php`
  - [ ] Move `class-wch-cart-cleanup-handler.php` (already in Queue)
  - [ ] Create `Features/AbandonedCart/RecoveryService.php`
  - [ ] Create `Features/AbandonedCart/CartHandler.php`
  - [ ] Create `Features/AbandonedCart/ReminderScheduler.php`

**Files:**
```
includes/Features/AbandonedCart/
  ├── RecoveryService.php
  ├── CartHandler.php
  ├── ReminderScheduler.php
  └── RecoveryRepository.php
```

#### 7.2 Reengagement Feature
- [ ] **Migrate reengagement**
  - [ ] Move `class-wch-reengagement-service.php`
  - [ ] Merge with `Services/Reengagement/`
  - [ ] Move to `Features/Reengagement/`

**Files:**
```
includes/Features/Reengagement/
  ├── ReengagementService.php
  ├── CampaignManager.php
  └── CustomerSegmentation.php
```

#### 7.3 Broadcasts Feature
- [ ] **Consolidate broadcasts**
  - [ ] Merge broadcast-related classes
  - [ ] Move `Services/Broadcasts/` to `Features/Broadcasts/`
  - [ ] Merge `class-wch-broadcast-job-handler.php` (already in Queue)

**Files:**
```
includes/Features/Broadcasts/
  ├── BroadcastService.php
  ├── BroadcastScheduler.php
  └── BroadcastRepository.php
```

#### 7.4 Analytics Feature
- [ ] **Migrate analytics**
  - [ ] Move `class-wch-analytics-data.php`
  - [ ] Create `Features/Analytics/AnalyticsService.php`
  - [ ] Create `Features/Analytics/MetricsCollector.php`

**Files:**
```
includes/Features/Analytics/
  ├── AnalyticsService.php
  ├── AnalyticsData.php
  └── MetricsCollector.php
```

#### 7.5 Notifications Feature
- [ ] **Migrate notifications**
  - [ ] Move `class-wch-order-notifications.php`
  - [ ] Merge with `Services/NotificationService.php`
  - [ ] Create unified notification system

**Files:**
```
includes/Features/Notifications/
  ├── NotificationService.php
  ├── OrderNotifications.php
  └── NotificationTemplates.php
```

#### 7.6 Payments Feature
- [ ] **Consolidate payment system**
  - [ ] Move `payments/` to `Features/Payments/`
  - [ ] Move `class-wch-payment-manager.php`
  - [ ] Merge with `payments/PaymentGatewayRegistry.php`
  - [ ] Move `class-wch-refund-handler.php`
  - [ ] Move `class-wch-payment-webhook-handler.php`

**Files:**
```
includes/Features/Payments/
  ├── PaymentGatewayRegistry.php
  ├── RefundService.php
  ├── WebhookHandler.php
  └── Gateways/
      ├── AbstractGateway.php
      ├── StripeGateway.php
      ├── RazorpayGateway.php
      ├── PixGateway.php
      ├── WhatsAppPayGateway.php
      └── CodGateway.php
```

#### 7.7 Update Service Providers
- [ ] **Create FeatureServiceProvider**
  - Register all feature modules
  - Configure feature dependencies
  - Set up feature flags (if needed)

### Deliverables
- All features organized as modules
- Feature-specific logic encapsulated
- Clean boundaries between features

### Success Criteria
- All features work independently
- No cross-feature dependencies
- Tests passing for all features
- Feature flags working (if implemented)

---

## 🛠️ Phase 8: Support & Utilities (Week 9-10)

**Status:** 🔴 Not Started
**Goal:** Organize shared utilities and helpers
**Risk Level:** Low

### Prerequisites
- ✅ Phase 7 complete

### Tasks

#### 8.1 AI & NLP Support
- [ ] **Consolidate AI services**
  - [ ] Merge `class-wch-intent-classifier.php` with `Services/IntentClassifierService.php`
  - [ ] Move to `Support/AI/IntentClassifier.php`
  - [ ] Merge `class-wch-response-parser.php` with `Services/ResponseParserService.php`
  - [ ] Move to `Support/AI/ResponseParser.php`
  - [ ] Move `class-wch-context-manager.php` to `Support/AI/ConversationContext.php`

**Files:**
```
includes/Support/AI/
  ├── IntentClassifier.php
  ├── ResponseParser.php
  └── ConversationContext.php
```

#### 8.2 Messaging Support
- [ ] **Consolidate messaging**
  - [ ] Merge `class-wch-message-builder.php` with `Services/MessageBuilderService.php`
  - [ ] Move to `Support/Messaging/MessageBuilder.php`
  - [ ] Create `Support/Messaging/MessageFormatter.php`

**Files:**
```
includes/Support/Messaging/
  ├── MessageBuilder.php
  └── MessageFormatter.php
```

#### 8.3 Utilities
- [ ] **Migrate utilities**
  - [ ] Move `class-wch-address-parser.php` to `Support/Utilities/AddressParser.php`
  - [ ] Create additional utilities as needed
  - [ ] Extract common helpers

**Files:**
```
includes/Support/Utilities/
  ├── AddressParser.php
  ├── DateFormatter.php
  └── StringHelper.php
```

#### 8.4 Value Objects
- [ ] **Consolidate value objects**
  - [ ] Move `class-wch-parsed-response.php` to `ValueObjects/ParsedResponse.php`
  - [ ] Move `class-wch-action-result.php` to `ValueObjects/ActionResult.php`
  - [ ] Review and organize existing `ValueObjects/`

### Deliverables
- Organized support utilities
- AI services consolidated
- Messaging utilities consolidated
- Value objects organized

### Success Criteria
- All utilities accessible
- No code duplication
- Clear responsibility boundaries

---

## 📦 Phase 9: Service Provider Reorganization (Week 10)

**Status:** 🔴 Not Started
**Goal:** Simplify service provider structure
**Risk Level:** Medium

### Prerequisites
- ✅ Phase 8 complete
- [ ] All classes migrated

### Tasks

#### 9.1 Consolidate Providers
- [ ] **Merge related providers**
  - [ ] Create new `DomainServiceProvider`
    - Merge BusinessServiceProvider functionality
    - Register all domain services
  
  - [ ] Create new `ApplicationServiceProvider`
    - Merge ProductSyncServiceProvider
    - Merge CheckoutServiceProvider
    - Register all application services
  
  - [ ] Create new `InfrastructureServiceProvider`
    - Merge RepositoryServiceProvider
    - Merge QueueServiceProvider
    - Merge ApiClientServiceProvider
    - Register all infrastructure
  
  - [ ] Create new `PresentationServiceProvider`
    - Merge AdminServiceProvider
    - Merge ControllerServiceProvider
    - Merge ActionServiceProvider
    - Register all presentation components
  
  - [ ] Create new `FeatureServiceProvider`
    - Merge ReengagementServiceProvider
    - Merge NotificationServiceProvider
    - Merge PaymentServiceProvider
    - Merge BroadcastsServiceProvider
    - Merge AdminSettingsServiceProvider
    - Register all features

  - [ ] Keep separate:
    - CoreServiceProvider
    - ResilienceServiceProvider
    - SecurityServiceProvider
    - SagaServiceProvider
    - EventServiceProvider
    - MonitoringServiceProvider

#### 9.2 Update Bootstrap
- [ ] **Update main plugin file**
  - [ ] Update provider list in `wch_get_container()`
  - [ ] Remove old providers
  - [ ] Add new consolidated providers
  - [ ] Test initialization order

#### 9.3 Test Integration
- [ ] **Verify all services resolve**
  - Test each service resolution
  - Check for circular dependencies
  - Verify initialization order
  - Test in production-like environment

### Deliverables
- Consolidated service providers (from 20 to 6-8)
- Updated bootstrap code
- All services working

### Success Criteria
- All services resolve correctly
- No circular dependencies
- Faster initialization
- Cleaner provider structure

---

## ✅ Phase 10: Testing & Documentation (Week 11-12)

**Status:** 🔴 Not Started
**Goal:** Comprehensive testing and documentation
**Risk Level:** Medium

### Prerequisites
- ✅ Phase 9 complete
- [ ] All migration complete

### Tasks

#### 10.1 Test Updates
- [ ] **Update test imports**
  - [ ] Update all test files to use new namespaces
  - [ ] Fix broken tests
  - [ ] Add tests for new classes

- [ ] **Integration tests**
  - [ ] Test critical workflows end-to-end
  - [ ] Payment flow
  - [ ] Checkout flow
  - [ ] Product sync
  - [ ] Order sync
  - [ ] Cart operations

- [ ] **Performance tests**
  - [ ] Benchmark autoloading
  - [ ] Benchmark service resolution
  - [ ] Compare with baseline
  - [ ] Optimize if needed

- [ ] **Coverage report**
  - [ ] Generate coverage report
  - [ ] Identify gaps
  - [ ] Add tests to reach goals
  - [ ] Document coverage

#### 10.2 Static Analysis
- [ ] **PHPStan**
  - [ ] Update configuration
  - [ ] Run analysis
  - [ ] Fix all errors
  - [ ] Update baseline if needed

- [ ] **PHPCS**
  - [ ] Run code sniffer
  - [ ] Fix all violations
  - [ ] Update rules if needed

#### 10.3 Documentation
- [ ] **Architecture documentation**
  - [ ] Create/update `ARCHITECTURE.md`
  - [ ] Document directory structure
  - [ ] Document namespace conventions
  - [ ] Document dependency flow
  - [ ] Add architecture diagrams

- [ ] **Developer guides**
  - [ ] Update `CONTRIBUTING.md`
  - [ ] Create migration guide
  - [ ] Document new patterns
  - [ ] Add code examples

- [ ] **API documentation**
  - [ ] Generate API docs
  - [ ] Document public interfaces
  - [ ] Add usage examples

- [ ] **Update READMEs**
  - [ ] Main README.md
  - [ ] Directory READMEs
  - [ ] Feature documentation

- [ ] **Inline documentation**
  - [ ] Review PHPDoc blocks
  - [ ] Add missing documentation
  - [ ] Update examples

#### 10.4 Migration Guide
- [ ] **Create migration guide**
  - [ ] Document breaking changes
  - [ ] Provide code examples
  - [ ] Create upgrade script if needed
  - [ ] Document BC layers

### Deliverables
- All tests updated and passing
- 80%+ test coverage
- PHPStan clean (level 5+)
- PHPCS clean
- Complete documentation
- Migration guide

### Success Criteria
- 100% test pass rate
- Coverage goals met
- No static analysis errors
- Documentation complete
- Migration path clear

---

## 🧹 Phase 11: Deprecation & Cleanup (Ongoing)

**Status:** 🔴 Not Started
**Goal:** Remove legacy code gradually
**Risk Level:** Low

### Prerequisites
- ✅ Phase 10 complete
- [ ] All functionality migrated
- [ ] BC wrappers in place

### Tasks

#### 11.1 Deprecation Period (Version 2.0.0)
- [ ] **Release with BC wrappers**
  - All legacy classes trigger deprecation warnings
  - Document migration path
  - Provide migration examples
  - Set removal timeline

- [ ] **Monitor usage**
  - Log deprecated class usage
  - Identify external dependencies
  - Contact extension developers
  - Provide migration support

#### 11.2 Legacy Removal (Version 3.0.0)
- [ ] **Remove legacy classes**
  - Delete all `class-wch-*.php` files
  - Remove BC wrappers
  - Remove custom autoloader
  - Update main plugin file

- [ ] **Remove old directories**
  - Clean up old structure
  - Remove empty directories
  - Update .gitignore

- [ ] **Update Composer**
  - Remove custom autoloader
  - Optimize autoloader
  - Update dependencies

#### 11.3 Final Cleanup
- [ ] **Code cleanup**
  - Remove unused code
  - Remove debug code
  - Optimize imports
  - Clean up comments

- [ ] **Performance optimization**
  - Optimize autoloader
  - Review service initialization
  - Cache optimization
  - Database query optimization

- [ ] **Final testing**
  - Complete regression test
  - Performance benchmarking
  - Security audit
  - Code review

### Deliverables
- Legacy code removed
- Clean codebase
- Optimized autoloader
- Final documentation

### Success Criteria
- No legacy code remaining
- All tests passing
- Performance improved or maintained
- Clean git history

---

## 📊 Progress Tracking

### Overall Progress: 8.3% Complete

| Phase | Status | Progress | Est. Completion |
|-------|--------|----------|-----------------|
| Phase 1 | 🟡 In Progress | 25% | Week 2 |
| Phase 2 | 🔴 Not Started | 0% | Week 3 |
| Phase 3 | 🔴 Not Started | 0% | Week 5 |
| Phase 4 | 🔴 Not Started | 0% | Week 6 |
| Phase 5 | 🔴 Not Started | 0% | Week 7 |
| Phase 6 | 🔴 Not Started | 0% | Week 8 |
| Phase 7 | 🔴 Not Started | 0% | Week 9 |
| Phase 8 | 🔴 Not Started | 0% | Week 10 |
| Phase 9 | 🔴 Not Started | 0% | Week 10 |
| Phase 10 | 🔴 Not Started | 0% | Week 12 |
| Phase 11 | 🔴 Not Started | 0% | TBD |

---

## 🎯 Next Steps

1. **Complete Phase 1 deliverables**
   - Create `MIGRATION_STATUS.md`
   - Create directory structure
   - Set up deprecation system
   - Run baseline tests

2. **Review and approve plan**
   - Team review
   - Stakeholder approval
   - Timeline confirmation

3. **Begin Phase 2**
   - Start with Logger migration
   - Test thoroughly
   - Create first BC wrapper

---

**Last Updated:** 2026-01-10
**Current Phase:** Phase 1 (Foundation & Planning)
**Next Milestone:** Phase 1 completion (Week 2)
