# Catalog Browser Usage Guide

## Overview

The catalog browsing functionality in WhatsApp Commerce Hub provides a comprehensive product browsing experience. It enables customers to browse products, search, view details, and select variants through WhatsApp's interactive messaging interface.

## Architecture

The catalog browsing implementation follows a modern action-based architecture:

- **Domain Service**: `WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser` (located at `includes/Domain/Catalog/CatalogBrowser.php`)
- **Action Handlers**: Individual action classes in `includes/Actions/`
  - `ShowMainMenuAction` - Main navigation menu
  - `ShowCategoryAction` - Category browsing with pagination
  - `ShowProductAction` - Product details and variants

The `CatalogBrowser` class serves as a facade that delegates to registered action handlers via the `ActionRegistry`.

## Public Methods

The `CatalogBrowser` class provides the following public methods:

### 1. showMainMenu($conversation): array

Displays the main catalog browsing menu with navigation options.

```php
use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;

$browser = wch(CatalogBrowser::class);
$messages = $browser->showMainMenu($conversation);
```

**Delegates to**: `ShowMainMenuAction` (action name: `show_main_menu`)

**Features:**
- Personalized greeting for returning customers
- Shopping options: Shop by Category, Search Products
- Orders & Support: View Cart, Track Order, Talk to Support
- Main menu navigation button

**Returns**: Array of `MessageBuilder` instances

### 2. showCategory(int $categoryId, int $page, $conversation): array

Shows products in a specific category with pagination support.

```php
$browser = wch(CatalogBrowser::class);
$messages = $browser->showCategory($categoryId, $page, $conversation);
```

**Parameters:**
- `$categoryId`: WooCommerce category term ID
- `$page`: Page number (starts at 1)
- `$conversation`: Conversation context (object or array)

**Delegates to**: `ShowCategoryAction` (action name: `show_category`)

**Features:**
- If no category_id provided: Shows category selection list
- Products displayed with name, price, and stock status (✅/❌)
- Pagination buttons (Previous/Next)
- Shows "Page X of Y" in footer
- 10 products per page
- Back to menu navigation

**Returns**: Array of `MessageBuilder` instances

### 3. showProduct(int $productId, $conversation): array

Displays comprehensive product information.

```php
$browser = wch(CatalogBrowser::class);
$messages = $browser->showProduct($productId, $conversation);
```

**Parameters:**
- `$productId`: WooCommerce product ID
- `$conversation`: Conversation context (object or array)

**Delegates to**: `ShowProductAction` (action name: `show_product`)

**Message Sequence:**
1. **Image message** (if available) - Product image URL
2. **Product details** - Name, description, price, stock
3. **Variant selector** (for variable products) - Interactive list of variants

**Features:**
- Product image display with URL
- Formatted description (max 1024 chars, HTML stripped)
- Price and stock information with emojis
- "Add to Cart" button for simple products
- Variant selection for variable products (up to 10 variants)
- Stock quantity display if managed
- Back navigation button

**Returns**: Array of `MessageBuilder` instances

### 4. searchProducts(string $query, int $page, $conversation): array

Searches products by name and returns matching products.

```php
$browser = wch(CatalogBrowser::class);
$messages = $browser->searchProducts($query, $page, $conversation);
```

**Parameters:**
- `$query`: Search term
- `$page`: Page number (starts at 1)
- `$conversation`: Conversation context

**Features:**
- Uses WooCommerce's native `wc_get_products()` search
- Returns up to 10 products per page
- Simple text-based product list with prices

**Returns**: Array of `MessageBuilder` instances

### 5. showFeaturedProducts(int $page, $conversation): array

Displays featured products.

```php
$browser = wch(CatalogBrowser::class);
$messages = $browser->showFeaturedProducts($page, $conversation);
```

**Parameters:**
- `$page`: Page number (starts at 1)
- `$conversation`: Conversation context

**Features:**
- Queries products with `featured` flag
- Returns up to 10 products per page
- Simple text-based product list with prices

