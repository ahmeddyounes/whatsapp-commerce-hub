# Extension Points Reference

This document provides a comprehensive reference of all stable extension points in the WhatsApp Commerce Hub for custom development.

## Table of Contents

1. [WordPress Hooks](#wordpress-hooks)
2. [Service Provider Methods](#service-provider-methods)
3. [Interface Implementations](#interface-implementations)
4. [Abstract Classes](#abstract-classes)
5. [Extension Examples](#extension-examples)

---

## WordPress Hooks

### Action: `wch_register_action_handlers`

**PRIMARY EXTENSION POINT for registering custom action handlers.**

**Location**: `includes/Providers/ActionServiceProvider.php:158`

**Fires**: After core action handlers are registered, before any message processing

**Parameters**:
- `ActionRegistry $registry` - The action registry instance
- `Container $container` - The DI container for resolving dependencies

**Usage**:
```php
add_action('wch_register_action_handlers', function($registry, $container) {
    // Create your custom handler
    $handler = new MyCustomAction();

    // Inject dependencies (optional)
    $handler->setLogger($container->get('logger'));
    $handler->setCartService($container->get(CartServiceInterface::class));
    $handler->setCustomerService($container->get(CustomerService::class));

    // Register with registry
    $registry->register($handler);
}, 10, 2);
```

**Use Cases**:
- Add custom action handlers for new conversation flows
- Override built-in actions with higher priority handlers
- Integrate third-party services into conversation flow
- Add custom business logic

**Documentation**: [Extending Actions Guide](../guides/EXTENDING_ACTIONS.md)

---

### Action: `wch_webhook_message_processed`

**SECONDARY EXTENSION POINT for post-processing message handling.**

**Location**: `includes/Queue/Processors/WebhookMessageProcessor.php:198`

**Fires**: After a webhook message has been processed and responses sent

**Parameters**:
- `array $data` - Webhook payload data
- `Conversation $conversation` - Updated conversation entity
- `string $intent` - Classified intent from AI
- `ActionResult $actionResult` - Result from action execution

**Usage**:
```php
add_action('wch_webhook_message_processed', function($data, $conversation, $intent, $actionResult) {
    // Custom analytics tracking
    MyAnalytics::track('conversation_interaction', [
        'phone' => $conversation->getPhone(),
        'intent' => $intent,
        'success' => $actionResult->isSuccess(),
        'state' => $conversation->getState(),
        'timestamp' => time(),
    ]);

    // Custom notifications
    if ($intent === 'customer_service') {
        MyNotifications::alertSupport($conversation);
    }
}, 10, 4);
```

**Use Cases**:
- Analytics and metrics collection
- External system notifications
- Logging and auditing
- Triggering side effects (emails, webhooks, etc.)
- Custom business intelligence

---

## Service Provider Methods

### Method: `ActionServiceProvider::addHandler()`

**ALTERNATIVE EXTENSION POINT for early handler registration.**

**Location**: `includes/Providers/ActionServiceProvider.php:220`

**Usage**: Register handlers before boot phase with full dependency injection support

**Signature**:
```php
public function addHandler(string $handlerClass): void
```

**Usage**:
```php
// In your plugin initialization (before boot)
$container = wch_container();
$actionProvider = $container->get(ActionServiceProvider::class);
$actionProvider->addHandler(MyCustomAction::class);
```

**Advantages over hook**:
- Handlers get full dependency injection
- Registered alongside core handlers
- Automatic instantiation by container

**Use Cases**:
- Plugin initialization
- Early registration for priority handlers
- Integration with other service providers

---

## Interface Implementations

### ActionHandlerInterface

**Contract for all action handlers.**

**Location**: `includes/Actions/Contracts/ActionHandlerInterface.php`

**Required Methods**:
```php
interface ActionHandlerInterface {
    public function handle(string $phone, array $params, ConversationContext $context): ActionResult;
    public function supports(string $actionName): bool;
    public function getName(): string;
    public function getPriority(): int;
}
```

**Implementation Guide**:

1. **Extend AbstractAction** (recommended) or implement interface directly
2. **getName()**: Return primary action name (lowercase snake_case)
3. **supports()**: Return true for all supported action names/aliases
4. **getPriority()**: Return priority (10 = default, 20+ = override, 1-9 = fallback)
5. **handle()**: Implement business logic, return ActionResult

**Example**:
```php
class MyAction extends AbstractAction {
    public function getName(): string {
        return 'my_action';
    }

    public function supports(string $actionName): bool {
        return $actionName === 'my_action';
    }

    public function getPriority(): int {
        return 10;
    }

    public function handle(string $phone, array $params, ConversationContext $context): ActionResult {
        // Your logic here
        $message = $this->createMessageBuilder()->text("Result");
        return ActionResult::success([$message], 'next_state');
    }
}
```

**Documentation**: [ActionHandlerInterface](../../includes/Actions/Contracts/ActionHandlerInterface.php)

---

### CheckoutStateManagerInterface

**Contract for managing checkout flow state.**

**Location**: `includes/Contracts/Services/Checkout/CheckoutStateManagerInterface.php`

**Purpose**: Custom checkout flow logic and state management

**Key Methods**:
```php
interface CheckoutStateManagerInterface {
    public function initializeCheckout(string $phone): CheckoutState;
    public function updateAddress(string $phone, array $addressData): CheckoutState;
    public function updatePaymentMethod(string $phone, string $method): CheckoutState;
    public function canProceedToPayment(string $phone): bool;
    public function finalizeCheckout(string $phone): CheckoutState;
}
```

**Use Cases**:
- Custom checkout steps
- Multi-step checkout flows
- Checkout validation rules
- Third-party checkout integration

---

### CartServiceInterface

**Contract for cart operations.**

**Location**: `includes/Contracts/Services/CartServiceInterface.php`

**Purpose**: Custom cart behavior and validation

**Key Methods**:
```php
interface CartServiceInterface {
    public function getCart(string $phone): Cart;
    public function addItem(string $phone, int $productId, int $quantity, array $options): Cart;
    public function removeItem(string $phone, int $itemId): Cart;
    public function updateQuantity(string $phone, int $itemId, int $quantity): Cart;
    public function clearCart(string $phone): Cart;
    public function calculateTotals(Cart $cart): array;
}
```

**Use Cases**:
- Custom cart logic
- External cart systems
- Custom pricing rules
- Cart validation

---

### CustomerServiceInterface

**Contract for customer management.**

**Location**: `includes/Contracts/Services/CustomerServiceInterface.php`

**Purpose**: Custom customer profile and preference management

**Use Cases**:
- Custom customer fields
- External CRM integration
- Customer segmentation
- Preference management

---

## Abstract Classes

### AbstractAction

**Base class providing common functionality for action handlers.**

**Location**: `includes/Actions/AbstractAction.php`

**Provides**:

**Dependency Injection**:
- `setLogger(LoggerInterface $logger)`
- `setCartService(CartServiceInterface $service)`
- `setCustomerService(CustomerService $service)`

**Helper Methods**:
```php
// Error handling
protected function error(string $errorMessage, ?string $nextState = null): ActionResult

// Logging
protected function log(string $message, array $data = [], string $level = 'info'): void

// Customer operations
protected function getCustomerProfile(string $phone): Customer
protected function getCart(string $phone): Cart

// Message building
protected function createMessageBuilder(): MessageBuilder

// WooCommerce helpers
protected function formatPrice(float $price): string
protected function hasStock(int $productId, int $quantity = 1, ?int $variantId = null): bool
protected function calculateCartTotal(array $items): array
```

**Default Values**:
- Priority: `10`
- Supports: Returns `true` only if `$actionName === $this->getName()`

**Usage**:
```php
class MyAction extends AbstractAction {
    public function getName(): string {
        return 'my_action';
    }

    public function handle(string $phone, array $params, ConversationContext $context): ActionResult {
        // Use helper methods
        $customer = $this->getCustomerProfile($phone);
        $cart = $this->getCart($phone);
        $this->log('Processing action', ['phone' => $phone]);

        $message = $this->createMessageBuilder()
            ->text("Hello {$customer->getFirstName()}!");

        return ActionResult::success([$message], 'browsing');
    }
}
```

**Benefits**:
- Automatic dependency injection
- Common helper methods
- Reduced boilerplate
- Consistent error handling

**Documentation**: [AbstractAction](../../includes/Actions/AbstractAction.php)

---

## Extension Examples

### Example 1: Register Custom Handler via Hook

```php
<?php
/**
 * Plugin Name: My WCH Extension
 */

add_action('wch_register_action_handlers', function($registry, $container) {
    require_once __DIR__ . '/includes/CustomAction.php';

    $handler = new MyPlugin\CustomAction();
    $handler->setLogger($container->get('logger'));
    $registry->register($handler);
}, 10, 2);
```

### Example 2: Override Built-in Handler

```php
<?php
namespace MyPlugin;

use WhatsAppCommerceHub\Actions\AbstractAction;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

class CustomHelpAction extends AbstractAction {
    public function getName(): string {
        return 'show_main_menu'; // Same as built-in
    }

    public function getPriority(): int {
        return 20; // Higher than default (10)
    }

    public function handle(string $phone, array $params, ConversationContext $context): ActionResult {
        // Your custom help implementation
        $message = $this->createMessageBuilder()
            ->text("Custom help menu");
        return ActionResult::success([$message], 'browsing');
    }
}
```

### Example 3: Analytics Post-Processing

```php
<?php
add_action('wch_webhook_message_processed', function($data, $conversation, $intent, $actionResult) {
    // Track to Google Analytics
    if (function_exists('gtag')) {
        gtag('event', 'whatsapp_interaction', [
            'intent' => $intent,
            'success' => $actionResult->isSuccess(),
            'state' => $conversation->getState(),
        ]);
    }

    // Log to custom analytics
    MyAnalytics::logEvent('conversation', [
        'phone' => $conversation->getPhone(),
        'intent' => $intent,
        'timestamp' => time(),
    ]);
}, 10, 4);
```

### Example 4: Custom Cart Implementation

```php
<?php
namespace MyPlugin;

use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\ValueObjects\Cart;

class CustomCartService implements CartServiceInterface {
    public function getCart(string $phone): Cart {
        // Custom cart retrieval from external system
        $externalCart = $this->externalApi->getCart($phone);
        return $this->convertToCart($externalCart);
    }

    public function addItem(string $phone, int $productId, int $quantity, array $options): Cart {
        // Custom add to cart logic
        $this->externalApi->addItem($phone, $productId, $quantity);
        return $this->getCart($phone);
    }

    // ... implement other methods
}

// Register in container
add_action('wch_services_registered', function($container) {
    $container->bind(
        CartServiceInterface::class,
        MyPlugin\CustomCartService::class
    );
});
```

---

## Extension Point Summary

| Extension Point | Type | Priority | Use Case |
|----------------|------|----------|----------|
| `wch_register_action_handlers` | Hook | Primary | Register custom actions |
| `wch_webhook_message_processed` | Hook | Secondary | Post-processing, analytics |
| `ActionServiceProvider::addHandler()` | Method | Alternative | Early registration with DI |
| `ActionHandlerInterface` | Interface | Core | Custom action handlers |
| `CheckoutStateManagerInterface` | Interface | Advanced | Custom checkout flows |
| `CartServiceInterface` | Interface | Advanced | Custom cart logic |
| `CustomerServiceInterface` | Interface | Advanced | Custom customer management |
| `AbstractAction` | Class | Recommended | Base for action handlers |

---

## Best Practices

1. **Use Hooks First**: Start with `wch_register_action_handlers` for most custom actions
2. **Extend AbstractAction**: Leverage helper methods and automatic DI
3. **Priority Strategy**:
   - Default: 10 (standard actions)
   - Override: 20+ (replace built-in actions)
   - Fallback: 1-9 (backup handlers)
4. **Test Thoroughly**: Write unit tests for custom handlers
5. **Document Extensions**: Add PHPDoc comments to custom code
6. **Follow Naming**: Use snake_case for action names
7. **Handle Errors**: Return helpful error messages via `ActionResult::failure()`
8. **Log Appropriately**: Use `$this->log()` for debugging and monitoring

---

## Additional Resources

- [Extending Actions Guide](../guides/EXTENDING_ACTIONS.md) - Practical examples
- [Action Routing Architecture](ACTION_ROUTING_FSM.md) - Complete architecture reference
- [Hooks Reference](../hooks-reference.md) - All WordPress hooks
- [API Reference](../api-reference.md) - REST API endpoints

---

**Last Updated**: 2026-01-18
**Covers Version**: 3.0.0
