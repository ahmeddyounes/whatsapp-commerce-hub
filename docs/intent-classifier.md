# Natural Language Intent Classifier

## Overview

The Intent Classifier is a Natural Language Processing (NLP) component that classifies free-text messages into predefined intents using rule-based pattern matching and optional AI-enhanced classification.

## Components

### WCH_Intent Class

Represents a classified intent from user input.

**Properties:**
- `intent_name` (string): Intent name from enum (GREETING, BROWSE, SEARCH, VIEW_CART, etc.)
- `confidence` (float): Confidence score between 0 and 1
- `entities` (array): Extracted entities with type, value, and position

**Methods:**
- `to_array()`: Convert intent to array
- `to_json()`: Convert intent to JSON string
- `get_entity($type)`: Get entity by type
- `get_entities_by_type($type)`: Get all entities of a specific type
- `has_entity($type)`: Check if has entity of type

**Intent Types:**
- `GREETING`: Greetings and salutations
- `BROWSE`: Request to browse products/catalog
- `SEARCH`: Search for specific products
- `VIEW_CART`: View shopping cart
- `CHECKOUT`: Proceed to checkout
- `ORDER_STATUS`: Check order status
- `CANCEL`: Cancel or remove items
- `HELP`: Request help or human agent
- `UNKNOWN`: Unrecognized intent

### WCH_Intent_Classifier Class

Main classifier that processes text and returns WCH_Intent objects.

**Methods:**

#### `classify($text, $context = array())`
Classify intent from text with optional context.

**Parameters:**
- `$text` (string): User message text to classify
- `$context` (array): Optional conversation context

**Returns:** WCH_Intent object

**Example:**
```php
$classifier = new WCH_Intent_Classifier();
$intent = $classifier->classify('I want to buy shoes', ['current_state' => 'BROWSING']);

echo $intent->intent_name;  // CHECKOUT
echo $intent->confidence;   // 0.9
```

## Pattern Matching Rules

The classifier uses regex patterns with associated confidence scores:

| Intent | Pattern | Confidence | Notes |
|--------|---------|------------|-------|
| GREETING | `/^(hi\|hello\|hey\|good\s*(morning\|afternoon\|evening))/i` | 0.95 | Greetings at start |
| BROWSE | `/(show\|browse\|see\|view).*(products?\|catalog\|items?\|collection)/i` | 0.9 | Browse requests |
| SEARCH | `/(search\|find\|looking for\|want\|need)\s+(.+)/i` | 0.85 | Search queries, extracts product name |
| VIEW_CART | `/(my )?(cart\|basket\|bag)/i` | 0.9 | Cart viewing |
| CHECKOUT | `/(checkout\|buy\|purchase\|pay\|order)/i` | 0.9 | Checkout intent |
| ORDER_STATUS | `/(order\|track\|where).*(status\|order\|package\|delivery)/i` | 0.85 | Order tracking, extracts order number |
| CANCEL | `/(cancel\|remove\|delete)/i` | 0.8 | Cancellation |
| HELP | `/(help\|support\|assist\|human\|agent\|person)/i` | 0.9 | Help requests |

## Entity Extraction

The classifier automatically extracts entities from user messages:

### Entity Types

#### PRODUCT_NAME
Extracted from SEARCH intents using the search pattern capture group.

**Example:**
- Input: "I am looking for blue shoes"
- Entity: `{type: 'PRODUCT_NAME', value: 'blue shoes', position: 17}`

#### QUANTITY
Extracted from numeric patterns with optional quantity words (pieces, items, units, pcs, x).

**Pattern:** `/(\d+)\s*(pieces?|items?|units?|pcs?|x)?/i`

**Example:**
- Input: "I want 3 items"
- Entity: `{type: 'QUANTITY', value: 3, position: 7}`

#### ORDER_NUMBER
Extracted from patterns like #12345 (4+ digits).

**Pattern:** `/#(\d{4,})/`

**Example:**
- Input: "Where is order #12345?"
- Entity: `{type: 'ORDER_NUMBER', value: '12345', position: 15}`

#### PHONE
Extracted phone numbers (10+ digits).

**Pattern:** `/(\+?[\d\s\-\(\)]{10,})/`

**Example:**
- Input: "My phone is +1-234-567-8900"
- Entity: `{type: 'PHONE', value: '+12345678900', position: 12}`

#### EMAIL
Extracted email addresses.

**Pattern:** `/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/`

**Example:**
- Input: "Email me at john@example.com"
- Entity: `{type: 'EMAIL', value: 'john@example.com', position: 12}`

#### ADDRESS
Heuristic extraction of addresses with street keywords.

**Pattern:** Looks for street, avenue, road, boulevard, lane, drive, court, way, place, etc.

## Custom Intents

Extend the classifier with business-specific intents using the `wch_custom_intents` filter:

```php
add_filter('wch_custom_intents', function($intents) {
    $intents['REFUND_REQUEST'] = array(
        'regex'      => '/(refund|money back|return)/i',
        'confidence' => 0.9,
    );

    $intents['COMPLAINT'] = array(
        'regex'      => '/(complain|complaint|not happy|dissatisfied)/i',
        'confidence' => 0.85,
    );

    return $intents;
});
```

## AI-Enhanced Classification

For ambiguous cases where rule-based confidence is below 0.7, the classifier can use OpenAI for enhanced classification.

### Setup