**Returns**: Array of `MessageBuilder` instances

### 6. getProductsPerPage(): int

Returns the number of products displayed per page.

```php
$browser = wch(CatalogBrowser::class);
$perPage = $browser->getProductsPerPage(); // Returns 10
```

**Returns**: int (constant value: 10)

## Implementation Details

### Action-Based Architecture

The catalog browser uses an action-based architecture where:

1. **CatalogBrowser** facade methods accept a conversation context
2. Methods extract the phone number from the conversation
3. Methods call `ActionRegistry::execute()` with:
   - Action name (e.g., `show_main_menu`, `show_category`, `show_product`)
   - Customer phone number
   - Action parameters (e.g., `category_id`, `product_id`, `page`)
   - Conversation context as `ConversationContext` value object
4. The action handler executes and returns an `ActionResult`
5. The facade returns the messages from the action result

### Conversation Context Handling

The `CatalogBrowser` class accepts flexible conversation formats:
- **Array**: `['customer_phone' => '...', 'context' => [...]]`
- **Object**: `$obj->customer_phone`, `$obj->context`
- **Context data**: Can be array or JSON string, automatically decoded

This flexibility allows integration with various conversation storage formats.

## Constants

The `CatalogBrowser` class defines the following private constants:

```php
private const PRODUCTS_PER_PAGE = 10;           // Products shown per page
private const MAX_PRODUCT_NAME_LENGTH = 50;     // Max chars for product name in lists
private const MAX_PRODUCT_DESC_LENGTH = 72;     // Max chars for description in lists
private const IMAGE_SIZE = 500;                 // Thumbnail size for optimization (500x500)
```

**Note**: These constants are private. Use `getProductsPerPage()` to access the products per page value.

## Integration Examples

### Example 1: Catalog Main Menu

```php
use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;

// In a webhook processor or action handler
$browser = wch(CatalogBrowser::class);
$messages = $browser->showMainMenu($conversation);

// Send messages via WhatsApp API
foreach ($messages as $messageBuilder) {
    $built = $messageBuilder->build();
    // Send via WhatsApp API client
    $apiClient->sendMessage($conversation['customer_phone'], $built);
}
```

### Example 2: Category Browsing with Pagination

```php
use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;

$browser = wch(CatalogBrowser::class);

// Show category products (page 1)
$categoryId = 42; // From user selection
$page = 1;
$messages = $browser->showCategory($categoryId, $page, $conversation);

// Navigate to next page
$page = 2;
$messages = $browser->showCategory($categoryId, $page, $conversation);
```

### Example 3: Product Search Flow

```php
use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;

$browser = wch(CatalogBrowser::class);

// User searches for "shoes"
$query = 'shoes';
$page = 1;
$messages = $browser->searchProducts($query, $page, $conversation);

// If user selects a product from results
$productId = 123; // From user selection
$messages = $browser->showProduct($productId, $conversation);
```

### Example 4: Featured Products

```php
use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;

$browser = wch(CatalogBrowser::class);

// Show featured products
$page = 1;
$messages = $browser->showFeaturedProducts($page, $conversation);
```

### Example 5: Direct Action Registry Usage

You can also use the action handlers directly via the `ActionRegistry`:

```php
use WhatsAppCommerceHub\Actions\ActionRegistry;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

$registry = wch(ActionRegistry::class);

// Show main menu
$result = $registry->execute(
    'show_main_menu',
    '+1234567890',
    [],
    new ConversationContext([])
);

// Show category
$result = $registry->execute(
    'show_category',
    '+1234567890',
    ['category_id' => 42, 'page' => 1],
    new ConversationContext([])
);

// Show product
$result = $registry->execute(
    'show_product',
    '+1234567890',
    ['product_id' => 123],
    new ConversationContext([])
);

// Get messages from result
$messages = $result->getMessages();
```

## Message Format

All methods return an array of `MessageBuilder` instances (from `WhatsAppCommerceHub\Support\Messaging\MessageBuilder`):

