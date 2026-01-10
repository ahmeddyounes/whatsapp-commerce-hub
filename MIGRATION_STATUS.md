# Migration Status - Legacy Class Inventory

**Total Legacy Classes:** 66
**Migrated:** 0
**In Progress:** 0
**Remaining:** 66
**Progress:** 0%

**Last Updated:** 2026-01-10

---

## Migration Priority Legend

- 🔴 **Critical** - Core infrastructure, must be done first (Phase 2)
- 🟠 **High** - Important business logic, early migration (Phase 3-4)
- 🟡 **Medium** - Standard features, mid-migration (Phase 5-7)
- 🟢 **Low** - Optional features, can be migrated last (Phase 8+)

## Status Legend

- ⚪ **Not Started** - No work begun
- 🔵 **In Progress** - Migration underway
- 🟡 **Testing** - Migration complete, being tested
- 🟢 **Complete** - Fully migrated with tests
- 🔴 **Blocked** - Migration blocked by dependencies

---

## Phase 2: Core Infrastructure (5 classes)

### Critical Priority Classes

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 1 | `class-wch-logger.php` | `Core/Logger.php` | `WhatsAppCommerceHub\Core` | 🔴 Critical | ⚪ Not Started | 2 | - | Foundation class, migrate first |
| 2 | `class-wch-error-handler.php` | `Core/ErrorHandler.php` | `WhatsAppCommerceHub\Core` | 🔴 Critical | ⚪ Not Started | 2 | - | Depends on Logger |
| 3 | `class-wch-encryption.php` | `Infrastructure/Security/Encryption.php` | `WhatsAppCommerceHub\Infrastructure\Security` | 🔴 Critical | ⚪ Not Started | 2 | - | Security critical |
| 4 | `class-wch-database-manager.php` | `Infrastructure/Database/DatabaseManager.php` | `WhatsAppCommerceHub\Infrastructure\Database` | 🔴 Critical | ⚪ Not Started | 2 | - | Extract migrations |
| 5 | `class-wch-settings.php` | `Infrastructure/Configuration/SettingsManager.php` | `WhatsAppCommerceHub\Infrastructure\Configuration` | 🔴 Critical | ⚪ Not Started | 2 | - | Merge with SettingsService.php |

---

## Phase 3: Domain Layer (18 classes)

### Cart Domain (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 6 | `class-wch-cart-manager.php` | `Domain/Cart/CartService.php` | `WhatsAppCommerceHub\Domain\Cart` | 🟠 High | ⚪ Not Started | 3 | - | Merge with Services/CartService.php |
| 7 | `class-wch-cart-exception.php` | `Domain/Cart/CartException.php` | `WhatsAppCommerceHub\Domain\Cart` | 🟡 Medium | ⚪ Not Started | 3 | - | Move to domain |

### Catalog Domain (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 8 | `class-wch-product-sync-service.php` | `Application/Services/ProductSyncService.php` | `WhatsAppCommerceHub\Application\Services` | 🟠 High | ⚪ Not Started | 3 | - | Merge with Services/ProductSyncService.php |
| 9 | `class-wch-catalog-browser.php` | `Domain/Catalog/CatalogBrowser.php` | `WhatsAppCommerceHub\Domain\Catalog` | 🟠 High | ⚪ Not Started | 3 | - | Refactor to use domain models |

### Order Domain (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 10 | `class-wch-order-sync-service.php` | `Application/Services/OrderSyncService.php` | `WhatsAppCommerceHub\Application\Services` | 🟠 High | ⚪ Not Started | 3 | - | Merge with Services/OrderSyncService.php |
| 11 | `class-wch-inventory-sync-handler.php` | `Application/Services/InventorySyncService.php` | `WhatsAppCommerceHub\Application\Services` | 🟠 High | ⚪ Not Started | 3 | - | Move to application layer |

### Customer Domain (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 12 | `class-wch-customer-profile.php` | `Domain/Customer/CustomerProfile.php` | `WhatsAppCommerceHub\Domain\Customer` | 🟠 High | ⚪ Not Started | 3 | - | Move to domain entities |
| 13 | `class-wch-customer-service.php` | `Domain/Customer/CustomerService.php` | `WhatsAppCommerceHub\Domain\Customer` | 🟠 High | ⚪ Not Started | 3 | - | ✅ Already exists, merge functionality |

