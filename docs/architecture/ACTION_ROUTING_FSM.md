# Action Routing, FSM & Conversation Architecture

## Overview

The WhatsApp Commerce Hub uses a flexible, priority-based action routing system combined with a finite state machine (FSM) for conversation management. This document describes the architecture, contracts, and extension points.

---

## Table of Contents

1. [Action Registry & Routing](#action-registry--routing)
2. [Action Handler Contract](#action-handler-contract)
3. [Finite State Machine](#finite-state-machine)
4. [Conversation Context](#conversation-context)
5. [Action Execution Flow](#action-execution-flow)
6. [Built-in Actions](#built-in-actions)
7. [Extension Points](#extension-points)
8. [Creating Custom Actions](#creating-custom-actions)
9. [Best Practices](#best-practices)

---

## Action Registry & Routing

### ActionRegistry Class

**Location:** `includes/Actions/ActionRegistry.php`

The `ActionRegistry` is the central orchestrator for action handlers. It provides:

- **Priority-based dispatch**: Higher priority handlers execute first
- **Multiple handlers per action**: Allows overriding and fallback handlers
- **Automatic caching**: Sorted handlers are cached for performance
- **Query capabilities**: List registered actions and handler counts

### Key Methods

```php
// Register a handler
$registry->register(ActionHandlerInterface $handler): void

// Get handlers for an action (sorted by priority, descending)
$registry->getHandlers(string $actionName): array

// Execute the highest-priority handler for an action
$registry->execute(string $actionName, string $phone, array $params, ConversationContext $context): ActionResult

// Find handlers that support an action (via supports() method)
$registry->findSupporting(string $actionName): array

// Query registered actions
$registry->getAllActions(): array
$registry->getHandlerCount(string $actionName): int
```

### How Actions are Resolved

When a message is received:

1. **Context-based routing**: Check if context indicates awaiting specific input (address, cart update, order ID)
2. **Pattern matching**: Regex patterns for product IDs, category IDs, menu selections
3. **Intent-based routing**: AI classifies user intent, maps to action name
4. **Fallback**: Unknown intents trigger help or clarification messages

**Reference:** `includes/Queue/Processors/WebhookMessageProcessor.php:339-482`

---

## Action Handler Contract

### ActionHandlerInterface

**Location:** `includes/Actions/Contracts/ActionHandlerInterface.php`

All action handlers must implement this interface:

```php
interface ActionHandlerInterface {
    /**
     * Execute the action
     *
     * @param string $phone Customer phone number
     * @param array $params Action parameters (product_id, quantity, etc.)
     * @param ConversationContext $context Current conversation state
     * @return ActionResult Result with messages, state transitions, context updates
     */
    public function handle(string $phone, array $params, ConversationContext $context): ActionResult;

    /**
     * Check if this handler supports the given action name
     *
     * @param string $actionName Action to check
     * @return bool True if handler can process this action
     */
    public function supports(string $actionName): bool;

    /**
     * Get the primary action name this handler handles
     *
     * @return string Action name (e.g., 'add_to_cart', 'show_cart')
     */
    public function getName(): string;

    /**
     * Get handler priority (higher = executes first)
     *
     * @return int Priority value (default: 10)
     */
    public function getPriority(): int;
}
```

### AbstractAction Base Class

**Location:** `includes/Actions/AbstractAction.php`

Provides common functionality for action handlers:

**Dependencies (via setters):**
- `LoggerInterface`: Action logging
- `CartServiceInterface`: Cart operations
- `CustomerService`: Customer profile management

**Helper Methods:**
```php
// Create error ActionResult
protected function error(string $errorMessage, ?string $nextState = null): ActionResult

// Log action with context
protected function log(string $message, array $data = [], string $level = 'info'): void

// Get/create customer profile
protected function getCustomerProfile(string $phone): Customer

// Get customer cart
protected function getCart(string $phone): Cart

// Create message builder
protected function createMessageBuilder(): MessageBuilder

// Format price with WooCommerce settings
protected function formatPrice(float $price): string

// Check product/variant stock
protected function hasStock(int $productId, int $quantity = 1, ?int $variantId = null): bool

// Calculate cart totals
protected function calculateCartTotal(array $items): array
```

**Default Priority:** `10` (can be overridden)

---

## Finite State Machine

### StateMachine Class

**Location:** `includes/Domain/Conversation/StateMachine.php`

Manages high-level conversation flow through defined states and transitions.

### States

```php
const STATE_INITIAL   = 'initial';   // Starting state
const STATE_BROWSING  = 'browsing';  // Customer browsing products
const STATE_CART      = 'cart';      // Managing shopping cart
const STATE_CHECKOUT  = 'checkout';  // Checkout process
const STATE_PAYMENT   = 'payment';   // Payment processing
const STATE_COMPLETED = 'completed'; // Order completed
const STATE_ABANDONED = 'abandoned'; // Conversation abandoned/timed out
```

### Valid Transitions

| From State   | To States                          |
|--------------|-------------------------------------|
| INITIAL      | BROWSING, ABANDONED                 |
| BROWSING     | CART, INITIAL, ABANDONED            |
| CART         | CHECKOUT, BROWSING, ABANDONED       |
| CHECKOUT     | PAYMENT, CART, ABANDONED            |
| PAYMENT      | COMPLETED, CHECKOUT, ABANDONED      |
| COMPLETED    | INITIAL, BROWSING                   |
| ABANDONED    | INITIAL, BROWSING                   |

### Methods

```php
// Check if transition is allowed
public function canTransitionTo(string $toState): bool

// Perform state transition (throws if invalid)
public function transitionTo(string $toState): void

// Get available next states
public function getAvailableTransitions(): array

// Check if state is terminal
public function isTerminalState(?string $state = null): bool
```

### Conversation Fine-Grained States

**Location:** `includes/Domain/Conversation/Conversation.php`

More granular states for detailed conversation tracking:

```php
const STATE_IDLE = 'idle';                           // Inactive
const STATE_BROWSING = 'browsing';                   // General browsing
const STATE_VIEWING_PRODUCT = 'viewing_product';     // Product details
const STATE_CART_MANAGEMENT = 'cart_management';     // Managing cart
const STATE_CHECKOUT_ADDRESS = 'checkout_address';   // Entering address
const STATE_CHECKOUT_PAYMENT = 'checkout_payment';   // Payment method
const STATE_CHECKOUT_CONFIRM = 'checkout_confirm';   // Final confirmation
const STATE_AWAITING_HUMAN = 'awaiting_human';       // Escalated to agent
```

**Status Flags:**
- `STATUS_ACTIVE`: Conversation active
- `STATUS_IDLE`: Customer inactive
- `STATUS_ESCALATED`: Escalated to human agent
- `STATUS_CLOSED`: Conversation ended

---

## Conversation Context

### ConversationContext Class

**Location:** `includes/ValueObjects/ConversationContext.php`

Immutable value object holding conversation state and temporary data.

### State Management

```php
// Get/set current FSM state
public function getCurrentState(): string
public function setCurrentState(string $state): self

// Get/set/update state data
public function getStateData(): array
public function get(string $key, mixed $default = null): mixed
public function set(string $key, mixed $value): self
public function updateStateData(array $data): self
public function clearStateData(): self
```

### Slot System (Entity Extraction)

Slots are used to store extracted entities from user messages (product IDs, quantities, addresses, etc.):

```php
// Slot management
public function getSlot(string $name, mixed $default = null): mixed
public function setSlot(string $name, mixed $value): self
public function hasSlot(string $name): bool
public function clearSlot(string $name): self
public function getAllSlots(): array
public function clearAllSlots(): self
```

### Conversation History

```php
// Add state transition to history
public function addHistoryEntry(string $event, string $fromState, string $toState, array $payload = []): self

// Add user/bot exchange
public function addExchange(string $userMessage, string $botResponse): self

// Get last exchange
public function getLastExchange(): ?array

// Get all history
public function getHistory(): array
```

### Timeout & Expiration

```php
// Check if conversation timed out (default: 24 hours)
public function isTimedOut(int $timeoutSeconds = 86400): bool

// Check if expired
public function isExpired(): bool

// Get inactive duration in seconds
public function getInactiveDuration(): int

// Reset to initial state
public function reset(): self
```

### AI Context Building

```php
// Build context string for AI prompts
public function buildAiContext(): string
```

Constructs a formatted string containing:
- Current FSM state
- Recent conversation exchanges (last 5)
- Current slots
- State data

Used when making AI classification/generation requests.

---

## Action Execution Flow

Complete request→action→response flow:

```
User Message (WhatsApp)
    ↓
WebhookMessageProcessor.process()
    ↓
IdempotencyService (deduplication)
    ↓
Message Storage + Intent Classification (AI)
    ↓
Action Resolution
    ├─ Context-based (awaiting address, cart update, etc.)
    ├─ Pattern matching (product IDs, category IDs)
    └─ Intent-based (fallback to AI intent)
    ↓
ActionRegistry.execute(actionName, phone, params, context)
    ↓
Handler Selection (highest priority handler)
    ↓
AbstractAction.handle() implementation
    ├─ CartService calls
    ├─ CustomerService calls
    ├─ Stock validation
    ├─ Price formatting
    └─ MessageBuilder creation
    ↓
ActionResult (success/failure + messages + state + context)
    ↓
applyActionResult()
    ├─ State transitions (via ConversationContext)
    ├─ Context updates (slots, state data)
    └─ Message queue for WhatsApp
    ↓
Response Messages sent to WhatsApp API
    ↓
wch_webhook_message_processed hook fired
```

**Reference:** `includes/Queue/Processors/WebhookMessageProcessor.php:127-209`

---

## Built-in Actions

### Core Action Handlers

Registered in `includes/Providers/ActionServiceProvider.php:46-55`:

1. **AddToCartAction**
   - **Action Name:** `add_to_cart`
   - **Parameters:** `product_id` (required), `variant_id` (optional), `quantity` (default: 1)
   - **Validation:** Stock check, product exists, variant required for variable products
   - **Result:** Cart summary in context, confirmation message

2. **ShowCartAction**
   - **Action Name:** `show_cart`
   - **Parameters:** None
   - **Returns:** Cart items, prices, totals, action buttons

3. **ShowCategoryAction**
   - **Action Name:** `show_category`
   - **Parameters:** `category_id` (required)
   - **Returns:** Products in category with pagination

4. **ShowProductAction**
   - **Action Name:** `show_product`
   - **Parameters:** `product_id` (required)
   - **Returns:** Product details, images, price, stock, variants

5. **ShowMainMenuAction**
   - **Action Name:** `show_main_menu`
   - **Parameters:** None
   - **Returns:** Personalized main menu with navigation options

6. **RequestAddressAction**
   - **Action Name:** `request_address`
   - **Parameters:** None
   - **State Transition:** Sets context to awaiting address input
   - **Returns:** Saved addresses or address input prompt

7. **ConfirmOrderAction**
   - **Action Name:** `confirm_order`
   - **Parameters:** None
   - **Returns:** Order summary, confirmation buttons

8. **ProcessPaymentAction**
   - **Action Name:** `process_payment`
   - **Parameters:** `payment_method` (optional)
   - **Integrates:** PaymentGatewayRegistry for payment processing

### Action Parameters

Actions receive parameters from:
- **Pattern matching:** Extracted from message text (e.g., product ID from "I want product #123")
- **Intent classification:** AI extracts entities from message
- **Context slots:** Previously stored values from conversation
- **Button payloads:** Structured data from interactive message button clicks

---

## Extension Points

### 1. WordPress Hook: `wch_register_action_handlers`

**Location:** `includes/Providers/ActionServiceProvider.php:107`

**Critical extension point** for registering custom action handlers.

**Parameters:**
- `$registry` (ActionRegistry): The action registry instance
- `$container` (Container): Dependency injection container

**Example Usage:**

```php
add_action('wch_register_action_handlers', function($registry, $container) {
    // Create custom handler
    $customHandler = new MyCustomAction();

    // Inject dependencies if needed
    $customHandler->setLogger($container->get('logger'));
    $customHandler->setCartService($container->get(CartServiceInterface::class));

    // Register with registry
    $registry->register($customHandler);
}, 10);
```

**Timing:** Fires after core handlers are registered, before any message processing.

### 2. Service Provider Method: `addHandler()`

**Location:** `includes/Providers/ActionServiceProvider.php:159-163`

Register handlers early in the plugin initialization lifecycle.

**Example:**

```php
// In your plugin initialization
$actionProvider = $container->get(ActionServiceProvider::class);
$actionProvider->addHandler(MyCustomAction::class);
```

### 3. WordPress Hook: `wch_webhook_message_processed`

**Location:** `includes/Queue/Processors/WebhookMessageProcessor.php:198`

Post-processing hook for analytics, logging, or side effects.

**Parameters:**
- `$data` (array): Webhook payload data
- `$conversation` (Conversation): Updated conversation entity
- `$intent` (string): Classified intent
- `$actionResult` (ActionResult): Result from action execution

**Example:**

```php
add_action('wch_webhook_message_processed', function($data, $conversation, $intent, $actionResult) {
    // Analytics tracking
    MyAnalytics::track('conversation_interaction', [
        'phone' => $conversation->getPhone(),
        'intent' => $intent,
        'success' => $actionResult->isSuccess(),
        'state' => $conversation->getState(),
    ]);
}, 10, 4);
```

### 4. Implementing Interfaces

Create custom implementations for:

- **ActionHandlerInterface**: Custom action handlers
- **CheckoutStateManagerInterface**: Custom checkout flow logic
- **CartServiceInterface**: Custom cart behavior
- **CustomerServiceInterface**: Custom customer management

---

## Creating Custom Actions

### Step 1: Create Handler Class

```php
<?php

namespace YourPlugin\Actions;

use WCH\Actions\Contracts\ActionHandlerInterface;
use WCH\Actions\AbstractAction;
use WCH\ValueObjects\ActionResult;
use WCH\ValueObjects\ConversationContext;

class MyCustomAction extends AbstractAction
{
    /**
     * Primary action name
     */
    public function getName(): string
    {
        return 'my_custom_action';
    }

    /**
     * Support additional action names
     */
    public function supports(string $actionName): bool
    {
        return in_array($actionName, ['my_custom_action', 'custom_alias'], true);
    }

    /**
     * Set higher priority to override built-in handlers
     */
    public function getPriority(): int
    {
        return 20; // Higher than default 10
    }

    /**
     * Handle the action
     */
    public function handle(string $phone, array $params, ConversationContext $context): ActionResult
    {
        // Log action
        $this->log('Executing custom action', ['phone' => $phone, 'params' => $params]);

        // Get customer profile
        $customer = $this->getCustomerProfile($phone);

        // Perform custom logic
        $result = $this->doCustomLogic($params);

        if (!$result['success']) {
            return $this->error($result['error'], 'browsing');
        }

        // Create response messages
        $message = $this->createMessageBuilder()
            ->text("Custom action completed successfully!")
            ->addButton('Back to Menu', 'show_main_menu');

        // Return success with state transition and context updates
        return ActionResult::success(
            messages: [$message],
            nextState: 'browsing',
            contextUpdates: [
                'last_custom_action' => $result['data'],
                'custom_action_timestamp' => time(),
            ]
        );
    }

    private function doCustomLogic(array $params): array
    {
        // Your custom business logic
        return ['success' => true, 'data' => []];
    }
}
```

### Step 2: Register Handler

**Option A: Via Hook (Recommended)**

```php
// In your plugin's main file or init hook
add_action('wch_register_action_handlers', function($registry, $container) {
    $handler = new \YourPlugin\Actions\MyCustomAction();

    // Inject dependencies
    $handler->setLogger($container->get('logger'));
    $handler->setCartService($container->get(\WCH\Contracts\Services\CartServiceInterface::class));
    $handler->setCustomerService($container->get(\WCH\Services\CustomerService::class));

    $registry->register($handler);
}, 10, 2);
```

**Option B: Via Service Provider**

```php
// Early in plugin initialization
$actionProvider = $container->get(\WCH\Providers\ActionServiceProvider::class);
$actionProvider->addHandler(\YourPlugin\Actions\MyCustomAction::class);
```

### Step 3: Trigger Your Action

Your action can be triggered by:

1. **AI Intent Classification**: AI classifies user message as your action name
2. **Pattern Matching**: Add pattern matching in `WebhookMessageProcessor::handleActionRouting()`
3. **Button Payload**: Use action name in interactive button callbacks
4. **Direct Invocation**: Other actions can trigger yours via `ActionRegistry::execute()`

---

## Best Practices

### 1. Priority Strategy

- **Default (10)**: Standard actions
- **High (20+)**: Override built-in actions
- **Low (1-9)**: Fallback handlers

### 2. State Management

- **Use `nextState`**: Always specify state transitions in ActionResult
- **Context Updates**: Store relevant data in context for subsequent actions
- **Slots**: Use slots for entity extraction, state data for action-specific info

### 3. Error Handling

```php
// Validate inputs
if (empty($params['product_id'])) {
    return $this->error('Product ID is required', 'browsing');
}

// Validate business logic
if (!$this->hasStock($productId, $quantity)) {
    return $this->error('Product out of stock', 'browsing');
}

// Handle exceptions gracefully
try {
    $result = $this->performOperation();
} catch (\Exception $e) {
    $this->log('Operation failed', ['error' => $e->getMessage()], 'error');
    return $this->error('Operation failed. Please try again.', 'browsing');
}
```

### 4. Logging

```php
// Log important actions
$this->log('Action started', ['params' => $params]);

// Log errors with context
$this->log('Validation failed', [
    'product_id' => $productId,
    'error' => 'Product not found',
], 'error');

// Log success
$this->log('Action completed', ['result' => $result]);
```

### 5. Message Building

```php
$message = $this->createMessageBuilder()
    ->text("Main message text")
    ->addButton('Option 1', 'action_name_1')
    ->addButton('Option 2', 'action_name_2')
    ->addImage($imageUrl); // Optional

return ActionResult::success([$message], 'next_state');
```

### 6. Context Updates

```php
// Store action-specific data
return ActionResult::success(
    messages: [$message],
    nextState: 'browsing',
    contextUpdates: [
        'selected_product_id' => $productId,
        'last_action' => $this->getName(),
        'action_timestamp' => time(),
        // Slots for entity extraction
        'slots' => [
            'product_id' => $productId,
            'quantity' => $quantity,
        ],
    ]
);
```

### 7. Testing Custom Actions

```php
// Unit test example
public function testCustomAction(): void
{
    $handler = new MyCustomAction();
    $context = new ConversationContext(
        phone: '1234567890',
        currentState: 'browsing',
        stateData: [],
        slots: [],
        history: []
    );

    $result = $handler->handle('1234567890', ['param' => 'value'], $context);

    $this->assertTrue($result->isSuccess());
    $this->assertEquals('browsing', $result->getNextState());
    $this->assertCount(1, $result->getMessages());
}
```

---

## Summary

The WhatsApp Commerce Hub action routing system provides:

- **Flexible Architecture**: Priority-based handler selection
- **Type Safety**: Strict contracts and interfaces
- **Extensibility**: Multiple extension points via hooks and interfaces
- **State Management**: FSM with conversation context and slots
- **Developer-Friendly**: Abstract base class with helpers
- **Testability**: Clear contracts for unit testing

**Key Contracts:**
- `ActionHandlerInterface`: Action handler contract
- `ActionResult`: Immutable result value object
- `ConversationContext`: Conversation state container
- `StateMachine`: FSM state transitions

**Extension Points:**
- `wch_register_action_handlers`: Register custom handlers
- `wch_webhook_message_processed`: Post-processing hook
- `ActionServiceProvider::addHandler()`: Early registration
- Interface implementations for custom behavior

For questions or contributions, refer to the codebase or submit an issue.