```php
use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;

$browser = wch(CatalogBrowser::class);
$messages = $browser->showCategory($categoryId, $page, $conversation);

foreach ($messages as $messageBuilder) {
    $builtMessage = $messageBuilder->build();
    // Send via WhatsApp API
    $apiClient->sendInteractiveMessage($phone, $builtMessage);
}
```

The `MessageBuilder` class provides methods for building WhatsApp message payloads:
- `text(string)` - Simple text message
- `header(string)` - Message header
- `body(string)` - Message body
- `footer(string)` - Message footer
- `section(string, array)` - Interactive list section with rows
- `button(string, array)` - Reply buttons
- `build()` - Build final message array for API

## Database Integration

The catalog browsing functionality integrates with WooCommerce's native data structures:

- **Categories**: Uses `get_terms()` and `get_term()` with taxonomy `product_cat`
- **Products**: Uses `wc_get_products()` with various filters (status, category, featured, search)
- **Customer Data**: Action handlers may fetch customer profiles for personalization

## Error Handling

Action handlers implement error handling:

- Returns error messages as `MessageBuilder` instances wrapped in `ActionResult::error()`
- Logs errors via the `AbstractAction::log()` method
- Validates input parameters (product_id, category_id)
- Checks product visibility and availability
- Returns user-friendly error messages on failures

## Logging

Action handlers log significant events via the `AbstractAction::log()` method:

- Main menu shown (with customer phone)
- Category browsing (category_id, page)
- Product details shown (product_id)
- Errors with exception messages

Logs are available through the plugin's logging infrastructure.

## Performance Considerations

1. **Pagination**: Limits to 10 products per page to stay within WhatsApp message limits
2. **Query Optimization**: Uses WooCommerce's native `wc_get_products()` with proper filters
3. **Text Truncation**: Product names and descriptions truncated with `wp_trim_words()` to stay within WhatsApp limits
4. **Action Registry**: Efficient action lookup and execution through centralized registry

## WhatsApp Message Limits

Action handlers respect WhatsApp API constraints:

- **Sections**: Max 10 per message (enforced by MessageBuilder)
- **Rows per section**: Max 10 (limited in action handlers)
- **Product name**: Truncated to 3 words with `wp_trim_words()`
- **Description**: Max 1024 characters for product descriptions
- **Header**: Standard WhatsApp limits
- **Footer**: Standard WhatsApp limits
- **Body**: Standard WhatsApp limits

## Action Handlers Reference

The following action handlers power catalog browsing:

### ShowMainMenuAction
- **File**: `includes/Actions/ShowMainMenuAction.php`
- **Action name**: `show_main_menu`
- **Namespace**: `WhatsAppCommerceHub\Actions\ShowMainMenuAction`
- **Features**: Personalized greetings, category shopping, search, cart, order tracking, support

### ShowCategoryAction
- **File**: `includes/Actions/ShowCategoryAction.php`
- **Action name**: `show_category`
- **Namespace**: `WhatsAppCommerceHub\Actions\ShowCategoryAction`
- **Features**: Category listing, product browsing, pagination, stock indicators

### ShowProductAction
- **File**: `includes/Actions/ShowProductAction.php`
- **Action name**: `show_product`
- **Namespace**: `WhatsAppCommerceHub\Actions\ShowProductAction`
- **Features**: Product images, details, variants, add to cart buttons

## Related Classes

- **ActionRegistry**: `WhatsAppCommerceHub\Actions\ActionRegistry` - Registers and executes actions
- **MessageBuilder**: `WhatsAppCommerceHub\Support\Messaging\MessageBuilder` - Builds WhatsApp message payloads
- **ActionResult**: `WhatsAppCommerceHub\ValueObjects\ActionResult` - Encapsulates action execution results
- **ConversationContext**: `WhatsAppCommerceHub\ValueObjects\ConversationContext` - Value object for conversation state
- **AbstractAction**: `WhatsAppCommerceHub\Actions\AbstractAction` - Base class for action handlers