### Conversation Domain (5 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 14 | `class-wch-conversation-context.php` | `Domain/Conversation/Context.php` | `WhatsAppCommerceHub\Domain\Conversation` | 🟠 High | ⚪ Not Started | 3 | - | Core conversation state |
| 15 | `class-wch-conversation-fsm.php` | `Domain/Conversation/StateMachine.php` | `WhatsAppCommerceHub\Domain\Conversation` | 🟠 High | ⚪ Not Started | 3 | - | State machine logic |
| 16 | `class-wch-intent.php` | `Domain/Conversation/Intent.php` | `WhatsAppCommerceHub\Domain\Conversation` | 🟠 High | ⚪ Not Started | 3 | - | Intent value object |
| 17 | `class-wch-intent-classifier.php` | `Support/AI/IntentClassifier.php` | `WhatsAppCommerceHub\Support\AI` | 🟡 Medium | ⚪ Not Started | 8 | - | Merge with IntentClassifierService |
| 18 | `class-wch-context-manager.php` | `Support/AI/ConversationContext.php` | `WhatsAppCommerceHub\Support\AI` | 🟡 Medium | ⚪ Not Started | 8 | - | Merge with ContextManagerService |

### Value Objects (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 19 | `class-wch-parsed-response.php` | `ValueObjects/ParsedResponse.php` | `WhatsAppCommerceHub\ValueObjects` | 🟡 Medium | ⚪ Not Started | 3 | - | Already has ValueObjects dir |
| 20 | `class-wch-action-result.php` | `ValueObjects/ActionResult.php` | `WhatsAppCommerceHub\ValueObjects` | 🟡 Medium | ⚪ Not Started | 3 | - | Already has ValueObjects dir |

### Exceptions (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 21 | `class-wch-exception.php` | `Exceptions/WchException.php` | `WhatsAppCommerceHub\Exceptions` | 🟡 Medium | ⚪ Not Started | 3 | - | Base exception class |
| 22 | `class-wch-api-exception.php` | `Exceptions/ApiException.php` | `WhatsAppCommerceHub\Exceptions` | 🟡 Medium | ⚪ Not Started | 3 | - | API-specific exceptions |

---

## Phase 4: Infrastructure Layer (9 classes)

### API Layer (4 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 23 | `class-wch-rest-api.php` | `Infrastructure/Api/Rest/RestApi.php` | `WhatsAppCommerceHub\Infrastructure\Api\Rest` | 🟠 High | ⚪ Not Started | 4 | - | Core REST API |
| 24 | `class-wch-rest-controller.php` | `Infrastructure/Api/Rest/RestController.php` | `WhatsAppCommerceHub\Infrastructure\Api\Rest` | 🟠 High | ⚪ Not Started | 4 | - | Base controller |
| 25 | `class-wch-webhook-handler.php` | `Infrastructure/Api/Rest/Controllers/WebhookController.php` | `WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers` | 🟠 High | ⚪ Not Started | 4 | - | Webhook endpoint |
| 26 | `class-wch-whatsapp-api-client.php` | `Infrastructure/Api/Clients/WhatsAppApiClient.php` | `WhatsAppCommerceHub\Infrastructure\Api\Clients` | 🟠 High | ⚪ Not Started | 4 | - | ✅ Already exists, merge |

### Controllers (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 27 | `class-wch-conversations-controller.php` | `Infrastructure/Api/Rest/Controllers/ConversationsController.php` | `WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers` | 🟡 Medium | ⚪ Not Started | 4 | - | Move from Controllers/ |
| 28 | `class-wch-analytics-controller.php` | `Infrastructure/Api/Rest/Controllers/AnalyticsController.php` | `WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers` | 🟡 Medium | ⚪ Not Started | 4 | - | Move from Controllers/ |

### Queue System (3 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 29 | `class-wch-queue.php` | `Infrastructure/Queue/QueueManager.php` | `WhatsAppCommerceHub\Infrastructure\Queue` | 🟠 High | ⚪ Not Started | 4 | - | Merge with Queue/ directory |
| 30 | `class-wch-job-dispatcher.php` | `Infrastructure/Queue/JobDispatcher.php` | `WhatsAppCommerceHub\Infrastructure\Queue` | 🟠 High | ⚪ Not Started | 4 | - | Job dispatching logic |
| 31 | `class-wch-sync-job-handler.php` | `Infrastructure/Queue/Handlers/SyncJobHandler.php` | `WhatsAppCommerceHub\Infrastructure\Queue\Handlers` | 🟡 Medium | ⚪ Not Started | 4 | - | Sync job handler |

