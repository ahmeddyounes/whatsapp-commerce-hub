# WCH AI Assistant Implementation

## Overview
The WCH_AI_Assistant class provides OpenAI-powered conversation assistance with function calling, rate limiting, cost tracking, and error handling.

## Features Implemented

### 1. Core Configuration
- Constructor accepts: `api_key`, `model`, `temperature`, `max_tokens`, `system_prompt`
- Settings integration for persistent configuration
- Default values from WCH_Settings

### 2. Response Generation (`generate_response()`)
- Dynamic system prompt building with:
  - Business identity and tone guidelines
  - Available products summary (top categories, promotions)
  - Current conversation state and context
  - Customer profile information
  - State-specific instructions (BROWSING, VIEWING_PRODUCT, etc.)
- OpenAI API integration with function calling
- Context-aware response generation

### 3. Function Calling
Implemented 5 functions with OpenAI function calling:

1. **suggest_products(query, limit)**
   - Search and suggest products based on customer query
   - Returns product list with IDs, names, and prices

2. **get_product_details(product_id)**
   - Get detailed product information
   - Returns name, price, description, stock status

3. **add_to_cart(product_id, quantity)**
   - Add product to customer's cart
   - Validates product exists
   - Returns action for cart update

4. **apply_coupon(code)**
   - Validate and apply coupon code
   - Checks coupon validity
   - Returns action for coupon application

5. **escalate_to_human(reason)**
   - Escalate conversation to human agent
   - Logs escalation reason
   - Returns REQUEST_HUMAN action

### 4. Rate Limiting
- Max 10 AI calls per conversation per hour
- Cache-based tracking using `wp_cache`
- Automatic expiration after 1 hour
- Graceful degradation when limit exceeded

### 5. Cost Tracking
- Token usage logging per conversation
- Estimated cost calculation (GPT-4 pricing)
- Monthly usage aggregation
- Budget cap with alerts:
  - Warning at 80% usage
  - Critical alert at 100% usage

### 6. Error Handling
- 15-second request timeout
- Automatic retry on 5xx errors (once)
- Graceful error messages
- Comprehensive logging

### 7. Content Filtering
- Validates AI responses before sending
- Blocks inappropriate content patterns
- Filter hook for custom validation
- Safe fallback messages

## Configuration

### Settings Schema
```php
'ai' => array(
    'enable_ai'          => 'bool',    // Enable/disable AI assistant
    'openai_api_key'     => 'string',  // OpenAI API key (encrypted)
    'ai_model'           => 'string',  // Model name (default: gpt-4)
    'ai_temperature'     => 'float',   // Temperature (default: 0.7)
    'ai_max_tokens'      => 'int',     // Max tokens (default: 500)
    'ai_system_prompt'   => 'string',  // Base system prompt
    'monthly_budget_cap' => 'float',   // Monthly budget in USD (0 = no limit)
)
```

### Default Values
- `enable_ai`: false
- `ai_model`: 'gpt-4'
- `ai_temperature`: 0.7
- `ai_max_tokens`: 500
- `monthly_budget_cap`: 0.0 (no limit)

## Usage Example

```php
// Create AI assistant instance
$ai = new WCH_AI_Assistant();

// Generate response
$response = $ai->generate_response(
    'I need help finding a laptop',
    array(
        'conversation_id' => 123,
        'current_state'   => 'BROWSING',
        'customer_name'   => 'John Doe',
        'cart_items'      => array(),
    )
);

// Check response
if ( empty( $response['error'] ) ) {
    // Send text response
    echo $response['text'];

    // Process any actions
    foreach ( $response['actions'] as $action ) {
        // Handle action based on function type
    }
}
```

## API Response Structure

```php
array(
    'text'    => 'AI response text',
    'actions' => array(
        array(
            'function' => 'function_name',
            'args'     => array(...),
            'result'   => array(...),
        ),
    ),
    'error'   => null, // or error message
)
```

## Monthly Usage Tracking

```php
// Get current month usage
$usage = $ai->get_monthly_usage();

// Returns:
array(
    'tokens' => 12345,      // Total tokens used
    'cost'   => 1.23,       // Total cost in USD
    'calls'  => 45,         // Total API calls
)
```

## Filters & Hooks

### Available Filters

1. **wch_ai_functions** - Modify available AI functions
2. **wch_ai_system_prompt** - Customize system prompt
3. **wch_ai_function_result** - Modify function call results
4. **wch_ai_content_safe** - Custom content safety checks

## Testing

Run the test suite:
```bash
php test-ai-assistant.php
```

## Notes

- API key is stored encrypted in settings
- Rate limits are per-conversation, not global
- Monthly usage resets automatically each month
- Function calls can be extended via filters
- All API calls are logged for debugging