1. Configure OpenAI API key in WordPress options:
```php
update_option('wch_openai_api_key', 'sk-...');
```

2. The classifier automatically uses AI fallback when:
   - API key is configured
   - Rule-based confidence < 0.7
   - AI classification confidence > rule-based confidence

### How It Works

1. Rule-based classification runs first
2. If confidence < 0.7 and AI is enabled:
   - Sends message and context to OpenAI GPT-3.5-turbo
   - Receives intent and confidence in JSON format
   - Uses AI result if confidence is higher
3. Entity extraction still uses rule-based methods

## Caching

Classifications are cached for 1 hour (3600 seconds) using WordPress object cache.

**Cache Key:** MD5 hash of lowercase text + context state

**Benefits:**
- Faster response times for repeated messages
- Reduced API calls for AI-enhanced classification
- Automatic cache invalidation after 1 hour

**Clear Cache:**
```php
$classifier = new WCH_Intent_Classifier();
$classifier->clear_cache();
```

## Logging

All classifications are logged via WCH_Logger for training data collection:

**Logged Information:**
- Original text
- Classified intent
- Confidence score
- Extracted entities
- Conversation context
- Timestamp

**Log Level:** INFO

**Example Log Entry:**
```php
[
    'text'       => 'I want to buy shoes',
    'intent'     => 'CHECKOUT',
    'confidence' => 0.9,
    'entities'   => [],
    'context'    => 'BROWSING',
    'timestamp'  => '2025-01-06 12:34:56',
]
```

## Performance

### Classification Speed

- **Rule-based:** < 100ms (target: 50ms average)
- **With AI fallback:** < 2000ms (depends on OpenAI API)

### Accuracy Requirements

- Common intents: > 90% accuracy
- Proper entity extraction for search terms, order numbers, contact info
- Fallback to UNKNOWN for ambiguous messages

## Usage Examples

### Basic Classification

```php
$classifier = new WCH_Intent_Classifier();
$intent = $classifier->classify('Hello!');

if ($intent->intent_name === WCH_Intent::INTENT_GREETING) {
    echo "User is greeting us!";
}
```

### With Context

```php
$context = array(
    'current_state'  => 'VIEWING_PRODUCT',
    'customer_phone' => '+1234567890',
);

$intent = $classifier->classify('Add to cart', $context);
```

### Entity Extraction

```php
$intent = $classifier->classify('Find me 3 blue shirts');

// Get product name
if ($intent->has_entity('PRODUCT_NAME')) {
    $product = $intent->get_entity('PRODUCT_NAME');
    echo "Product: " . $product['value'];
}

// Get quantity
if ($intent->has_entity('QUANTITY')) {
    $qty = $intent->get_entity('QUANTITY');
    echo "Quantity: " . $qty['value'];
}
```

### Integration with Conversation Flow

```php
function handle_user_message($message, $context) {
    $classifier = new WCH_Intent_Classifier();
    $intent = $classifier->classify($message, $context->to_array());

    switch ($intent->intent_name) {
        case WCH_Intent::INTENT_GREETING:
            return send_welcome_message();

        case WCH_Intent::INTENT_SEARCH:
            if ($intent->has_entity('PRODUCT_NAME')) {
                $product_name = $intent->get_entity('PRODUCT_NAME')['value'];
                return search_and_display_products($product_name);
            }
            return ask_what_to_search();

        case WCH_Intent::INTENT_CHECKOUT:
            return start_checkout_flow();

        default:
            return send_help_message();
    }
}
```

## Testing

### Automated Tests

Run the test suite:

1. Navigate to `/wp-content/plugins/whatsapp-commerce-hub/test-intent-classifier.php`
2. Must be logged in as admin
3. Tests cover:
   - All intent types
   - Entity extraction
   - Confidence thresholds
   - Edge cases

### Interactive Testing

The test page includes an interactive form to test custom messages.

### Expected Results

- **Greetings:** 95%+ confidence
- **Browse/Checkout/Cart:** 90%+ confidence
- **Search/Order Status:** 85%+ confidence
- **Entity Extraction:** Correct extraction for defined patterns

## Statistics

Get classifier statistics:

```php
$classifier = new WCH_Intent_Classifier();
$stats = $classifier->get_statistics();

print_r($stats);
// Output:
// Array (
//     [patterns_count] => 8
//     [custom_intents_count] => 0
//     [ai_enabled] => false
//     [cache_expiration] => 3600
// )
```

## Best Practices

1. **Use Context:** Always provide conversation context when available for better accuracy
2. **Handle Low Confidence:** Implement fallback for UNKNOWN intents or low confidence scores
3. **Log Everything:** Use built-in logging for continuous improvement
4. **Custom Intents:** Add business-specific patterns via filter
5. **Entity Validation:** Validate extracted entities before use
6. **Cache Management:** Clear cache after pattern updates
7. **Monitor Performance:** Track classification times and accuracy

## Limitations

1. **Rule-based limitations:** May miss nuanced or complex language patterns
2. **Language Support:** Currently optimized for English
3. **Entity Accuracy:** Heuristic extraction may have false positives
4. **AI Dependency:** Optional AI features require external API and key
5. **Cache Persistence:** Uses WordPress object cache (may not persist across requests without persistent cache)

## Future Enhancements

- Multi-language support
- Machine learning model training from logged data
- Confidence threshold configuration
- Advanced entity extraction (NER models)
- Intent suggestion/autocomplete
- A/B testing for pattern optimization