---

## Phase 6: Presentation Layer (21 classes)

### Admin Pages (10 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 32 | `class-wch-admin-analytics.php` | `Presentation/Admin/Pages/AnalyticsPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 33 | `class-wch-admin-catalog-sync.php` | `Presentation/Admin/Pages/CatalogSyncPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 34 | `class-wch-admin-inbox.php` | `Presentation/Admin/Pages/InboxPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 35 | `class-wch-admin-jobs.php` | `Presentation/Admin/Pages/JobsPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 36 | `class-wch-admin-logs.php` | `Presentation/Admin/Pages/LogsPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 37 | `class-wch-admin-templates.php` | `Presentation/Admin/Pages/TemplatesPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 38 | `class-wch-admin-broadcasts.php` | `Presentation/Admin/Pages/BroadcastsPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | Create broadcasts page |
| 39 | `class-wch-admin-settings.php` | `Presentation/Admin/Pages/SettingsPage.php` | `WhatsAppCommerceHub\Presentation\Admin\Pages` | 🟡 Medium | ⚪ Not Started | 6 | - | Settings UI |
| 40 | `class-wch-dashboard-widgets.php` | `Presentation/Admin/Widgets/DashboardWidgets.php` | `WhatsAppCommerceHub\Presentation\Admin\Widgets` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 41 | `class-wch-template-manager.php` | `Presentation/Templates/TemplateManager.php` | `WhatsAppCommerceHub\Presentation\Templates` | 🟡 Medium | ⚪ Not Started | 6 | - | Template system |

