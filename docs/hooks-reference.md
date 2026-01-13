# Hooks Reference

Complete reference of WordPress actions and filters available in WhatsApp Commerce Hub.

## Table of Contents

- [Conversation Hooks](#conversation-hooks)
- [Message Hooks](#message-hooks)
- [Order Hooks](#order-hooks)
- [Sync Hooks](#sync-hooks)
- [Payment Hooks](#payment-hooks)
- [AI Assistant Hooks](#ai-assistant-hooks)
- [Admin Hooks](#admin-hooks)
- [Webhook Hooks](#webhook-hooks)
- [Settings Hooks](#settings-hooks)
- [Cart Hooks](#cart-hooks)

---

## Conversation Hooks

### Actions

#### `wch_conversation_started`

Fires when a new conversation is initiated.

**Parameters**:
- `$conversation` (object) - The conversation object
- `$customer_phone` (string) - Customer phone number

**Example**:
```php
add_action('wch_conversation_started', function($conversation, $customer_phone) {
    // Log new conversation
    error_log("New conversation from: " . $customer_phone);

    // Send notification to Slack
    notify_slack("New WhatsApp conversation started with {$customer_phone}");
}, 10, 2);
```

#### `wch_conversation_completed`

Fires when a conversation is marked as completed.

**Parameters**:
- `$conversation` (object) - The conversation object
- `$order_id` (int|null) - Associated order ID if exists

**Example**:
```php
add_action('wch_conversation_completed', function($conversation, $order_id) {
    if ($order_id) {
        // Tag customer in CRM
        update_customer_tag($conversation->customer_phone, 'whatsapp-purchaser');
    }
}, 10, 2);
```

#### `wch_conversation_abandoned`

Fires when a conversation is marked as abandoned.

**Parameters**:
- `$conversation` (object) - The conversation object
- `$state` (string) - The state where conversation was abandoned

**Example**:
```php
add_action('wch_conversation_abandoned', function($conversation, $state) {
    // Track abandonment analytics
    analytics_track('conversation_abandoned', [
        'phone' => $conversation->customer_phone,
        'state' => $state,
        'duration' => $conversation->duration
    ]);
}, 10, 2);
```

#### `wch_conversation_state_changed`

Fires when conversation state changes.

**Parameters**:
- `$conversation` (object) - The conversation object
- `$old_state` (string) - Previous state
- `$new_state` (string) - New state

**Example**:
```php
add_action('wch_conversation_state_changed', function($conversation, $old_state, $new_state) {
    if ($new_state === 'CHECKOUT') {
        // Customer reached checkout, high intent
        trigger_special_offer($conversation->customer_phone);
    }
}, 10, 3);
```

### Filters

#### `wch_conversation_timeout`

Filter conversation timeout duration in seconds.

**Parameters**:
- `$timeout` (int) - Default timeout in seconds (1800 = 30 minutes)

**Returns**: (int) Modified timeout

**Example**:
```php
add_filter('wch_conversation_timeout', function($timeout) {
    // Extend timeout to 1 hour
    return 3600;
});
```

#### `wch_conversation_metadata`

Filter conversation metadata before saving.

**Parameters**:
- `$metadata` (array) - Conversation metadata
- `$conversation` (object) - The conversation object

**Returns**: (array) Modified metadata

**Example**:
```php
add_filter('wch_conversation_metadata', function($metadata, $conversation) {
    // Add custom tracking data
    $metadata['utm_source'] = 'whatsapp';
    $metadata['referrer'] = get_customer_referrer($conversation->customer_phone);
    return $metadata;
}, 10, 2);
```

---

## Message Hooks

### Actions

#### `wch_message_received`

Fires when a message is received from WhatsApp.

**Parameters**:
- `$message` (object) - The message object
- `$conversation` (object) - The conversation object

**Example**:
```php
add_action('wch_message_received', function($message, $conversation) {
    // Log all incoming messages
    log_message('inbound', $message);

    // Detect urgent keywords
    if (preg_match('/urgent|emergency/i', $message->text)) {
        alert_support_team($conversation->customer_phone);
    }
}, 10, 2);
```

#### `wch_message_sent`

Fires after a message is successfully sent to WhatsApp.

**Parameters**:
- `$message` (object) - The message object
- `$whatsapp_message_id` (string) - WhatsApp message ID
- `$conversation` (object) - The conversation object

**Example**:
```php
add_action('wch_message_sent', function($message, $whatsapp_message_id, $conversation) {
    // Track message delivery
    analytics_track('message_sent', [
        'type' => $message->type,
        'conversation_id' => $conversation->id
    ]);
}, 10, 3);
```

#### `wch_message_failed`

Fires when a message fails to send.

**Parameters**:
- `$message` (object) - The message object
- `$error` (WP_Error) - Error object
- `$conversation` (object) - The conversation object

**Example**:
```php
add_action('wch_message_failed', function($message, $error, $conversation) {
    // Alert admin of failed messages
    if ($message->attempts >= 3) {
        notify_admin("Message failed after 3 attempts: " . $error->get_error_message());
    }
}, 10, 3);
```

#### `wch_message_read`

Fires when a message is marked as read.

**Parameters**:
- `$message_id` (string) - WhatsApp message ID
- `$conversation` (object) - The conversation object

**Example**:
```php
add_action('wch_message_read', function($message_id, $conversation) {
    // Update engagement metrics
    update_engagement_score($conversation->customer_phone, 'message_read');
}, 10, 2);
```

### Filters

#### `wch_message_content`

Filter message content before sending.

**Parameters**:
- `$content` (string) - Message content
- `$type` (string) - Message type (text, image, interactive, etc.)
- `$conversation` (object) - The conversation object

**Returns**: (string) Modified content

**Example**:
```php
add_filter('wch_message_content', function($content, $type, $conversation) {
    if ($type === 'text') {
        // Add signature to all text messages
        $content .= "\n\n-- " . get_option('wch_business_name');
    }
    return $content;
}, 10, 3);
```

#### `wch_message_buttons`

Filter interactive message buttons.

**Parameters**:
- `$buttons` (array) - Array of button objects
- `$context` (string) - Context (e.g., 'product', 'cart', 'checkout')

**Returns**: (array) Modified buttons

**Example**:
```php
add_filter('wch_message_buttons', function($buttons, $context) {
    if ($context === 'product') {
        // Add "Save for Later" button
        $buttons[] = [
            'id' => 'save_for_later',
            'title' => '🔖 Save for Later'
        ];
    }
    return $buttons;
}, 10, 2);
```

---

## Order Hooks

### Actions

#### `wch_order_created`

Fires when an order is created through WhatsApp.

**Parameters**:
- `$order_id` (int) - WooCommerce order ID
- `$conversation` (object) - The conversation object
- `$cart` (object) - The cart object

**Example**:
```php
add_action('wch_order_created', function($order_id, $conversation, $cart) {
    // Add order note
    $order = wc_get_order($order_id);
    $order->add_order_note('Order placed via WhatsApp');

    // Tag customer
    update_user_meta($order->get_customer_id(), 'ordered_via_whatsapp', true);
}, 10, 3);
```

#### `wch_order_status_changed`

Fires when order status changes.

**Parameters**:
- `$order_id` (int) - Order ID
- `$old_status` (string) - Previous status
- `$new_status` (string) - New status

**Example**:
```php
add_action('wch_order_status_changed', function($order_id, $old_status, $new_status) {
    if ($new_status === 'completed') {
        // Send thank you message via WhatsApp
        $order = wc_get_order($order_id);
        send_thank_you_message($order->get_billing_phone());
    }
}, 10, 3);
```

### Filters

#### `wch_order_confirmation_message`

Filter order confirmation message.

**Parameters**:
- `$message` (string) - Confirmation message
- `$order` (WC_Order) - WooCommerce order object

**Returns**: (string) Modified message

**Example**:
```php
add_filter('wch_order_confirmation_message', function($message, $order) {
    // Add estimated delivery date
    $delivery_date = calculate_delivery_date($order);
    $message .= "\n\nEstimated delivery: " . $delivery_date;
    return $message;
}, 10, 2);
```

---

## Sync Hooks

### Actions

#### `wch_product_synced`

Fires after a product is synced to WhatsApp catalog.

**Parameters**:
- `$product_id` (int) - WooCommerce product ID
- `$whatsapp_product_id` (string) - WhatsApp catalog product ID
- `$success` (bool) - Whether sync was successful

**Example**:
```php
add_action('wch_product_synced', function($product_id, $whatsapp_product_id, $success) {
    if ($success) {
        update_post_meta($product_id, '_whatsapp_synced', time());
    }
}, 10, 3);
```

#### `wch_inventory_synced`

Fires after inventory is synced.

**Parameters**:
- `$product_id` (int) - Product ID
- `$old_stock` (int) - Previous stock quantity
- `$new_stock` (int) - New stock quantity

**Example**:
```php
add_action('wch_inventory_synced', function($product_id, $old_stock, $new_stock) {
    if ($new_stock === 0) {
        // Notify admin of out-of-stock
        notify_low_stock($product_id);
    }
}, 10, 3);
```

### Filters

#### `wch_product_sync_data`

Filter product data before syncing to WhatsApp.

**Parameters**:
- `$product_data` (array) - Product data for WhatsApp API
- `$product` (WC_Product) - WooCommerce product object

**Returns**: (array) Modified product data

**Example**:
```php
add_filter('wch_product_sync_data', function($product_data, $product) {
    // Add custom fields
    $product_data['custom_label_0'] = $product->get_attribute('brand');
    $product_data['custom_label_1'] = $product->get_attribute('material');
    return $product_data;
}, 10, 2);
```

#### `wch_sync_product_categories`

Filter which product categories to sync.

**Parameters**:
- `$categories` (array) - Array of category IDs

**Returns**: (array) Modified category IDs

**Example**:
```php
add_filter('wch_sync_product_categories', function($categories) {
    // Don't sync "Private" category
    $private_cat_id = get_term_by('slug', 'private', 'product_cat')->term_id;
    return array_diff($categories, [$private_cat_id]);
});
```

---

## Payment Hooks

### Actions

#### `wch_payment_initiated`

Fires when payment process starts.

**Parameters**:
- `$payment_method` (string) - Payment method (cod, stripe, razorpay, etc.)
- `$order_id` (int) - Order ID
- `$amount` (float) - Payment amount

**Example**:
```php
add_action('wch_payment_initiated', function($payment_method, $order_id, $amount) {
    // Track payment method usage
    analytics_track('payment_initiated', [
        'method' => $payment_method,
        'amount' => $amount
    ]);
}, 10, 3);
```

#### `wch_payment_completed`

Fires when payment is successfully completed.

**Parameters**:
- `$payment_method` (string) - Payment method
- `$order_id` (int) - Order ID
- `$transaction_id` (string) - Payment gateway transaction ID

**Example**:
```php
add_action('wch_payment_completed', function($payment_method, $order_id, $transaction_id) {
    // Send receipt
    $order = wc_get_order($order_id);
    send_payment_receipt($order, $transaction_id);
}, 10, 3);
```

#### `wch_payment_failed`

Fires when payment fails.

**Parameters**:
- `$payment_method` (string) - Payment method
- `$order_id` (int) - Order ID
- `$error` (WP_Error) - Error object

**Example**:
```php
add_action('wch_payment_failed', function($payment_method, $order_id, $error) {
    // Alert customer of payment failure
    $order = wc_get_order($order_id);
    send_payment_failure_message($order->get_billing_phone(), $error->get_error_message());
}, 10, 3);
```

#### `wch_register_payment_gateways`

Fires during payment gateway registration.

**Parameters**:
- `$payment_manager` (WCH_Payment_Manager) - Payment manager instance

**Example**:
```php
add_action('wch_register_payment_gateways', function($payment_manager) {
    // Register custom payment gateway
    $payment_manager->register_gateway(new My_Custom_Payment_Gateway());
});
```

### Filters

#### `wch_available_payment_methods`

Filter available payment methods for customer.

**Parameters**:
- `$methods` (array) - Array of payment method IDs
- `$customer_phone` (string) - Customer phone number
- `$cart_total` (float) - Cart total amount

**Returns**: (array) Modified payment methods

**Example**:
```php
add_filter('wch_available_payment_methods', function($methods, $customer_phone, $cart_total) {
    // Disable COD for orders over $500
    if ($cart_total > 500) {
        $methods = array_diff($methods, ['cod']);
    }
    return $methods;
}, 10, 3);
```

---

## AI Assistant Hooks

### Filters

#### `wch_ai_system_prompt`

Filter AI system prompt.

**Parameters**:
- `$prompt` (string) - System prompt
- `$context` (array) - Conversation context

**Returns**: (string) Modified prompt

**Example**:
```php
add_filter('wch_ai_system_prompt', function($prompt, $context) {
    // Add dynamic business hours
    $hours = get_business_hours();
    $prompt .= "\n\nCurrent business hours: {$hours}";
    return $prompt;
}, 10, 2);
```

#### `wch_ai_functions`

Filter AI function calling definitions.

**Parameters**:
- `$functions` (array) - Array of function definitions

**Returns**: (array) Modified functions

**Example**:
```php
add_filter('wch_ai_functions', function($functions) {
    // Add custom function
    $functions[] = [
        'name' => 'check_store_hours',
        'description' => 'Check if store is currently open',
        'parameters' => [
            'type' => 'object',
            'properties' => []
        ]
    ];
    return $functions;
});
```

#### `wch_ai_function_result`

Filter AI function execution result.

**Parameters**:
- `$result` (mixed) - Function result
- `$function_name` (string) - Function name
- `$arguments` (array) - Function arguments
- `$context` (array) - Conversation context

**Returns**: (mixed) Modified result

**Example**:
```php
add_filter('wch_ai_function_result', function($result, $function_name, $arguments, $context) {
    if ($function_name === 'get_product_info') {
        // Add real-time inventory check
        $result['in_stock'] = check_realtime_inventory($arguments['product_id']);
    }
    return $result;
}, 10, 4);
```

#### `wch_ai_content_safe`

Filter AI content safety check.

**Parameters**:
- `$is_safe` (bool) - Whether content is safe
- `$text` (string) - Content to check

**Returns**: (bool) Modified safety status

**Example**:
```php
add_filter('wch_ai_content_safe', function($is_safe, $text) {
    // Add custom content filters
    $blocked_words = ['spam', 'scam'];
    foreach ($blocked_words as $word) {
        if (stripos($text, $word) !== false) {
            return false;
        }
    }
    return $is_safe;
}, 10, 2);
```

---

## Admin Hooks

### Actions

#### `wch_admin_settings_saved`

Fires after settings are saved.

**Parameters**:
- `$settings` (array) - Saved settings

**Example**:
```php
add_action('wch_admin_settings_saved', function($settings) {
    // Clear cache when settings change
    wp_cache_flush();
}, 10, 1);
```

#### `wch_admin_broadcast_sent`

Fires after broadcast campaign is sent.

**Parameters**:
- `$broadcast_id` (int) - Broadcast ID
- `$sent_count` (int) - Number of messages sent
- `$failed_count` (int) - Number of failures

**Example**:
```php
add_action('wch_admin_broadcast_sent', function($broadcast_id, $sent_count, $failed_count) {
    // Log broadcast results
    error_log("Broadcast {$broadcast_id}: {$sent_count} sent, {$failed_count} failed");
}, 10, 3);
```

### Filters

#### `wch_admin_menu_capability`

Filter required capability for admin menu access.

**Parameters**:
- `$capability` (string) - Required capability (default: 'manage_options')

**Returns**: (string) Modified capability

**Example**:
```php
add_filter('wch_admin_menu_capability', function($capability) {
    // Allow shop managers to access WhatsApp Commerce
    return 'manage_woocommerce';
});
```

---

## Webhook Hooks

### Actions

#### `wch_webhook_messages`

Fires when messages webhook is received.

**Parameters**:
- `$data` (array) - Webhook data

**Example**:
```php
add_action('wch_webhook_messages', function($data) {
    // Custom message processing
    foreach ($data['messages'] as $message) {
        custom_message_processor($message);
    }
});
```

#### `wch_webhook_message_status`

Fires when message status webhook is received.

**Parameters**:
- `$data` (array) - Status update data

**Example**:
```php
add_action('wch_webhook_message_status', function($data) {
    // Track delivery analytics
    foreach ($data['statuses'] as $status) {
        track_message_status($status['id'], $status['status']);
    }
});
```

### Filters

#### `wch_webhook_validate_signature`

Filter webhook signature validation.

**Parameters**:
- `$is_valid` (bool) - Whether signature is valid
- `$signature` (string) - Provided signature
- `$payload` (string) - Request payload

**Returns**: (bool) Modified validation result

**Example**:
```php
add_filter('wch_webhook_validate_signature', function($is_valid, $signature, $payload) {
    // Add custom signature validation
    if (!$is_valid) {
        // Try alternative validation method
        return custom_signature_check($signature, $payload);
    }
    return $is_valid;
}, 10, 3);
```

---

## Settings Hooks

### Filters

#### `wch_settings_defaults`

Filter default settings.

**Parameters**:
- `$defaults` (array) - Default settings array

**Returns**: (array) Modified defaults

**Example**:
```php
add_filter('wch_settings_defaults', function($defaults) {
    // Set custom defaults
    $defaults['general']['welcome_message'] = 'Hi! Welcome to our store 🛍️';
    $defaults['ai']['ai_temperature'] = 0.5;
    return $defaults;
});
```

---

## Cart Hooks

### Actions

#### `wch_cart_item_added`

Fires when item is added to cart.

**Parameters**:
- `$cart_id` (int) - Cart ID
- `$product_id` (int) - Product ID
- `$quantity` (int) - Quantity added

**Example**:
```php
add_action('wch_cart_item_added', function($cart_id, $product_id, $quantity) {
    // Track add to cart event
    analytics_track('cart_item_added', [
        'product_id' => $product_id,
        'quantity' => $quantity
    ]);
}, 10, 3);
```

#### `wch_cart_abandoned`

Fires when cart is marked as abandoned.

**Parameters**:
- `$cart` (object) - Cart object

**Example**:
```php
add_action('wch_cart_abandoned', function($cart) {
    // Queue abandoned cart recovery
    schedule_cart_recovery($cart);
});
```

### Filters

#### `wch_cart_item_price`

Filter cart item price display.

**Parameters**:
- `$price` (float) - Item price
- `$product_id` (int) - Product ID
- `$cart_id` (int) - Cart ID

**Returns**: (float) Modified price

**Example**:
```php
add_filter('wch_cart_item_price', function($price, $product_id, $cart_id) {
    // Apply VIP customer discount
    $cart = get_cart($cart_id);
    if (is_vip_customer($cart->customer_phone)) {
        $price *= 0.9; // 10% discount
    }
    return $price;
}, 10, 3);
```

#### `wch_abandoned_cart_reminder_message`

Filter abandoned cart reminder message.

**Parameters**:
- `$message` (string) - Reminder message
- `$cart` (object) - Cart object

**Returns**: (string) Modified message

**Example**:
```php
add_filter('wch_abandoned_cart_reminder_message', function($message, $cart) {
    // Add personalized discount code
    $discount_code = generate_recovery_discount($cart->customer_phone);
    $message .= "\n\nUse code {$discount_code} for 10% off!";
    return $message;
}, 10, 2);
```

---

## FSM (Finite State Machine) Hooks

### Filters

#### `wch_fsm_transitions`

Filter FSM state transitions.

**Parameters**:
- `$transitions` (array) - Array of state transitions

**Returns**: (array) Modified transitions

**Example**:
```php
add_filter('wch_fsm_transitions', function($transitions) {
    // Add custom state transition
    $transitions[] = [
        'from' => 'BROWSING',
        'to' => 'WISHLIST',
        'event' => 'save_for_later',
        'guards' => [],
        'actions' => ['save_to_wishlist']
    ];
    return $transitions;
});
```

#### `wch_fsm_guard_check`

Filter FSM guard checks.

**Parameters**:
- `$result` (bool|null) - Guard check result (null = use default)
- `$guard_name` (string) - Guard name
- `$conversation` (object) - Conversation object
- `$payload` (array) - Event payload

**Returns**: (bool|null) Modified result

**Example**:
```php
add_filter('wch_fsm_guard_check', function($result, $guard_name, $conversation, $payload) {
    if ($guard_name === 'has_minimum_order') {
        // Custom minimum order check
        $cart = get_cart($conversation->cart_id);
        return $cart->total >= 25.00;
    }
    return $result;
}, 10, 4);
```

#### `wch_fsm_action_execute`

Filter FSM action execution.

**Parameters**:
- `$result` (mixed|null) - Action result (null = use default)
- `$action_name` (string) - Action name
- `$conversation` (object) - Conversation object
- `$payload` (array) - Event payload

**Returns**: (mixed|null) Modified result

**Example**:
```php
add_filter('wch_fsm_action_execute', function($result, $action_name, $conversation, $payload) {
    if ($action_name === 'show_special_offer') {
        // Custom special offer logic
        return custom_show_offer($conversation, $payload);
    }
    return $result;
}, 10, 4);
```

---

## Intent Classification Hooks

### Filters

#### `wch_custom_intents`

Filter custom intent definitions.

**Parameters**:
- `$intents` (array) - Array of custom intent definitions

**Returns**: (array) Modified intents

**Example**:
```php
add_filter('wch_custom_intents', function($intents) {
    $intents[] = [
        'name' => 'request_gift_wrap',
        'patterns' => ['gift wrap', 'wrap as gift', 'gift packaging'],
        'confidence' => 0.8,
        'handler' => 'handle_gift_wrap_request'
    ];
    return $intents;
});
```

#### `wch_intent_keywords`

Filter intent keyword patterns.

**Parameters**:
- `$keywords` (array) - Intent keyword mappings

**Returns**: (array) Modified keywords

**Example**:
```php
add_filter('wch_intent_keywords', function($keywords) {
    // Add regional variations
    $keywords['BROWSE_PRODUCTS'][] = 'show catalogue';
    $keywords['CHECKOUT'][] = 'purchase now';
    return $keywords;
});
```

#### `wch_detected_intent`

Filter detected intent.

**Parameters**:
- `$intent` (string) - Detected intent
- `$text` (string) - User message text
- `$keyword` (string) - Matched keyword

**Returns**: (string) Modified intent

**Example**:
```php
add_filter('wch_detected_intent', function($intent, $text, $keyword) {
    // Override intent based on context
    if ($intent === 'BROWSE_PRODUCTS' && time() > strtotime('22:00')) {
        return 'CUSTOMER_SERVICE'; // Shop closed, redirect to support
    }
    return $intent;
}, 10, 3);
```

---

## Recovery Hooks

### Filters

#### `wch_recovery_template_variables`

Filter template variables for recovery messages.

**Parameters**:
- `$variables` (array) - Template variables
- `$cart` (object) - Cart object
- `$sequence` (int) - Recovery sequence number (1, 2, or 3)

**Returns**: (array) Modified variables

**Example**:
```php
add_filter('wch_recovery_template_variables', function($variables, $cart, $sequence) {
    // Add discount based on sequence
    $discounts = [1 => 5, 2 => 10, 3 => 15];
    $variables['discount_percent'] = $discounts[$sequence];
    $variables['discount_code'] = generate_discount_code($cart->id, $discounts[$sequence]);
    return $variables;
}, 10, 3);
```

---

## Best Practices

### Hook Priority

Use appropriate priorities for hooks:
- **10**: Default priority for most hooks
- **5**: Earlier execution (before defaults)
- **15-20**: Later execution (after defaults)
- **100+**: Very late execution

### Error Handling

Always handle errors in hook callbacks:

```php
add_action('wch_order_created', function($order_id, $conversation, $cart) {
    try {
        // Your code here
        risky_operation($order_id);
    } catch (Exception $e) {
        WCH_Logger::log('error', 'Hook failed: ' . $e->getMessage(), 'hooks');
    }
}, 10, 3);
```

### Performance

Avoid heavy operations in frequently-fired hooks:

```php
// BAD: Heavy database query on every message
add_action('wch_message_received', function($message) {
    $stats = calculate_all_time_stats(); // Expensive!
});

// GOOD: Use caching or schedule async
add_action('wch_message_received', function($message) {
    wp_schedule_single_event(time() + 60, 'update_message_stats');
});
```

### Type Checking

Always validate hook parameters:

```php
add_filter('wch_cart_item_price', function($price, $product_id, $cart_id) {
    if (!is_numeric($price) || $price < 0) {
        return $price; // Return unchanged if invalid
    }
    // Your logic here
    return $price;
}, 10, 3);
```

---

## Examples

### Complete Custom Integration

```php
/**
 * Custom loyalty program integration
 */
class WCH_Loyalty_Integration {
    public function __construct() {
        // Track points on order
        add_action('wch_order_created', [$this, 'award_points'], 10, 3);

        // Apply points discount
        add_filter('wch_cart_item_price', [$this, 'apply_points_discount'], 10, 3);

        // Add custom intent for points inquiry
        add_filter('wch_custom_intents', [$this, 'add_points_intent']);

        // Show points in welcome message
        add_filter('wch_message_content', [$this, 'add_points_to_message'], 10, 3);
    }

    public function award_points($order_id, $conversation, $cart) {
        $order = wc_get_order($order_id);
        $points = floor($order->get_total());
        update_user_meta($order->get_customer_id(), 'loyalty_points', $points);
    }

    public function apply_points_discount($price, $product_id, $cart_id) {
        $cart = get_cart($cart_id);
        $customer = get_customer($cart->customer_phone);

        if ($customer && $customer->use_points) {
            $points = get_user_meta($customer->id, 'loyalty_points', true);
            $discount = min($points * 0.01, $price * 0.5); // Max 50% off
            return $price - $discount;
        }

        return $price;
    }

    public function add_points_intent($intents) {
        $intents[] = [
            'name' => 'check_loyalty_points',
            'patterns' => ['points', 'loyalty', 'rewards', 'how many points'],
            'confidence' => 0.9
        ];
        return $intents;
    }

    public function add_points_to_message($content, $type, $conversation) {
        if ($type === 'text' && strpos($content, 'Welcome') !== false) {
            $customer = get_customer($conversation->customer_phone);
            if ($customer) {
                $points = get_user_meta($customer->id, 'loyalty_points', true);
                $content .= "\n\n💎 You have {$points} loyalty points!";
            }
        }
        return $content;
    }
}

new WCH_Loyalty_Integration();
```

---

For more examples, see [Extending the Plugin](extending.md).