### Actions (11 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 42 | `class-wch-flow-action.php` | `Presentation/Actions/AbstractAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists as AbstractAction |
| 43 | `class-wch-action-add-to-cart.php` | `Presentation/Actions/AddToCartAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 44 | `class-wch-action-show-cart.php` | `Presentation/Actions/ShowCartAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 45 | `class-wch-action-show-product.php` | `Presentation/Actions/ShowProductAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 46 | `class-wch-action-show-category.php` | `Presentation/Actions/ShowCategoryAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 47 | `class-wch-action-show-main-menu.php` | `Presentation/Actions/ShowMainMenuAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 48 | `class-wch-action-request-address.php` | `Presentation/Actions/RequestAddressAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 49 | `class-wch-action-confirm-order.php` | `Presentation/Actions/ConfirmOrderAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |
| 50 | `class-wch-action-process-payment.php` | `Presentation/Actions/ProcessPaymentAction.php` | `WhatsAppCommerceHub\Presentation\Actions` | 🟡 Medium | ⚪ Not Started | 6 | - | ✅ Already exists, merge |

---

## Phase 7: Feature Modules (9 classes)

### Abandoned Cart Feature (3 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 51 | `class-wch-abandoned-cart-recovery.php` | `Features/AbandonedCart/RecoveryService.php` | `WhatsAppCommerceHub\Features\AbandonedCart` | 🟡 Medium | ⚪ Not Started | 7 | - | Recovery logic |
| 52 | `class-wch-abandoned-cart-handler.php` | `Features/AbandonedCart/CartHandler.php` | `WhatsAppCommerceHub\Features\AbandonedCart` | 🟡 Medium | ⚪ Not Started | 7 | - | Cart tracking |
| 53 | `class-wch-cart-cleanup-handler.php` | `Features/AbandonedCart/CleanupHandler.php` | `WhatsAppCommerceHub\Features\AbandonedCart` | 🟡 Medium | ⚪ Not Started | 7 | - | Also in Queue/Handlers |

### Reengagement Feature (1 class)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 54 | `class-wch-reengagement-service.php` | `Features/Reengagement/ReengagementService.php` | `WhatsAppCommerceHub\Features\Reengagement` | 🟡 Medium | ⚪ Not Started | 7 | - | ✅ Dir exists, merge |

### Broadcasts Feature (1 class)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 55 | `class-wch-broadcast-job-handler.php` | `Features/Broadcasts/BroadcastJobHandler.php` | `WhatsAppCommerceHub\Features\Broadcasts` | 🟡 Medium | ⚪ Not Started | 7 | - | Also in Queue/Handlers |

### Analytics Feature (1 class)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 56 | `class-wch-analytics-data.php` | `Features/Analytics/AnalyticsData.php` | `WhatsAppCommerceHub\Features\Analytics` | 🟡 Medium | ⚪ Not Started | 7 | - | Analytics data model |

### Notifications Feature (1 class)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 57 | `class-wch-order-notifications.php` | `Features/Notifications/OrderNotifications.php` | `WhatsAppCommerceHub\Features\Notifications` | 🟡 Medium | ⚪ Not Started | 7 | - | Order notification system |

### Payments Feature (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 58 | `class-wch-refund-handler.php` | `Features/Payments/RefundService.php` | `WhatsAppCommerceHub\Features\Payments` | 🟡 Medium | ⚪ Not Started | 7 | - | Refund processing |
| 59 | `class-wch-payment-webhook-handler.php` | `Features/Payments/WebhookHandler.php` | `WhatsAppCommerceHub\Features\Payments` | 🟡 Medium | ⚪ Not Started | 7 | - | Payment webhooks |

---

## Phase 8: Support & Utilities (4 classes)

### AI Support (2 classes)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 60 | `class-wch-response-parser.php` | `Support/AI/ResponseParser.php` | `WhatsAppCommerceHub\Support\AI` | 🟡 Medium | ⚪ Not Started | 8 | - | Merge with ResponseParserService |
| 61 | `class-wch-ai-assistant.php` | `Support/AI/AiAssistant.php` | `WhatsAppCommerceHub\Support\AI` | 🟢 Low | ⚪ Not Started | 8 | - | AI helper utilities |

### Messaging Support (1 class)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 62 | `class-wch-message-builder.php` | `Support/Messaging/MessageBuilder.php` | `WhatsAppCommerceHub\Support\Messaging` | 🟡 Medium | ⚪ Not Started | 8 | - | Merge with MessageBuilderService |

### Utilities (1 class)

| # | Current File | New Location | New Namespace | Priority | Status | Phase | Assignee | Notes |
|---|-------------|--------------|---------------|----------|--------|-------|----------|-------|
| 63 | `class-wch-address-parser.php` | `Support/Utilities/AddressParser.php` | `WhatsAppCommerceHub\Support\Utilities` | 🟢 Low | ⚪ Not Started | 8 | - | Utility class |

---

## Test Files (Move to tests/) - 3 classes

These should be moved to the `tests/` directory, not migrated to PSR-4 in includes.

| # | Current File | New Location | Priority | Status | Phase | Notes |
|---|-------------|--------------|----------|--------|-------|-------|
| 64 | `class-wch-test.php` | `tests/Legacy/WchTest.php` | 🟢 Low | ⚪ Not Started | 10 | Move to tests or delete |
| 65 | `class-wch-settings-test.php` | `tests/Legacy/SettingsTest.php` | 🟢 Low | ⚪ Not Started | 10 | Move to tests or delete |
| 66 | `class-wch-rest-api-test.php` | `tests/Legacy/RestApiTest.php` | 🟢 Low | ⚪ Not Started | 10 | Move to tests or delete |

---

## Progress by Phase

| Phase | Total Classes | Completed | In Progress | Remaining | Progress |
|-------|--------------|-----------|-------------|-----------|----------|
| Phase 2 | 5 | 0 | 0 | 5 | 0% |
| Phase 3 | 18 | 0 | 0 | 18 | 0% |
| Phase 4 | 9 | 0 | 0 | 9 | 0% |
| Phase 5 | 0 | 0 | 0 | 0 | - |
| Phase 6 | 21 | 0 | 0 | 21 | 0% |
| Phase 7 | 9 | 0 | 0 | 9 | 0% |
| Phase 8 | 4 | 0 | 0 | 4 | 0% |
| Test Files | 3 | 0 | 0 | 3 | 0% |
| **TOTAL** | **66** | **0** | **0** | **66** | **0%** |

---

## Progress by Priority

| Priority | Total | Completed | Remaining | Progress |
|----------|-------|-----------|-----------|----------|
| 🔴 Critical | 5 | 0 | 5 | 0% |
| 🟠 High | 15 | 0 | 15 | 0% |
| 🟡 Medium | 43 | 0 | 43 | 0% |
| 🟢 Low | 6 | 0 | 6 | 0% |

---

## Classes Already Having PSR-4 Equivalents

These legacy classes already have modern PSR-4 equivalents. Migration involves merging functionality and deprecating the old class.

| Legacy Class | Modern Equivalent | Status | Notes |
|-------------|-------------------|--------|-------|
| `class-wch-customer-service.php` | `Services/CustomerService.php` | ✅ Exists | Merge and consolidate |
| `class-wch-intent-classifier.php` | `Services/IntentClassifierService.php` | ✅ Exists | Merge functionality |
| `class-wch-response-parser.php` | `Services/ResponseParserService.php` | ✅ Exists | Merge functionality |
| `class-wch-context-manager.php` | `Services/ContextManagerService.php` | ✅ Exists | Merge functionality |
| `class-wch-message-builder.php` | `Services/MessageBuilderService.php` | ✅ Exists | Merge functionality |
| `class-wch-cart-manager.php` | `Services/CartService.php` | ✅ Exists | Merge functionality |
| `class-wch-product-sync-service.php` | `Services/ProductSyncService.php` | ✅ Exists | Merge functionality |
| `class-wch-order-sync-service.php` | `Services/OrderSyncService.php` | ✅ Exists | Merge functionality |
| `class-wch-whatsapp-api-client.php` | `Clients/WhatsAppApiClient.php` | ✅ Exists | Merge functionality |
| `class-wch-flow-action.php` | `Actions/AbstractAction.php` | ✅ Exists | Base class already migrated |
| All `class-wch-action-*.php` | `Actions/*Action.php` | ✅ Exists | Action classes already PSR-4 |
| Admin pages | `Admin/*Page.php` | ✅ Exists | Most admin pages already PSR-4 |
| `class-wch-dashboard-widgets.php` | `Admin/DashboardWidgets.php` | ✅ Exists | Merge functionality |
| `class-wch-reengagement-service.php` | `Services/Reengagement/*` | ✅ Exists | Directory structure exists |

**Total with equivalents:** ~23 classes (merge strategy required)
**Total completely new:** ~43 classes (migration strategy required)

---

## Dependencies & Migration Order

### Phase 2 Critical Path (Must go in order)
1. **Logger** → No dependencies
2. **ErrorHandler** → Depends on Logger
3. **Encryption** → Independent
4. **DatabaseManager** → Independent
5. **Settings** → Can merge with existing SettingsService

### Phase 3 Key Dependencies
- **Cart** domain requires: Settings, Database
- **Order** domain requires: Cart, Product, Customer
- **Conversation** domain requires: Intent, Context
- **Customer** domain requires: Database

### High-Risk Classes (Require Extra Care)
1. `class-wch-payment-webhook-handler.php` - Financial transactions
2. `class-wch-order-sync-service.php` - Data integrity critical
3. `class-wch-product-sync-service.php` - Sync logic
4. `class-wch-cart-manager.php` - User experience impact
5. `class-wch-database-manager.php` - Schema changes

---

## Deprecation Timeline

### Version 2.0.0 (Migration Complete)
- All legacy classes have PSR-4 equivalents
- Legacy classes trigger deprecation warnings
- BC wrappers in place
- Migration guide published

### Version 2.x (Deprecation Period)
- Continue supporting BC wrappers
- Monitor usage via logs
- Provide migration support
- Update documentation

### Version 3.0.0 (Legacy Removal)
- Remove all `class-wch-*.php` files
- Remove BC wrappers
- Remove custom autoloader
- 100% PSR-4 compliant

---

## Change Log

### 2026-01-10
- ✅ Initial inventory created
- 📊 66 legacy classes identified
- 📋 Migration paths mapped for all classes
- 🎯 Priorities assigned
- 📝 Dependencies documented

---

## Notes

### Merge Strategy
For classes with PSR-4 equivalents:
1. Review both implementations
2. Identify unique functionality in legacy class
3. Merge unique features into PSR-4 class
4. Create BC wrapper that proxies to new class
5. Add deprecation warning
6. Update all internal references
7. Test thoroughly
8. Document changes

### Migration Strategy
For classes without equivalents:
1. Create new PSR-4 class structure
2. Migrate code with minimal changes
3. Refactor to match new architecture
4. Create BC wrapper
5. Update service providers
6. Test extensively
7. Update documentation

---

**Last Updated:** 2026-01-10
**Next Review:** 2026-01-17
**Review Frequency:** Weekly during active migration
